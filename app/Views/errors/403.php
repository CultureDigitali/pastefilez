<?php
declare(strict_types=1);
?><div class="min-h-screen flex items-center justify-center gradient-bg px-4">
  <div class="card max-w-md w-full p-8 text-center">
    <div class="text-7xl font-black opacity-30">403</div>
    <div class="text-xl font-semibold mt-2"><?= e(t('errors.403.title')) ?></div>
    <p class="text-fg-2 mt-2"><?= e($msg ?? '') ?></p>
    <a href="<?= e(url('/')) ?>" class="btn btn-primary mt-6 inline-flex"><?= e(t('errors.404.back')) ?></a>
  </div>
</div>
