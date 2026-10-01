<h1 class="text-2xl font-bold mb-4">🔎 <?= e(t('search.title')) ?></h1>
<form method="get" class="card p-4 flex gap-2 mb-6">
  <input class="input flex-1" aria-label="<?= e(t('search.placeholder')) ?>" name="q" placeholder="<?= e(t('search.placeholder')) ?>" value="<?= e($q) ?>" autofocus id="f-q-1">
  <button class="btn btn-primary"><?= e(t('search.submit')) ?></button>
</form>
<?php if ($q !== '' && !$results): ?>
  <div class="card p-8 text-center text-fg-2"><?= e(t('search.empty')) ?></div>
<?php endif; ?>
<?php if ($results): ?>
  <div class="grid-items">
    <?php foreach ($results as $r): $it = $r['item']; ?>
      <a href="<?= e(url('/items/' . (int)$it['id'])) ?>" class="thumb relative" title="<?= e($it['original_name']) ?>">
        <?php if (!empty($it['thumbnail_path'])): ?>
          <img loading="lazy" src="<?= e(url('/items/' . (int)$it['id'] . '/thumb')) ?>" alt="">
        <?php else: ?>
          <span class="icon">📁</span>
        <?php endif; ?>
        <div class="absolute bottom-0 left-0 right-0 bg-black/60 text-xs px-2 py-1 truncate"><?= e($it['original_name']) ?></div>
        <div class="absolute top-1 right-1 bg-black/60 text-[10px] px-1.5 py-0.5 rounded"><?= e(number_format($r['score'], 3)) ?></div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
