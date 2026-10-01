<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

abstract class Controller
{
    protected function view(string $template, array $data = [], bool|string $layout = true): void
    {
        $u = Auth::user();
        View::share('app_name', \App\Core\Config::get('app_name'));
        View::share('app_url', \App\Core\Config::get('app_url'));
        View::share('user', $u);
        View::share('lang', I18n::lang());
        View::share('theme', Session::get('theme') ?: 'dark');
        View::share('is_admin', Auth::isAdminUser($u));
        View::share('csrf_token', \App\Core\Csrf::token());
        $content = View::render($template, $data);
        if ($layout === 'centered') {
            echo View::render('layouts/centered', ['content' => $content, 'page' => $template] + $data);
        } elseif ($layout) {
            echo View::render('layouts/main', ['content' => $content, 'page' => $template] + $data);
        } else {
            echo $content;
        }
    }

    protected function jsonOk(mixed $data = []): void
    {
        Response::json(['ok' => true] + (is_array($data) ? $data : ['data' => $data]));
    }

    protected function jsonErr(string $msg, int $status = 400): void
    {
        Response::json(['ok' => false, 'error' => $msg], $status);
    }

    protected function inputInt(string $k, int $default = 0): int
    {
        $v = Request::input($k, $default);
        return is_numeric($v) ? (int)$v : $default;
    }

    protected function redirect(string $path): void
    {
        Response::redirect($path);
    }
}
