<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Crypto;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Services\ApiAuth;
use App\Services\SearchService;

final class SearchApiController
{
    public function query(): void
    {
        $u = ApiAuth::user(); if (!$u) Response::json(['error' => 'unauthenticated'], 401);
        $q = SearchService::boundQuery(Request::query('q', ''));
        if ($q === '') Response::json(['results' => []]);
        $r = (new SearchService())->query((int)$u['id'], $q);
        Response::json(['results' => $r]);
    }

}
