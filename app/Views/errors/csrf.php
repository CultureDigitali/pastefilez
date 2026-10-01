<?php
declare(strict_types=1);
?><div class="min-h-screen flex items-center justify-center gradient-bg px-4">
  <div class="card max-w-md w-full p-8 text-center">
    <div class="text-7xl font-black opacity-30">CSRF</div>
    <div class="text-xl font-semibold mt-2"><?= e(t('errors.csrf.title')) ?></div>
    <a href="javascript:location.reload()" class="btn btn-primary mt-6 inline-flex"><?= e(t('errors.csrf.back')) ?></a>
  </div>
</div>
