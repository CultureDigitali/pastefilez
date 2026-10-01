<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;
use App\Core\Logger;
use App\Models\Item;

final class AnalysisService
{
    public function run(int $userId, int $itemId): void
    {
        $item = Db::first('SELECT * FROM items WHERE id = ? AND user_id = ?', [$itemId, $userId]);
        if (!$item) return;
        Db::update('items', ['ai_status' => 'processing', 'updated_at' => time()], 'id = ?', [$itemId]);
        $storage = (string)Config::get('storage_path');
        $abs = $storage . '/' . $item['storage_path'];
        if (!is_file($abs)) {
            Db::update('items', ['ai_status' => 'failed', 'ai_error' => 'file_missing', 'updated_at' => time()], 'id = ?', [$itemId]);
            return;
        }
        $thumbPath = null;
        if (!$item['thumbnail_path']) {
            $thumbName = bin2hex(random_bytes(8));
            $thumbPath = ThumbnailService::make($abs, $item['mime'], $thumbName);
            if ($thumbPath) {
                $info = @getimagesize($abs);
                $w = $info ? $info[0] : null;
                $h = $info ? $info[1] : null;
                Db::update('items', [
                    'thumbnail_path' => $thumbPath,
                    'width' => $w,
                    'height' => $h,
                    'updated_at' => time(),
                ], 'id = ?', [$itemId]);
            }
        }
        $description = ''; $tags = []; $ocr = '';
        if (str_starts_with($item['mime'], 'image/') || str_starts_with($item['mime'], 'video/')) {
            $imgForVision = $abs;
            $mimeForVision = $item['mime'];
            if (str_starts_with($item['mime'], 'video/')) {
                $imgForVision = $storage . '/' . ($item['thumbnail_path'] ?: '');
                $mimeForVision = 'image/jpeg';
                if (!is_file($imgForVision)) {
                    $imgForVision = $abs;
                    $mimeForVision = $item['mime'];
                }
            }
            $r = AiProvider::visionDescribe($userId, $imgForVision, $mimeForVision);
            if ($r['ok']) {
                $parsed = self::parseVisionJson($r['text']);
                $description = $parsed['description'] ?? trim($r['text']);
                $tags = $parsed['tags'] ?? [];
                $ocr = $parsed['ocr'] ?? '';
            } else {
                Logger::warning('vision.skip', ['err' => $r['error'] ?? '?']);
            }
        } elseif (str_starts_with($item['mime'], 'text/') || in_array($item['mime'], ['application/json','application/xml'], true)) {
            $text = StorageService::read($item['storage_path'], (bool)$item['is_encrypted']);
            if ($text !== null) {
                $ocr = mb_substr($text, 0, 4000);
                $description = 'Text file: ' . basename($item['original_name']);
                $r = AiProvider::chatComplete($userId, "Summarize in one sentence the following content. Respond with a single short sentence only.\n\n" . $ocr, ['max_tokens' => 120]);
                if ($r['ok']) $description = $r['text'];
            }
        } elseif ($item['mime'] === 'application/pdf') {
            $storage = (string)\App\Core\Config::get('storage_path');
            $absPdf = $storage . '/' . $item['storage_path'];
            $pdfText = PdfExtractor::extractText($absPdf);
            $pages = PdfExtractor::pageCount($absPdf);
            if ($pdfText !== '') {
                $ocr = mb_substr($pdfText, 0, 8000);
                $description = $pages > 0 ? "PDF ({$pages} pagine)" : 'PDF';
                $prompt = "Riassumi in una frase il seguente estratto di un PDF. Rispondi in italiano con una sola frase breve.\n\n" . $ocr;
                $r = AiProvider::chatComplete($userId, $prompt, ['max_tokens' => 160]);
                if ($r['ok']) $description = $r['text'];
                if (preg_match('/Title:\s*([^|]+)/', $pdfText, $m)) {
                    $tags[] = trim($m[1]);
                }
            } else {
                $description = 'PDF (testo non estraibile)';
            }
        } else {
            $description = basename($item['original_name']);
        }
        $tagsStr = json_encode(array_slice(array_values(array_unique(array_filter($tags, fn($x) => is_string($x) && $x !== ''))), 0, 10), JSON_UNESCAPED_UNICODE);
        Db::update('items', [
            'ai_status' => 'done',
            'ai_description' => mb_substr((string)$description, 0, 1000),
            'ai_tags' => $tagsStr,
            'ai_ocr' => mb_substr((string)$ocr, 0, 8000),
            'ai_analyzed_at' => time(),
            'ai_error' => null,
            'updated_at' => time(),
        ], 'id = ?', [$itemId]);
        JobService::enqueue('embedding', $userId, ['item_id' => $itemId], 1);
    }

    public function embed(int $userId, int $itemId): void
    {
        $item = Db::first('SELECT * FROM items WHERE id = ? AND user_id = ?', [$itemId, $userId]);
        if (!$item) return;
        $text = trim(implode(' ', array_filter([
            $item['ai_description'] ?? '',
            $item['ai_ocr'] ?? '',
            $item['original_name'] ?? '',
        ])));
        if ($text === '') return;
        $r = AiProvider::embed($userId, $text);
        if (!$r['ok']) {
            Logger::warning('embed.fail', ['err' => $r['error'] ?? '?']);
            return;
        }
        $blob = AiProvider::encodeVector($r['vector']);
        $provider = \App\Models\Setting::get($userId, 'ai.default_provider', 'openrouter');
        $model = AiProvider::defaultModel($userId, 'embedding');
        Db::update('items', [
            'ai_embedding' => $blob,
            'ai_provider' => $provider,
            'ai_model' => $model,
            'updated_at' => time(),
        ], 'id = ?', [$itemId]);
    }

    private static function parseVisionJson(string $text): array
    {
        if (preg_match('/\{[\s\S]*\}/m', $text, $m)) {
            $j = json_decode($m[0], true);
            if (is_array($j)) return $j;
        }
        return [];
    }
}
