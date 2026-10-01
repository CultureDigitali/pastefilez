<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\I18n;
use App\Core\Mailer;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class AuthController extends Controller
{
    public function loginForm(): void
    {
        if (Auth::check()) Response::redirect('/');
        $this->view('auth/login', [], 'centered');
    }

    public function login(): void
    {
        $email = Request::inputString('email');
        $password = Request::inputString('password');
        if (!RateLimiter::check('login:' . Request::ip())) {
            $this->view('auth/login', ['email' => $email, 'error' => t('auth.login.too_many')], 'centered');
            return;
        }
        $r = Auth::attempt($email, $password);
        if (!$r['ok']) {
            $msg = $r['reason'] === 'locked'
                ? t('auth.login.locked', ['minutes' => (int)ceil(($r['wait'] ?? 60) / 60)])
                : t('auth.login.invalid');
            $this->view('auth/login', ['email' => $email, 'error' => $msg], 'centered');
            return;
        }
        Auth::login($r['user'], $r['requires_totp']);
        if ($r['requires_totp']) {
            Session::set('totp_user_id', (int)$r['user']['id']);
            $this->view('auth/totp', [], 'centered');
            return;
        }
        $lang = $r['user']['language'] ?? 'it';
        I18n::setLang($lang);
        Response::redirect('/');
    }

    public function loginTotp(): void
    {
        $uid = (int)Session::get('totp_user_id', 0);
        $code = Request::inputString('code');
        if ($uid <= 0) { Response::redirect('/login'); }
        if (!RateLimiter::check('totp:' . $uid, 8)) {
            $this->view('auth/totp', ['error' => t('auth.login.too_many')], 'centered');
            return;
        }
        RateLimiter::hit('totp:' . $uid, 8);
        $user = Db::first('SELECT * FROM users WHERE id = ? AND is_active = 1', [$uid]);
        $locked = $user && $user['locked_until'] && (int)$user['locked_until'] > time();
        if (!$user || $locked || !Auth::verifyTotp($user, $code)) {
            if ($user) {
                $fa = (int)$user['failed_attempts'] + 1;
                Db::update('users', [
                    'failed_attempts' => $fa,
                    'locked_until' => $fa >= 5 ? time() + (int)pow(2, min($fa - 5, 6)) * 60 : null,
                    'updated_at' => time(),
                ], 'id = ?', [$uid]);
            }
            $this->view('auth/totp', ['error' => t('auth.login.invalid')], 'centered');
            return;
        }
        Db::update('users', ['failed_attempts' => 0, 'locked_until' => null, 'updated_at' => time()], 'id = ?', [$uid]);
        Auth::login($user, false);
        Session::forget('totp_user_id');
        Session::set('totp_verified', true);
        I18n::setLang($user['language'] ?? 'it');
        Response::redirect('/');
    }

    public function logout(): void
    {
        Auth::logout();
        Session::set('flash_success', t('auth.logout.done'));
        Response::redirect('/login');
    }

    public function forgotForm(): void
    {
        $this->view('auth/forgot', [], 'centered');
    }

    public function forgot(): void
    {
        $email = strtolower(trim(Request::inputString('email')));
        if ($email !== '' && !RateLimiter::check('forgot:' . Request::ip(), 5)) {
            Session::set('flash_error', t('auth.forgot.too_many'));
            Response::redirect('/forgot');
        }
        RateLimiter::hit('forgot:' . Request::ip(), 5);
        $user = $email ? Db::first('SELECT * FROM users WHERE email = ?', [$email]) : null;
        if ($user) {
            $token = Crypto::randomToken(32);
            $ttl = 3600;
            Db::insert('recovery_codes', [
                'user_id' => $user['id'],
                'code_hash' => password_hash($token, PASSWORD_BCRYPT),
                'expires_at' => time() + $ttl,
                'created_at' => time(),
            ]);
            $link = url('reset/' . $user['id'] . ':' . $token);
            $body = "Ciao,\n\nper reimpostare la password apri: " . $link . "\n\nScade tra 1h.\n";
            Mailer::send($user['email'], 'Reimposta la tua password', $body);
            Audit::log('forgot.request', 'user:' . $user['id']);
        }
        Session::set('flash_success', t('auth.forgot.sent'));
        Response::redirect('/forgot');
    }

    public function resetForm(array $params): void
    {
        $this->view('auth/reset', ['token' => $params['token'] ?? ''], 'centered');
    }

    public function reset(array $params): void
    {
        $token = (string)($params['token'] ?? '');
        $pwd = Request::inputString('password');
        if (strlen($pwd) < 12) {
            $this->view('auth/reset', ['token' => $token, 'error' => t('settings.password.short')], 'centered');
            return;
        }
        if (!str_contains($token, ':')) {
            $this->view('auth/reset', ['token' => $token, 'error' => t('auth.reset.invalid')], 'centered');
            return;
        }
        [$uid, $code] = explode(':', $token, 2);
        $user = Db::first('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int)$uid]);
        if (!$user) { Response::redirect('/forgot'); }
        $rec = Db::first('SELECT * FROM recovery_codes WHERE user_id = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1', [(int)$user['id']]);
        $valid = $rec && (int)($rec['expires_at'] ?? 0) > time() && password_verify($code, (string)$rec['code_hash']);
        if (!$valid) {
            $this->view('auth/reset', ['token' => $token, 'error' => t('auth.reset.invalid')], 'centered');
            return;
        }
        Db::update('users', [
            'password_hash' => Crypto::hash($pwd),
            'failed_attempts' => 0,
            'locked_until' => null,
            'totp_enabled' => 0,
            'totp_secret' => null,
            'updated_at' => time(),
        ], 'id = ?', [$user['id']]);
        Db::update('recovery_codes', ['used_at' => time()], 'id = ?', [$rec['id']]);
        Db::delete('recovery_codes', 'user_id = ? AND used_at IS NULL', [$user['id']]);
        Session::invalidateUserSessions((int)$user['id']);
        Audit::log('password.reset', 'user:' . $user['id']);
        Session::set('flash_success', t('auth.reset.done'));
        Response::redirect('/login');
    }
}
