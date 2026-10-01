<?php
declare(strict_types=1);

namespace App\Services;

interface AiProviderInterface
{
    public function name(): string;
    public function listModels(): array;
    public function testKey(): array;
    public function chat(array $messages, string $model, array $opts = []): array;
    public function vision(string $model, string $prompt, string $imageDataUrl, array $opts = []): array;
    public function embedding(string $model, string $input): array;
    public function fileToDataUrl(string $path, string $mime): string;
}
