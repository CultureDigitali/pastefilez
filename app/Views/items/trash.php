<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold"><?= e(t('trash.title')) ?> <span class="text-sm text-fg-2 font-normal">· <?= count($items) ?></span></h1>
  <div class="flex gap-2">
    <a href="/items" class="btn"><?= e(t('trash.back')) ?></a>
    <?php if (count($items) > 0): ?>
      <button id="empty-trash" class="btn btn-danger"><?= e(t('trash.empty_all')) ?></button>
    <?php endif; ?>
  </div>
</div>

<?php if (empty($items)): ?>
  <div class="card p-8 text-center text-fg-2"><?= e(t('trash.empty')) ?></div>
<?php else: ?>
  <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
    <?php foreach ($items as $it): ?>
      <div class="card overflow-hidden flex flex-col" data-item-id="<?= (int)$it['id'] ?>">
        <a href="/items/<?= (int)$it['id'] ?>" class="thumb aspect-square" style="background-image:url(/items/<?= (int)$it['id'] ?>/thumb)" title="<?= e($it['original_name']) ?>"></a>
        <div class="p-2 flex-1 flex flex-col gap-1">
          <div class="truncate text-xs font-medium" title="<?= e($it['original_name']) ?>"><?= e($it['original_name']) ?></div>
          <div class="text-xs text-fg-2"><?= e(\bytes_to_human((int)$it['size'])) ?> · <?= e(date('d M', (int)$it['deleted_at'])) ?></div>
          <div class="flex gap-1 mt-auto pt-1">
            <button class="btn btn-sm btn-primary flex-1 js-restore" data-id="<?= (int)$it['id'] ?>"><?= e(t('trash.restore')) ?></button>
            <button class="btn btn-sm btn-danger js-purge" data-id="<?= (int)$it['id'] ?>">×</button>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  function csrf() {
    var m = document.querySelector('meta[name="csrf"]');
    return m ? m.getAttribute('content') : '';
  }
  document.querySelectorAll('.js-restore').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = b.getAttribute('data-id');
      var fd = new FormData();
      fd.append('_csrf', csrf());
      fetch('/items/' + id + '/restore', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j.ok) location.reload(); else alert(j.error || 'error'); });
    });
  });
  document.querySelectorAll('.js-purge').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!confirm('<?= e(t('trash.purge_confirm')) ?>')) return;
      var id = b.getAttribute('data-id');
      var fd = new FormData();
      fd.append('_csrf', csrf());
      fetch('/items/' + id + '/purge', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j.ok) location.reload(); else alert(j.error || 'error'); });
    });
  });
  var eb = document.getElementById('empty-trash');
  if (eb) eb.addEventListener('click', function () {
    if (!confirm('<?= e(t('trash.empty_confirm')) ?>')) return;
    var fd = new FormData();
    fd.append('_csrf', csrf());
    fetch('/trash/empty', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j.ok) location.reload(); else alert(j.error || 'error'); });
  });
});
</script>
