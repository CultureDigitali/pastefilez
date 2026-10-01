<?php
declare(strict_types=1);
$errors = $errors ?? [];
$email = $email ?? '';
$name = $name ?? '';
?><!doctype html>
<html lang="it" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(t('install.title')) ?></title>
<link rel="icon" href="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><rect width='64' height='64' rx='14' fill='%230ea5e9'/><text x='50%25' y='58%25' font-size='38' text-anchor='middle' fill='white' font-family='-apple-system,sans-serif' font-weight='800'>P</text></svg>">
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="bg-bg text-fg antialiased min-h-screen flex items-center justify-center gradient-bg px-4">
<div class="card w-full max-w-md p-8">
  <div class="flex items-center gap-3 mb-6">
    <div class="w-10 h-10 rounded-xl bg-brand-500 flex items-center justify-center text-white font-black text-xl">P</div>
    <div>
      <div class="font-bold"><?= e(t('app.name')) ?></div>
      <div class="text-xs text-fg-2"><?= e(t('install.title')) ?></div>
    </div>
  </div>
  <h1 class="text-lg font-semibold mb-1"><?= e(t('install.welcome')) ?></h1>
  <p class="text-sm text-fg-2 mb-6"><?= e(t('install.generating')) ?></p>
  <?php if ($errors): ?>
    <div class="mb-4 p-3 rounded-lg border border-rose-500/30 bg-rose-500/10 text-rose-200 text-sm">
      <ul class="list-disc pl-4"><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
  <form method="post" action="<?= e(url('/install')) ?>" class="space-y-4">
    <input type="hidden" name="_csrf" value="<?= e(\App\Core\Csrf::token()) ?>">
    <div>
      <label for="f-email-1"><?= e(t('install.email')) ?></label>
      <input class="input" type="email" name="email" required autocomplete="email" value="<?= e($email) ?>" id="f-email-1">
    </div>
    <div>
      <label for="f-display-name-2"><?= e(t('install.name')) ?></label>
      <input class="input" name="display_name" autocomplete="name" value="<?= e($name) ?>" id="f-display-name-2">
    </div>
    <div>
      <label for="f-password-3"><?= e(t('install.password')) ?></label>
      <input class="input" type="password" name="password" required minlength="12" autocomplete="new-password" id="f-password-3">
    </div>
    <button class="btn btn-primary w-full justify-center" type="submit"><?= e(t('install.submit')) ?></button>
  </form>
</div>
</body>
</html>
