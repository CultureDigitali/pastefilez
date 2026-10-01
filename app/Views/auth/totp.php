<div class="card w-full max-w-sm mx-auto p-7">
  <h1 class="font-semibold text-lg mb-1"><?= e(t('auth.login.totp')) ?></h1>
  <p class="text-fg-2 text-sm mb-5"><?= e(t('auth.login.totp_help')) ?></p>
  <?php if (!empty($error)): ?><div class="mb-4 p-3 rounded-lg border border-rose-500/30 bg-rose-500/10 text-rose-200 text-sm"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url('/login/totp')) ?>" class="space-y-4">
    <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
    <input class="input text-center tracking-[0.5em] text-2xl" name="code" id="totp-code" aria-label="<?= e(t('auth.login.totp')) ?>" pattern="\d{6}" maxlength="6" inputmode="numeric" autofocus required>
    <button class="btn btn-primary w-full justify-center" type="submit"><?= e(t('auth.login.submit')) ?></button>
  </form>
</div>
