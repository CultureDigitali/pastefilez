<?php
declare(strict_types=1);

namespace App\Services;

final class GroqClient extends BaseAiProvider
{
    public function name(): string { return 'groq'; }

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
        if (!$r['ok'] || !is_array($r['data']['data'] ?? null)) return [];
        $visionIds = ['llava-v1.5-7b-4096-preview', 'llama-3.2-11b-vision-preview', 'llama-3.2-90b-vision-preview'];
        $out = [];
        foreach ($r['data']['data'] as $m) {
            $id = isset($m['id']) && is_scalar($m['id']) ? (string)$m['id'] : null;
            if ($id === null || $id === '') continue;
            $out[] = [
                'id' => $id,
                'name' => $id,
                'context' => $m['context_window'] ?? $m['max_context_length'] ?? null,
                'vision' => in_array($id, $visionIds, true) || str_contains($id, 'vision'),
                'embedding' => false,
            ];
        }
        return $out;
    }

    public function testKey(): array
    {
        $body = ['model' => 'llama-3.1-8b-instant', 'messages' => [['role' => 'user', 'content' => 'hi']], 'max_tokens' => 4];
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
        return ['ok' => false, 'error' => 'Groq does not provide embeddings. Use NVIDIA or set a custom provider.'];
    }
}
