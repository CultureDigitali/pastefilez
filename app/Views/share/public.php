<div class="card w-full max-w-3xl mx-auto overflow-hidden">
  <?php if (!empty($item['thumbnail_path']) && str_starts_with($item['mime'], 'image/')): ?>
    <img src="<?= e(url('s/' . $token . '/thumb')) ?>" class="w-full" alt="">
  <?php elseif (str_starts_with($item['mime'], 'video/')): ?>
    <video controls class="w-full" src="<?= e(url('s/' . $token . '/file')) ?>"></video>
  <?php elseif (str_starts_with($item['mime'], 'audio/')): ?>
    <audio controls class="w-full" src="<?= e(url('s/' . $token . '/file')) ?>"></audio>
  <?php elseif ($item['mime'] === 'application/pdf'): ?>
    <iframe class="w-full" style="height:80vh" src="<?= e(url('s/' . $token . '/file')) ?>"></iframe>
  <?php endif; ?>
  <div class="p-5">
    <div class="text-xs text-fg-2"><?= e($item['mime']) ?> · <?= e(\bytes_to_human((int)$item['size'])) ?> · <?= e(date('d M Y H:i', (int)$item['created_at'])) ?></div>
    <h1 class="font-semibold text-lg mt-1 break-all"><?= e($item['original_name']) ?></h1>
    <?php if (!empty($item['ai_description'])): ?>
      <p class="mt-2 text-fg-2 text-sm"><?= e($item['ai_description']) ?></p>
    <?php endif; ?>
    <a class="btn btn-primary mt-4 inline-flex" href="<?= e(url('s/' . $token . '/file')) ?>">⬇ <?= e(t('share.download')) ?></a>
  </div>
</div>
