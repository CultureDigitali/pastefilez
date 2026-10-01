<div class="card w-full max-w-sm mx-auto p-7">
  <h1 class="font-semibold text-lg mb-5"><?= e(t('auth.forgot.title')) ?></h1>
  <form method="post" action="<?= e(url('/forgot')) ?>" class="space-y-4">
    <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
    <input class="input" type="email" name="email" id="forgot-email" required aria-label="<?= e(t('auth.login.email')) ?>" placeholder="<?= e(t('auth.login.email')) ?>">
    <button class="btn btn-primary w-full justify-center" type="submit"><?= e(t('auth.forgot.submit')) ?></button>
  </form>
  <div class="text-center text-sm text-fg-2 mt-4">
    <a href="<?= e(url('/login')) ?>" class="hover:text-brand-400"><?= e(t('common.back')) ?></a>
  </div>
</div>
