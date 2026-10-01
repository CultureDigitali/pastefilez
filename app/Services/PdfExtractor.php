<?php
declare(strict_types=1);

namespace App\Services;

final class PdfExtractor
{
    public static function extractText(string $absPath): string
    {
        if (!is_file($absPath) || filesize($absPath) === 0) return '';
        $raw = file_get_contents($absPath);
        if ($raw === false) return '';
        if (strpos($raw, '%PDF-') !== 0) return '';
        $out = [];
        $meta = self::extractMeta($raw);
        if (!empty($meta)) $out[] = $meta;
        if (preg_match_all('/BT\b(.*?)ET\b/s', $raw, $matches)) {
            foreach ($matches[1] as $block) {
                $t = self::extractFromBlock($block);
                if ($t !== '') $out[] = $t;
            }
        }
        if (empty($out)) {
            $words = [];
            $ascii = preg_match_all('/[A-Za-z]{3,}/', $raw, $wm);
            if ($ascii === false || !is_array($wm) || empty($wm[0])) {
                $wm = [[]];
                preg_match_all('/[\x80-\xFF]{3,}/', $raw, $wm);
            }
            if (is_array($wm) && !empty($wm[0])) {
                foreach ($wm[0] as $w) {
                    if (self::isStructuralToken($w)) continue;
                    $words[] = $w;
                    if (count($words) >= 500) break;
                }
            }
            if (!empty($words)) $out[] = implode(' ', $words);
        }
        return mb_substr(trim(implode("\n\n", $out)), 0, 32000);
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
