<?php
declare(strict_types=1);

namespace App\Core;

final class TOTP
{
    public static function generateSecret(int $length = 20): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $secret;
    }

    public static function code(string $secret, ?int $time = null): string
    {
        $time = $time ?? time();
        $key = self::base32Decode($secret);
        $bin = pack('N*', 0) . pack('N*', intdiv($time, 30));
        $hash = hash_hmac('sha1', $bin, $key, true);
        $offset = ord($hash[19]) & 0xf;
        $code = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % 1000000;
        return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) return false;
        $t = time();
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, $t + $i * 30), $code)) return true;
        }
        return false;
    }

    public static function provisioningUri(string $secret, string $accountName, string $issuer): string
    {
        $label = rawurlencode($issuer . ':' . $accountName);
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ]);
        return 'otpauth://totp/' . $label . '?' . $params;
    }

    private static function base32Decode(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper($b32);
        $buffer = 0; $bits = 0; $output = '';
        for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
            $v = strpos($alphabet, $b32[$i]);
            if ($v === false) continue;
            $buffer = ($buffer << 5) | $v;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 0xff);
            }
        }
        return $output;
    }
}
