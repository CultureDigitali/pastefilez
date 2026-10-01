<form method="post" action="<?= e(url('/settings/totp')) ?>" class="card w-full max-w-md mx-auto p-6">
  <h1 class="font-semibold text-lg mb-2"><?= e(t('settings.totp.enable')) ?></h1>
  <p class="text-fg-2 text-sm mb-4"><?= e(t('settings.totp.scan')) ?></p>
  <div id="qr" class="bg-white p-3 rounded inline-block mb-4"></div>
  <div class="text-xs text-fg-2 mb-2">Secret: <code class="text-fg"><?= e($secret) ?></code></div>
  <?php if (!empty($error)): ?><div class="mb-3 p-3 rounded bg-rose-500/10 border border-rose-500/30 text-rose-200 text-sm"><?= e($error) ?></div><?php endif; ?>
  <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
  <input class="input text-center tracking-[0.5em] text-2xl" name="code" pattern="\d{6}" maxlength="6" inputmode="numeric" placeholder="000000" required autofocus id="f-code-1" aria-label="<?= e(t('settings.totp.code')) ?>">
  <button class="btn btn-primary w-full mt-3 justify-center"><?= e(t('settings.totp.verify')) ?></button>
</form>
<script>PZ.qr(document.getElementById('qr'), '<?= e($uri) ?>');</script>
