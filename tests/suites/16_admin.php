<?php
declare(strict_types=1);

use App\Core\Db;
use App\Models\Role;
use App\Services\AuditQuery;
use Tests\T;

T::group('admin/roles-model');

T::ok(Role::exists('admin'), 'admin role exists');
T::ok(Role::exists('owner'), 'owner role exists');
T::ok(Role::exists('operator'), 'operator role exists');
T::ok(Role::exists('user'), 'user role exists');
T::ok(!Role::exists('superuser'), 'an arbitrary role does not exist');
T::ok(!Role::exists(''), 'the empty role does not exist');
T::ok(!Role::exists('ADMIN'), 'role lookup is case sensitive by definition');

T::eq('admin', Role::normalize('admin'), 'normalize returns a known role');
T::eq('admin', Role::normalize('  ADMIN  '), 'normalize trims and lowercases');
T::eq(null, Role::normalize('nope'), 'normalize rejects an unknown role');
T::eq(null, Role::normalize(''), 'normalize rejects an empty role');
T::eq(null, Role::normalize('admin; drop table users'), 'normalize rejects an injection attempt');

T::ok(Role::isAdmin('admin'), 'admin is an admin role');
T::ok(Role::isAdmin('owner'), 'owner is an admin role');
T::ok(!Role::isAdmin('operator'), 'operator is not an admin role');
T::ok(!Role::isAdmin('user'), 'user is not an admin role');
T::ok(!Role::isAdmin(null), 'null is not an admin role');
T::ok(!Role::isAdmin('bogus'), 'an unknown role is not an admin role');

T::ok(in_array('*', Role::grants('owner'), true), 'owner holds the wildcard grant');
T::ok(in_array('users.write', Role::grants('admin'), true), 'admin can write users');
T::ok(in_array('roles.write', Role::grants('admin'), true), 'admin can write roles');
T::ok(!in_array('users.write', Role::grants('operator'), true), 'operator cannot write users');
T::ok(in_array('users.read', Role::grants('operator'), true), 'operator can read users');
T::eq([], Role::grants('user'), 'a plain user holds no admin grants');
T::eq([], Role::grants('bogus'), 'an unknown role holds no grants');

T::ok(\App\Core\Auth::can('owner', 'anything.at.all'), 'owner can do anything');
T::ok(\App\Core\Auth::can('admin', 'audit.export'), 'admin can export the audit');
T::ok(!\App\Core\Auth::can('operator', 'audit.export'), 'operator cannot export the audit');
T::ok(!\App\Core\Auth::can('user', 'users.read'), 'a plain user cannot read other users');
T::ok(!\App\Core\Auth::can(null, 'users.read'), 'a null role has no grants');

$matrix = Role::grantsMatrix();
T::ok(count($matrix) > 3, 'the permission matrix is populated');
T::eq($matrix, array_values(array_unique($matrix)), 'the matrix has no duplicate grants');
$sorted = $matrix; sort($sorted);
T::eq($sorted, $matrix, 'the matrix is sorted');

T::group('admin/audit-query');

$owner = mkUser('aq-owner@x.test', 'owner');
$other = mkUser('aq-other@x.test', 'user');
for ($i = 0; $i < 12; $i++) {
    Db::insert('audit_log', [
        'user_id' => $i % 3 === 0 ? $other : $owner,
        'action' => $i % 4 === 0 ? 'login' : 'item.upload',
        'target' => $i === 5 ? 'item:5' : ($i === 6 ? '100%_x' : ($i % 2 === 0 ? 'item:' . $i : 'user:' . $other)),
        'ip' => '203.0.113.' . ($i % 5),
        'ua' => 'agent/' . $i,
        'meta' => json_encode(['i' => $i]),
        'created_at' => time() - $i * 60,
    ]);
}
Db::insert('audit_log', ['user_id' => $owner, 'action' => 'password.reset', 'target' => 'user:1', 'ip' => '198.51.100.1', 'ua' => 'x', 'meta' => null, 'created_at' => time() - 86400]);
Db::insert('audit_log', ['user_id' => $owner, 'action' => 'quote",injected', 'target' => "line1\nline2", 'ip' => '198.51.100.2', 'ua' => 'x', 'meta' => null, 'created_at' => time() - 86400]);

$all = AuditQuery::page([], 1, 5);
T::eq(5, count($all['rows']), 'per_page is honoured');
T::ok($all['total'] >= 15, 'total counts every row');
T::ok($all['pages'] >= 3, 'pages is computed from the total');
T::eq(1, $all['page'], 'the first page is page 1');

$last = AuditQuery::page([], 9999, 5);
T::eq($last['pages'], $last['page'], 'an out-of-range page clamps to the last page');
T::ok(count($last['rows']) <= 5, 'the clamped page still respects per_page');

$byAction = AuditQuery::page(['action' => 'login'], 1, 50);
T::ok($byAction['total'] > 0, 'action filter matches');
foreach ($byAction['rows'] as $r) T::eq('login', $r['action'], 'every returned row matches the action filter');

$byEmail = AuditQuery::page(['email' => 'AQ-OTHER@X.TEST'], 1, 50);
T::ok($byEmail['total'] > 0, 'email filter is case-insensitive');
foreach ($byEmail['rows'] as $r) T::eq('aq-other@x.test', $r['email'], 'every row matches the email filter');

$byIp = AuditQuery::page(['ip' => '198.51.100.1'], 1, 50);
T::eq(1, $byIp['total'], 'ip filter matches exactly');

$byTarget = AuditQuery::page(['target' => 'item:5'], 1, 50);
T::eq(1, $byTarget['total'], 'target substring filter matches');
T::eq(0, AuditQuery::page(['target' => 'item:%'], 1, 50)['total'], 'a LIKE wildcard in the target filter is escaped, not honoured');
T::eq(0, AuditQuery::page(['target' => 'item:_'], 1, 50)['total'], 'a LIKE single-char wildcard in the target filter is escaped');

$byDate = AuditQuery::page(['from' => time() - 120, 'to' => time()], 1, 50);
foreach ($byDate['rows'] as $r) {
    T::ok((int)$r['created_at'] <= time() && (int)$r['created_at'] >= time() - 120, 'date range is respected');
}

$hostile = ['action' => "'; DROP TABLE audit_log; --", 'ip' => 'not-an-ip', 'email' => str_repeat('a', 500)];
T::ok(count(AuditQuery::validate($hostile)) >= 2, 'hostile filters are reported as invalid rather than silently dropped');
T::ok(in_array('invalid_action', AuditQuery::validate($hostile), true), 'an SQL injection attempt in the action filter is rejected');
T::ok(in_array('invalid_ip', AuditQuery::validate($hostile), true), 'a non-IP is rejected');
T::ok(in_array('invalid_email', AuditQuery::validate($hostile), true), 'an over-long email is rejected');
T::eq([], AuditQuery::validate([]), 'empty filters are valid');
T::eq([], AuditQuery::validate(['action' => 'login', 'ip' => '1.2.3.4', 'email' => 'a@b.c']), 'well-formed filters are accepted');
T::ok(in_array('invalid_range', AuditQuery::validate(['from' => time(), 'to' => time() - 100]), true), 'an inverted date range is rejected');
$bad = AuditQuery::page($hostile, 1, 50);
T::ok($bad['total'] >= 0, 'a hostile filter set never errors out');
T::ok((int)Db::value('SELECT COUNT(*) FROM sqlite_master WHERE type = ? AND name = ?', ['table', 'audit_log']) === 1, 'the audit table survived the injection attempt');

$likeInj = AuditQuery::page(['target' => '%'], 1, 50);
T::eq(1, $likeInj['total'], 'a bare percent matches the row that literally contains a percent, not all rows');
T::eq(1, AuditQuery::page(['target' => '100%_x'], 1, 50)['total'], 'a literal percent in the data is findable');

T::group('admin/audit-csv');

$rows = AuditQuery::page([], 1, 100)['rows'];
$csv = AuditQuery::toCsv($rows);
$header = strtok($csv, "\n");
T::eq('id,created_at,user_email,action,target,ip,user_agent,meta', $header, 'the CSV header is stable');
T::eq(1, substr_count($csv, 'quote"",injected'), 'exactly one injected record survives in the CSV');
T::eq(true, str_starts_with($csv, 'id,created_at'), 'the header is the first line');

$quoted = AuditQuery::toCsv([[
    'id' => 1, 'created_at' => time(), 'email' => 'a@b.c',
    'action' => 'quote",injected', 'target' => "line1\nline2", 'ip' => '1.2.3.4', 'ua' => 'ua', 'meta' => null,
]]);
T::contains($quoted, '"quote"",injected"', 'embedded quotes are doubled');
T::eq(2, substr_count($quoted, "\n") - 1, 'an embedded newline is quoted, not a new record');
T::contains($quoted, '"line1' . "\n" . 'line2"', 'the embedded newline is preserved inside the quoted field');

T::eq('plain', AuditQuery::cell('plain'), 'a plain value is not quoted');
T::eq('"a,b"', AuditQuery::cell('a,b'), 'a comma forces quoting');
T::eq('a b', AuditQuery::cell('a b'), 'a bare space needs no quoting in CSV');

$exported = AuditQuery::export(['action' => 'password.reset'], 10);
T::eq(1, count($exported), 'export honours the filters');
T::eq('password.reset', $exported[0]['action'], 'export returns the filtered action');
T::ok(count(AuditQuery::export([], 1)) <= 1, 'export honours the limit');
T::eq(1, count(AuditQuery::export([], 0)), 'a zero limit is clamped to 1, never a negative offset');
T::ok(count(AuditQuery::export([], -5)) >= 1, 'a negative limit is clamped too');

T::group('admin/audit-helpers');

T::ok(in_array('login', AuditQuery::distinctActions(), true), 'distinctActions lists recorded actions');
$actions = AuditQuery::distinctActions();
$sorted = $actions; sort($sorted);
T::eq($sorted, $actions, 'distinctActions is sorted');

T::eq(['action' => 'login'], AuditQuery::sanitize(['action' => ' login ', 'email' => '', 'bogus' => 'x']), 'sanitize keeps only known keys and drops empties');
T::eq(190, mb_strlen(AuditQuery::sanitize(['action' => str_repeat('l', 500)])['action']), 'sanitize bounds the length to 190');
T::eq('100\\%', AuditQuery::escapeLike('100%'), 'percent signs are escaped for LIKE');
T::eq('a\\_b', AuditQuery::escapeLike('a_b'), 'underscores are escaped for LIKE');
T::eq('back\\\\slash', AuditQuery::escapeLike('back\\slash'), 'backslashes are escaped for LIKE');
T::contains((string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/AuditQuery.php'), "ESCAPE '", 'every LIKE filter declares an ESCAPE clause');

T::group('admin/route-and-view-wiring');

$routes = (string)file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
T::contains($routes, "'/admin/audit/export'", 'the CSV export route exists');
T::contains($routes, "'/admin/roles'", 'the roles page route exists');
T::contains($routes, "AdminController::class, 'auditExport'", 'the export is wired to the controller');
T::contains($routes, "AdminController::class, 'updateRole'", 'the role update is wired to the controller');

$ctl = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/AdminController.php');
T::eq(0, preg_match('/Auth::requireAdmin\(\)/', $ctl), 'the literal requireAdmin() string compare is gone from AdminController');
foreach (['users.read', 'users.write', 'roles.read', 'roles.write', 'audit.read', 'audit.export'] as $grant) {
    T::contains($ctl, "requireGrant('{$grant}')", "the {$grant} grant is enforced");
}
T::ok(substr_count($ctl, 'requireGrant(') >= 8, 'every admin action is grant-gated');
T::contains($ctl, 'assertRoleChangeAllowed', 'role changes go through one shared guard');
T::ok(substr_count($ctl, 'assertRoleChangeAllowed(') === 3, 'the guard is defined once and called from both entry points');
T::contains($ctl, "'cannot_remove_own_admin'", 'an admin cannot demote themselves');
T::contains($ctl, "'last_admin'", 'the last admin cannot be demoted');
T::contains($ctl, "'invalid_role'", 'an unknown role is rejected');
T::contains($ctl, "Audit::log('admin.audit.export'", 'the export itself is audited');
T::contains($ctl, "Audit::log('admin.role.change'", 'role changes are audited');
T::contains($ctl, "Audit::log('admin.user.create'", 'user creation is audited');

$view = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/audit.php');
T::contains($view, 'name="action"', 'the action filter field exists');
T::contains($view, 'name="email"', 'the email filter field exists');
T::contains($view, 'name="target"', 'the target filter field exists');
T::contains($view, 'name="ip"', 'the ip filter field exists');
T::contains($view, '<label for="f-action"', 'every filter input has an associated label');
T::contains($view, 'scope="col"', 'the audit table uses th scope');
T::contains($view, 'aria-label=', 'pagination is labelled');
T::contains($view, 'class="sr-only"', 'there is a screen-reader-only caption');
T::contains($view, '/admin/audit/export', 'the export link is present');

$rolesView = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/roles.php');
T::contains($rolesView, 'Role::grantsMatrix()', 'the roles view renders the permission matrix');
T::contains($rolesView, 'name="role"', 'the role selector exists');
T::contains($rolesView, 'class="sr-only"', 'the role selector has a screen-reader label');
T::contains($rolesView, '/admin/roles/', 'each row posts to the role update route');

T::group('admin/roles-page-renders');

$ctlSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/AdminController.php');
T::contains($ctlSrc, '$defs = Role::all();', 'roles() reads the role definitions');
T::notContains($ctlSrc, 'Role::grants()', 'roles() does not call grants() with no argument');
T::notContains($ctlSrc, '\App\Models\Role::all()', 'roles() uses the imported class');
$rolesViewSrc = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/roles.php');
T::notContains($rolesViewSrc, '$grants[', 'the view does not reference an undefined $grants variable');
foreach (['$defs', '$users', '$csrf_token'] as $var) {
    T::contains($rolesViewSrc, $var, "the view receives {$var}");
}
