<div class="card w-full max-w-sm mx-auto p-7">
  <h1 class="font-semibold text-lg mb-5"><?= e(t('auth.reset.title')) ?></h1>
  <?php if (!empty($error)): ?><div class="mb-4 p-3 rounded-lg border border-rose-500/30 bg-rose-500/10 text-rose-200 text-sm"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url('/reset/' . rawurlencode($token ?? ''))) ?>" class="space-y-4">
    <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
    <input class="input" type="password" name="password" id="reset-password" aria-label="<?= e(t('settings.password.new')) ?>" minlength="12" required autofocus>
    <button class="btn btn-primary w-full justify-center" type="submit"><?= e(t('auth.reset.submit')) ?></button>
  </form>
</div>
