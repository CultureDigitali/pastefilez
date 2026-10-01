<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;

final class ThumbnailService
{
    public const MAX_PIXELS = 40000000;

    public static function make(string $srcPath, string $mime, string $destName): ?string
    {
        if (!preg_match('/^[a-f0-9]{8,64}$/', $destName)) return null;
        $storage = (string)Config::get('storage_path');
        $thumbs = $storage . '/thumbs';
        if (!is_dir($thumbs)) @mkdir($thumbs, 0750, true);
        $dest = $thumbs . '/' . $destName . '.jpg';
        $maxSide = 480;
        $mime = strtolower($mime);
        if (str_starts_with($mime, 'image/')) {
            $info = @getimagesize($srcPath);
            if (!$info) return null;
            [$w, $h] = $info;
            if ($w < 1 || $h < 1 || ($w * $h) > self::MAX_PIXELS) {
                Logger::warning('thumb.pixel_bomb', ['w' => $w, 'h' => $h, 'path' => basename($srcPath)]);
                return null;
            }
            $src = self::loadImage($srcPath, $mime);
            if (!$src) return null;
            $scale = min(1, $maxSide / max($w, $h));
            $nw = (int)max(1, round($w * $scale));
            $nh = (int)max(1, round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            if (!$dst) { imagedestroy($src); return null; }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagejpeg($dst, $dest, 80);
            imagedestroy($src);
            imagedestroy($dst);
            return 'thumbs/' . $destName . '.jpg';
        }
        if (str_starts_with($mime, 'video/')) {
            $dst = self::renderVideoIcon($mime);
            imagejpeg($dst, $dest, 80);
            imagedestroy($dst);
            return 'thumbs/' . $destName . '.jpg';
        }
        if ($mime === 'application/pdf') {
            $dst = self::renderTextIcon('PDF');
            imagejpeg($dst, $dest, 80);
            imagedestroy($dst);
            return 'thumbs/' . $destName . '.jpg';
        }
        if (str_starts_with($mime, 'audio/')) {
            $dst = self::renderTextIcon('♪');
            imagejpeg($dst, $dest, 80);
            imagedestroy($dst);
            return 'thumbs/' . $destName . '.jpg';
        }
        $dst = self::renderTextIcon('📄');
        imagejpeg($dst, $dest, 80);
        imagedestroy($dst);
        return 'thumbs/' . $destName . '.jpg';
    }

    private static function loadImage(string $path, string $mime)
    {
        switch ($mime) {
            case 'image/jpeg': return @imagecreatefromjpeg($path);
            case 'image/png': return @imagecreatefrompng($path);
            case 'image/gif': return @imagecreatefromgif($path);
            case 'image/webp': return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null;
            case 'image/bmp': return function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($path) : null;
        }
        return null;
    }

    private static function renderVideoIcon(string $mime): \GdImage
    {
        $dst = imagecreatetruecolor(320, 320);
        $bg = imagecolorallocate($dst, 30, 41, 59);
        imagefill($dst, 0, 0, $bg);
        $fg = imagecolorallocate($dst, 14, 165, 233);
        $cx = 160; $cy = 160; $r = 60;
        imagefilledellipse($dst, $cx, $cy, $r * 2, $r * 2, $fg);
        $tx = 142; $ty = 120;
        imagefilledpolygon($dst, [$tx, $ty, $tx, $ty + 80, $tx + 60, $ty + 40], $bg);
        return $dst;
    }

    private static function renderTextIcon(string $txt): \GdImage
    {
        $dst = imagecreatetruecolor(320, 320);
        $bg = imagecolorallocate($dst, 30, 41, 59);
        imagefill($dst, 0, 0, $bg);
        $fg = imagecolorallocate($dst, 148, 163, 184);
        $font = 5;
        $w = imagefontwidth($font) * strlen($txt);
        imagestring($dst, $font, (int)((320 - $w) / 2), 150, $txt, $fg);
        return $dst;
    }
}
