<?php
declare(strict_types=1);

namespace App\Services;

final class OpenRouterClient extends BaseAiProvider
{
    public function name(): string { return 'openrouter'; }

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
        $out = [];
        foreach ($r['data']['data'] as $m) {
            $id = isset($m['id']) && is_scalar($m['id']) ? (string)$m['id'] : null;
            if ($id === null || $id === '') continue;
            $arch = is_string($m['architecture'] ?? null) ? strtolower((string)$m['architecture']) : '';
            $inputMods = $m['architecture']['input_modalities'] ?? ($m['input_modalities'] ?? []);
            $out[] = [
                'id' => $id,
                'name' => $m['name'] ?? $id,
                'context' => $m['context_length'] ?? null,
                'vision' => is_array($inputMods) ? in_array('image', $inputMods, true) : (str_contains($arch, 'vision') || str_contains($id, 'vision')),
                'embedding' => false,
                'pricing_prompt' => $m['pricing']['prompt'] ?? null,
                'pricing_completion' => $m['pricing']['completion'] ?? null,
            ];
        }
        return $out;
    }

    public function testKey(): array
    {
        $r = $this->http('GET', '/auth/key', ['Authorization: Bearer ' . $this->apiKey]);
        if ($r['ok']) return ['ok' => true, 'data' => $r['data']];
        return ['ok' => false, 'error' => $r['error'] ?? ('HTTP ' . $r['status'])];
    }

    public function chat(array $messages, string $model, array $opts = []): array
    {
        $body = ['model' => $model, 'messages' => $messages];
        if (!empty($opts['temperature'])) $body['temperature'] = $opts['temperature'];
        if (!empty($opts['max_tokens'])) $body['max_tokens'] = $opts['max_tokens'];
        $r = $this->http('POST', '/chat/completions', ['Authorization: Bearer ' . $this->apiKey], $body);
        if (!$r['ok']) return ['ok' => false, 'error' => 'HTTP ' . $r['status'] . ' ' . ($r['data']['error']['message'] ?? '')];
        $text = $r['data']['choices'][0]['message']['content'] ?? '';
        return ['ok' => true, 'text' => $text, 'raw' => $r['data']];
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
        ];
        $r = $this->http('POST', '/chat/completions', ['Authorization: Bearer ' . $this->apiKey], $body);
        if (!$r['ok']) return ['ok' => false, 'error' => 'HTTP ' . $r['status']];
        return ['ok' => true, 'text' => $r['data']['choices'][0]['message']['content'] ?? '', 'raw' => $r['data']];
    }

    public function embedding(string $model, string $input): array
    {
        return ['ok' => false, 'error' => 'OpenRouter does not provide a stable embedding endpoint. Use NVIDIA or Groq, or set a custom URL.'];
    }
}
