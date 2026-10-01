<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Models\Setting;

final class SearchService
{
    public static function boundQuery(mixed $raw): string
    {
        if (!is_string($raw)) return '';
        $q = trim($raw);
        if (strlen($q) > self::QUERY_MAX_BYTES) $q = substr($q, 0, self::QUERY_MAX_BYTES);
        return trim($q);
    }

    public function query(int $userId, string $q, array $filters = []): array
    {
        $q = self::boundQuery($q);
        if ($q === '') return [];
        $intent = self::extractIntent($userId, $q);
        $typeFilter = $filters['type'] ?? ($intent['type'] ?? null);
        $tagsFilter = $filters['tags'] ?? ($intent['tags'] ?? []);
        $dateFrom = $filters['from'] ?? null;
        $dateTo = $filters['to'] ?? null;
        $bm = self::bm25($userId, $q, $typeFilter, $tagsFilter, $dateFrom, $dateTo);
        $sm = self::semantic($userId, $intent['rewritten'] ?? $q, $typeFilter, $tagsFilter, $dateFrom, $dateTo, 30);
        $combined = self::rrf($bm, $sm, 60);
        usort($combined, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($combined, 0, 50);
    }

    private function extractIntent(int $userId, string $q): array
    {
        $qLower = strtolower($q);
        $type = null;
        if (preg_match('/\b(screenshot|screencap|schermata|screen)\b/u', $qLower)) $type = 'image';
        elseif (preg_match('/\b(video|filmato|registrazione)\b/u', $qLower)) $type = 'video';
        elseif (preg_match('/\b(immagine|foto|photo|image)\b/u', $qLower)) $type = 'image';
        elseif (preg_match('/\b(pdf|documento|doc)\b/u', $qLower)) $type = 'pdf';
        elseif (preg_match('/\b(audio|registrazione vocale|voice)\b/u', $qLower)) $type = 'audio';
        $rewritten = $q;
        return ['rewritten' => $rewritten, 'type' => $type, 'tags' => []];
    }

    private function bm25(int $userId, string $q, ?string $type, array $tags, ?int $from, ?int $to): array
    {
        $fts = self::ftsQuery($q);
        if ($fts === '') return [];
        $params = [$fts, $userId];
        $sql = "SELECT i.id, i.original_name, i.mime, i.thumbnail_path, i.ai_description, i.ai_tags, i.created_at, i.share_token, i.is_public, i.share_password_hash, bm25(items_fts) AS score
                FROM items_fts
                JOIN items i ON i.id = items_fts.rowid
                WHERE items_fts MATCH ? AND i.user_id = ? AND i.deleted_at IS NULL";
        if ($type) { $sql .= ' AND i.mime LIKE ?'; $params[] = self::typeToMime($type) . '%'; }
        if ($from) { $sql .= ' AND i.created_at >= ?'; $params[] = $from; }
        if ($to) { $sql .= ' AND i.created_at <= ?'; $params[] = $to; }
        $sql .= ' ORDER BY score ASC LIMIT 50';
        $rows = Db::all($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['id']] = [
                'item' => $r,
                'bm' => max(0.0, -((float)$r['score'])),
            ];
        }
        if ($tags) {
            foreach ($tags as $tg) {
                $rows2 = Db::all('SELECT id, original_name, mime, thumbnail_path, ai_description, ai_tags, created_at, share_token, is_public, share_password_hash FROM items WHERE user_id = ? AND deleted_at IS NULL AND (ai_tags LIKE ? OR ai_description LIKE ? OR original_name LIKE ?)',
                    [$userId, '%' . $tg . '%', '%' . $tg . '%', '%' . $tg . '%']);
                foreach ($rows2 as $r) {
                    $id = (int)$r['id'];
                    if (isset($out[$id])) $out[$id]['bm'] += 1.5;
                    else $out[$id] = ['item' => $r, 'bm' => 1.0];
                }
            }
        }
        return $out;
    }

    private function semantic(int $userId, string $q, ?string $type, array $tags, ?int $from, ?int $to, int $limit): array
    {
        $r = AiProvider::embed($userId, $q);
        if (!$r['ok']) return [];
        $vec = $r['vector'];
        $sql = 'SELECT id, original_name, mime, thumbnail_path, ai_description, ai_tags, created_at, share_token, is_public, share_password_hash, ai_embedding FROM items WHERE user_id = ? AND deleted_at IS NULL AND ai_embedding IS NOT NULL';
        $params = [$userId];
        if ($type) { $sql .= ' AND mime LIKE ?'; $params[] = self::typeToMime($type) . '%'; }
        if ($from) { $sql .= ' AND created_at >= ?'; $params[] = $from; }
        if ($to) { $sql .= ' AND created_at <= ?'; $params[] = $to; }
        $rows = Db::all($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $v = AiProvider::decodeVector((string)$r['ai_embedding']);
            if (!$v) continue;
            $score = AiProvider::cosine($vec, $v);
            $out[(int)$r['id']] = ['item' => $r, 'sm' => $score];
        }
        uasort($out, fn($a, $b) => $b['sm'] <=> $a['sm']);
        return array_slice($out, 0, $limit, true);
    }

    private function rrf(array $bm, array $sm, int $k = 60): array
    {
        $combined = [];
        $i = 0;
        foreach ($bm as $id => $v) {
            $combined[$id] = $v + ['rrf' => 1.0 / ($k + $i)];
            $i++;
        }
        $i = 0;
        foreach ($sm as $id => $v) {
            if (isset($combined[$id])) $combined[$id]['rrf'] += 1.0 / ($k + $i);
            else $combined[$id] = $v + ['rrf' => 1.0 / ($k + $i)];
            $i++;
        }
        $out = [];
        foreach ($combined as $id => $v) {
            $out[] = [
                'id' => $id,
                'item' => $v['item'],
                'score' => (float)$v['rrf'],
            ];
        }
        return $out;
    }

    public const FTS_MAX_TERMS = 8;
    public const QUERY_MAX_BYTES = 512;

    public static function ftsQuery(string $q): string
    {
        if (strlen($q) > self::QUERY_MAX_BYTES) $q = substr($q, 0, self::QUERY_MAX_BYTES);
        $tokens = preg_split('/\s+/u', trim($q));
        if (!is_array($tokens)) return '';
        $clean = [];
        foreach ($tokens as $t) {
            if (count($clean) >= self::FTS_MAX_TERMS) break;
            $t = preg_replace('/[^\p{L}\p{N}\-]/u', '', $t);
            if ($t === null || mb_strlen($t) < 2) continue;
            $term = '"' . $t . '"*';
            if (in_array($term, $clean, true)) continue;
            $clean[] = $term;
        }
        return implode(' ', $clean);
    }

    public static function typeToMime(string $type): string
    {
        return match ($type) {
            'image' => 'image/',
            'video' => 'video/',
            'audio' => 'audio/',
            'pdf' => 'application/pdf',
            'text' => 'text/',
            default => '',
        };
    }
}
