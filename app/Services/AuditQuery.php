<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

final class AuditQuery
{
    public const PER_PAGE = 50;
    public const MAX_PER_PAGE = 200;

    private static function scalar(mixed $v): string
    {
        if (is_string($v)) return trim($v);
        if (is_int($v) || is_float($v)) return trim((string)$v);
        return '';
    }

    private static function notScalar(mixed $v): bool
    {
        return is_array($v) || is_object($v);
    }

    public static function validate(array $filters): array
    {
        $errors = [];
        foreach (['action', 'email', 'target', 'ip', 'from', 'to'] as $k) {
            if (self::notScalar($filters[$k] ?? null)) $errors[] = 'invalid_' . $k;
        }
        $action = self::scalar($filters['action'] ?? '');
        if ($action !== '' && preg_match('/^[a-z0-9_.]{1,64}$/i', $action) !== 1) {
            $errors[] = 'invalid_action';
        }
        $email = self::scalar($filters['email'] ?? '');
        if ($email !== '' && (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors[] = 'invalid_email';
        }
        $target = self::scalar($filters['target'] ?? '');
        if ($target !== '' && mb_strlen($target) > 100) {
            $errors[] = 'invalid_target';
        }
        $ip = self::scalar($filters['ip'] ?? '');
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $errors[] = 'invalid_ip';
        }
        foreach (['from', 'to'] as $k) {
            $v = $filters[$k] ?? 0;
            if ($v !== '' && $v !== 0 && $v !== null && (!is_numeric($v) || (int)$v < 0)) {
                $errors[] = 'invalid_' . $k;
            }
        }
        if ($errors === []) {
            $from = (int)($filters['from'] ?? 0);
            $to = (int)($filters['to'] ?? 0);
            if ($from > 0 && $to > 0 && $from > $to) $errors[] = 'invalid_range';
        }
        return $errors;
    }

    public static function build(array $filters): array
    {
        $where = [];
        $params = [];

        $action = trim((string)($filters['action'] ?? ''));
        if ($action !== '' && preg_match('/^[a-z0-9_.]{1,64}$/i', $action) === 1) {
            $where[] = "a.action LIKE ? ESCAPE '\\'";
            $params[] = self::escapeLike($action) . '%';
        }
        $email = trim((string)($filters['email'] ?? ''));
        if ($email !== '' && mb_strlen($email) <= 190) {
            $where[] = 'u.email = ?';
            $params[] = strtolower($email);
        }
        $target = trim((string)($filters['target'] ?? ''));
        if ($target !== '' && mb_strlen($target) <= 100) {
            $where[] = "a.target LIKE ? ESCAPE '\\'";
            $params[] = '%' . self::escapeLike($target) . '%';
        }
        $ip = trim((string)($filters['ip'] ?? ''));
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $where[] = 'a.ip = ?';
            $params[] = $ip;
        }
        $from = (int)($filters['from'] ?? 0);
        if ($from > 0) {
            $where[] = 'a.created_at >= ?';
            $params[] = $from;
        }
        $to = (int)($filters['to'] ?? 0);
        if ($to > 0) {
            $where[] = 'a.created_at <= ?';
            $params[] = $to;
        }

        $sql = 'FROM audit_log a LEFT JOIN users u ON u.id = a.user_id';
        if (!empty($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
        return [$sql, $params];
    }

    public static function page(array $filters, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        [$from, $params] = self::build($filters);
        $total = (int)Db::value('SELECT COUNT(*) ' . $from, $params);
        $pages = max(1, (int)ceil($total / $perPage));
        if ($page > $pages) $page = $pages;
        $rows = Db::all(
            'SELECT a.id, a.user_id, a.action, a.target, a.ip, a.ua, a.meta, a.created_at, u.email '
            . $from . ' ORDER BY a.id DESC LIMIT ? OFFSET ?',
            array_merge($params, [$perPage, ($page - 1) * $perPage])
        );
        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'filters' => self::sanitize($filters),
        ];
    }

    public static function export(array $filters, int $limit = 5000): array
    {
        [$from, $params] = self::build($filters);
        $limit = max(1, min(50000, $limit));
        return Db::all(
            'SELECT a.id, a.user_id, a.action, a.target, a.ip, a.ua, a.meta, a.created_at, u.email '
            . $from . ' ORDER BY a.id DESC LIMIT ' . (int)$limit,
            $params
        );
    }

    public static function toCsv(array $rows): string
    {
        $out = "id,created_at,user_email,action,target,ip,user_agent,meta\n";
        foreach ($rows as $r) {
            $out .= self::cell($r['id']) . ','
                . self::cell(date('c', (int)$r['created_at'])) . ','
                . self::cell($r['email'] ?? '') . ','
                . self::cell($r['action'] ?? '') . ','
                . self::cell($r['target'] ?? '') . ','
                . self::cell($r['ip'] ?? '') . ','
                . self::cell($r['ua'] ?? '') . ','
                . self::cell($r['meta'] ?? '') . "\n";
        }
        return $out;
    }

    public static function distinctActions(): array
    {
        return array_map(
            static fn(array $r): string => (string)$r['action'],
            Db::all('SELECT DISTINCT action FROM audit_log ORDER BY action ASC')
        );
    }

    public static function sanitize(array $filters): array
    {
        $out = [];
        foreach (['action', 'email', 'target', 'ip'] as $k) {
            $v = trim((string)($filters[$k] ?? ''));
            if ($v !== '') $out[$k] = mb_substr($v, 0, 190);
        }
        foreach (['from', 'to'] as $k) {
            $v = (int)($filters[$k] ?? 0);
            if ($v > 0) $out[$k] = $v;
        }
        return $out;
    }

    public static function escapeLike(string $v): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);
    }

    public static function cell(mixed $v): string
    {
        $s = (string)$v;
        if (preg_match('/^[=+\-@\t\r]/', $s) === 1) $s = "'" . $s;
        if (preg_match('/[",\r\n]/', $s) === 1) {
            return '"' . str_replace('"', '""', $s) . '"';
        }
        return $s;
    }
}
