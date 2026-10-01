<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Role;
use App\Services\UploadGuard;
use App\Services\AuditQuery;

final class AdminController extends Controller
{
    public function users(): void
    {
        $user = Auth::requireGrant('users.read');
        $rows = Db::all('SELECT id, email, display_name, role, is_active, language, theme, storage_quota_mb, last_login_at FROM users ORDER BY id ASC');
        $this->view('admin/users', ['rows' => $rows]);
    }

    public function createUser(): void
    {
        $user = Auth::requireGrant('users.write');
        $email = strtolower(trim(Request::inputString('email')));
        $name = trim(Request::inputString('display_name'));
        $role = Request::inputString('role', 'user');
        $quota = (int)Request::input('storage_quota_mb', 10240);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { Session::set('flash_error', t('install.error_email')); Response::redirect('/admin/users'); }
        if (Db::first('SELECT id FROM users WHERE email = ?', [$email])) { Session::set('flash_error', 'email_exists'); Response::redirect('/admin/users'); }
        $pwd = Crypto::randomToken(12) . 'Aa1!';
        $id = Db::insert('users', [
            'email' => $email,
            'password_hash' => Crypto::hash($pwd),
            'role' => in_array($role, ['user', 'admin'], true) ? $role : 'user',
            'display_name' => $name,
            'language' => 'it',
            'theme' => 'dark',
            'storage_backend' => 'local',
            'storage_quota_mb' => max(1, $quota),
            'is_active' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        Audit::log('admin.user.create', 'user:' . $id);
        Session::set('flash_success', t('admin.users.reset_pwd_done', ['pwd' => $pwd]) . ' · ' . $email);
        Response::redirect('/admin/users');
    }

    public function updateUser(array $params): void
    {
        $actor = Auth::requireGrant('users.write');
        $id = (int)($params['id'] ?? 0);
        $row = Db::first('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$row) Response::notFound();
        $name = UploadGuard::safeName(Request::inputString('display_name'));
        $quota = (int)Request::input('storage_quota_mb', $row['storage_quota_mb']);
        $fields = [
            'display_name' => $name,
            'storage_quota_mb' => max(1, min($quota, 1048576)),
            'updated_at' => time(),
        ];
        $requestedRole = Role::normalize(Request::inputString('role'));
        if ($requestedRole !== null && $requestedRole !== (string)$row['role']) {
            self::assertRoleChangeAllowed($actor, $row, $requestedRole);
            $fields['role'] = $requestedRole;
        }
        Db::update('users', $fields, 'id = ?', [$id]);
        Audit::log('admin.user.update', 'user:' . $id, ['role_changed' => isset($fields['role'])]);
        Session::set('flash_success', t('admin.users.updated'));
        Response::redirect('/admin/users');
    }

    private static function assertRoleChangeAllowed(array $actor, array $row, string $role): void
    {
        $actorIsOwner = (string)$actor['role'] === 'owner';
        $targetIsOwner = (string)$row['role'] === 'owner';
        $roleIsOwner = $role === 'owner';
        if (!$actorIsOwner && ($role === 'owner' || $targetIsOwner)) {
            Response::json(['ok' => false, 'error' => 'owner_role_requires_owner'], 403);
        }
        $wasAdmin = Auth::isAdminUser($row);
        $willBeAdmin = Auth::isAdminRole($role);
        if ((int)$row['id'] === (int)$actor['id'] && !$willBeAdmin) {
            Session::set('flash_error', 'cannot_remove_own_admin');
            Response::redirect('/admin/roles');
        }
        if ($wasAdmin && !$willBeAdmin) {
            $others = 0;
            foreach (Db::all('SELECT role, is_active FROM users WHERE id <> ?', [(int)$row['id']]) as $u) {
                if ((int)$u['is_active'] === 1 && (string)$u['role'] === 'owner') $others++;
            }
            if ($others === 0) {
                Session::set('flash_error', 'last_admin');
                Response::redirect('/admin/roles');
            }
        }
        if ($targetIsOwner && !$roleIsOwner) {
            $others = 0;
            foreach (Db::all('SELECT role, is_active FROM users WHERE id <> ?', [(int)$row['id']]) as $u) {
                if ((int)$u['is_active'] === 1 && (string)$u['role'] === 'owner') $others++;
            }
            if ($others === 0) {
                Session::set('flash_error', 'last_admin');
                Response::redirect('/admin/roles');
            }
        }
    }

    public function resetPassword(array $params): void
    {
        $user = Auth::requireGrant('users.write');
        $id = (int)($params['id'] ?? 0);
        $pwd = Crypto::randomToken(12) . 'Aa1!';
        Db::update('users', [
            'password_hash' => Crypto::hash($pwd),
            'failed_attempts' => 0,
            'locked_until' => null,
            'updated_at' => time(),
        ], 'id = ?', [$id]);
        Audit::log('admin.user.reset', 'user:' . $id);
        Session::set('flash_success', t('admin.users.reset_pwd_done', ['pwd' => $pwd]));
        Response::redirect('/admin/users');
    }

    public function toggleUser(array $params): void
    {
        $user = Auth::requireGrant('users.write');
        $id = (int)($params['id'] ?? 0);
        $row = Db::first('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$row) Response::notFound();
        if ((int)$row['id'] === (int)$user['id']) { Session::set('flash_error', 'cannot_disable_self'); Response::redirect('/admin/users'); }
        $newState = (int)$row['is_active'] === 1 ? 0 : 1;
        Db::update('users', ['is_active' => $newState, 'updated_at' => time()], 'id = ?', [$id]);
        Audit::log('admin.user.toggle', 'user:' . $id, ['active' => $newState]);
        Session::set('flash_success', $newState ? t('admin.users.enabled') : t('admin.users.disabled'));
        Response::redirect('/admin/users');
    }

    public function audit(): void
    {
        Auth::requireGrant('audit.read');
        $filters = [
            'action' => Request::query('action', ''),
            'email' => Request::query('email', ''),
            'target' => Request::query('target', ''),
            'ip' => Request::query('ip', ''),
            'from' => Request::query('from', 0),
            'to' => Request::query('to', 0),
        ];
        $errors = AuditQuery::validate($filters);
        if ($errors !== []) {
            Session::set('flash_error', 'audit.filter_invalid: ' . implode(',', $errors));
            Response::json(['ok' => false, 'error' => 'invalid_filter', 'fields' => $errors], 400);
        }
        $page = AuditQuery::page($filters, (int)Request::query('page', 1), (int)Request::query('per_page', AuditQuery::PER_PAGE));
        $this->view('admin/audit', $page + ['actions' => AuditQuery::distinctActions()]);
    }

    public function auditExport(): void
    {
        Auth::requireGrant('audit.export');
        $filters = [
            'action' => Request::query('action', ''),
            'email' => Request::query('email', ''),
            'target' => Request::query('target', ''),
            'ip' => Request::query('ip', ''),
            'from' => Request::query('from', 0),
            'to' => Request::query('to', 0),
        ];
        $errors = AuditQuery::validate($filters);
        if ($errors !== []) Response::json(['ok' => false, 'error' => 'invalid_filter', 'fields' => $errors], 400);
        $rows = AuditQuery::export($filters, (int)Request::query('limit', 5000));
        $csv = AuditQuery::toCsv($rows);
        Audit::log('admin.audit.export', 'audit', ['rows' => count($rows)]);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="audit-' . date('Ymd-His') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo "\xEF\xBB\xBF" . $csv;
        exit;
    }

    public function roles(): void
    {
        $user = Auth::requireGrant('roles.read');
        $defs = Role::all();
        $this->view('admin/roles', [
            'defs' => $defs,
            'users' => Db::all('SELECT id, email, role FROM users ORDER BY id ASC'),
        ]);
    }

    public function updateRole(array $params): void
    {
        $admin = Auth::requireGrant('roles.write');
        $id = (int)($params['id'] ?? 0);
        $row = Db::first('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$row) Response::notFound();
        $role = Role::normalize(Request::inputString('role'));
        if ($role === null) {
            Session::set('flash_error', 'invalid_role');
            Response::redirect('/admin/roles');
        }
        self::assertRoleChangeAllowed($admin, $row, $role);
        Db::update('users', ['role' => $role, 'updated_at' => time()], 'id = ?', [$id]);
        Audit::log('admin.role.change', 'user:' . $id, ['from' => $row['role'], 'to' => $role]);
        Session::set('flash_success', t('common.save'));
        Response::redirect('/admin/roles');
    }
}

