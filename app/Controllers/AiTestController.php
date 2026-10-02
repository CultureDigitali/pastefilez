<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Setting;
use App\Services\AiProvider;
use App\Services\HttpClient;

final class AiTestController extends Controller
{
    public function test(array $params): void
    {
        $user = Auth::require();
        $id = (int)($params['id'] ?? 0);
        $row = Db::first('SELECT * FROM api_keys WHERE id = ? AND user_id = ?', [$id, $user['id']]);
        if (!$row) Response::json(['ok' => false, 'error' => 'not_found'], 404);
        $key = Crypto::decryptFromBase64($row['encrypted_key']);
        if (!$key) Response::json(['ok' => false, 'error' => 'decrypt']);
        $client = AiProvider::factory($row['provider'], $key, (int)$user['id']);
        if (!$client) Response::json(['ok' => false, 'error' => t('ai.test.unconfigured')]);
        $r = $client->testKey();
        $status = $r['ok'] ? 'ok' : 'fail';
        $msg = $r['ok'] ? null : ($r['error'] ?? 'unknown');
        Db::update('api_keys', [
            'last_tested_at' => time(),
            'last_test_status' => $status,
            'last_test_msg' => $msg,
            'updated_at' => time(),
        ], 'id = ?', [$id]);
        if ($r['ok']) Session::set('flash_success', t('ai.test.ok'));
        else Session::set('flash_error', t('ai.test.fail', ['msg' => $msg]));
        Response::redirect('/settings/ai');
    }

    public function refreshModels(array $params): void
    {
        $user = Auth::require();
        $id = (int)($params['id'] ?? 0);
        $row = Db::first('SELECT * FROM api_keys WHERE id = ? AND user_id = ?', [$id, $user['id']]);
        if (!$row) Response::json(['ok' => false, 'error' => 'not_found'], 404);
        $key = Crypto::decryptFromBase64($row['encrypted_key']);
        if (!$key) Response::json(['ok' => false, 'error' => 'decrypt']);
        $client = AiProvider::factory($row['provider'], $key, (int)$user['id']);
        if (!$client) Response::json(['ok' => false, 'error' => t('ai.test.unconfigured')]);
        $models = $client->listModels();
        $now = time();
        $pdo = Db::pdo();
        $pdo->prepare('DELETE FROM ai_models_cache WHERE provider = ?')->execute([$row['provider']]);
        $n = 0;
        foreach ($models as $m) {
            $pdo->prepare("INSERT INTO ai_models_cache(provider, model_id, display_name, supports_vision, supports_embedding, context_length, pricing_prompt, pricing_completion, raw_json, fetched_at) VALUES(?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $row['provider'],
                    $m['id'],
                    $m['name'] ?? $m['id'],
                    !empty($m['vision']) ? 1 : 0,
                    !empty($m['embedding']) ? 1 : 0,
                    $m['context'] ?? null,
                    $m['pricing_prompt'] ?? null,
                    $m['pricing_completion'] ?? null,
                    json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    $now,
                ]);
            $n++;
        }
        if ($n === 0) Session::set('flash_error', t('ai.fetch.fail', ['msg' => 'no_models']));
        else Session::set('flash_success', t('ai.fetch.ok', ['n' => $n]));
        Response::redirect('/settings/ai');
    }

    public function setDefault(): void
    {
        $user = Auth::require();
        $provider = Request::inputString('provider');
        if ($provider !== '') Setting::set($user['id'], 'ai.default_provider', $provider);
        $purpose = Request::inputString('purpose');
        if ($purpose !== '' && !in_array($purpose, ['chat', 'vision', 'embedding'], true)) {
            Session::set('flash_error', 'invalid_role');
            Response::redirect('/settings/ai');
        }
        if ($purpose !== '') {
            Setting::set($user['id'], 'ai.model.' . $purpose, Request::inputString('model'));
        }
        foreach (['chat' => 'model_chat', 'vision' => 'model_vision', 'embedding' => 'model_embedding'] as $scope => $field) {
            $model = trim(Request::inputString($field));
            if ($model !== '') Setting::set($user['id'], 'ai.model.' . $scope, $model);
        }
        Session::set('flash_success', t('common.save'));
        Response::redirect('/settings/ai');
    }

    public function setOllamaBase(): void
    {
        $user = Auth::requireAdmin();
        $base = rtrim(trim(Request::inputString('ollama_base', '')), '/');
        if ($base === '' || !preg_match('#^https?://#', $base)) {
            Session::set('flash_error', 'URL non valido');
            Response::redirect('/settings/ai');
        }
        try {
            HttpClient::assertSafeUrl($base, true);
        } catch (\RuntimeException $e) {
            Session::set('flash_error', t('ai.ollama.blocked', ['reason' => $e->getMessage()]));
            Response::redirect('/settings/ai');
        }
        Setting::set($user['id'], 'ai.ollama_base', $base);
        Session::set('flash_success', t('common.save'));
        Response::redirect('/settings/ai');
    }

}
