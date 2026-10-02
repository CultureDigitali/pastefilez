<?php
declare(strict_types=1);

$lang = $lang ?? 'it';
$theme = $theme ?? 'dark';
$csrf = $csrf_token ?? '';
$appName = $app_name ?? 'PasteFilez';
$isAdmin = $is_admin ?? false;
$user = $user ?? null;
$page = $page ?? '';
$appUrl = $app_url ?? '';
$flashes = [];
foreach (['success','error','warning','info'] as $f) {
    $m = \App\Core\Session::flash($f);
    if ($m) $flashes[$f] = \App\Core\I18n::t($m);
}
?><!doctype html>
<html lang="<?= e($lang) ?>" data-theme="<?= e($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="color-scheme" content="dark light">
<meta name="referrer" content="no-referrer">
<meta name="robots" content="noindex,nofollow">
<meta name="csrf" content="<?= e($csrf) ?>">
<title><?= e($appName) ?> · <?= e(ucfirst(str_replace('/', ' · ', trim($page, '/')))) ?></title>
<link rel="icon" href="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><rect width='64' height='64' rx='14' fill='%230ea5e9'/><text x='50%25' y='58%25' font-size='38' text-anchor='middle' fill='white' font-family='-apple-system,sans-serif' font-weight='800'>P</text></svg>">
<link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
<meta name="theme-color" content="#0ea5e9">
<link rel="apple-touch-icon" href="<?= e(url('assets/img/icon-192.png')) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="mobile-web-app-capable" content="yes">
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/htmx.org@1.9.12/dist/htmx.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.4/build/qrcode.min.js"></script>
<script src="<?= e(url('assets/js/app.js')) ?>" defer></script>
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="bg-bg text-fg antialiased min-h-screen flex flex-col">
<a class="skip-link" href="#main"><?= e(t('nav.skip')) ?></a>
<?php if ($user): ?>
<header class="sticky top-0 z-30 backdrop-blur bg-surface/80 border-b border-border">
  <div class="max-w-7xl mx-auto px-4 h-14 flex items-center gap-4">
    <a href="<?= e(url('/')) ?>" class="flex items-center gap-2 font-bold tracking-tight">
      <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-brand-500 text-white">P</span>
      <span class="hidden sm:inline"><?= e($appName) ?></span>
    </a>
    <nav class="flex items-center gap-1 ml-2 text-sm" aria-label="<?= e(t('nav.primary')) ?>">
      <a href="<?= e(url('/')) ?>" class="px-3 py-1.5 rounded-lg hover:bg-surface-2 <?= $page==='dashboard/index'?'bg-surface-2 text-brand-400':'' ?>"><?= e(t('nav.dashboard')) ?></a>
      <a href="<?= e(url('/items')) ?>" class="px-3 py-1.5 rounded-lg hover:bg-surface-2 <?= str_starts_with($page,'items/')?'bg-surface-2 text-brand-400':'' ?>"><?= e(t('nav.items')) ?></a>
      <a href="<?= e(url('/search')) ?>" class="px-3 py-1.5 rounded-lg hover:bg-surface-2 <?= $page==='search/index'?'bg-surface-2 text-brand-400':'' ?>"><?= e(t('nav.search')) ?></a>
      <a href="<?= e(url('/settings')) ?>" class="px-3 py-1.5 rounded-lg hover:bg-surface-2 <?= str_starts_with($page,'settings/')?'bg-surface-2 text-brand-400':'' ?>"><?= e(t('nav.settings')) ?></a>
      <?php if (!empty($is_admin)): ?>
        <a href="<?= e(url('/admin/users')) ?>" class="px-3 py-1.5 rounded-lg hover:bg-surface-2 <?= str_starts_with($page,'admin/users')?'bg-surface-2 text-brand-400':'' ?>"><?= e(t('nav.admin')) ?></a>
        <?php if (\App\Core\Auth::can((string)($user['role'] ?? ''), 'roles.read')): ?>
        <a href="<?= e(url('/admin/roles')) ?>" class="px-3 py-1.5 rounded-lg hover:bg-surface-2 <?= $page==='admin/roles'?'bg-surface-2 text-brand-400':'' ?>"><?= e(t('admin.roles.title')) ?></a>
        <?php endif; ?>
      <?php endif; ?>
    </nav>
    <div class="ml-auto flex items-center gap-2">
      <button id="theme-toggle" class="px-2 py-1.5 rounded-lg hover:bg-surface-2 text-sm" title="Theme">◐</button>
      <div class="hidden sm:flex items-center gap-2 text-sm text-fg-2">
        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-brand-600 text-white text-xs font-semibold"><?= e(strtoupper(substr($user['display_name'] ?? $user['email'], 0, 1))) ?></span>
        <span><?= e($user['email']) ?></span>
      </div>
      <form method="post" action="<?= e(url('/logout')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button class="px-3 py-1.5 rounded-lg hover:bg-surface-2 text-sm"><?= e(t('nav.logout')) ?></button>
      </form>
    </div>
  </div>
</header>
<?php endif; ?>

<main id="main" class="flex-1 max-w-7xl w-full mx-auto px-4 py-6" tabindex="-1">
<?php foreach ($flashes as $type => $msg): ?>
  <div x-data="{show:true}" x-show="show" x-transition class="mb-4 px-4 py-3 rounded-xl border <?= $type==='success'?'border-emerald-500/30 bg-emerald-500/10 text-emerald-200':($type==='error'?'border-rose-500/30 bg-rose-500/10 text-rose-200':'border-amber-500/30 bg-amber-500/10 text-amber-200') ?>">
    <?= e($msg) ?>
    <button @click="show=false" class="float-right opacity-60">✕</button>
  </div>
<?php endforeach; ?>
<?= $content ?>
</main>

<footer class="border-t border-border py-4 text-center text-xs text-fg-2">
  <span><?= e($appName) ?></span> · <span class="opacity-60">secure by design</span>
</footer>
<script>
window.PZ_LANG = <?= json_encode([
    'loading' => t('loading'),
    'copied' => t('js.copied'),
    'dup' => t('js.dup'),
    'success' => t('js.success'),
    'uploading' => t('js.uploading'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.PZ_T = window.PZ_LANG;
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/sw.js').catch(function(){});
}
</script>
</body>
</html>
