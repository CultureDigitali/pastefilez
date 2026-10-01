<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Installer;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

final class InstallController extends Controller
{
    public function index(): void
    {
        if (Installer::isInstalled()) {
            \App\Core\Response::redirect('/login');
        }
        $this->view('install/index', [], false);
    }

    public function submit(array $params = []): void
    {
        if (Installer::isInstalled()) {
            Response::redirect('/login');
        }
        $email = trim(Request::inputString('email'));
        $password = Request::inputString('password');
        $name = trim(Request::inputString('display_name', ''));
        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = t('install.error_email');
        if (strlen($password) < 12) $errors[] = t('install.error_pwd');
        if ($errors) {
            $this->view('install/index', ['errors' => $errors, 'email' => $email, 'name' => $name], false);
            return;
        }
        Installer::generateMasterKey();
        $id = Installer::createAdmin($email, $password, $name ?: $email);
        $user = Db::first('SELECT * FROM users WHERE id = ?', [$id]);
        Auth::login($user, false);
        Audit::log('install.complete', 'user:' . $id);
        \App\Core\Session::set('flash_success', t('install.done'));
        Response::redirect('/');
    }
}
