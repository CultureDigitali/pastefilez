<?php
declare(strict_types=1);

namespace App\Services;

final class OllamaClient extends BaseAiProvider
{
    public function name(): string { return 'ollama'; }

    public function listModels(): array
    {
        try {
            return $this->parseModels($this->http('GET', '/api/tags'));
        } catch (\Throwable) {
            return [];
        }
    }

    private function parseModels(array $r): array
    {
        if (!$r['ok'] || !is_array($r['data']['models'] ?? null)) return [];
        $out = [];
        foreach ($r['data']['models'] as $m) {
            $name = isset($m['name']) && is_scalar($m['name']) ? (string)$m['name'] : (isset($m['model']) && is_scalar($m['model']) ? (string)$m['model'] : null);
            if ($name === null || $name === '') continue;
            $lc = strtolower($name);
            $out[] = [
                'id' => $name,
                'name' => $m['name'] ?? $name,
                'vision' => str_contains($lc, 'vision') || str_contains($lc, 'vl') || str_contains($lc, 'llava') || str_contains($lc, 'bakllava') || str_contains($lc, 'minicpm-v'),
                'embedding' => str_contains($lc, 'embed') || str_contains($lc, 'nomic-embed') || str_contains($lc, 'mxbai-embed') || str_contains($lc, 'all-minilm') || str_contains($lc, 'bge-'),
                'context' => $m['context_length'] ?? $m['details']['context_length'] ?? null,
            ];
        }
        return $out;
    }

    public function testKey(): array
    {
        $r = $this->http('GET', '/api/tags');
        if ($r['ok']) {
            $cnt = count($r['data']['models'] ?? []);
            return ['ok' => true, 'data' => ['models' => $cnt, 'base' => $this->baseUrl]];
        }
        return ['ok' => false, 'error' => $r['error'] ?? 'HTTP ' . $r['status']];
    }

    public function chat(array $messages, string $model, array $opts = []): array
    {
        $body = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'options' => [],
        ];
        if (isset($opts['temperature'])) $body['options']['temperature'] = $opts['temperature'];
        if (!empty($opts['max_tokens'])) $body['options']['num_predict'] = (int)$opts['max_tokens'];
        $r = $this->http('POST', '/api/chat', [], $body);
        if (!$r['ok']) {
            $msg = $r['data']['error'] ?? ('HTTP ' . $r['status']);
            return ['ok' => false, 'error' => $msg];
        }
        $text = $r['data']['message']['content'] ?? '';
        return ['ok' => true, 'text' => $text, 'raw' => $r['data']];
    }

    public function vision(string $model, string $prompt, string $imageDataUrl, array $opts = []): array
    {
        $b64 = $imageDataUrl;
        if (str_starts_with($b64, 'data:') && ($i = strpos($b64, 'base64,')) !== false) {
            $b64 = substr($b64, $i + 7);
        }
        $body = [
            'model' => $model,
            'messages' => [[
                'role' => 'user',
                'content' => $prompt,
                'images' => [$b64],
            ]],
            'stream' => false,
            'options' => [],
        ];
        if (!empty($opts['max_tokens'])) $body['options']['num_predict'] = (int)$opts['max_tokens'];
        $r = $this->http('POST', '/api/chat', [], $body);
        if (!$r['ok']) {
            $msg = $r['data']['error'] ?? ('HTTP ' . $r['status']);
            return ['ok' => false, 'error' => $msg];
        }
        return ['ok' => true, 'text' => $r['data']['message']['content'] ?? '', 'raw' => $r['data']];
    }

    public function embedding(string $model, string $input): array
    {
        $body = ['model' => $model, 'prompt' => $input];
        $r = $this->http('POST', '/api/embeddings', [], $body);
        if (!$r['ok']) {
            $msg = $r['data']['error'] ?? ('HTTP ' . $r['status']);
            return ['ok' => false, 'error' => $msg];
        }
        $vec = $r['data']['embedding'] ?? null;
        if (!is_array($vec)) return ['ok' => false, 'error' => 'No embedding returned'];
        return ['ok' => true, 'vector' => $vec];
    }
}
