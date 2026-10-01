<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Db;
use App\Core\Response;

final class ThumbsController extends Controller
{
    public function show(array $params): void
    {
        $name = preg_replace('/[^a-f0-9.]/', '', (string)($params['name'] ?? ''));
        if (!str_ends_with($name, '.jpg')) Response::notFound();
        if (!preg_match('/^[a-f0-9]{16}\.jpg$/', $name)) Response::notFound();
        if (!self::isVisibleToCaller($name)) Response::notFound();
        $path = (string)Config::get('storage_path') . '/thumbs/' . $name;
        if (!is_file($path)) Response::notFound();
        Response::stream($path, 'image/jpeg', false);
    }

    private static function isVisibleToCaller(string $name): bool
    {
        $user = Auth::user();
        if ($user) {
            return Db::first('SELECT id FROM items WHERE user_id = ? AND thumbnail_path = ? LIMIT 1', [(int)$user['id'], 'thumbs/' . $name]) !== null;
        }
        return Db::first("SELECT id FROM items WHERE thumbnail_path = ? AND is_public = 1 LIMIT 1", ['thumbs/' . $name]) !== null;
    }
}
