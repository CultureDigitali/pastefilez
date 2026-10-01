<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;

final class JwtService
{
    public static function encode(array $payload, int $ttlSec = 900): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload['iat'] = time();
        $payload['exp'] = time() + $ttlSec;
        $segments = [
            self::b64u(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::b64u(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];
        $sig = hash_hmac('sha256', implode('.', $segments), Crypto::masterKey(), true);
        $segments[] = self::b64u($sig);
        return implode('.', $segments);
    }

    public static function decode(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) return null;
        $sig = self::b64uDecode($parts[2]);
        $expected = hash_hmac('sha256', $parts[0] . '.' . $parts[1], Crypto::masterKey(), true);
        if (!hash_equals($expected, $sig)) return null;
        $payload = json_decode(self::b64uDecode($parts[1]), true);
        if (!is_array($payload)) return null;
        if (!isset($payload['exp']) || $payload['exp'] < time()) return null;
        return $payload;
    }

    private static function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function b64uDecode(string $s): string
    {
        $padded = strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4);
        return base64_decode($padded);
    }
}
