<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Crypto;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Services\ApiAuth;
use App\Services\AiProvider;

final class AiApiController
{
    public function analyze(): void
    {
        $u = ApiAuth::user(); if (!$u) Response::json(['error' => 'unauthenticated'], 401);
        $itemId = (int)Request::input('item_id');
        $row = Db::first('SELECT * FROM items WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [$itemId, $u['id']]);
        if (!$row) Response::json(['error' => 'not_found'], 404);
        $force = filter_var(Request::input('force', false), FILTER_VALIDATE_BOOLEAN);
        if ((string)$row['ai_status'] === 'done' && !$force) {
            Response::json(['ok' => true, 'skipped' => true, 'reason' => 'already_analyzed']);
        }
        (new \App\Services\AnalysisService())->run((int)$u['id'], $itemId);
        Response::json(['ok' => true]);
    }

    public function models(): void
    {
        $u = ApiAuth::user(); if (!$u) Response::json(['error' => 'unauthenticated'], 401);
        $rows = Db::all('SELECT provider, model_id, display_name, supports_vision, supports_embedding, context_length FROM ai_models_cache ORDER BY provider ASC, display_name ASC');
        Response::json(['models' => $rows]);
    }

}
