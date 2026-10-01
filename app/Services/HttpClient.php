<?php
declare(strict_types=1);

namespace App\Services;

final class HttpClient
{
    public const ALLOWED_SCHEMES = ['http', 'https'];

    public static function isBlockedHost(string $host, bool $allowLoopback = false, bool $allowPrivate = false): bool
    {
        $host = strtolower(trim($host));
        if (strlen($host) > 2 && $host[0] === '[' && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        $host = rtrim($host, '.');
        if ($host === '') return true;
        $loopbackName = $host === 'localhost' || str_ends_with($host, '.localhost');
        if ($loopbackName) return !$allowLoopback;
        if ($host === 'metadata.google.internal' || str_ends_with($host, '.internal')) return true;
        if (filter_var($host, FILTER_VALIDATE_IP)) return self::isBlockedIp($host, $allowLoopback, $allowPrivate);
        $ips = self::resolve($host);
        if ($ips === []) return true;
        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip, $allowLoopback, $allowPrivate)) return true;
        }
        return false;
    }

    public static function resolve(string $host): array
    {
        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) foreach ($v4 as $ip) if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $ips[] = $ip;
        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $r) {
                if (!empty($r['ipv6']) && filter_var((string)$r['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) $ips[] = (string)$r['ipv6'];
            }
        }
        return array_values(array_unique($ips));
    }

    public static function isBlockedIp(string $ip, bool $allowLoopback = false, bool $allowPrivate = false): bool
    {
        $ip = trim($ip);
        if (strlen($ip) > 2 && $ip[0] === '[' && str_ends_with($ip, ']')) $ip = substr($ip, 1, -1);
        if ($ip === '') return true;
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return true;
        $packed = @inet_pton($ip);
        if ($packed === false) return true;
        if (strlen($packed) === 4) {
            $b = array_values(unpack('C4', $packed));
            if ($b[0] === 127) return !$allowLoopback;
            if ($allowLoopback) {
                if ($b[0] === 0 || $b[0] === 10 || $b[0] >= 224) return true;
                if ($b[0] === 169 && $b[1] === 254) return true;
                if ($b[0] === 172 && $b[1] >= 16 && $b[1] <= 31) return true;
                if ($b[0] === 192 && $b[1] === 168) return true;
                if ($b[0] === 100 && $b[1] >= 64 && $b[1] <= 127) return true;
                if ($b[0] === 192 && $b[1] === 0) return true;
                return false;
            }
            if ($b[0] === 0 || $b[0] === 10 || $b[0] === 127) return true;
            if ($b[0] === 169 && $b[1] === 254) return true;
            if ($b[0] === 172 && $b[1] >= 16 && $b[1] <= 31) return true;
            if ($b[0] === 192 && $b[1] === 168) return true;
            if ($b[0] === 100 && $b[1] >= 64 && $b[1] <= 127) return true;
            if ($b[0] === 192 && $b[1] === 0) return true;
            if ($b[0] >= 224) return true;
            return false;
        }
        $b = array_values(unpack('C16', $packed));
        if ($b[0] === 0xFF) return true;
        if ($b === array_fill(0, 16, 0)) return true;
        if ($b[0] === 0 && array_sum(array_slice($b, 0, 14)) === 0 && $b[14] === 0 && $b[15] === 1) return !$allowLoopback;
        if (str_starts_with($ip, '::ffff:')) {
            $v4 = substr($ip, 7);
            if (filter_var($v4, FILTER_VALIDATE_IP)) return self::isBlockedIp($v4, $allowLoopback);
        }
        if ($b[0] === 0x20 && $b[1] === 0x01 && $b[2] === 0x0D && $b[3] === 0xB8) return true;
        if (($b[0] & 0xFE) === 0xFC) return true;
        if ($b[0] === 0xFE && ($b[1] & 0xC0) === 0x80) return true;
        $zeroPrefix = array_slice($b, 0, 10) === array_fill(0, 10, 0);
        if ($zeroPrefix && $b[10] === 0xFF && $b[11] === 0xFF) {
            return self::isBlockedIp(implode('.', array_slice($b, 12, 4)), false);
        }
        if ($b[0] === 0x00 && $b[1] === 0x64 && $b[2] === 0xFF && $b[3] === 0x9B && array_sum(array_slice($b, 4, 8)) === 0) {
            return self::isBlockedIp(implode('.', array_slice($b, 12, 4)), false);
        }
        return false;
    }

    public static function assertSafeUrl(string $url, bool $allowLoopback = false): void
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new \RuntimeException('scheme_not_allowed');
        }
        $host = (string)parse_url($url, PHP_URL_HOST);
        if ($host === '') throw new \RuntimeException('host_missing');
        if (self::isBlockedHost($host, $allowLoopback)) throw new \RuntimeException('host_blocked');
    }

    public static function request(string $method, string $url, array $options = []): array
    {
        $allowLoopback = (bool)($options['allow_loopback'] ?? false);
        self::assertSafeUrl($url, $allowLoopback);
        $timeout = (int)($options['timeout'] ?? 20);
        $follow = (bool)($options['follow'] ?? false);
        $maxRedirects = $follow ? min((int)($options['max_redirects'] ?? 3), 5) : 0;
        $maxBytes = (int)($options['max_bytes'] ?? 2097152);
        $method = strtoupper($method);
        $redirects = 0;

        while (true) {
            $res = self::hop($method, $url, $options, $timeout, $maxBytes);
            $loc = $res['location'];
            if ($loc === null || !in_array($res['status'], [301, 302, 303, 307, 308], true)) return $res;
            if ($redirects >= $maxRedirects) {
                $res['ok'] = false;
                $res['error'] = 'too_many_redirects';
                return $res;
            }
            $next = self::absolutize($url, $loc);
            try {
                self::assertSafeUrl($next, $allowLoopback);
            } catch (\RuntimeException $e) {
                $res['ok'] = false;
                $res['error'] = 'redirect_blocked: ' . $e->getMessage();
                return $res;
            }
            if ($res['status'] === 303 || (in_array($res['status'], [301, 302], true) && $method === 'POST')) $method = 'GET';
            $url = $next;
            $redirects++;
        }
    }

    private static function hop(string $method, string $url, array $options, int $timeout, int $maxBytes): array
    {
        $headers = [];
        foreach (($options['headers'] ?? []) as $k => $v) $headers[] = $k . ': ' . $v;
        $ch = curl_init($url);
        $curl = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'PasteFilez/1.0',
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            $curl[CURLOPT_POST] = true;
            if (isset($options['body'])) $curl[CURLOPT_POSTFIELDS] = (string)$options['body'];
            elseif (isset($options['json'])) $curl[CURLOPT_POSTFIELDS] = json_encode($options['json']);
        } elseif ($method !== 'GET' && $method !== 'HEAD') {
            $curl[CURLOPT_CUSTOMREQUEST] = $method;
            if (isset($options['body'])) $curl[CURLOPT_POSTFIELDS] = (string)$options['body'];
        }
        if (!empty($options['userpwd'])) $curl[CURLOPT_USERPWD] = (string)$options['userpwd'];
        $cap = $maxBytes > 0 ? $maxBytes : PHP_INT_MAX;
        $truncated = false;
        $curl[CURLOPT_WRITEFUNCTION] = function ($ch, string $chunk) use ($cap, &$truncated): int {
            $len = strlen($chunk);
            $room = $cap - (int)curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
            if ($len > $room) {
                $truncated = true;
                $chunk = substr($chunk, 0, max(0, $room));
                if ($chunk === '') return 0;
            }
            echo $chunk;
            return strlen($chunk);
        };
        $curl[CURLOPT_HEADERFUNCTION] = function ($ch, string $line) use (&$location): int {
            if (preg_match('/^location:\s*(.+)$/i', $line, $m)) $location = trim($m[1]);
            return strlen($line);
        };
        $location = null;
        curl_setopt_array($ch, $curl);
        ob_start();
        $fail = curl_exec($ch);
        $body = (string)ob_get_clean();
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $effUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        if ($truncated) {
            $err = 'body_truncated';
            $fail = false;
        }
        if ($fail === false && $err !== '') {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $err, 'content_type' => '', 'effective_url' => $effUrl, 'location' => null, 'truncated' => $truncated];
        }
        return [
            'ok' => $status >= 200 && $status < 400,
            'status' => $status,
            'body' => $body,
            'error' => '',
            'content_type' => $ctype,
            'effective_url' => $effUrl,
            'location' => $location,
            'truncated' => $truncated,
        ];
    }

    private static function absolutize(string $base, string $loc): string
    {
        if (preg_match('#^https?://#i', $loc)) return $loc;
        $p = parse_url($base);
        $scheme = (string)($p['scheme'] ?? 'http');
        $host = (string)($p['host'] ?? '');
        $port = isset($p['port']) ? ':' . $p['port'] : '';
        if (str_starts_with($loc, '//')) return $scheme . ':' . $loc;
        $root = $scheme . '://' . $host . $port;
        if (str_starts_with($loc, '/')) return $root . $loc;
        $dir = rtrim(dirname((string)($p['path'] ?? '/')), '/');
        return $root . $dir . '/' . $loc;
    }
}
