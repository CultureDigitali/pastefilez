<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Services\ItemIngest;
use App\Services\StorageService;

final class UploadController extends Controller
{
    public function index(): void
    {
        $user = Auth::require();
        $used = StorageService::quotaUsed((int)$user['id']);
        $quota = StorageService::userQuotaBytes((int)$user['id']);
        $this->view('upload/index', ['used' => $used, 'quota' => $quota]);
    }

    public function upload(): void
    {
        $user = Auth::require();
        $f = $this->fileOrFail();
        self::respond(ItemIngest::fromUpload($user, $f));
    }

    public function clipboard(): void
    {
        $user = Auth::require();
        $f = $this->fileOrFail();
        self::respond(ItemIngest::fromUpload($user, $f, 'clipboard-'));
    }

    public function url(): void
    {
        $user = Auth::require();
        $url = trim(Request::inputString('url'));
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            Response::json(['ok' => false, 'error' => 'invalid_url'], 400);
        }
        try {
            \App\Services\HttpClient::assertSafeUrl($url);
        } catch (\RuntimeException $e) {
            Response::json(['ok' => false, 'error' => 'url_blocked', 'reason' => $e->getMessage()], 400);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'pzurl');
        if ($tmp === false) Response::json(['ok' => false, 'error' => 'fetch_failed'], 500);
        $maxBytes = min((int)Config::get('max_upload_mb', 10240) * 1024 * 1024, 256 * 1024 * 1024);
        $r = \App\Services\HttpClient::request('GET', $url, [
            'follow' => true,
            'timeout' => 60,
            'max_bytes' => $maxBytes,
        ]);
        if ($r['error'] === 'body_truncated') {
            @unlink($tmp);
            Response::json(['ok' => false, 'error' => 'too_large', 'max_bytes' => $maxBytes], 413);
        }
        if (!$r['ok'] || $r['body'] === '') {
            @unlink($tmp);
            Response::json(['ok' => false, 'error' => 'fetch_failed', 'status' => $r['status']], 400);
        }
        file_put_contents($tmp, $r['body']);
        $name = basename((string)(parse_url($url, PHP_URL_PATH) ?: 'remote'));
        if ($name === '' || $name === '/' || $name === '.') $name = 'remote';
        self::respond(ItemIngest::fromUpload($user, [
            'name' => $name,
            'type' => $r['content_type'] ?: 'application/octet-stream',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($r['body']),
        ], 'url-'));
    }

    private function fileOrFail(): array
    {
        $f = Request::file('file');
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::json(['ok' => false, 'error' => t('upload.failed', ['error' => 'upload_error'])], 400);
        }
        return $f;
    }

    public static function respond(array $out): void
    {
        if (empty($out['ok'])) Response::json($out, (int)($out['status'] ?? 400));
        Response::json($out);
    }
}
