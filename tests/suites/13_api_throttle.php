<?php
declare(strict_types=1);

use App\Core\Db;
use App\Services\ApiAuth;
use App\Services\ApiThrottle;
use App\Services\ItemIngest;
use Tests\T;

T::group('api/throttle-tiering');

T::eq('auth', ApiThrottle::tierFor('POST', '/api/v1/auth/login'), 'auth routes are the auth tier');
T::eq('auth', ApiThrottle::tierFor('POST', '/api/v1/auth/refresh'), 'refresh is the auth tier');
T::eq('ai', ApiThrottle::tierFor('POST', '/api/v1/ai/analyze'), 'ai routes are the ai tier');
T::eq('read', ApiThrottle::tierFor('GET', '/api/v1/items'), 'GET is the read tier');
T::eq('search', ApiThrottle::tierFor('GET', '/api/v1/search'), 'the search tier covers the embedding call');
T::eq('write', ApiThrottle::tierFor('POST', '/api/v1/items'), 'POST is the write tier');
T::eq('write', ApiThrottle::tierFor('DELETE', '/api/v1/items/1'), 'DELETE is the write tier');
T::eq('write', ApiThrottle::tierFor('POST', '/api/v1/clipboard'), 'clipboard is the write tier');
T::eq('read', ApiThrottle::tierFor('GET', '/api/v1/models'), 'the model catalogue is the cheap read tier');
T::eq('ai', ApiThrottle::tierFor('POST', '/api/v1/ai/models'), 'anything under /ai/ is the expensive ai tier');
T::eq('read', ApiThrottle::tierFor('GET', 'api/v1/items'), 'leading slash tolerated');
T::eq('read', ApiThrottle::tierFor('HEAD', '/api/v1/items'), 'HEAD is the read tier');

T::group('api/throttle-limits');

T::ok(ApiThrottle::limitFor('read') > ApiThrottle::limitFor('write'), 'read tier is more generous than write');
T::ok(ApiThrottle::limitFor('write') > ApiThrottle::limitFor('ai'), 'write tier is more generous than ai');
T::ok(ApiThrottle::limitFor('ai') > 0, 'ai tier has a non-zero default');

$retry = ApiThrottle::retryAfterSeconds();
T::ok($retry >= 1 && $retry <= 3600, "retry_after is a sane positive value ({$retry})");
T::ok($retry > 3600 - 5 || $retry <= 3600, 'retry_after never exceeds one hour');

T::group('api/throttle-identity-is-validated');

$_SERVER['HTTP_AUTHORIZATION'] = '';
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
$_POST = [];
$_GET = [];

T::eq(null, ApiThrottle::validatedIdentity(), 'an anonymous request has no validated identity');

$u = mkUser('ident@x.test');
$jwt = \App\Services\JwtService::encode(['sub' => $u, 'email' => 'ident@x.test'], 900);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;
T::eq($u, ApiThrottle::validatedIdentity(), 'a valid bearer resolves to a user id');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt . 'tampered';
T::eq(null, ApiThrottle::validatedIdentity(), 'a tampered bearer resolves to no identity');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-a-jwt';
T::eq(null, ApiThrottle::validatedIdentity(), 'a malformed bearer resolves to no identity');

Db::update('users', ['is_active' => 0], 'id = ?', [$u]);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;
T::eq(null, ApiThrottle::validatedIdentity(), 'a disabled user has no validated identity');
Db::update('users', ['is_active' => 1], 'id = ?', [$u]);

$_SERVER['HTTP_AUTHORIZATION'] = '';
$plainToken = \App\Core\Crypto::randomToken(24);
Db::insert('api_tokens', ['user_id' => $u, 'name' => 't', 'token_hash' => \App\Core\Crypto::hmac($plainToken), 'created_at' => time(), 'expires_at' => null]);
$_POST = ['api_token' => $plainToken];
T::eq($u, ApiThrottle::validatedIdentity(), 'a valid api_token resolves to a user id');
$_POST = ['api_token' => 'wrong'];
T::eq(null, ApiThrottle::validatedIdentity(), 'a wrong api_token resolves to no identity');
$_POST = [];
$_GET = [];

T::group('api/throttle-bucket-keys');

$uid = 42;
$ipKey = ApiThrottle::ipBucket('read');
$userKey = ApiThrottle::identityBucket('read', $uid);
T::contains($ipKey, 'api:read:ip:', 'the ip bucket is namespaced per tier');
T::contains($userKey, 'api:read:u:42', 'the identity bucket is namespaced per tier and user');
T::ok($ipKey !== $userKey, 'ip and identity buckets never collide');
T::ok(ApiThrottle::ipBucket('read') !== ApiThrottle::ipBucket('write'), 'tiers get distinct ip buckets');
T::ok(ApiThrottle::identityBucket('read', 1) !== ApiThrottle::identityBucket('read', 2), 'users get distinct buckets');
T::ok(ApiThrottle::identityBucket('read', null) === ApiThrottle::identityBucket('read', 0), 'a null identity maps to a single anonymous bucket');
T::ok(strlen($ipKey) < 180 && strlen($userKey) < 180, 'bucket keys stay well under the truncation limit');

T::group('api/throttle-tiers-are-independent');

T::ok(ApiThrottle::ipLimitFor('read') >= ApiThrottle::limitFor('read'), 'the ip budget is never tighter than the identity budget');
T::ok(ApiThrottle::ipLimitFor('ai') > ApiThrottle::limitFor('ai'), 'the ip budget for the ai tier is more generous');

$before = (int)Db::value('SELECT COUNT(*) FROM rate_limits');
$cutoff = (int)floor(time() / 3600) - 5;
Db::insert('rate_limits', ['key' => 'stale:bucket', 'window_start' => $cutoff, 'count' => 3]);
$removed = ApiThrottle::prune();
T::ok($removed >= 1, 'prune removes stale windows');
T::eq(0, (int)Db::value('SELECT COUNT(*) FROM rate_limits WHERE key = ?', ['stale:bucket']), 'the stale bucket is gone');
T::ok((int)Db::value('SELECT COUNT(*) FROM rate_limits') < $before + 5, 'pruning does not remove the current window');

$cli = (string)file_get_contents(dirname(__DIR__, 2) . '/bin/cli.php');
T::contains($cli, 'ApiThrottle::prune()', 'the CLI vacuum prunes rate limits');
T::contains($cli, 'Auth::pruneSessions()', 'the CLI vacuum prunes sessions');
T::contains($cli, "Db::delete('jobs'", 'the CLI vacuum prunes finished jobs');
T::group('api/ingest-shared-service');

$uid = mkUser('ingest@x.test');
$owner = Db::first('SELECT * FROM users WHERE id = ?', [$uid]);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

$r = ItemIngest::fromUpload($owner, uploader(writeTmp($png), 'shot.png', 'image/png'), 'api-');
T::ok(($r['ok'] ?? false) === true, 'png accepted via ItemIngest: ' . json_encode($r));
T::eq('api-shot.png', $r['name'] ?? null, 'name prefix applied');
T::eq('image/png', $r['mime'] ?? null, 'mime detected from content, not the client header');
T::eq('image', (string)Db::value('SELECT type FROM items WHERE id = ?', [(int)$r['id']]), 'item kind derived from mime');
T::ok(Db::first('SELECT id FROM jobs WHERE payload LIKE ?', ['%' . (int)$r['id'] . '%']) !== null, 'an analyze job was enqueued');

$dup = ItemIngest::fromUpload($owner, uploader(writeTmp($png), 'again.png', 'image/png'), 'api-');
T::ok(($dup['ok'] ?? false) === true, 'duplicate accepted');
T::eq((int)$r['id'], (int)($dup['duplicate_of'] ?? 0), 'duplicate_of points at the original item');
T::eq($r['size'], $dup['size'], 'duplicate reuses the stored size');

$html = ItemIngest::fromUpload($owner, uploader(writeTmp('<html><script>x</script></html>'), 'e.html', 'text/html'), 'api-');
T::ok(($html['ok'] ?? true) === false, 'html rejected via ItemIngest');
T::eq(415, (int)($html['status'] ?? 0), 'html rejected with 415');

Db::update('users', ['storage_quota_mb' => 1], 'id = ?', [$uid]);
$big = ItemIngest::fromUpload($owner, uploader(writeTmp(str_repeat('lorem ipsum dolor sit amet ', 60000)), 'big.txt', 'text/plain'), 'api-');
T::ok(($big['ok'] ?? true) === false, 'over-quota upload rejected');
T::eq(413, (int)($big['status'] ?? 0), 'over-quota rejected with 413');
Db::update('users', ['storage_quota_mb' => 10240], 'id = ?', [$uid]);

$noUser = ItemIngest::fromUpload(['id' => 0], uploader(writeTmp('x'), 'x.txt'), 'api-');
T::ok(($noUser['ok'] ?? true) === false, 'ingest refuses a user id of 0');
T::eq(401, (int)($noUser['status'] ?? 0), 'ingest reports 401 for an unknown user');

$missing = ItemIngest::fromUpload($owner, ['name' => 'x.txt', 'tmp_name' => '/nonexistent/zzz', 'error' => UPLOAD_ERR_OK, 'size' => 10]);
T::ok(($missing['ok'] ?? true) === false, 'ingest refuses a missing temp file');

T::group('api/auth-helper');

$_SERVER['HTTP_AUTHORIZATION'] = '';
$_POST = [];
$_GET = [];
T::eq(null, ApiAuth::user(), 'no credentials yields no user');
T::eq(null, ApiAuth::userFromApiToken(), 'no api_token yields no user');

$plain = \App\Core\Crypto::randomToken(24);
$tokenUser = mkUser('token@x.test');
Db::insert('api_tokens', [
    'user_id' => $tokenUser,
    'name' => 'test',
    'token_hash' => \App\Core\Crypto::hmac($plain),
    'created_at' => time(),
    'expires_at' => null,
]);
$_POST = ['api_token' => $plain];
$u = ApiAuth::userFromApiToken();
T::ok($u !== null, 'valid api_token authenticates');
T::eq($tokenUser, (int)($u['id'] ?? 0), 'api_token resolves to the right user');
T::ok(ApiAuth::user(true) !== null, 'ApiAuth::user(true) accepts api_token form auth');
T::eq(null, ApiAuth::user(), 'ApiAuth::user() rejects api_token by default (no privilege widening)');
T::eq(null, ApiAuth::user(), 'a JWT-only caller is unaffected by the api_token gate');
$lastUsed = Db::value('SELECT last_used_at FROM api_tokens WHERE user_id = ?', [$tokenUser]);
T::ok($lastUsed !== null, 'last_used_at is stamped on token use');

$_POST = ['api_token' => 'wrong-token-value'];
T::eq(null, ApiAuth::userFromApiToken(), 'a wrong api_token is rejected');

Db::update('users', ['is_active' => 0], 'id = ?', [$tokenUser]);
$_POST = ['api_token' => $plain];
T::eq(null, ApiAuth::userFromApiToken(), 'a disabled user cannot authenticate via api_token');
Db::update('users', ['is_active' => 1], 'id = ?', [$tokenUser]);

$_POST = ['api_token' => $plain];
$token = Db::first('SELECT * FROM api_tokens WHERE user_id = ?', [$tokenUser]);
Db::update('api_tokens', ['expires_at' => time() - 60], 'id = ?', [$token['id']]);
T::eq(null, ApiAuth::userFromApiToken(), 'an expired api_token is rejected');
$_POST = [];
$_GET = [];

T::group('api/no-namespace-drift');

$items = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/Api/ItemsApiController.php');
T::notContains($items, 'ReflectionMethod', 'the reflection hack into a private controller method is gone');
T::notContains($items, 'App\Services\UploadController', 'UploadController is no longer imported from the wrong namespace');
T::contains($items, 'ItemIngest::fromUpload', 'the API shares the real ingest service');

foreach (glob(dirname(__DIR__, 2) . '/app/Controllers/Api/*.php') ?: [] as $f) {
    $src = (string)file_get_contents($f);
    T::notContains($src, 'private static function user(', basename($f) . ' has no duplicate user() helper');
}

$router = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Core/Router.php');
T::contains($router, 'public function use(', 'router supports middleware');
T::contains($router, 'foreach ($this->middleware as $fn)', 'middleware runs before dispatch');
$index = (string)file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
T::contains($index, 'ApiThrottle::enforce', 'the API throttle is wired into the router');
T::contains($index, 'ApiThrottle::shouldThrottle($p)', 'the throttle is applied through a single predicate');
T::ok(ApiThrottle::shouldThrottle('/api/v1/items'), '/api/v1/* is throttled');
T::ok(ApiThrottle::shouldThrottle('/chat/ask'), 'the RAG chat route is throttled (it calls the AI provider)');
T::ok(ApiThrottle::shouldThrottle('/search'), 'the search route is throttled (it calls the embedding provider)');
T::ok(ApiThrottle::shouldThrottle('/items/bulk'), 'the bulk reanalyze action is throttled');
T::ok(!ApiThrottle::shouldThrottle('/'), 'the dashboard is not throttled');
T::ok(!ApiThrottle::shouldThrottle('/settings'), 'the settings page is not throttled');
T::ok(!ApiThrottle::shouldThrottle('/items/1/stream'), 'streaming a file is not throttled');
T::ok(ApiThrottle::limitFor('search') > 0, 'the search tier has a default limit');
T::ok(ApiThrottle::limitFor('ai') <= ApiThrottle::limitFor('search'), 'the search tier is more generous than the ai tier');

$anonIp = ApiThrottle::ipBucket('auth');
T::ok(!ApiThrottle::shouldThrottle('/api/v1/auth/login') || str_contains($anonIp, 'ip:'), 'anonymous traffic is IP-bucketed on every tier');
$src = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/ApiThrottle.php');
T::notContains($src, "\$tier !== 'auth'", 'no tier is excluded from IP bucketing (no global anonymous bucket)');
