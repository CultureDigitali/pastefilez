<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Services\RagService;

final class ChatController extends Controller
{
    public function index(): void
    {
        $user = Auth::require();
        $this->view('chat/index', ['user' => $user]);
    }

    public function ask(): void
    {
        $user = Auth::require();
        $q = \App\Services\SearchService::boundQuery(Request::inputString('q', ''));
        if ($q === '') Response::json(['ok' => false, 'error' => 'empty'], 400);
        $r = RagService::ask((int)$user['id'], $q, 8);
        Response::json($r);
    }
}
