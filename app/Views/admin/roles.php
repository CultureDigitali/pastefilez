<?php
declare(strict_types=1);

$defs = $defs ?? [];
$grants = $grants ?? [];
$users = $users ?? [];
$allGrants = \App\Models\Role::grantsMatrix();
?>
<h1 class="text-2xl font-bold mb-4">🛡 <?= e(t('admin.roles.title')) ?></h1>

<div class="card p-5 mb-4 overflow-x-auto">
  <table class="t">
    <caption class="text-left text-sm text-fg-2 mb-2"><?= e(t('admin.roles.matrix')) ?></caption>
    <thead>
      <tr>
        <th scope="col"><?= e(t('admin.roles.grant')) ?></th>
        <?php foreach ($defs as $key => $d): ?>
          <th scope="col" class="text-center"><?= e($d['label']) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td class="text-xs text-fg-2"><?= e(t('admin.roles.admin_area')) ?></td>
        <?php foreach ($defs as $key => $d): ?>
          <td class="text-center"><?= $d['admin'] ? '✓' : '—' ?></td>
        <?php endforeach; ?>
      </tr>
      <?php foreach ($allGrants as $g): ?>
        <tr>
          <td class="font-mono text-xs"><?= e($g) ?></td>
          <?php foreach ($defs as $key => $d): ?>
            <td class="text-center"><?= in_array('*', $d['grants'], true) || in_array($g, $d['grants'], true) ? '✓' : '—' ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card p-5 overflow-x-auto">
  <h2 class="font-semibold mb-3"><?= e(t('admin.roles.assign')) ?></h2>
  <table class="t">
    <thead>
      <tr>
        <th scope="col"><?= e(t('admin.users.email')) ?></th>
        <th scope="col"><?= e(t('admin.roles.role')) ?></th>
        <th scope="col"><?= e(t('admin.roles.description')) ?></th>
        <th scope="col"></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td class="text-sm"><?= e($u['email']) ?></td>
          <form method="post" action="<?= e(url('/admin/roles/' . (int)$u['id'])) ?>" class="contents">
            <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
            <td>
              <label class="sr-only" for="role-<?= (int)$u['id'] ?>"><?= e(t('admin.roles.role')) ?></label>
              <select class="input" id="role-<?= (int)$u['id'] ?>" name="role">
                <?php foreach ($defs as $key => $d): ?>
                  <option value="<?= e($key) ?>" <?= $u['role'] === $key ? 'selected' : '' ?>><?= e($d['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td class="text-xs text-fg-2"><?= e($defs[$u['role']]['desc'] ?? t('admin.roles.unknown')) ?></td>
            <td><button class="btn btn-sm btn-primary" type="submit"><?= e(t('common.save')) ?></button></td>
          </form>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
