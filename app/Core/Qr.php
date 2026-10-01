<?php
declare(strict_types=1);

namespace App\Core;

final class Qr
{
    public static function jsRenderTarget(string $text): string
    {
        return 'data:text/plain;base64,' . base64_encode($text);
    }
}
