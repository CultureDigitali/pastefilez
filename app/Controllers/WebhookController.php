<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Db;
use App\Core\Request;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Services\HttpClient;
use App\Services\ItemIngest;
use App\Services\WebhookService;

final class WebhookController extends Controller
{
    public function index(): void
    {
        $user = Auth::require();
        $rows = WebhookService::list((int)$user['id']);
        $this->view('settings/webhooks', ['webhooks' => $rows]);
    }

    public function createOutgoing(): void
    {
        $user = Auth::require();
        $name = trim(Request::inputString('name', 'webhook'));
        $url = trim(Request::inputString('target_url', ''));
        $events = (array)Request::input('events', []);
        $secret = trim(Request::inputString('secret', '')) ?: null;
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) Response::json(['ok' => false, 'error' => 'invalid_url'], 400);
        if (self::assertOutboundUrl($url) !== null) Response::json(['ok' => false, 'error' => 'url_blocked'], 400);
        $allowed = ['item.uploaded', 'item.deleted', 'share.created', 'share.opened'];
        $events = array_values(array_intersect($events, $allowed));
        if (empty($events)) Response::json(['ok' => false, 'error' => 'no_events'], 400);
        $existing = (int)Db::value('SELECT COUNT(*) FROM webhooks WHERE user_id = ? AND direction = ?', [(int)$user['id'], 'out']);
        if ($existing >= 20) Response::json(['ok' => false, 'error' => 'too_many_webhooks'], 400);
        $id = WebhookService::outgoingCreate((int)$user['id'], $name, $url, $events, $secret);
        Audit::log('webhook.create_out', 'wh:' . $id);
        Response::json(['ok' => true, 'id' => $id]);
    }

    public function createIncoming(): void
    {
        $user = Auth::require();
        $name = trim(Request::inputString('name', 'incoming'));
        $r = WebhookService::incomingCreate((int)$user['id'], $name);
        Audit::log('webhook.create_in', 'wh:' . $r['id']);
        Response::json(['ok' => true, 'id' => $r['id'], 'token' => $r['token'], 'url' => url('hooks/' . $r['token'])]);
    }

    public function delete(): void
    {
        $user = Auth::require();
        $id = (int)Request::input('id', 0);
        if (!WebhookService::delete($id, (int)$user['id'])) Response::json(['ok' => false, 'error' => 'not_found'], 404);
        Audit::log('webhook.delete', 'wh:' . $id);
        Response::json(['ok' => true]);
    }

    public function test(): void
    {
        $user = Auth::require();
        $url = trim(Request::inputString('target_url', ''));
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) Response::json(['ok' => false, 'error' => 'invalid_url'], 400);
        $blocked = self::assertOutboundUrl($url);
        if ($blocked !== null) Response::json(['ok' => false, 'error' => 'url_blocked', 'reason' => $blocked], 400);
        $resp = HttpClient::request('POST', $url, [
            'headers' => ['Content-Type' => 'application/json', 'X-PZ-Event' => 'webhook.test'],
            'body' => json_encode(['event' => 'webhook.test', 'ts' => time()]),
            'timeout' => 5,
            'max_bytes' => 65536,
        ]);
        Response::json(['ok' => $resp['ok'], 'status' => $resp['status'], 'bytes' => strlen($resp['body'])]);
    }

    private static function assertOutboundUrl(string $url): ?string
    {
        try {
            HttpClient::assertSafeUrl($url);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        return null;
    }

    public function deliveries(): void
    {
        $user = Auth::require();
        $id = (int)Request::input('id', 0);
        $w = Db::first('SELECT id FROM webhooks WHERE id = ? AND user_id = ?', [$id, (int)$user['id']]);
        if (!$w) Response::json(['ok' => false, 'error' => 'not_found'], 404);
        $rows = Db::all('SELECT id, event, status, response_body, created_at FROM webhook_deliveries WHERE webhook_id = ? ORDER BY id DESC LIMIT 20', [$id]);
        Response::json(['ok' => true, 'deliveries' => $rows]);
    }

    public function receive(array $params): void
    {
        $token = (string)($params['token'] ?? '');
        $w = WebhookService::findIncoming($token);
        if (!$w) Response::notFound();
        $rlKey = 'hook:' . substr(hash('sha256', $token), 0, 16);
        if (!RateLimiter::check($rlKey, 120)) {
            Response::json(['ok' => false, 'error' => 'rate_limited'], 429);
        }
        RateLimiter::hit($rlKey, 120);
        $rlIp = 'hook_ip:' . Request::ip();
        if (!RateLimiter::check($rlIp, 600)) {
            Response::json(['ok' => false, 'error' => 'rate_limited'], 429);
        }
        RateLimiter::hit($rlIp, 600);
        $f = Request::file('file');
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::json(['ok' => false, 'error' => 'no_file'], 400);
        }
        $owner = Db::first('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int)$w['user_id']]);
        if (!$owner) Response::json(['ok' => false, 'error' => 'owner_gone'], 410);
        $r = ItemIngest::fromUpload($owner, $f, 'hook-', true);
        if (empty($r['ok'])) Response::json($r, (int)($r['status'] ?? 400));
        Audit::log('webhook.received', 'wh:' . $w['id'], ['item' => $r['id']]);
        Response::json(['ok' => true, 'id' => $r['id'], 'name' => $r['name']]);
    }
}
