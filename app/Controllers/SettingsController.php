<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\I18n;
use App\Core\Mailer;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\TOTP;
use App\Models\Setting;
use App\Services\StorageService;

final class SettingsController extends Controller
{
    public function index(): void
    {
        $user = Auth::require();
        $tab = Request::queryString('tab', 'profile');
        $this->view('settings/index', ['tab' => $tab]);
    }

    public function profile(): void
    {
        $user = Auth::require();
        $name = trim(Request::inputString('display_name'));
        $email = strtolower(trim(Request::inputString('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::set('flash_error', t('install.error_email'));
            Response::redirect('/settings?tab=profile');
        }
        $exists = Db::first('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $user['id']]);
        if ($exists) {
            Session::set('flash_error', t('install.error_email'));
            Response::redirect('/settings?tab=profile');
        }
        Db::update('users', ['display_name' => $name, 'email' => $email, 'updated_at' => time()], 'id = ?', [$user['id']]);
        Session::set('user_email', $email);
        Audit::log('profile.update', 'user:' . $user['id']);
        Session::set('flash_success', t('settings.profile.saved'));
        Response::redirect('/settings?tab=profile');
    }

    public function password(): void
    {
        $user = Auth::require();
        $cur = Request::inputString('current');
        $new = Request::inputString('password');
        if (!Crypto::verify($cur, $user['password_hash'])) {
            Session::set('flash_error', t('settings.password.wrong'));
            Response::redirect('/settings?tab=security');
        }
        if (strlen($new) < 12) {
            Session::set('flash_error', t('settings.password.short'));
            Response::redirect('/settings?tab=security');
        }
        Db::update('users', ['password_hash' => Crypto::hash($new), 'failed_attempts' => 0, 'locked_until' => null, 'updated_at' => time()], 'id = ?', [$user['id']]);
        Db::delete('recovery_codes', 'user_id = ?', [$user['id']]);
        Db::delete('api_tokens', 'user_id = ?', [$user['id']]);
        $revoked = Auth::revokeAllSessions((int)$user['id'], (string)session_id());
        Audit::log('password.change', 'user:' . $user['id'], ['sessions_revoked' => $revoked]);
        Session::set('flash_success', t('settings.password.changed'));
        Response::redirect('/settings?tab=security');
    }

    public function totpSetup(): void
    {
        $user = Auth::require();
        $secret = Session::get('totp_pending_secret');
        if (!$secret) {
            $secret = TOTP::generateSecret();
            Session::set('totp_pending_secret', $secret);
        }
        $uri = TOTP::provisioningUri($secret, $user['email'], (string)Config::get('app_name'));
        $this->view('settings/totp_setup', ['secret' => $secret, 'uri' => $uri, 'user' => $user], 'centered');
    }

    public function totpEnable(): void
    {
        $user = Auth::require();
        $code = Request::inputString('code');
        $pending = Session::get('totp_pending_secret');
        if (!$pending) {
            $secret = TOTP::generateSecret();
            Session::set('totp_pending_secret', $secret);
            $uri = TOTP::provisioningUri($secret, $user['email'], (string)Config::get('app_name'));
            $this->view('settings/totp_setup', ['secret' => $secret, 'uri' => $uri, 'user' => $user], 'centered');
            return;
        }
        if (!TOTP::verify($pending, $code)) {
            Session::set('flash_error', t('auth.login.invalid'));
            $uri = TOTP::provisioningUri($pending, $user['email'], (string)Config::get('app_name'));
            $this->view('settings/totp_setup', ['secret' => $pending, 'uri' => $uri, 'user' => $user, 'error' => t('auth.login.invalid')], 'centered');
            return;
        }
        $enc = Crypto::encryptToBase64($pending);
        Db::update('users', ['totp_secret' => $enc, 'totp_enabled' => 1, 'updated_at' => time()], 'id = ?', [$user['id']]);
        Session::forget('totp_pending_secret');
        Audit::log('totp.enable', 'user:' . $user['id']);
        Session::set('flash_success', t('settings.totp.enabled'));
        Response::redirect('/settings?tab=security');
    }

    public function totpDisable(): void
    {
        $user = Auth::require();
        $code = Request::inputString('code');
        $secret = Crypto::decryptFromBase64((string)$user['totp_secret']);
        if (!$secret || !TOTP::verify($secret, $code)) {
            Session::set('flash_error', t('auth.login.invalid'));
            Response::redirect('/settings?tab=security');
        }
        Db::update('users', ['totp_secret' => null, 'totp_enabled' => 0, 'updated_at' => time()], 'id = ?', [$user['id']]);
        Audit::log('totp.disable', 'user:' . $user['id']);
        Session::set('flash_success', t('settings.totp.disabled'));
        Response::redirect('/settings?tab=security');
    }

    public function preferences(): void
    {
        $user = Auth::require();
        $lang = Request::inputString('language', 'it');
        $theme = Request::inputString('theme', 'dark');
        if (!in_array($lang, ['it', 'en'], true)) $lang = 'it';
        if (!in_array($theme, ['dark', 'light', 'auto'], true)) $theme = 'dark';
        I18n::setLang($lang);
        Db::update('users', ['language' => $lang, 'theme' => $theme, 'updated_at' => time()], 'id = ?', [$user['id']]);
        Session::set('theme', $theme);
        Session::set('flash_success', t('common.save'));
        Response::redirect('/settings?tab=preferences');
    }

    public function smtp(): void
    {
        Auth::requireGrant('config.write');
        $envPath = dirname(__DIR__, 2) . '/.env';
        $vals = [
            'SMTP_ENABLED' => Request::input('enabled') ? 'true' : 'false',
            'SMTP_HOST' => Request::inputString('host'),
            'SMTP_PORT' => Request::inputString('port'),
            'SMTP_USER' => Request::inputString('user'),
            'SMTP_PASS' => Request::inputString('pass'),
            'SMTP_FROM' => Request::inputString('from'),
            'SMTP_FROM_NAME' => Request::inputString('from_name'),
            'SMTP_ENCRYPTION' => Request::inputString('encryption'),
        ];
        self::updateEnv($envPath, $vals);
        $testTo = Request::inputString('test_to');
        if ($testTo && filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
            $ok = Mailer::send($testTo, 'PasteFilez test', 'SMTP test from PasteFilez at ' . date('c'));
            if ($ok) Session::set('flash_success', t('smtp.test_ok'));
            else Session::set('flash_error', t('smtp.test_fail', ['msg' => 'check logs']));
        } else {
            Session::set('flash_success', t('smtp.saved'));
        }
        Response::redirect('/settings?tab=smtp');
    }

    public function encryption(): void
    {
        Auth::requireGrant('config.write');
        $envPath = dirname(__DIR__, 2) . '/.env';
        self::updateEnv($envPath, ['ENCRYPT_FILES_AT_REST' => Request::inputString('enabled') === 'true' ? 'true' : 'false']);
        Session::set('flash_success', t('common.save'));
        Response::redirect('/settings?tab=preferences');
    }

    public function revokeSession(array $params): void
    {
        $user = Auth::require();
        $sid = (string)($params['id'] ?? '');
        if (!Auth::revokeSession((int)$user['id'], $sid)) Response::json(['ok' => false, 'error' => 'not_found'], 404);
        Audit::log('session.revoke', 'session:' . substr($sid, 0, 8));
        Response::json(['ok' => true]);
    }

    public function createApiToken(): void
    {
        $user = Auth::require();
        $name = trim(Request::inputString('name', 'token'));
        $plain = Crypto::randomToken(32);
        $hash = Crypto::hmac($plain);
        $id = Db::insert('api_tokens', [
            'user_id' => $user['id'],
            'name' => $name,
            'token_hash' => $hash,
            'created_at' => time(),
            'expires_at' => null,
        ]);
        Session::set('flash_success', t('settings.api_tokens.once', ['token' => $plain]));
        Audit::log('api_token.create', 'token:' . $id);
        Response::redirect('/settings?tab=sessions');
    }

    public function deleteApiToken(array $params): void
    {
        $user = Auth::require();
        $id = (int)($params['id'] ?? 0);
        Db::delete('api_tokens', 'id = ? AND user_id = ?', [$id, $user['id']]);
        Audit::log('api_token.delete', 'token:' . $id);
        Response::json(['ok' => true]);
    }

    public function cloud(): void
    {
        Auth::requireGrant('config.write');
        Response::redirect('/settings?tab=cloud');
    }

    public function cloudSave(): void
    {
        Auth::requireGrant('config.write');
        $envPath = dirname(__DIR__, 2) . '/.env';
        $vals = [
            'GOOGLE_CLIENT_ID' => Request::inputString('google_client_id'),
            'GOOGLE_CLIENT_SECRET' => Request::inputString('google_client_secret'),
            'GOOGLE_REDIRECT' => Request::inputString('google_redirect'),
            'DROPBOX_CLIENT_ID' => Request::inputString('dropbox_client_id'),
            'DROPBOX_CLIENT_SECRET' => Request::inputString('dropbox_client_secret'),
            'DROPBOX_REDIRECT' => Request::inputString('dropbox_redirect'),
        ];
        self::updateEnv($envPath, $vals);
        Session::set('flash_success', t('cloud.saved'));
        Response::redirect('/settings?tab=cloud');
    }

    public function cloudConnect(array $params): void
    {
        $user = Auth::require();
        $provider = (string)($params['provider'] ?? '');
        $client = self::cloudFor($provider, (int)$user['id']);
        if (!$client) {
            Session::set('flash_error', t('cloud.connect_error', ['msg' => 'unconfigured']));
            Response::redirect('/settings?tab=cloud');
        }
        $state = Crypto::randomToken(16);
        $verifier = Crypto::randomToken(48);
        Db::insert('oauth_states', [
            'state' => $state,
            'user_id' => $user['id'],
            'provider' => $provider,
            'code_verifier' => $verifier,
            'redirect_to' => '/settings?tab=cloud',
            'created_at' => time(),
        ]);
        Response::redirect($client->authorizeUrl($state, $verifier));
    }

    public function cloudCallback(array $params): void
    {
        $user = Auth::require();
        $provider = (string)($params['provider'] ?? '');
        $code = Request::inputString('code');
        $state = Request::inputString('state');
        $row = Db::first('SELECT * FROM oauth_states WHERE state = ? AND user_id = ? AND provider = ?', [$state, $user['id'], $provider]);
        if (!$row) { Session::set('flash_error', 'invalid_state'); Response::redirect('/settings?tab=cloud'); }
        Db::delete('oauth_states', 'state = ?', [$state]);
        $client = self::cloudFor($provider, (int)$user['id']);
        if (!$client) { Session::set('flash_error', 'unconfigured'); Response::redirect('/settings?tab=cloud'); }
        $r = $client->exchangeCode($code, (string)$row['code_verifier']);
        if (!$r['ok']) {
            Session::set('flash_error', t('cloud.connect_error', ['msg' => $r['error'] ?? 'unknown']));
        } else {
            Session::set('flash_success', t('cloud.connected', ['email' => 'OK']));
        }
        Response::redirect('/settings?tab=cloud');
    }

    public function cloudDisconnect(array $params): void
    {
        $user = Auth::require();
        $provider = (string)($params['provider'] ?? '');
        $client = self::cloudFor($provider, (int)$user['id']);
        if ($client) $client->disconnect();
        Session::set('flash_success', t('cloud.disconnected_ok'));
        Response::redirect('/settings?tab=cloud');
    }

    public function storageBackend(): void
    {
        $user = Auth::requireGrant('config.write');
        $backend = Request::inputString('backend', 'local');
        if (!in_array($backend, ['local', 'google', 'dropbox'], true)) $backend = 'local';
        if ($backend !== 'local' && StorageService::factory($backend, (int)$user['id']) === null) {
            Session::set('flash_error', t('cloud.backend.not_connected'));
            Response::redirect('/settings?tab=cloud');
        }
        Db::update('users', ['storage_backend' => $backend, 'updated_at' => time()], 'id = ?', [$user['id']]);
        Session::set('flash_success', t('common.save'));
        Response::redirect('/settings?tab=cloud');
    }

    private static function cloudFor(string $provider, int $userId)
    {
        if ($provider === 'google') return new \App\Services\GoogleDriveClient($userId);
        if ($provider === 'dropbox') return new \App\Services\DropboxClient($userId);
        return null;
    }

    public static function updateEnv(string $path, array $values): void
    {
        if (!is_file($path)) {
            $example = dirname(__DIR__, 2) . '/.env.example';
            if (is_file($example)) copy($example, $path);
            @chmod($path, 0640);
        }
        $content = (string)file_get_contents($path);
        foreach ($values as $k => $v) {
            $k = self::envKey((string)$k);
            if ($k === null) continue;
            $v = self::envValue((string)$v);
            $pattern = '/^' . preg_quote($k, '/') . '=.*$/m';
            if (preg_match($pattern, $content)) {
                $content = (string)preg_replace_callback($pattern, static fn(): string => $k . '=' . $v, $content, 1);
            } else {
                $content .= "\n" . $k . '=' . $v;
            }
        }
        @file_put_contents($path, $content, LOCK_EX);
    }

    private static function envKey(string $k): ?string
    {
        return preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $k) === 1 ? $k : null;
    }

    private static function envValue(string $v): string
    {
        $v = str_replace(["\r", "\n", "\0"], ' ', $v);
        $v = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v) ?? '';
        if (!str_contains($v, '"') && !str_contains($v, "'") && !str_contains($v, '\\')) return $v;
        return '"' . addcslashes($v, "\"\\\n\r") . '"';
    }
}
