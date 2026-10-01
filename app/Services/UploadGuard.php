<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

final class UploadGuard
{
    private const ACTIVE_CONTENT = [
        'text/html',
        'application/xhtml+xml',
        'image/svg+xml',
        'text/xml',
        'application/xml',
        'application/mathml+xml',
        'application/rdf+xml',
        'text/x-php',
        'application/x-php',
        'application/x-httpd-php',
        'application/x-httpd-php-source',
        'text/x-shellscript',
        'application/x-sh',
        'application/x-shellscript',
        'text/x-perl',
        'text/x-python',
        'text/x-ruby',
        'application/javascript',
        'text/javascript',
        'application/ecmascript',
        'text/ecmascript',
    ];

    private const INLINE_SAFE = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'image/avif',
        'image/bmp',
        'image/x-icon',
        'image/vnd.microsoft.icon',
    ];

    public static function mimeAllowed(string $mime): bool
    {
        $mime = self::normalize($mime);
        if ($mime === '') return false;
        if (self::isActiveContent($mime)) return false;
        $allowed = (string)Config::get('allowed_mime', 'image/*,video/*,audio/*,application/pdf');
        $rules = array_filter(array_map('trim', explode(',', $allowed)));
        foreach ($rules as $r) {
            $r = strtolower($r);
            if ($r === $mime) return true;
            if (str_ends_with($r, '/*')) {
                $base = substr($r, 0, -2);
                if (str_starts_with($mime, $base . '/')) return true;
            }
        }
        return false;
    }

    public static function isActiveContent(string $mime): bool
    {
        return in_array(self::normalize($mime), self::ACTIVE_CONTENT, true);
    }

    public static function inlineSafe(string $mime): bool
    {
        $mime = self::normalize($mime);
        if ($mime === '') return false;
        if (in_array($mime, self::INLINE_SAFE, true)) return true;
        if ($mime === 'application/pdf') return true;
        if (self::isActiveContent($mime)) return false;
        if (str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/')) return true;
        return false;
    }

    public static function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) return '';
        $mime = (string)finfo_file($finfo, $path);
        finfo_close($finfo);
        return $mime;
    }

    public static function safeName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = preg_replace('/[\/\\\\]/', '_', $name) ?? '';
        $name = trim($name);
        if (preg_match('/^\.+$/', $name) === 1) return 'file';
        if ($name === '') return 'file';
        if (mb_strlen($name) > 200) $name = mb_substr($name, 0, 200);
        return $name !== '' ? $name : 'file';
    }

    public static function kindFromMime(string $mime): string
    {
        $mime = self::normalize($mime);
        if (str_starts_with($mime, 'image/')) return 'image';
        if (str_starts_with($mime, 'video/')) return 'video';
        if (str_starts_with($mime, 'audio/')) return 'audio';
        if ($mime === 'application/pdf') return 'pdf';
        if (str_starts_with($mime, 'text/')) return 'text';
        return 'file';
    }

    private static function normalize(string $mime): string
    {
        $mime = strtolower(trim($mime));
        $semi = strpos($mime, ';');
        if ($semi !== false) $mime = trim(substr($mime, 0, $semi));
        if (str_contains($mime, ',')) return '';
        return $mime;
    }
}
