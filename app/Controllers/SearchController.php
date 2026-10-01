<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Services\SearchService;

final class SearchController extends Controller
{
    public function index(): void
    {
        $user = Auth::require();
        $q = SearchService::boundQuery(Request::query('q', ''));
        $type = Request::query('type');
        $from = Request::timestamp('from');
        $to = Request::timestamp('to');
        $results = $q !== '' ? (new SearchService())->query((int)$user['id'], $q, ['type' => $type, 'from' => $from, 'to' => $to]) : [];
        $this->view('search/index', ['q' => $q, 'results' => $results, 'type' => $type]);
    }
}
