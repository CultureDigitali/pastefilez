<?php
declare(strict_types=1);

use App\Core\Db;
use App\Services\HttpClient;
use Tests\T;

T::group('http-client/ssrf');

T::throws(fn() => HttpClient::assertSafeUrl('file:///etc/passwd'), 'file:// rejected');
T::throws(fn() => HttpClient::assertSafeUrl('gopher://127.0.0.1:6379/_INFO'), 'gopher:// rejected');
T::throws(fn() => HttpClient::assertSafeUrl('dict://127.0.0.1:11211/stat'), 'dict:// rejected');
T::throws(fn() => HttpClient::assertSafeUrl('ftp://example.com/x'), 'ftp:// rejected');
T::throws(fn() => HttpClient::assertSafeUrl('http://127.0.0.1/x'), 'loopback ipv4 rejected');
T::throws(fn() => HttpClient::assertSafeUrl('http://169.254.169.254/latest/meta-data/'), 'link-local 169.254 rejected');
T::throws(fn() => HttpClient::assertSafeUrl('http://localhost:11434/api/tags'), 'localhost rejected');
T::throws(fn() => HttpClient::assertSafeUrl('http://[::1]:80/'), 'ipv6 loopback rejected');
T::throws(fn() => HttpClient::assertSafeUrl('http://[fd00::1]/'), 'ipv6 unique-local rejected');
T::throws(fn() => HttpClient::assertSafeUrl('http://10.0.0.5/'), '10/8 rejected');
T::throws(fn() => HttpClient::assertSafeUrl('http://192.168.1.1/'), '192.168/16 rejected');
T::throws(fn() => HttpClient::assertSafeUrl('http://172.16.0.1/'), '172.16/12 rejected');
T::ok(!HttpClient::isBlockedIp('172.32.0.1'), '172.32 is outside the private range');
T::ok(!HttpClient::isBlockedIp('172.15.0.1'), '172.15 is outside the private range');
T::throws(fn() => HttpClient::assertSafeUrl('http://metadata.google.internal/'), 'metadata host rejected');
T::throws(fn() => HttpClient::assertSafeUrl('http://0.0.0.0/'), '0.0.0.0 rejected');
T::throws(fn() => HttpClient::assertSafeUrl('http://100.64.0.1/'), 'CGNAT 100.64/10 rejected');
T::throws(fn() => HttpClient::assertSafeUrl('http://192.0.0.1/'), '192.0.0.0/24 rejected');
T::throws(fn() => HttpClient::assertSafeUrl('http://2130706433/'), 'decimal-encoded 127.0.0.1 rejected');
T::throws(fn() => HttpClient::assertSafeUrl('http://[::ffff:127.0.0.1]/'), 'ipv4-mapped loopback rejected');
T::throws(fn() => HttpClient::assertSafeUrl('http://[64:ff9b::7f00:1]/'), 'nat64 loopback rejected');

T::ok(!HttpClient::isBlockedIp('8.8.8.8'), '8.8.8.8 allowed');
T::ok(!HttpClient::isBlockedIp('1.1.1.1'), '1.1.1.1 allowed');
T::ok(!HttpClient::isBlockedIp('2606:4700::1111'), 'public ipv6 allowed');
T::ok(HttpClient::isBlockedIp('224.0.0.1'), 'multicast rejected');
T::ok(HttpClient::isBlockedIp('255.255.255.255'), 'broadcast rejected');
T::ok(HttpClient::isBlockedIp('not-an-ip'), 'garbage rejected');
