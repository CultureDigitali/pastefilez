<h1 class="text-2xl font-bold mb-4"><?= e(t('upload.title')) ?></h1>
<div id="dropzone" class="dropzone">
  <div class="text-3xl">⇪</div>
  <div class="mt-2 font-semibold"><?= e(t('upload.dropzone')) ?></div>
  <div class="text-fg-2 text-sm mt-1"><?= e(t('upload.paste_hint')) ?></div>
  <label for="file-input" class="btn btn-primary mt-4 cursor-pointer">
    <span><?= e(t('common.browse')) ?></span>
    <input id="file-input" type="file" multiple class="hidden">
  </label>
  <div class="text-fg-2 text-xs mt-3"><?= e(t('upload.max_size', ['mb' => (int)\App\Core\Config::get('max_upload_mb', 10240)])) ?></div>
</div>
<div id="upload-list" class="space-y-2 mt-4"></div>

<div class="card p-5 mt-6">
  <h2 class="font-semibold mb-2"><?= e(t('upload.url')) ?></h2>
  <form id="url-form" class="flex gap-2">
    <input class="input flex-1" name="url" placeholder="<?= e(t('upload.url_placeholder')) ?>" id="f-url-1" aria-label="<?= e(t('upload.url')) ?>">
    <button class="btn btn-primary"><?= e(t('upload.fetch')) ?></button>
  </form>
  <div id="url-result" class="text-sm mt-3 text-fg-2"></div>
</div>

<script>
document.getElementById('url-form').addEventListener('submit', function (e) {
  e.preventDefault();
  var fd = new FormData(e.target);
  fd.append('_csrf', PZ.csrf());
  document.getElementById('url-result').textContent = PZ.t ? PZ.t('loading') : '…';
  fetch('/upload/url', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d.ok) {
        document.getElementById('url-result').innerHTML = '<a class="text-brand-400" href="/items/' + d.id + '">' + d.name + '</a>';
      } else {
        document.getElementById('url-result').textContent = d.error || 'error';
      }
    });
});
</script>
