<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

final class RagService
{
    public static function ask(int $userId, string $question, int $topK = 8): array
    {
        $question = trim($question);
        if ($question === '') return ['ok' => false, 'error' => 'empty'];
        $sources = self::retrieve($userId, $question, $topK);
        if (empty($sources)) {
            return ['ok' => true, 'answer' => "Non ho trovato elementi rilevanti nella tua libreria per rispondere.", 'sources' => []];
        }
        $context = self::buildContext($sources);
        $prompt = "Rispondi alla domanda dell'utente basandoti SOLO sul contesto fornito. Cita i numeri [1], [2]... per ogni affermazione. Se il contesto non basta, dillo chiaramente. Rispondi in italiano.\n\nContesto:\n" . $context . "\n\nDomanda: " . $question;
        $r = AiProvider::chatComplete($userId, $prompt, ['max_tokens' => 600, 'temperature' => 0.3]);
        if (!$r['ok']) return ['ok' => false, 'error' => $r['error'] ?? 'chat_failed', 'sources' => $sources];
        return ['ok' => true, 'answer' => trim($r['text']), 'sources' => $sources];
    }

    private static function retrieve(int $userId, string $question, int $topK): array
    {
        $bm = [];
        $fts = SearchService::ftsQuery($question);
        if ($fts !== '') {
            $sql = "SELECT i.id, i.original_name, i.mime, i.thumbnail_path, i.ai_description, i.ai_ocr, i.ai_tags, bm25(items_fts) AS score
                    FROM items_fts JOIN items i ON i.id = items_fts.rowid
                    WHERE items_fts MATCH ? AND i.user_id = ? AND i.deleted_at IS NULL
                    ORDER BY score ASC LIMIT 20";
            $rows = Db::all($sql, [$fts, $userId]);
            foreach ($rows as $i => $r) {
                $bm[(int)$r['id']] = ['item' => $r, 'bm' => max(0.0, -((float)$r['score'])), 'rank' => $i + 1];
            }
        }
        $sm = [];
        $emb = AiProvider::embed($userId, $question);
        if ($emb['ok']) {
            $qvec = $emb['vector'];
            $rows = Db::all('SELECT id, original_name, mime, thumbnail_path, ai_description, ai_ocr, ai_tags, ai_embedding FROM items WHERE user_id = ? AND deleted_at IS NULL AND ai_embedding IS NOT NULL', [$userId]);
            $scored = [];
            foreach ($rows as $r) {
                $vec = AiProvider::decodeVector((string)$r['ai_embedding']);
                if (!$vec) continue;
                $scored[] = ['item' => $r, 'sm' => AiProvider::cosine($qvec, $vec)];
            }
            usort($scored, fn($a, $b) => $b['sm'] <=> $a['sm']);
            foreach (array_slice($scored, 0, 20) as $i => $s) {
                $sm[(int)$s['item']['id']] = $s + ['rank' => $i + 1];
            }
        }
        $k = 60;
        $combined = [];
        foreach ($bm as $id => $v) {
            $combined[$id] = $v + ['rrf' => 1.0 / ($k + $v['rank'])];
        }
        foreach ($sm as $id => $v) {
            if (isset($combined[$id])) {
                $combined[$id]['rrf'] += 1.0 / ($k + $v['rank']);
                $combined[$id]['item'] = $v['item'];
                $combined[$id]['sm'] = $v['sm'];
            } else {
                $combined[$id] = $v + ['rrf' => 1.0 / ($k + $v['rank'])];
            }
        }
        uasort($combined, fn($a, $b) => $b['rrf'] <=> $a['rrf']);
        $top = array_slice(array_values($combined), 0, $topK);
        foreach ($top as &$t) {
            $t['item'] = self::publicItem($t['item']);
        }
        unset($t);
        return $top;
    }

    private static function publicItem(array $item): array
    {
        return [
            'id' => (int)$item['id'],
            'original_name' => (string)$item['original_name'],
            'mime' => (string)$item['mime'],
            'thumbnail_path' => (string)($item['thumbnail_path'] ?? ''),
            'ai_description' => mb_substr((string)($item['ai_description'] ?? ''), 0, 300),
            'ai_ocr' => mb_substr((string)($item['ai_ocr'] ?? ''), 0, 1200),
            'ai_tags' => (string)($item['ai_tags'] ?? '[]'),
        ];
    }

    private static function buildContext(array $sources): string
    {
        $lines = [];
        foreach ($sources as $i => $s) {
            $it = $s['item'];
            $parts = [];
            $parts[] = "name=" . ($it['original_name'] ?? '');
            if (!empty($it['ai_description'])) $parts[] = "desc=" . mb_substr((string)$it['ai_description'], 0, 200);
            $tags = json_decode($it['ai_tags'] ?? '[]', true) ?: [];
            if ($tags) $parts[] = "tags=" . implode(',', array_slice($tags, 0, 5));
            if (!empty($it['ai_ocr'])) $parts[] = "text=" . mb_substr((string)$it['ai_ocr'], 0, 400);
            $lines[] = "[" . ($i + 1) . "] " . implode(' | ', $parts);
        }
        return implode("\n", $lines);
    }
}
