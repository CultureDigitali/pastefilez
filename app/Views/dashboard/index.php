<div class="grid gap-6 lg:grid-cols-3">
  <div class="lg:col-span-2 space-y-6">
    <div class="card p-6 gradient-bg">
      <h1 class="text-2xl font-bold tracking-tight"><?= e(t('dashboard.welcome', ['name' => $user['display_name'] ?? $user['email']])) ?></h1>
      <p class="text-fg-2 mt-1 text-sm"><?= e(t('app.tagline')) ?></p>
      <div class="mt-4 flex items-center gap-3 text-sm text-fg-2">
        <span><?= e(t('upload.quota_used', ['used' => bytes_to_human($used), 'total' => bytes_to_human($quota)])) ?></span>
        <div class="flex-1 h-2 bg-surface-2 rounded-full overflow-hidden">
          <div class="h-full bg-brand-500" style="width: <?= e((string)$used_pct) ?>%"></div>
        </div>
        <span class="text-xs"><?= e((string)$used_pct) ?>%</span>
      </div>
    </div>

    <form method="get" action="<?= e(url('/search')) ?>" class="card p-4 flex items-center gap-3">
      <input class="input flex-1" name="q" aria-label="<?= e(t('search.placeholder')) ?>" placeholder="<?= e(t('dashboard.placeholder_search')) ?>" autofocus id="f-q-1">
      <button class="btn btn-primary"><?= e(t('search.submit')) ?> ↵</button>
    </form>

    <div id="dropzone" class="dropzone">
      <div class="text-3xl">⇪</div>
      <div class="mt-2 font-semibold"><?= e(t('dashboard.drop_here')) ?></div>
      <div class="text-fg-2 text-sm mt-1"><?= e(t('dashboard.paste_hint')) ?></div>
      <label for="file-input" class="btn btn-primary mt-4 cursor-pointer">
        <span><?= e(t('dashboard.browse')) ?></span>
        <input id="file-input" type="file" multiple class="hidden">
      </label>
    </div>

    <div id="upload-list" class="space-y-2"></div>

    <div>
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-lg font-semibold"><?= e(t('dashboard.recent')) ?></h2>
        <a class="text-sm text-brand-400" href="<?= e(url('/items')) ?>"><?= e(t('nav.items')) ?> →</a>
      </div>
      <?php if (!$items): ?>
        <div class="card p-8 text-center text-fg-2"><?= e(t('dashboard.no_items')) ?></div>
      <?php else: ?>
        <div class="grid-items">
          <?php foreach ($items as $it): ?>
            <a href="<?= e(url('/items/' . (int)$it['id'])) ?>" class="thumb" title="<?= e($it['original_name']) ?>">
              <?php if ($it['thumbnail_path']): ?>
                <img loading="lazy" src="<?= e(url('/items/' . (int)$it['id'] . '/thumb')) ?>" alt="">
              <?php elseif (str_starts_with($it['mime'], 'video/')): ?>
                <span class="icon">▶</span>
              <?php elseif (str_starts_with($it['mime'], 'audio/')): ?>
                <span class="icon">♪</span>
              <?php elseif ($it['mime'] === 'application/pdf'): ?>
                <span class="icon">📄</span>
              <?php else: ?>
                <span class="icon">📁</span>
              <?php endif; ?>
              <?php if ($it['is_public']): ?><span class="badge"><?= $it['share_password_hash'] ? '🔒' : '🌐' ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <aside class="space-y-3">
    <div class="card p-4">
      <h3 class="font-semibold mb-2">✨ <?= e(t('dashboard.feature_extract')) ?></h3>
      <p class="text-sm text-fg-2">Ogni immagine, video e screenshot viene analizzato automaticamente per estrarre descrizione, tag e OCR.</p>
    </div>
    <div class="card p-4">
      <h3 class="font-semibold mb-2">🔎 <?= e(t('dashboard.feature_semantic')) ?></h3>
      <p class="text-sm text-fg-2">Cerca in linguaggio naturale. Esempio: <em>"screenshot del sito xyz di ieri"</em>.</p>
    </div>
    <div class="card p-4">
      <h3 class="font-semibold mb-2">🔗 <?= e(t('dashboard.feature_share')) ?></h3>
      <p class="text-sm text-fg-2">Condividi con link pubblico, protetto da password, con scadenza e limite di visualizzazioni.</p>
    </div>
  </aside>
</div>
