<?php
declare(strict_types=1);

$lang = $lang ?? 'it';
$theme = $theme ?? 'dark';
$appName = $app_name ?? 'PasteFilez';
$csrf = $csrf_token ?? '';
$flashes = [];
foreach (['success','error','warning','info'] as $f) {
    $m = \App\Core\Session::flash($f);
    if ($m) $flashes[$f] = $m;
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
<title><?= e($appName) ?> · <?= e(ucfirst(str_replace('/', ' · ', trim($page ?? '', '/')))) ?></title>
<link rel="icon" href="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'><rect width='64' height='64' rx='14' fill='%230ea5e9'/><text x='50%25' y='58%25' font-size='38' text-anchor='middle' fill='white' font-family='-apple-system,sans-serif' font-weight='800'>P</text></svg>">
<link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
<meta name="theme-color" content="#0ea5e9">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.4/build/qrcode.min.js"></script>
<script src="<?= e(url('assets/js/app.js')) ?>" defer></script>
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="bg-bg text-fg antialiased min-h-screen flex items-center justify-center gradient-bg px-4 py-10">
<main id="main" class="w-full max-w-7xl" tabindex="-1">
<?php foreach ($flashes as $type => $msg): ?>
  <div x-data="{show:true}" x-show="show" x-transition class="mb-4 max-w-sm mx-auto px-4 py-3 rounded-xl border <?= $type==='success'?'border-emerald-500/30 bg-emerald-500/10 text-emerald-200':($type==='error'?'border-rose-500/30 bg-rose-500/10 text-rose-200':'border-amber-500/30 bg-amber-500/10 text-amber-200') ?>">
    <?= e($msg) ?>
    <button @click="show=false" class="float-right opacity-60">✕</button>
  </div>
<?php endforeach; ?>
<?= $content ?>
</main>
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
