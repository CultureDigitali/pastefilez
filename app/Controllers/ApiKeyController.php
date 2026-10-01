<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class ApiKeyController extends Controller
{
    public function index(): void
    {
        $user = Auth::require();
        $rows = Db::all('SELECT * FROM api_keys WHERE user_id = ? ORDER BY provider ASC', [$user['id']]);
        $modelsByProvider = [];
        foreach ($rows as $r) {
            $modelsByProvider[$r['provider']] = Db::all('SELECT * FROM ai_models_cache WHERE provider = ? ORDER BY display_name ASC', [$r['provider']]);
        }
        $defaults = [
            'provider' => (string)(\App\Models\Setting::get($user['id'], 'ai.default_provider', '') ?: ''),
            'chat' => (string)\App\Models\Setting::get($user['id'], 'ai.model.chat', ''),
            'vision' => (string)\App\Models\Setting::get($user['id'], 'ai.model.vision', ''),
            'embedding' => (string)\App\Models\Setting::get($user['id'], 'ai.model.embedding', ''),
        ];
        $this->view('settings/ai', ['rows' => $rows, 'models' => $modelsByProvider, 'defaults' => $defaults]);
    }

    public function save(): void
    {
        $user = Auth::require();
        $provider = Request::inputString('provider');
        $label = trim(Request::inputString('label'));
        $key = trim(Request::inputString('key'));
        if (!in_array($provider, ['openrouter', 'nvidia', 'groq', 'ollama'], true)) Response::json(['ok' => false, 'error' => 'bad_provider']);
        if ($key === '') Response::json(['ok' => false, 'error' => 'empty_key']);
        $enc = Crypto::encryptToBase64($key);
        $now = time();
        $exists = Db::first('SELECT id FROM api_keys WHERE user_id = ? AND provider = ?', [$user['id'], $provider]);
        if ($exists) {
            Db::update('api_keys', ['encrypted_key' => $enc, 'label' => $label, 'updated_at' => $now], 'id = ?', [$exists['id']]);
        } else {
            Db::insert('api_keys', [
                'user_id' => $user['id'],
                'provider' => $provider,
                'label' => $label,
                'encrypted_key' => $enc,
                'iv' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        Session::set('flash_success', t('common.save'));
        Response::redirect('/settings/ai');
    }

    public function delete(array $params): void
    {
        $user = Auth::require();
        $id = (int)($params['id'] ?? 0);
        Db::delete('api_keys', 'id = ? AND user_id = ?', [$id, $user['id']]);
        Session::set('flash_success', t('common.delete'));
        Response::redirect('/settings/ai');
    }
}
