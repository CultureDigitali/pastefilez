<?php
declare(strict_types=1);

use App\Services\UploadGuard;
use Tests\T;

T::group('response/stream-disposition');

T::ok(UploadGuard::inlineSafe('image/png'), 'png served inline');
T::ok(UploadGuard::inlineSafe('image/jpeg'), 'jpeg served inline');
T::ok(UploadGuard::inlineSafe('image/gif'), 'gif served inline');
T::ok(UploadGuard::inlineSafe('image/webp'), 'webp served inline');
T::ok(UploadGuard::inlineSafe('video/webm'), 'video served inline');
T::ok(UploadGuard::inlineSafe('video/mp4'), 'mp4 served inline');
T::ok(UploadGuard::inlineSafe('audio/ogg'), 'audio served inline');
T::ok(UploadGuard::inlineSafe('audio/mpeg'), 'mp3 served inline');

T::ok(!UploadGuard::inlineSafe('text/plain'), 'text/plain forced to attachment');
T::ok(!UploadGuard::inlineSafe('text/html'), 'text/html forced to attachment');
T::ok(!UploadGuard::inlineSafe('image/svg+xml'), 'svg forced to attachment');
T::ok(UploadGuard::inlineSafe('application/pdf'), 'pdf served inline for the preview iframe');
T::ok(!UploadGuard::inlineSafe('application/zip'), 'zip forced to attachment');
T::ok(!UploadGuard::inlineSafe('application/json'), 'json forced to attachment');
T::ok(!UploadGuard::inlineSafe(''), 'unknown mime forced to attachment');
T::ok(!UploadGuard::inlineSafe('application/octet-stream'), 'binary forced to attachment');

T::group('response/function-existence');

foreach (['fpassthru', 'readfile', 'header', 'hash_equals', 'random_bytes'] as $fn) {
    T::ok(function_exists($fn), "global function {$fn}() is callable from namespaced code");
}

$src = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Core/Response.php');
T::ok(!str_contains($src, 'fpasesthru'), 'fpasesthru typo is gone from Response.php');
T::contains($src, 'fpassthru(', 'Response::stream uses fpassthru');
