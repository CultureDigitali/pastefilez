<form method="post" class="card w-full max-w-sm mx-auto p-7">
  <h1 class="font-semibold text-lg">🔒 <?= e(t('share.locked')) ?></h1>
  <?php if (!empty($error)): ?><div class="mt-3 p-3 rounded bg-rose-500/10 border border-rose-500/30 text-rose-200 text-sm"><?= e($error) ?></div><?php endif; ?>
  <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
  <input class="input mt-4" type="password" name="password" required autofocus id="f-password-1" aria-label="<?= e(t('share.password')) ?>">
  <button class="btn btn-primary w-full mt-3 justify-center"><?= e(t('common.confirm')) ?></button>
</form>
