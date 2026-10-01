<?php
declare(strict_types=1);

namespace App\Core;

final class Mailer
{
    public static function send(string $to, string $subject, string $body): bool
    {
        $cfg = (array)Config::get('smtp');
        if (!($cfg['enabled'] ?? false)) {
            Logger::info('mail.disabled', ['to' => $to, 'subject' => $subject]);
            return true;
        }
        if (!function_exists('stream_socket_client')) {
            return false;
        }
        $host = (string)($cfg['host'] ?? '');
        $port = (int)($cfg['port'] ?? 587);
        $user = (string)($cfg['user'] ?? '');
        $pass = (string)($cfg['pass'] ?? '');
        $enc = strtolower((string)($cfg['encryption'] ?? 'tls'));
        $from = (string)($cfg['from'] ?? $user);
        $fromName = (string)($cfg['from_name'] ?? 'PasteFilez');
        if (!self::hostIsRoutable($host)) {
            Logger::error('mail.host_blocked', ['host' => $host]);
            return false;
        }
        if (!self::headerSafe($fromName) || !self::addrSafe($from) || !self::addrSafe($to)) {
            Logger::error('mail.header_injection_blocked', ['to' => $to]);
            return false;
        }
        $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, 10);
        if (!$fp) {
            Logger::error('mail.connect.fail', ['err' => $errstr, 'host' => $host, 'port' => $port]);
            return false;
        }
        stream_set_timeout($fp, 10);
        $read = function () use ($fp) { $line = ''; $buf = ''; while (!feof($fp)) { $line = fgets($fp, 1024); $buf .= $line; if (isset($line[3]) && $line[3] === ' ') break; } return $buf; };
        $send = function (string $s) use ($fp) { fwrite($fp, $s . "\r\n"); };
        try {
            $read();
            $send('EHLO localhost');
            $read();
            if ($enc === 'tls') {
                $send('STARTTLS');
                $read();
                stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $send('EHLO localhost');
                $read();
            }
            $send('AUTH LOGIN');
            $read();
            $send(base64_encode($user));
            $read();
            $send(base64_encode($pass));
            $read();
            $send('MAIL FROM:<' . $from . '>');
            $read();
            $send('RCPT TO:<' . $to . '>');
            $read();
            $send('DATA');
            $read();
            $headers = [
                'From: ' . sprintf('%s <%s>', $fromName, $from),
                'To: ' . $to,
                'Subject: ' . '=?UTF-8?B?' . base64_encode($subject) . '?=',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Date: ' . date('r'),
                'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '>',
            ];
            $send(implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.");
            $read();
            $send('QUIT');
            fclose($fp);
            Logger::info('mail.sent', ['to' => $to, 'subject' => $subject]);
            return true;
        } catch (\Throwable $e) {
            Logger::error('mail.send.fail', ['err' => $e->getMessage()]);
            @fclose($fp);
            return false;
        }
    }

    public static function hostIsRoutable(string $host): bool
    {
        $host = trim($host);
        if ($host === '') return false;
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return !\App\Services\HttpClient::isBlockedIp($host);
        }
        return !\App\Services\HttpClient::isBlockedHost($host);
    }

    public static function headerSafe(string $v): bool
    {
        return !preg_match('/[\r\n\x00]/', $v);
    }

    public static function addrSafe(string $v): bool
    {
        return (bool)filter_var($v, FILTER_VALIDATE_EMAIL);
    }
}
