<?php
declare(strict_types=1);

namespace App\Services;

final class NvidiaClient extends BaseAiProvider
{
    public function name(): string { return 'nvidia'; }

    public function listModels(): array
    {
        try {
            return $this->parseModels($this->http('GET', '/models', ['Authorization: Bearer ' . $this->apiKey]));
        } catch (\Throwable) {
            return [];
        }
    }

    private function parseModels(array $r): array
    {
        if (!$r['ok'] || !is_array($r['data']['data'] ?? null)) {
            $fallback = [
                ['id' => 'meta/llama-3.1-70b-instruct', 'name' => 'Llama 3.1 70B Instruct', 'vision' => false, 'embedding' => false, 'context' => 131072],
                ['id' => 'meta/llama-3.1-8b-instruct', 'name' => 'Llama 3.1 8B Instruct', 'vision' => false, 'embedding' => false, 'context' => 131072],
                ['id' => 'nvidia/nemotron-4-340b-instruct', 'name' => 'Nemotron-4 340B', 'vision' => false, 'embedding' => false, 'context' => 4096],
                ['id' => 'nvidia/llama-3.1-nemotron-70b-instruct', 'name' => 'Nemotron 70B', 'vision' => false, 'embedding' => false, 'context' => 32768],
                ['id' => 'nvidia/nvclip', 'name' => 'NV-CLIP (multimodal embed)', 'vision' => true, 'embedding' => true, 'context' => 77],
                ['id' => 'nvidia/embed-qa-4', 'name' => 'Embed QA 4', 'vision' => false, 'embedding' => true, 'context' => 2048],
                ['id' => 'baai/bge-m3', 'name' => 'BGE-M3 Multilingual Embed', 'vision' => false, 'embedding' => true, 'context' => 8192],
            ];
            return $r['ok'] ? $fallback : [];
        }
        $out = [];
        foreach ($r['data']['data'] as $m) {
            $id = isset($m['id']) && is_scalar($m['id']) ? (string)$m['id'] : null;
            if ($id === null || $id === '') continue;
            $out[] = [
                'id' => $id,
                'name' => $m['name'] ?? $m['displayName'] ?? $id,
                'vision' => (bool)($m['supportsVision'] ?? $m['vision'] ?? (str_contains(strtolower($id), 'vl') || str_contains(strtolower($id), 'clip'))),
                'embedding' => (bool)($m['embedding'] ?? str_contains(strtolower($id), 'embed') || str_contains(strtolower($id), 'clip')),
                'context' => $m['contextLength'] ?? null,
            ];
        }
        return $out;
    }

    public function testKey(): array
    {
        $body = [
            'model' => 'meta/llama-3.1-8b-instruct',
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'max_tokens' => 4,
        ];
        $r = $this->http('POST', '/chat/completions', ['Authorization: Bearer ' . $this->apiKey], $body);
        if ($r['ok']) return ['ok' => true, 'data' => $r['data']];
        return ['ok' => false, 'error' => $r['error'] ?? 'HTTP ' . $r['status']];
    }

    public function chat(array $messages, string $model, array $opts = []): array
    {
        $body = ['model' => $model, 'messages' => $messages, 'max_tokens' => $opts['max_tokens'] ?? 1024];
        if (isset($opts['temperature'])) $body['temperature'] = $opts['temperature'];
        $r = $this->http('POST', '/chat/completions', ['Authorization: Bearer ' . $this->apiKey], $body);
        if (!$r['ok']) return ['ok' => false, 'error' => 'HTTP ' . $r['status']];
        return ['ok' => true, 'text' => $r['data']['choices'][0]['message']['content'] ?? ''];
    }

    public function vision(string $model, string $prompt, string $imageDataUrl, array $opts = []): array
    {
        $body = [
            'model' => $model,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageDataUrl]],
                ],
            ]],
            'max_tokens' => $opts['max_tokens'] ?? 1024,
        ];
        $r = $this->http('POST', '/chat/completions', ['Authorization: Bearer ' . $this->apiKey], $body);
        if (!$r['ok']) return ['ok' => false, 'error' => 'HTTP ' . $r['status']];
        return ['ok' => true, 'text' => $r['data']['choices'][0]['message']['content'] ?? ''];
    }

    public function embedding(string $model, string $input): array
    {
        $body = ['model' => $model, 'input' => $input, 'encoding_format' => 'float'];
        $r = $this->http('POST', '/embeddings', ['Authorization: Bearer ' . $this->apiKey], $body);
        if (!$r['ok']) return ['ok' => false, 'error' => 'HTTP ' . $r['status']];
        $vec = $r['data']['data'][0]['embedding'] ?? null;
        if (!is_array($vec)) return ['ok' => false, 'error' => 'No embedding returned'];
        return ['ok' => true, 'vector' => $vec];
    }
}
