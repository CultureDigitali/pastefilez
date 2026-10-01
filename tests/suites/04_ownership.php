<?php
declare(strict_types=1);

use App\Core\Db;
use App\Models\Item;
use Tests\T;

T::group('tenant-isolation');

$alice = mkUser('alice@x.test');
$bob = mkUser('bob@x.test');

$a1 = mkItem($alice, 'alice-secret.txt');
$b1 = mkItem($bob, 'bob-secret.txt');

T::ok(Item::find($a1, $alice) !== null, 'owner can find own item');
T::ok(Item::find($b1, $alice) === null, 'non-owner cannot find item (find)');
T::ok(Item::find($a1, $bob) === null, 'other non-owner cannot find item (find)');

// --- tag attach must require item ownership ---
$attackerTag = (int)Db::insert('tags', ['user_id' => $alice, 'name' => 'pwned', 'color' => '', 'created_at' => time()]);
$res = \App\Services\Ownership::ownsItem($alice, $b1);
T::ok($res === false, 'attacker does not own victim item');
$res = \App\Services\Ownership::ownsItem($bob, $b1);
T::ok($res === true, 'owner owns own item');

$res = \App\Services\Ownership::canAttachTag($bob, $b1, $attackerTag);
T::ok($res === false, 'cannot attach a foreign tag to a victim item');

$res = \App\Services\Ownership::canAttachTag($alice, $b1, $attackerTag);
T::ok($res === false, 'cannot attach own tag to a victim item');

$res = \App\Services\Ownership::canAttachTag($alice, $a1, $attackerTag);
T::ok($res === true, 'can attach own tag to own item');

$res = \App\Services\Ownership::canDetachTag($alice, $a1, $attackerTag);
T::ok($res === false, 'cannot detach a link that does not exist');

Db::run('INSERT OR IGNORE INTO item_tags(item_id, tag_id) VALUES(?,?)', [$a1, $attackerTag]);
$res = \App\Services\Ownership::canDetachTag($alice, $a1, $attackerTag);
T::ok($res === true, 'can detach own tag from own item');

$res = \App\Services\Ownership::canDetachTag($alice, $b1, $attackerTag);
T::ok($res === false, 'cannot detach from another user item');

$tags = \App\Services\Ownership::itemTags($a1, $alice);
T::eq(1, count($tags), 'owner sees own tag on own item');
$tags = \App\Services\Ownership::itemTags($a1, $bob);
T::eq(0, count($tags), 'other user sees no tags on the item');

$res = \App\Services\Ownership::ownsFolder($bob, 999999);
T::ok($res === false, 'unknown folder not owned');

// --- folder ownership ---
$folderB = (int)Db::insert('folders', ['user_id' => $bob, 'name' => 'bpriv', 'parent_id' => null, 'created_at' => time()]);
T::ok(\App\Services\Ownership::ownsFolder($bob, $folderB), 'owner owns folder');
T::ok(!\App\Services\Ownership::ownsFolder($alice, $folderB), 'non-owner does not own folder');

T::ok(!\App\Services\Ownership::ownsFolder($alice, null), 'null folder not owned');
T::ok(!\App\Services\Ownership::ownsFolder($alice, 0), 'zero folder not owned');
T::ok(!\App\Services\Ownership::ownsFolder($alice, -1), 'negative folder not owned');

// --- item listing must never leak other users ---
$rows = Db::all('SELECT id FROM items WHERE user_id = ?', [$alice]);
T::ok(count($rows) === 1, 'alice sees exactly 1 item');

// --- purge refcount: dedup blobs must survive when a sibling reference exists ---
$tmp = writeTmp('shared-dedup-body');
$s1 = \App\Services\StorageService::put($tmp, 'dup1.txt', $alice, false);
$s2 = \App\Services\StorageService::put(writeTmp('shared-dedup-body'), 'dup2.txt', $alice, false);
$dup1 = (int)Db::insert('items', ['user_id' => $alice, 'type' => 'file', 'mime' => 'text/plain', 'original_name' => 'dup1.txt', 'storage_backend' => 'local', 'storage_path' => $s1['path'], 'size' => $s1['size'], 'sha256' => $s1['sha256'], 'is_encrypted' => 0, 'ai_status' => 'done', 'is_public' => 0, 'created_at' => time(), 'updated_at' => time()]);
$dup2 = (int)Db::insert('items', ['user_id' => $alice, 'type' => 'file', 'mime' => 'text/plain', 'original_name' => 'dup2.txt', 'storage_backend' => 'local', 'storage_path' => $s1['path'], 'size' => $s1['size'], 'sha256' => $s1['sha256'], 'is_encrypted' => 0, 'ai_status' => 'done', 'is_public' => 0, 'created_at' => time(), 'updated_at' => time()]);
T::eq($s1['path'], $s1['path'], 'dedup siblings share storage_path');
$refs = (int)Db::value('SELECT COUNT(*) FROM items WHERE sha256 = ? AND deleted_at IS NULL AND id != ?', [$s1['sha256'], $dup1]);
T::eq(1, $refs, 'one live sibling reference counted before purge');
