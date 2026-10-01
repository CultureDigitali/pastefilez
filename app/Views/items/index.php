<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold"><?= e(t('nav.items')) ?> <span class="text-sm text-fg-2 font-normal">· <?= e((string)count($items)) ?></span></h1>
  <div class="flex gap-1 text-sm">
    <a class="btn btn-sm <?= !$type?'btn-primary':'' ?>" href="<?= e(url('/items')) ?>">All</a>
    <a class="btn btn-sm <?= $type==='image'?'btn-primary':'' ?>" href="?type=image"><?= e(t('items.type.image')) ?></a>
    <a class="btn btn-sm <?= $type==='video'?'btn-primary':'' ?>" href="?type=video"><?= e(t('items.type.video')) ?></a>
    <a class="btn btn-sm <?= $type==='audio'?'btn-primary':'' ?>" href="?type=audio"><?= e(t('items.type.audio')) ?></a>
    <a class="btn btn-sm <?= $type==='pdf'?'btn-primary':'' ?>" href="?type=pdf">PDF</a>
    <a class="btn btn-sm" href="<?= e(url('/trash')) ?>" title="<?= e(t('trash.title')) ?>">🗑</a>
  </div>
</div>
<?php if (!$items): ?>
  <div class="card p-8 text-center text-fg-2"><?= e(t('dashboard.no_items')) ?></div>
<?php else: ?>
  <div class="grid-items" id="items-grid">
    <?php foreach ($items as $it): ?>
      <div class="space-y-2 relative" data-item-id="<?= (int)$it['id'] ?>">
        <label class="absolute top-1 left-1 z-10 w-6 h-6 rounded bg-black/60 border border-white/30 flex items-center justify-center cursor-pointer opacity-0 hover:opacity-100 transition js-pick">
          <input type="checkbox" class="js-bulk-cb w-4 h-4" value="<?= (int)$it['id'] ?>">
        </label>
        <a href="<?= e(url('/items/' . (int)$it['id'])) ?>" class="thumb" title="<?= e($it['original_name']) ?>">
          <?php if ($it['thumbnail_path']): ?>
            <img loading="lazy" src="<?= e(url('/items/' . (int)$it['id'] . '/thumb')) ?>" alt="">
          <?php else: ?>
            <span class="icon">📁</span>
          <?php endif; ?>
          <?php if ($it['is_public']): ?><span class="badge"><?= $it['share_password_hash'] ? '🔒' : '🌐' ?></span><?php endif; ?>
        </a>
        <div class="text-xs truncate" title="<?= e($it['original_name']) ?>"><?= e($it['original_name']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if (count($items) === 60): ?>
    <div class="text-center mt-6"><a class="btn" href="?page=<?= e((string)($page+1)) ?><?= $type?'&type='.e($type):'' ?>">→</a></div>
  <?php endif; ?>

  <div id="bulk-bar" class="hidden fixed bottom-6 left-1/2 -translate-x-1/2 z-40 card p-3 flex items-center gap-2 shadow-lg">
    <span class="text-sm"><b id="bulk-count">0</b> <?= e(t('items.bulk.selected')) ?></span>
    <button class="btn btn-sm js-bulk-act" data-action="reanalyze">🔄 <?= e(t('items.bulk.reanalyze')) ?></button>
    <button class="btn btn-sm btn-danger js-bulk-act" data-action="delete">🗑 <?= e(t('items.bulk.delete')) ?></button>
    <button class="btn btn-sm" id="bulk-cancel">✕</button>
  </div>
<?php endif; ?>

<script>
(function () {
  var cbs = document.querySelectorAll('.js-bulk-cb');
  var bar = document.getElementById('bulk-bar');
  var count = document.getElementById('bulk-count');
  var cancel = document.getElementById('bulk-cancel');
  function update() {
    var n = document.querySelectorAll('.js-bulk-cb:checked').length;
    count.textContent = n;
    if (n > 0) { bar.classList.remove('hidden'); bar.classList.add('flex'); }
    else { bar.classList.add('hidden'); bar.classList.remove('flex'); }
    document.querySelectorAll('.js-pick').forEach(function (l) { l.style.opacity = n > 0 ? '1' : ''; });
  }
  cbs.forEach(function (cb) {
    cb.addEventListener('change', update);
    cb.addEventListener('click', function (e) { e.stopPropagation(); });
  });
  document.querySelectorAll('.js-pick').forEach(function (l) {
    l.addEventListener('click', function (e) { e.stopPropagation(); e.preventDefault(); });
  });
  if (cancel) cancel.addEventListener('click', function () {
    document.querySelectorAll('.js-bulk-cb').forEach(function (cb) { cb.checked = false; });
    update();
  });
  function csrf() {
    var m = document.querySelector('meta[name="csrf"]');
    return m ? m.getAttribute('content') : '';
  }
  document.querySelectorAll('.js-bulk-act').forEach(function (b) {
    b.addEventListener('click', function () {
      var ids = Array.from(document.querySelectorAll('.js-bulk-cb:checked')).map(function (cb) { return cb.value; });
      if (ids.length === 0) return;
      var action = b.getAttribute('data-action');
      if (action === 'delete' && !confirm('<?= e(t('items.bulk.confirm_delete')) ?>')) return;
      var fd = new FormData();
      fd.append('_csrf', csrf());
      fd.append('action', action);
      ids.forEach(function (id) { fd.append('ids[]', id); });
      fetch('/items/bulk', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j.ok) location.reload(); else alert(j.error || 'error'); });
    });
  });
})();
</script>
