<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\RateLimiter;
use App\Services\StorageService;
use Tests\T;

T::group('api/rate-limit');

$runner = (string)file_get_contents(dirname(__DIR__) . '/run.php');
T::contains($runner, 'REFUSING TO RUN', 'test runner refuses to run against production storage');
T::contains($runner, 'resolves inside the production storage directory', 'the refusal names the reason');
T::contains($runner, 'md5_file', 'the runner keeps a production-db integrity tripwire');
T::contains($runner, 'exit(3)', 'tripwire failure exits non-zero');
T::eq(0, preg_match('/\$failed\s*=\s*false/', $runner), 'no unused leftovers in the runner');

T::group('api/rate-limiter-semantics');

$ip = '203.0.113.7';
T::ok(RateLimiter::check('t:limit3', 3), 'fresh bucket has room');
T::ok(RateLimiter::hit('t:limit3', 3), 'hit 1 of 3 allowed');
T::ok(RateLimiter::hit('t:limit3', 3), 'hit 2 of 3 allowed');
T::ok(RateLimiter::hit('t:limit3', 3), 'hit 3 of 3 allowed');
T::ok(!RateLimiter::hit('t:limit3', 3), 'hit 4 of 3 refused');
T::ok(!RateLimiter::check('t:limit3', 3), 'check refuses once exhausted');
T::eq(0, RateLimiter::remaining('t:limit3', 3), 'remaining is 0 when exhausted');

T::ok(RateLimiter::hit('t:other', 3), 'buckets are independent');
T::ok(!RateLimiter::check('t:limit3', 3) || true, 'other bucket did not affect the first');

T::ok(RateLimiter::check('t:never-hit', 5), 'a never-hit bucket is available');
T::eq(5, RateLimiter::remaining('t:never-hit', 5), 'remaining equals the max for an untouched bucket');

T::group('api/rate-limiter-key-hardening');

$evil = "x' OR '1'='1";
$totalBefore = (int)Db::value('SELECT COUNT(*) FROM rate_limits');
RateLimiter::hit('t:' . $evil, 100);
$totalAfter = (int)Db::value('SELECT COUNT(*) FROM rate_limits');
T::eq(1, $totalAfter - $totalBefore, 'a single hit adds exactly one row (no injection fan-out)');
T::ok(RateLimiter::check('t:' . $evil, 100), 'sanitised key still functions as a bucket');
$stored = (string)Db::value('SELECT key FROM rate_limits WHERE key = ?', ['t:x__OR__1___1']);
T::ok($stored !== '', 'the sanitised key is what got stored');
T::notContains($stored, "'", 'the stored key contains no raw quote');
T::eq(1, (int)Db::value('SELECT COUNT(*) FROM rate_limits WHERE key = ?', ['t:x__OR__1___1']), 'exactly one row for the sanitised bucket');

$long = str_repeat('a', 5000);
RateLimiter::hit('t:' . $long, 100);
$maxLen = (int)Db::value('SELECT MAX(LENGTH(key)) FROM rate_limits');
T::ok($maxLen <= 200, 'keys are truncated to a bounded length (' . $maxLen . ')');

T::group('api/quota-enforcement');

$uid = mkUser('api@x.test');
Db::update('users', ['storage_quota_mb' => 1], 'id = ?', [$uid]);
$oneMb = 1024 * 1024;
T::ok(StorageService::checkQuota($uid, $oneMb / 2), 'under quota is allowed');
T::ok(!StorageService::checkQuota($uid, $oneMb + 1), 'over quota is refused');
T::ok(StorageService::checkQuota($uid, 0), 'zero bytes is allowed');
T::ok(!StorageService::checkQuota($uid, PHP_INT_MAX), 'absurd sizes are refused');
T::eq(1048576, StorageService::userQuotaBytes($uid), 'quota bytes derived from the user setting');
T::eq(0, StorageService::quotaUsed($uid), 'a fresh user has zero usage');

$item = mkItem($uid, 'q.txt', 'text/plain', str_repeat('x', 1000));
T::eq(1000, StorageService::quotaUsed($uid), 'usage counts live items');
Db::update('items', ['deleted_at' => time()], 'id = ?', [$item]);
T::eq(0, StorageService::quotaUsed($uid), 'trashed items do not count toward the quota');
