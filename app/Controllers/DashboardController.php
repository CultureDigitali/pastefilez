<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Models\Item;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $user = \App\Core\Auth::require();
        $items = Item::list((int)$user['id'], 24, 0);
        $total = (int)Db::value('SELECT COUNT(*) FROM items WHERE user_id = ? AND deleted_at IS NULL', [$user['id']]);
        $used = \App\Services\StorageService::quotaUsed((int)$user['id']);
        $quota = \App\Services\StorageService::userQuotaBytes((int)$user['id']);
        $this->view('dashboard/index', [
            'items' => $items,
            'total' => $total,
            'used' => $used,
            'quota' => $quota,
            'used_pct' => $quota > 0 ? min(100, round($used * 100 / $quota)) : 0,
        ]);
    }
}
