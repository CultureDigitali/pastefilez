<?php
declare(strict_types=1);

$qs = static function (array $over = []): string {
    $q = array_merge($_GET, $over);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null) unset($q[$k]);
    }
    return '?' . http_build_query($q);
};
$filter = static function (string $k): string { return e((string)($_GET[$k] ?? '')); };
$actions = $actions ?? [];
?>
<h1 class="text-2xl font-bold mb-4">📋 <?= e(t('admin.audit.title')) ?></h1>

<form method="get" class="card p-4 mb-4 grid gap-3 md:grid-cols-6 items-end">
  <div>
    <label for="f-action"><?= e(t('admin.audit.action')) ?></label>
    <input class="input" id="f-action" name="action" list="action-list" value="<?= $filter('action') ?>" placeholder="login">
    <datalist id="action-list">
      <?php foreach ($actions as $a): ?>
        <option value="<?= e($a) ?>"></option>
      <?php endforeach; ?>
    </datalist>
  </div>
  <div>
    <label for="f-email"><?= e(t('admin.audit.email')) ?></label>
    <input class="input" id="f-email" type="email" name="email" value="<?= $filter('email') ?>">
  </div>
  <div>
    <label for="f-target"><?= e(t('admin.audit.target')) ?></label>
    <input class="input" id="f-target" name="target" value="<?= $filter('target') ?>">
  </div>
  <div>
    <label for="f-ip">IP</label>
    <input class="input" id="f-ip" name="ip" value="<?= $filter('ip') ?>">
  </div>
  <div class="flex gap-2">
    <button class="btn btn-primary"><?= e(t('admin.audit.filter')) ?></button>
    <a class="btn" href="<?= e(url('/admin/audit')) ?>"><?= e(t('common.reset')) ?></a>
  </div>
</form>

<div class="flex items-center justify-between mb-3 text-sm text-fg-2">
  <span><?= e(t('admin.audit.results', ['n' => $total, 'p' => $page, 'pages' => $pages])) ?></span>
  <a class="btn btn-sm" href="<?= e(url('/admin/audit/export') . ($filters ? '?' . http_build_query($filters) : '')) ?>">⬇ CSV</a>
</div>

<div class="card overflow-x-auto">
  <table class="t">
    <caption class="sr-only"><?= e(t('admin.audit.title')) ?></caption>
    <thead>
      <tr>
        <th scope="col">ID</th>
        <th scope="col"><?= e(t('admin.audit.when')) ?></th>
        <th scope="col"><?= e(t('admin.audit.user')) ?></th>
        <th scope="col"><?= e(t('admin.audit.action')) ?></th>
        <th scope="col"><?= e(t('admin.audit.target')) ?></th>
        <th scope="col">IP</th>
        <th scope="col">UA</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="7" class="text-center text-fg-2 py-6"><?= e(t('admin.audit.empty')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td class="text-xs"><?= e(date('d M Y H:i:s', (int)$r['created_at'])) ?></td>
          <td class="text-xs"><?= e($r['email'] ?? '—') ?></td>
          <td><span class="tag"><?= e($r['action']) ?></span></td>
          <td class="text-xs text-fg-2"><?= e($r['target'] ?? '') ?></td>
          <td class="font-mono text-xs"><?= e($r['ip'] ?? '') ?></td>
          <td class="text-xs text-fg-2 max-w-xs truncate"><?= e(mb_substr((string)$r['ua'], 0, 50)) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($pages > 1): ?>
  <nav class="flex items-center justify-center gap-2 mt-4" aria-label="<?= e(t('admin.audit.pagination')) ?>">
    <?php if ($page > 1): ?>
      <a class="btn btn-sm" href="<?= e(url('/admin/audit') . $qs(['page' => $page - 1])) ?>">‹ <?= e(t('admin.audit.prev')) ?></a>
    <?php endif; ?>
    <span class="text-sm text-fg-2"><?= e((string)$page) ?> / <?= e((string)$pages) ?></span>
    <?php if ($page < $pages): ?>
      <a class="btn btn-sm" href="<?= e(url('/admin/audit') . $qs(['page' => $page + 1])) ?>"><?= e(t('admin.audit.next')) ?> ›</a>
    <?php endif; ?>
  </nav>
<?php endif; ?>
