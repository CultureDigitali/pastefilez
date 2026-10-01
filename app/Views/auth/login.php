<div class="card w-full max-w-sm mx-auto p-7">
  <div class="flex items-center gap-3 mb-6">
    <div class="w-10 h-10 rounded-xl bg-brand-500 flex items-center justify-center text-white font-black text-xl">P</div>
    <div>
      <div class="font-bold"><?= e($app_name ?? 'PasteFilez') ?></div>
      <div class="text-xs text-fg-2"><?= e(t('auth.login.title')) ?></div>
    </div>
  </div>
  <?php if (!empty($error)): ?>
    <div class="mb-4 p-3 rounded-lg border border-rose-500/30 bg-rose-500/10 text-rose-200 text-sm"><?= e($error) ?></div>
  <?php endif; ?>
  <form method="post" action="<?= e(url('/login')) ?>" class="space-y-4">
    <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
    <div>
      <label for="login-email"><?= e(t('auth.login.email')) ?></label>
      <input class="input" type="email" name="email" id="login-email" required autocomplete="username" autofocus value="<?= e($email ?? '') ?>">
    </div>
    <div>
      <label for="login-password"><?= e(t('auth.login.password')) ?></label>
      <input class="input" type="password" name="password" id="login-password" required autocomplete="current-password">
    </div>
    <button class="btn btn-primary w-full justify-center" type="submit"><?= e(t('auth.login.submit')) ?></button>
  </form>
  <div class="text-center text-sm text-fg-2 mt-4">
    <a href="<?= e(url('/forgot')) ?>" class="hover:text-brand-400"><?= e(t('auth.forgot.title')) ?></a>
  </div>
</div>
