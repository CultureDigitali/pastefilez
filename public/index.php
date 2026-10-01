<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('html_errors', '0');

require __DIR__ . '/../app/Core/autoload.php';

ob_start(static function (string $buffer): string {
    if ($buffer === '' || (!str_contains($buffer, 'post_max_size') && !str_contains($buffer, 'upload_max_filesize'))) {
        return $buffer;
    }
    return (string)preg_replace(
        '#\s*<br\s*/?>\s*<b>(?:Warning|Fatal error|Notice|Deprecated)</b>:[^\n]*#i',
        '',
        $buffer
    );
});

use App\Core\Bootstrap;
use App\Core\Csrf;
use App\Core\Installer;
use App\Core\Router;
use App\Controllers\InstallController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\UploadController;
use App\Controllers\ItemController;
use App\Controllers\E2eShareController;
use App\Controllers\WebhookController;
use App\Controllers\ChatController;
use App\Controllers\SearchController;
use App\Controllers\ShareController;
use App\Controllers\SharePublicController;
use App\Controllers\SettingsController;
use App\Controllers\AdminController;
use App\Controllers\ApiKeyController;
use App\Controllers\AiTestController;
use App\Controllers\FolderController;
use App\Controllers\TagController;
use App\Controllers\JobController;
use App\Controllers\ThumbsController;
use App\Controllers\Api\AuthApiController;
use App\Controllers\Api\ItemsApiController;
use App\Controllers\Api\ClipboardApiController;
use App\Controllers\Api\SearchApiController;
use App\Controllers\Api\AiApiController;

Bootstrap::init();

if (!Installer::isInstalled() && !str_starts_with(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '', '/install')) {
    header('Location: ' . url('install'));
    exit;
}

$router = new Router();

$router->use(static function (string $method, string $path): void {
    $p = '/' . ltrim($path, '/');
    if (\App\Services\ApiThrottle::shouldThrottle($p)) \App\Services\ApiThrottle::enforce($method, $p);
});

$router->get('/install', [InstallController::class, 'index']);
$router->post('/install', [InstallController::class, 'submit']);

$router->get('/login', [AuthController::class, 'loginForm']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/login/totp', [AuthController::class, 'loginTotp']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/forgot', [AuthController::class, 'forgotForm']);
$router->post('/forgot', [AuthController::class, 'forgot']);
$router->get('/reset/{token}', [AuthController::class, 'resetForm']);
$router->post('/reset/{token}', [AuthController::class, 'reset']);

$router->get('/', [DashboardController::class, 'index']);

$router->get('/upload', [UploadController::class, 'index']);
$router->post('/upload', [UploadController::class, 'upload']);
$router->post('/upload/clipboard', [UploadController::class, 'clipboard']);
$router->post('/upload/url', [UploadController::class, 'url']);

$router->get('/items', [ItemController::class, 'index']);
$router->post('/items/bulk', [ItemController::class, 'bulk']);
$router->get('/trash', [ItemController::class, 'trash']);
$router->post('/trash/empty', [ItemController::class, 'emptyTrash']);
$router->get('/items/{id}', [ItemController::class, 'show']);
$router->post('/items/{id}', [ItemController::class, 'update']);
$router->post('/items/{id}/delete', [ItemController::class, 'delete']);
$router->post('/items/{id}/restore', [ItemController::class, 'restore']);
$router->post('/items/{id}/purge', [ItemController::class, 'purge']);
$router->post('/items/{id}/reanalyze', [ItemController::class, 'reanalyze']);
$router->get('/items/{id}/download', [ItemController::class, 'download']);
$router->get('/items/{id}/stream', [ItemController::class, 'stream']);
$router->get('/items/{id}/thumb', [ItemController::class, 'thumb']);
$router->get('/items/{id}/edit', [ItemController::class, 'edit']);
$router->post('/items/{id}/edit/save', [ItemController::class, 'editSave']);
$router->get('/thumbs/{name}', [ThumbsController::class, 'show']);

$router->post('/items/{id}/share', [ShareController::class, 'create']);
$router->post('/items/{id}/share/delete', [ShareController::class, 'delete']);
$router->post('/items/{id}/share-e2e', [E2eShareController::class, 'create']);
$router->post('/e2e-shares/{id}/revoke', [E2eShareController::class, 'revoke']);
$router->get('/s-e2e/{token}', [E2eShareController::class, 'page']);
$router->get('/s-e2e/{token}/payload', [E2eShareController::class, 'payload']);

$router->get('/settings/webhooks', [WebhookController::class, 'index']);
$router->post('/settings/webhooks/outgoing', [WebhookController::class, 'createOutgoing']);
$router->post('/settings/webhooks/incoming', [WebhookController::class, 'createIncoming']);
$router->post('/settings/webhooks/delete', [WebhookController::class, 'delete']);
$router->post('/settings/webhooks/test', [WebhookController::class, 'test']);
$router->post('/settings/webhooks/deliveries', [WebhookController::class, 'deliveries']);
$router->post('/hooks/{token}', [WebhookController::class, 'receive']);

$router->post('/folders', [FolderController::class, 'create']);
$router->post('/folders/{id}/delete', [FolderController::class, 'delete']);

$router->post('/tags', [TagController::class, 'create']);
$router->post('/items/{id}/tags', [TagController::class, 'attach']);
$router->post('/items/{id}/tags/{tagId}/delete', [TagController::class, 'detach']);

$router->get('/search', [SearchController::class, 'index']);
$router->get('/chat', [ChatController::class, 'index']);
$router->post('/chat/ask', [ChatController::class, 'ask']);

$router->get('/settings', [SettingsController::class, 'index']);
$router->post('/settings/profile', [SettingsController::class, 'profile']);
$router->post('/settings/password', [SettingsController::class, 'password']);
$router->post('/settings/totp', [SettingsController::class, 'totpEnable']);
$router->post('/settings/totp/disable', [SettingsController::class, 'totpDisable']);
$router->post('/settings/preferences', [SettingsController::class, 'preferences']);
$router->post('/settings/smtp', [SettingsController::class, 'smtp']);
$router->post('/settings/encryption', [SettingsController::class, 'encryption']);
$router->post('/settings/sessions/{id}/revoke', [SettingsController::class, 'revokeSession']);
$router->post('/settings/api-tokens', [SettingsController::class, 'createApiToken']);
$router->post('/settings/api-tokens/{id}/delete', [SettingsController::class, 'deleteApiToken']);

$router->get('/settings/ai', [ApiKeyController::class, 'index']);
$router->post('/settings/ai', [ApiKeyController::class, 'save']);
$router->post('/settings/ai/{id}/delete', [ApiKeyController::class, 'delete']);
$router->post('/settings/ai/{id}/test', [AiTestController::class, 'test']);
$router->post('/settings/ai/{id}/refresh-models', [AiTestController::class, 'refreshModels']);
$router->post('/settings/ai/model-default', [AiTestController::class, 'setDefault']);
$router->post('/settings/ai/ollama-base', [AiTestController::class, 'setOllamaBase']);

$router->get('/settings/cloud', [SettingsController::class, 'cloud']);
$router->post('/settings/cloud', [SettingsController::class, 'cloudSave']);
$router->get('/settings/cloud/{provider}/connect', [SettingsController::class, 'cloudConnect']);
$router->get('/settings/cloud/{provider}/callback', [SettingsController::class, 'cloudCallback']);
$router->post('/settings/cloud/{provider}/disconnect', [SettingsController::class, 'cloudDisconnect']);
$router->post('/settings/storage-backend', [SettingsController::class, 'storageBackend']);

$router->get('/admin/users', [AdminController::class, 'users']);
$router->post('/admin/users', [AdminController::class, 'createUser']);
$router->post('/admin/users/{id}', [AdminController::class, 'updateUser']);
$router->post('/admin/users/{id}/reset-password', [AdminController::class, 'resetPassword']);
$router->post('/admin/users/{id}/toggle', [AdminController::class, 'toggleUser']);
$router->get('/admin/audit', [AdminController::class, 'audit']);
$router->get('/admin/audit/export', [AdminController::class, 'auditExport']);
$router->get('/admin/roles', [AdminController::class, 'roles']);
$router->post('/admin/roles/{id}', [AdminController::class, 'updateRole']);
$router->get('/admin/jobs', [JobController::class, 'index']);

$router->get('/s/{token}', [SharePublicController::class, 'page']);
$router->post('/s/{token}', [SharePublicController::class, 'unlock']);
$router->get('/s/{token}/file', [SharePublicController::class, 'file']);
$router->get('/s/{token}/thumb', [SharePublicController::class, 'thumb']);

$router->post('/api/v1/auth/login', [AuthApiController::class, 'login']);
$router->post('/api/v1/auth/refresh', [AuthApiController::class, 'refresh']);
$router->get('/api/v1/items', [ItemsApiController::class, 'index']);
$router->post('/api/v1/items', [ItemsApiController::class, 'create']);
$router->get('/api/v1/items/{id}', [ItemsApiController::class, 'show']);
$router->delete('/api/v1/items/{id}', [ItemsApiController::class, 'delete']);
$router->post('/api/v1/clipboard', [ClipboardApiController::class, 'store']);
$router->get('/api/v1/search', [SearchApiController::class, 'query']);
$router->post('/api/v1/ai/analyze', [AiApiController::class, 'analyze']);
$router->get('/api/v1/models', [AiApiController::class, 'models']);

Csrf::validateRequest();
$router->dispatch();
