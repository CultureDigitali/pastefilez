<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\Response;
use App\Services\StorageService;
use App\Services\UploadGuard;
use Tests\T;

T::group('upload-guard/mime');

// --- allowlist ---
T::ok(UploadGuard::mimeAllowed('image/png'), 'image/png allowed');
T::ok(UploadGuard::mimeAllowed('application/pdf'), 'pdf allowed');
T::ok(UploadGuard::mimeAllowed('video/mp4'), 'video/* allowed');
T::ok(!UploadGuard::mimeAllowed('application/x-msdownload'), 'exe rejected');
T::ok(!UploadGuard::mimeAllowed('application/x-sh'), 'shell rejected');
T::ok(!UploadGuard::mimeAllowed('application/octet-stream'), 'octet-stream rejected');

// --- active content must never be allowed even via text/* or image/* wildcards ---
T::ok(!UploadGuard::mimeAllowed('text/html'), 'text/html rejected');
T::ok(!UploadGuard::mimeAllowed('image/svg+xml'), 'svg rejected');
T::ok(!UploadGuard::mimeAllowed('application/xhtml+xml'), 'xhtml rejected');
T::ok(!UploadGuard::mimeAllowed('text/xml'), 'text/xml rejected');
T::ok(!UploadGuard::mimeAllowed('application/xml'), 'application/xml rejected');
T::ok(!UploadGuard::mimeAllowed('text/html; charset=utf-8'), 'html with charset rejected');
T::ok(!UploadGuard::mimeAllowed('TEXT/HTML'), 'uppercase TEXT/HTML rejected');

// --- empty/garbage mime ---
T::ok(!UploadGuard::mimeAllowed(''), 'empty mime rejected');
T::ok(!UploadGuard::mimeAllowed('image/png, text/html'), 'comma-injected mime rejected');

// --- inline serving decision: XSS-relevant types must be forced to attachment ---
T::ok(!UploadGuard::inlineSafe('text/html'), 'text/html not inline safe');
T::ok(!UploadGuard::inlineSafe('image/svg+xml'), 'svg not inline safe');
T::ok(UploadGuard::inlineSafe('application/pdf'), 'pdf inline safe (iframe preview)');
T::ok(UploadGuard::inlineSafe('image/png'), 'png inline safe');
T::ok(UploadGuard::inlineSafe('image/jpeg'), 'jpeg inline safe');
T::ok(UploadGuard::inlineSafe('image/gif'), 'gif inline safe');
T::ok(UploadGuard::inlineSafe('image/webp'), 'webp inline safe');
T::ok(UploadGuard::inlineSafe('video/mp4'), 'mp4 inline safe');
T::ok(UploadGuard::inlineSafe('audio/mpeg'), 'mp3 inline safe');
T::ok(!UploadGuard::inlineSafe('text/plain'), 'text/plain not inline safe (charset sniffing)');

// --- safeName ---
T::eq('a_b.txt', UploadGuard::safeName('a/b.txt'), 'slash in name replaced');
T::eq('a_b.txt', UploadGuard::safeName('a\\b.txt'), 'backslash in name replaced');
T::eq('ab.txt', UploadGuard::safeName("a\x00b.txt"), 'NUL stripped');
T::eq('ab.txt', UploadGuard::safeName("a\nb.txt"), 'newline stripped');
T::eq('file', UploadGuard::safeName(''), 'empty name falls back');
T::eq('file', UploadGuard::safeName('...'), 'dot-only name falls back');
T::ok(mb_strlen(UploadGuard::safeName(str_repeat('a', 500))) <= 200, 'name truncated to 200');
