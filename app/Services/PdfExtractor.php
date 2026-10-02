<?php
declare(strict_types=1);

namespace App\Services;

final class PdfExtractor
{
    public const MAX_BYTES = 20971520;
    public const MAX_TEXT = 32000;

    public static function extractText(string $absPath): string
    {
        if (!is_file($absPath)) return '';
        $size = (int)filesize($absPath);
        if ($size === 0) return '';
        if ($size > self::MAX_BYTES) return '';
        $raw = @file_get_contents($absPath);
        if ($raw === false || !str_starts_with($raw, '%PDF-')) return '';
        $out = [];
        $meta = self::extractMeta($raw);
        if ($meta !== '') $out[] = $meta;
        foreach (self::contentStreams($raw) as $stream) {
            foreach (self::textBlocks($stream) as $block) {
                $t = self::extractFromBlock($block);
                if (self::isReadable($t)) $out[] = $t;
            }
        }
        if (!empty($out)) return mb_substr(trim(implode("\n\n", $out)), 0, self::MAX_TEXT);
        return '';
    }

    private static function contentStreams(string $raw): array
    {
        $streams = [];
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $m)) {
            foreach ($m[1] as $blob) {
                $decoded = @gzuncompress($blob);
                if ($decoded === false || $decoded === '') $decoded = @gzinflate($blob);
                if ($decoded === false || $decoded === '') $decoded = $blob;
                $streams[] = $decoded;
                if (count($streams) >= 400) break;
            }
        }
        $streams[] = $raw;
        return $streams;
    }

    private static function textBlocks(string $stream): array
    {
        if (!preg_match_all('/BT\b(.*?)\bET/s', $stream, $m)) return [];
        return $m[1];
    }

    private static function isReadable(string $text): bool
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) < 3) return false;
        if (preg_match('/^[\p{L}\p{N}\s.,;:!?"\'()\-%\/@#&+*]{3,}$/u', $text) !== 1) return false;
        if (preg_match('/[\p{L}]/u', $text) !== 1) return false;
        if (self::isStructuralToken($text)) return false;
        $printable = preg_match_all('/[\p{L}\p{N}\s.,;:!?"\'()\-%\/@#&+*]/u', $text);
        return $printable > 0 && ($printable / max(1, mb_strlen($text))) >= 0.8;
    }

    private static function extractFromBlock(string $block): string
    {
        $text = '';
        if (preg_match_all('~\\((?:\\\\[0-7]{1,3}|\\[nrtbf()\\\\]|\\\\[^\\r\\n]|[^()\\\\])*\\)\\s*Tj~s', $block, $m)) {
            foreach ($m[0] as $s) {
                $str = $s;
                $str = preg_replace('/\)\s*Tj$/', '', $str) ?? $str;
                $str = self::unescapePdf(substr($str, 1));
                $text .= $str . ' ';
            }
        }
        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $m2)) {
            foreach ($m2[1] as $arr) {
                if (preg_match_all('~\\((?:\\\\[0-7]{1,3}|\\[nrtbf()\\\\]|\\\\[^\\r\\n]|[^()\\\\])*\\)~s', $arr, $parts)) {
                    foreach ($parts[0] as $p) {
                        $str = substr($p, 1, -1);
                        $text .= self::unescapePdf($str) . ' ';
                    }
                }
            }
        }
        if ($text === '') {
            if (preg_match_all('/\(([^\n\r()]{2,300})\)/', $block, $loose)) {
                foreach ($loose[1] as $chunk) {
                    $t = self::unescapePdf($chunk);
                    if (trim($t) !== '') $text .= $t . ' ';
                }
            }
        }
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\s+([.,;:!?])/', '$1', $text) ?? $text;
        return trim($text);
    }

    private static function unescapePdf(string $s): string
    {
        $s = preg_replace_callback('/\\\\([nrtbf()\\\\])/', static function (array $m): string {
            return in_array($m[1], ['n', 'r', 't', 'b', 'f'], true) ? ' ' : $m[1];
        }, $s) ?? $s;
        $s = preg_replace_callback('/\\\\(\d{1,3})/', static function (array $m): string {
            $c = (int)$m[1];
            if ($c < 32 || $c > 126) return ' ';
            return chr($c);
        }, $s) ?? $s;
        return $s;
    }

    private static function extractMeta(string $raw): string
    {
        $parts = [];
        if (preg_match('/\/Title\s*\((.*?)\)\s*/s', $raw, $m)) $parts[] = 'Title: ' . trim(self::unescapePdf($m[1]));
        if (preg_match('/\/Author\s*\((.*?)\)\s*/s', $raw, $m)) $parts[] = 'Author: ' . trim(self::unescapePdf($m[1]));
        if (preg_match('/\/Subject\s*\((.*?)\)\s*/s', $raw, $m)) $parts[] = 'Subject: ' . trim(self::unescapePdf($m[1]));
        return implode(' | ', $parts);
    }

    public static function pageCount(string $absPath): int
    {
        $raw = @file_get_contents($absPath);
        if ($raw === false) return 0;
        $n = preg_match_all('#/Type\s*/Page(?![A-Za-z])#', $raw);
        return max(0, (int)$n);
    }

    private static function isStructuralToken(string $w): bool
    {
        static $noise = [
            'pdf' => true, 'obj' => true, 'endobj' => true, 'stream' => true, 'endstream' => true,
            'trailer' => true, 'startxref' => true, 'xref' => true, 'root' => true, 'info' => true,
            'type' => true, 'page' => true, 'pages' => true, 'catalog' => true, 'catalogs' => true,
            'mediabox' => true, 'contents' => true, 'resources' => true, 'font' => true, 'kids' => true,
            'count' => true, 'length' => true, 'filter' => true, 'metadata' => true, 'linearized' => true,
            'procset' => true, 'eof' => true, 'null' => true, 'true' => true, 'false' => true,
        ];
        $k = strtolower($w);
        if (isset($noise[$k])) return true;
        if (preg_match('/^[0-9\-]+$/', $k) === 1) return true;
        return preg_match('~^(?=[0-9a-f]{6,}$)(?=.*\d)[0-9a-f]+$~i', $k) === 1;
    }
}
