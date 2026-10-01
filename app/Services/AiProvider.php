<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Db;
use App\Core\Logger;
use App\Models\Setting;

final class AiProvider
{
    public const BASE_URLS = [
        'openrouter' => 'https://openrouter.ai/api/v1',
        'nvidia' => 'https://integrate.api.nvidia.com/v1',
        'groq' => 'https://api.groq.com/openai/v1',
    ];

    public const DEFAULT_OLLAMA_BASE = 'http://127.0.0.1:11434';

    public static function factory(string $provider, string $key, ?int $userId = null): ?BaseAiProvider
    {
        if ($key === '') return null;
        if ($provider === 'ollama') {
            $base = self::DEFAULT_OLLAMA_BASE;
            if ($userId !== null) {
                $configured = (string)Setting::get($userId, 'ai.ollama_base', '');
                if ($configured !== '') $base = $configured;
            }
            try {
                \App\Services\HttpClient::assertSafeUrl($base, true);
            } catch (\RuntimeException $e) {
                \App\Core\Logger::warning('ai.ollama_base_blocked', ['err' => $e->getMessage()]);
                $base = self::DEFAULT_OLLAMA_BASE;
            }
            return new OllamaClient($key, $base);
        }
        $base = self::BASE_URLS[$provider] ?? null;
        if ($base === null) return null;
        return match ($provider) {
            'openrouter' => new OpenRouterClient($key, $base),
            'nvidia' => new NvidiaClient($key, $base),
            'groq' => new GroqClient($key, $base),
            default => null,
        };
    }

    public static function resolvedProvider(int $userId, string $preferred): string
    {
        if ($preferred !== '' && Db::first('SELECT id FROM api_keys WHERE user_id = ? AND provider = ?', [$userId, $preferred]) !== null) {
            return $preferred;
        }
        $row = Db::first('SELECT provider FROM api_keys WHERE user_id = ? ORDER BY id ASC LIMIT 1', [$userId]);
        return $row ? (string)$row['provider'] : $preferred;
    }

    public static function forUser(int $userId, ?string $provider = null): ?BaseAiProvider
    {
        if ($provider === null) {
            $provider = (string)Setting::get($userId, 'ai.default_provider', '');
        }
        if ($provider === '') $provider = 'openrouter';
        $row = Db::first('SELECT * FROM api_keys WHERE user_id = ? AND provider = ?', [$userId, $provider]);
        if (!$row) {
            $row = Db::first('SELECT * FROM api_keys WHERE user_id = ? ORDER BY id ASC LIMIT 1', [$userId]);
            if (!$row) return null;
            $provider = (string)$row['provider'];
        }
        $key = Crypto::decryptFromBase64((string)$row['encrypted_key']);
        if ($key === null || $key === '') return null;
        return self::factory($provider, $key, $userId);
    }

    public static function defaultModel(int $userId, string $purpose): string
    {
        $key = 'ai.model.' . $purpose;
        $m = (string)Setting::get($userId, $key, '');
        if ($m) return $m;
        $provider = self::resolvedProvider($userId, (string)Setting::get($userId, 'ai.default_provider', 'openrouter'));
        switch ($purpose) {
            case 'vision':
                return match ($provider) {
                    'groq' => 'llama-3.2-11b-vision-preview',
                    'nvidia' => 'nvidia/nvclip',
                    'ollama' => 'llava',
                    default => 'openai/gpt-4o-mini',
                };
            case 'embedding':
                return match ($provider) {
                    'nvidia' => 'nvidia/embed-qa-4',
                    'openrouter' => 'openai/text-embedding-3-small',
                    'ollama' => 'nomic-embed-text',
                    default => '',
                };
            default:
                return match ($provider) {
                    'groq' => 'llama-3.1-70b-versatile',
                    'nvidia' => 'meta/llama-3.1-70b-instruct',
                    'ollama' => 'llama3.2',
                    default => 'openai/gpt-4o-mini',
                };
        }
    }

    public static function chatComplete(int $userId, string $prompt, array $opts = []): array
    {
        $provider = self::forUser($userId);
        if (!$provider) return ['ok' => false, 'error' => 'no_provider'];
        $model = $opts['model'] ?? self::defaultModel($userId, 'chat');
        return $provider->chat([['role' => 'user', 'content' => $prompt]], $model, $opts);
    }

    public static function visionDescribe(int $userId, string $imagePath, string $mime, string $prompt = '', array $opts = []): array
    {
        $provider = self::forUser($userId);
        if (!$provider) return ['ok' => false, 'error' => 'no_provider'];
        $model = $opts['model'] ?? self::defaultModel($userId, 'vision');
        $dataUrl = $provider->fileToDataUrl($imagePath, $mime);
        if ($dataUrl === '') return ['ok' => false, 'error' => 'image_unreadable'];
        if ($prompt === '') $prompt = 'Describe in 2-3 sentences what is in this image. Also list 5 short tags. Return JSON: {"description":"...", "tags":["t1","t2","t3","t4","t5"], "ocr":"text if any"}';
        $r = $provider->vision($model, $prompt, $dataUrl, $opts);
        if (!$r['ok']) {
            Logger::warning('vision.fail', ['err' => $r['error'] ?? '?', 'model' => $model]);
            return $r;
        }
        return $r;
    }

    public static function embed(int $userId, string $text): array
    {
        $provider = self::forUser($userId);
        if (!$provider) return ['ok' => false, 'error' => 'no_provider'];
        $model = self::defaultModel($userId, 'embedding');
        if ($model === '') return ['ok' => false, 'error' => 'no_embedding_model'];
        $r = $provider->embedding($model, $text);
        if (!$r['ok']) return $r;
        $vec = $r['vector'] ?? null;
        if (!is_array($vec) || count($vec) === 0) return ['ok' => false, 'error' => 'empty_embedding'];
        return ['ok' => true, 'vector' => self::normalize($vec), 'dim' => count($vec)];
    }

    public static function normalize(array $vec): array
    {
        $sum = 0.0;
        foreach ($vec as $v) $sum += $v * $v;
        $norm = sqrt($sum);
        if ($norm == 0) return $vec;
        $out = [];
        foreach ($vec as $v) $out[] = $v / $norm;
        return $out;
    }

    public static function encodeVector(array $vec): string
    {
        $buf = '';
        foreach ($vec as $v) $buf .= pack('g', (float)$v);
        return $buf;
    }

    public static function decodeVector(string $blob): array
    {
        $len = strlen($blob);
        $size = 4;
        if ($len % $size !== 0) return [];
        $n = intdiv($len, $size);
        $vec = [];
        for ($i = 0; $i < $n; $i++) {
            $u = unpack('g', substr($blob, $i * $size, $size));
            $vec[] = $u[1];
        }
        return $vec;
    }

    public static function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        $dot = 0.0;
        for ($i = 0; $i < $n; $i++) $dot += $a[$i] * $b[$i];
        return $dot;
    }
}
