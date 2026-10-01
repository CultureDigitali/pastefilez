<?php
declare(strict_types=1);

use App\Core\Crypto;
use App\Core\Db;
use Tests\T;

T::group('cloud-token-encryption');

$uid = mkUser('cloud@x.test');

$r = new ReflectionClass(\App\Services\GoogleDriveClient::class);
$c = $r->newInstance($uid);

$accessPlain = 'ya29.access-token-value';
$refreshPlain = '1//refresh-token-value';
$c->saveTokens($accessPlain, $refreshPlain, time() + 3600, 'a@b.test');

$row = Db::first('SELECT * FROM cloud_tokens WHERE user_id = ? AND provider = ?', [$uid, 'google']);
T::ok($row !== null, 'token row persisted');
T::ok(!str_contains((string)$row['encrypted_access'], $accessPlain), 'access token not stored in cleartext');
T::ok(!str_contains((string)$row['encrypted_refresh'], $refreshPlain), 'refresh token not stored in cleartext');

$rm = new ReflectionMethod(\App\Services\BaseCloudClient::class, 'loadTokens');
$rm->setAccessible(true);
$loaded = $rm->invoke($c);

T::eq($accessPlain, $loaded['access'], 'access token round-trips');
T::eq($refreshPlain, $loaded['refresh'], 'refresh token round-trips (IV bound to its own ciphertext)');
T::eq(time() + 3600, (int)$loaded['expires_at'], 'expiry round-trips');

// second save must overwrite cleanly (update branch, not insert branch)
$c->saveTokens('ya29.second', '1//second-refresh', time() + 7200, 'a@b.test');
$loaded2 = $rm->invoke($c);
T::eq('ya29.second', $loaded2['access'], 'access token round-trips after update');
T::eq('1//second-refresh', $loaded2['refresh'], 'refresh token round-trips after update');

// no-refresh save must null the refresh column, not keep stale
$c->saveTokens('ya29.third', null, time() + 100, 'a@b.test');
$loaded3 = $rm->invoke($c);
T::eq('ya29.third', $loaded3['access'], 'access token round-trips when refresh is null');
T::eq(null, $loaded3['refresh'], 'refresh token nulled when not supplied');
