<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;

abstract class BaseAiProvider implements AiProviderInterface
{
    protected string $apiKey;
    protected string $baseUrl;

    public function __construct(string $apiKey, string $baseUrl)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    abstract public function name(): string;

    protected function http(string $method, string $path, array $headers = [], mixed $body = null): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init();
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        $h = $headers;
        if ($body !== null) {
            if (is_array($body)) {
                $h[] = 'Content-Type: application/json';
                $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } else {
                $opts[CURLOPT_POSTFIELDS] = (string)$body;
            }
        }
        if (!empty($h)) $opts[CURLOPT_HTTPHEADER] = $h;
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            Logger::error('ai.http.fail', ['provider' => $this->name(), 'err' => $err, 'url' => $url]);
            return ['ok' => false, 'status' => 0, 'error' => $err, 'raw' => null];
        }
        $json = json_decode($resp, true);
        return ['ok' => $code >= 200 && $code < 300, 'status' => $code, 'data' => $json, 'raw' => $resp];
    }

    public function fileToDataUrl(string $path, string $mime): string
    {
        $data = @file_get_contents($path);
        if ($data === false) return '';
        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }
}
