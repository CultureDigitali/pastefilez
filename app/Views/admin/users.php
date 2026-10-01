<h1 class="text-2xl font-bold mb-4">👥 <?= e(t('admin.users.title')) ?></h1>

<form method="post" action="<?= e(url('/admin/users')) ?>" class="card p-4 mb-4 grid md:grid-cols-5 gap-2 items-end">
  <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
  <div class="md:col-span-2"><label>Email</label><input class="input" type="email" name="email" id="nu-email" required aria-label="Email"></div>
  <div><label for="f-display-name-2"><?= e(t('install.name')) ?></label><input class="input" name="display_name" id="f-display-name-2"></div>
  <div><label for="f-role-3"><?= e(t('admin.users.role')) ?></label>
    <select class="input" name="role" id="f-role-3">
      <option value="user"><?= e(t('admin.users.role.user')) ?></option>
      <option value="admin"><?= e(t('admin.users.role.admin')) ?></option>
    </select>
  </div>
  <div><label for="nu-quota"><?= e(t('admin.users.quota_mb')) ?></label><input class="input" type="number" name="storage_quota_mb" id="nu-quota" value="10240" min="1"></div>
  <button class="btn btn-primary md:col-span-5 justify-center"><?= e(t('admin.users.create')) ?></button>
</form>

<div class="card overflow-hidden">
  <table class="t">
    <thead><tr><th scope="col">ID</th><th scope="col">Email</th><th scope="col"><?= e(t('install.name')) ?></th><th scope="col"><?= e(t('admin.users.role')) ?></th><th scope="col">Quota</th><th scope="col">Stato</th><th scope="col">Last login</th><th scope="col"></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $u): ?>
      <tr>
        <td><?= (int)$u['id'] ?></td>
        <td><?= e($u['email']) ?></td>
        <td><?= e($u['display_name'] ?? '') ?></td>
        <td>
          <form method="post" action="<?= e(url('/admin/users/' . (int)$u['id'])) ?>" class="inline">
            <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
            <input type="hidden" name="display_name" value="<?= e($u['display_name'] ?? '') ?>">
            <input type="hidden" name="storage_quota_mb" value="<?= (int)$u['storage_quota_mb'] ?>">
            <select class="input" name="role" onchange="this.form.submit()" aria-label="Ruolo" id="f-role-5">
              <option value="user" <?= $u['role']==='user'?'selected':'' ?>><?= e(t('admin.users.role.user')) ?></option>
              <option value="admin" <?= $u['role']==='admin'?'selected':'' ?>><?= e(t('admin.users.role.admin')) ?></option>
            </select>
          </form>
        </td>
        <td><?= e((int)$u['storage_quota_mb']) ?> MB</td>
        <td>
          <?php if ((int)$u['is_active']===1): ?>
            <span class="tag tag-emerald"><?= e(t('admin.users.active')) ?></span>
          <?php else: ?>
            <span class="tag tag-rose"><?= e(t('admin.users.inactive')) ?></span>
          <?php endif; ?>
        </td>
        <td class="text-xs text-fg-2"><?= $u['last_login_at'] ? e(date('d M H:i', (int)$u['last_login_at'])) : e(t('common.never')) ?></td>
        <td class="flex gap-1">
          <form method="post" action="<?= e(url('/admin/users/' . (int)$u['id'] . '/reset-password')) ?>" onsubmit="return PZ.confirm()">
            <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
            <button class="btn btn-sm"><?= e(t('admin.users.reset_pwd')) ?></button>
          </form>
          <form method="post" action="<?= e(url('/admin/users/' . (int)$u['id'] . '/toggle')) ?>" onsubmit="return PZ.confirm()">
            <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
            <button class="btn btn-sm"><?= ((int)$u['is_active']===1) ? '🚫' : '✓' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
