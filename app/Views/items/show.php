<?php
$shareUrl = $item['share_token'] ? url('s/' . $item['share_token']) : '';
?>
<div class="grid lg:grid-cols-3 gap-6">
  <div class="lg:col-span-2 space-y-4">
    <div class="card overflow-hidden">
      <?php if ($item['thumbnail_path'] && str_starts_with($item['mime'], 'image/')): ?>
        <img src="<?= e(url('/items/' . (int)$item['id'] . '/thumb')) ?>" class="w-full" alt="">
      <?php elseif (str_starts_with($item['mime'], 'video/')): ?>
        <video controls class="w-full" src="<?= e(url('/items/' . (int)$item['id'] . '/stream')) ?>"></video>
      <?php elseif (str_starts_with($item['mime'], 'audio/')): ?>
        <audio controls class="w-full" src="<?= e(url('/items/' . (int)$item['id'] . '/stream')) ?>"></audio>
      <?php elseif ($item['mime'] === 'application/pdf'): ?>
        <iframe class="w-full" style="height:70vh" src="<?= e(url('/items/' . (int)$item['id'] . '/stream')) ?>"></iframe>
      <?php else: ?>
        <?php
          $icon = '📄';
          $hint = t('items.preview_unavailable');
          $m = (string)$item['mime'];
          $name = strtolower($item['original_name']);
          if (str_ends_with($name, '.zip') || str_ends_with($name, '.rar') || str_contains($m, 'zip') || str_contains($m, 'rar') || str_contains($m, 'tar') || str_contains($m, '7z') || str_contains($m, 'gzip') || str_contains($m, 'bzip')) { $icon = '🗜️'; $hint = t('items.preview_archive'); }
          elseif (str_ends_with($name, '.json') || str_ends_with($name, '.xml') || str_starts_with($m, 'text/') || $m === 'application/json' || $m === 'application/xml' || $m === 'application/javascript') { $icon = '📃'; $hint = t('items.preview_text'); }
          elseif (str_ends_with($name, '.doc') || str_ends_with($name, '.docx') || str_ends_with($name, '.odt')) { $icon = '📝'; $hint = t('items.preview_doc'); }
          elseif (str_ends_with($name, '.xls') || str_ends_with($name, '.xlsx') || str_ends_with($name, '.csv') || str_ends_with($name, '.ods')) { $icon = '📊'; $hint = t('items.preview_sheet'); }
          elseif (str_ends_with($name, '.ppt') || str_ends_with($name, '.pptx') || str_ends_with($name, '.odp')) { $icon = '📈'; $hint = t('items.preview_slide'); }
          elseif (str_ends_with($name, '.sketch') || str_ends_with($name, '.fig') || str_ends_with($name, '.xd')) { $icon = '🎨'; $hint = t('items.preview_design'); }
          elseif (str_ends_with($name, '.epub')) { $icon = '📖'; $hint = t('items.preview_ebook'); }
          elseif (str_ends_with($name, '.exe') || str_ends_with($name, '.dmg') || str_ends_with($name, '.app') || str_ends_with($name, '.deb') || str_ends_with($name, '.rpm')) { $icon = '⚙️'; $hint = t('items.preview_binary'); }
          elseif (str_ends_with($name, '.ttf') || str_ends_with($name, '.otf') || str_ends_with($name, '.woff') || str_ends_with($name, '.woff2')) { $icon = '🔤'; $hint = t('items.preview_font'); }
        ?>
        <div class="p-12 text-center">
          <div class="text-7xl mb-3"><?= $icon ?></div>
          <div class="text-fg-2 text-sm mb-3"><?= e($hint) ?></div>
          <div class="font-mono text-xs text-fg-2 break-all"><?= e($item['original_name']) ?></div>
          <a href="<?= e(url('/items/' . (int)$item['id'] . '/download')) ?>" class="btn btn-primary mt-4 inline-flex">
            <span>⬇</span> <?= e(t('items.download')) ?>
          </a>
        </div>
      <?php endif; ?>
    </div>

    <div class="card p-5">
      <div class="flex items-center gap-2 text-xs text-fg-2">
        <span class="tag"><?= e($item['mime']) ?></span>
        <span>·</span>
        <span><?= e(\bytes_to_human((int)$item['size'])) ?></span>
        <span>·</span>
        <span><?= e(date('d M Y H:i', (int)$item['created_at'])) ?></span>
        <span class="ml-auto tag">AI: <?= e(t('items.ai_status.' . $item['ai_status'])) ?></span>
      </div>
      <?php if ($item['ai_description']): ?>
        <div class="mt-4">
          <div class="text-xs uppercase tracking-wider text-fg-2 mb-1"><?= e(t('items.ai_desc')) ?></div>
          <div><?= nl2br(e($item['ai_description'])) ?></div>
        </div>
      <?php endif; ?>
      <?php if ($item['ai_tags']): $tg = json_decode($item['ai_tags'], true) ?: []; ?>
        <?php if ($tg): ?>
          <div class="mt-3 flex flex-wrap gap-1">
            <?php foreach ($tg as $tag): ?><span class="tag tag-violet">#<?= e($tag) ?></span><?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($item['ai_ocr']): ?>
        <details class="mt-4">
          <summary class="cursor-pointer text-sm text-fg-2">OCR / testo estratto</summary>
          <pre class="mt-2 text-xs whitespace-pre-wrap text-fg-2 max-h-80 overflow-auto"><?= e(mb_substr($item['ai_ocr'], 0, 4000)) ?></pre>
        </details>
      <?php endif; ?>
    </div>
  </div>

  <aside class="space-y-3">
    <div class="card p-4">
      <h3 class="font-semibold mb-2"><?= e(t('common.actions')) ?></h3>
      <div class="flex flex-col gap-2">
        <a class="btn btn-primary justify-center" href="<?= e(url('/items/' . (int)$item['id'] . '/download')) ?>">⬇ <?= e(t('common.download')) ?></a>
        <?php if (str_starts_with((string)$item['mime'], 'image/')): ?>
          <a class="btn justify-center" href="<?= e(url('/items/' . (int)$item['id'] . '/edit')) ?>">✏ <?= e(t('editor.title')) ?></a>
        <?php endif; ?>
        <button class="btn" onclick="reanalyze(<?= (int)$item['id'] ?>)">↻ <?= e(t('items.action.reanalyze')) ?></button>
        <button class="btn" onclick="openShare(<?= (int)$item['id'] ?>)">🔗 <?= e(t('common.share')) ?></button>
        <button class="btn btn-danger" onclick="delItem(<?= (int)$item['id'] ?>)">✕ <?= e(t('common.delete')) ?></button>
      </div>
    </div>
    <div class="card p-4">
      <h3 class="font-semibold mb-2"><?= e(t('items.share.public')) ?></h3>
      <?php if ($item['is_public'] && $shareUrl): ?>
        <div class="text-xs text-fg-2 mb-1"><?= $item['share_password_hash'] ? e(t('items.share.protected')) : e(t('items.share.public')) ?></div>
        <div class="flex gap-1">
          <input class="input text-xs" value="<?= e($shareUrl) ?>" readonly aria-label="<?= e(t('items.share.url')) ?>">
          <button class="btn btn-sm" onclick="PZ.copy('<?= e($shareUrl) ?>')">📋</button>
        </div>
        <div id="qrcode" class="mt-3 bg-white p-2 rounded inline-block"></div>
        <script>PZ.qr(document.getElementById('qrcode'), '<?= e($shareUrl) ?>');</script>
      <?php else: ?>
        <p class="text-sm text-fg-2"><?= e(t('share.copy_hint')) ?></p>
      <?php endif; ?>
      <div class="mt-3 pt-3 border-t border-surface-2">
        <button class="btn btn-sm w-full" onclick="openE2E(<?= (int)$item['id'] ?>)">🔐 <?= e(t('share.btn.e2e')) ?></button>
      </div>
    </div>
    <?php if ($tags): ?>
    <div class="card p-4">
      <h3 class="font-semibold mb-2"><?= e(t('items.ai_tags')) ?></h3>
      <div class="flex flex-wrap gap-1">
        <?php foreach ($tags as $tag): ?><span class="tag">#<?= e($tag['name']) ?></span><?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </aside>
</div>

<div id="share-modal" class="fixed inset-0 z-50 hidden items-center justify-center modal-back" onclick="if(event.target===this) closeShare()">
  <div class="card p-6 w-full max-w-md">
    <h3 class="font-semibold mb-3"><?= e(t('share.title')) ?></h3>
    <p class="text-xs text-fg-2 mb-4"><?= e(t('share.password_help')) ?></p>
    <form id="share-form" class="space-y-3">
      <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
      <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
      <div>
        <label for="f-password-1"><?= e(t('items.share.password_placeholder')) ?></label>
        <input class="input" name="password" type="password" autocomplete="new-password" id="f-password-1">
      </div>
      <div>
        <label for="f-expires-at-2"><?= e(t('items.share.expires')) ?></label>
        <input class="input" name="expires_at" type="datetime-local" id="f-expires-at-2">
      </div>
      <div>
        <label for="f-max-views-3"><?= e(t('items.share.max_views')) ?></label>
        <input class="input" name="max_views" type="number" min="1" id="f-max-views-3">
      </div>
      <div class="flex gap-2 justify-end">
        <button type="button" class="btn" onclick="closeShare()"><?= e(t('common.cancel')) ?></button>
        <button class="btn btn-primary"><?= e(t('common.save')) ?></button>
      </div>
    </form>
    <div id="share-result" class="mt-3 text-sm"></div>
  </div>
</div>

<div id="e2e-modal" class="fixed inset-0 z-50 hidden items-center justify-center modal-back" onclick="if(event.target===this) closeE2E()">
  <div class="card p-6 w-full max-w-md">
    <h3 class="font-semibold mb-3">🔐 <?= e(t('share.btn.e2e')) ?></h3>
    <p class="text-xs text-fg-2 mb-4"><?= e(t('share.e2e.subtitle', ['size' => '', 'src' => $item['original_name']])) ?></p>
    <form id="e2e-create-form" class="space-y-3">
      <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
      <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
      <div>
        <label for="e2e-pwd"><?= e(t('share.btn.e2e_pwd')) ?></label>
        <input class="input" id="e2e-pwd" type="password" autocomplete="new-password" required>
      </div>
      <div>
        <label for="f-expires-at-4"><?= e(t('items.share.expires')) ?></label>
        <input class="input" name="expires_at" type="datetime-local" id="f-expires-at-4">
      </div>
      <div>
        <label for="f-max-views-5"><?= e(t('items.share.max_views')) ?></label>
        <input class="input" name="max_views" type="number" min="1" id="f-max-views-5">
      </div>
      <div class="flex gap-2 justify-end">
        <button type="button" class="btn" onclick="closeE2E()"><?= e(t('common.cancel')) ?></button>
        <button class="btn btn-primary" id="e2e-create-btn">🔐 <?= e(t('common.create')) ?></button>
      </div>
    </form>
    <div id="e2e-result" class="mt-3 text-sm"></div>
  </div>
</div>

<script>
function openE2E(id) {
  document.getElementById('e2e-modal').classList.remove('hidden');
  document.getElementById('e2e-modal').classList.add('flex');
  document.getElementById('e2e-pwd').focus();
}
function closeE2E() {
  document.getElementById('e2e-modal').classList.add('hidden');
  document.getElementById('e2e-modal').classList.remove('flex');
}
function _b64encode(bytes) {
  var s = ''; for (var i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
  return btoa(s);
}
async function e2eCreate(fileUrl, fileName, fileMime, pwd) {
  var r = await fetch(fileUrl, { credentials: 'same-origin' });
  if (!r.ok) throw new Error('fetch_failed');
  var blob = await r.blob();
  var plain = new Uint8Array(await blob.arrayBuffer());
  var salt = crypto.getRandomValues(new Uint8Array(16));
  var iv = crypto.getRandomValues(new Uint8Array(12));
  var baseKey = await crypto.subtle.importKey('raw', new TextEncoder().encode(pwd), { name: 'PBKDF2' }, false, ['deriveKey']);
  var key = await crypto.subtle.deriveKey(
    { name: 'PBKDF2', salt: salt, iterations: 200000, hash: 'SHA-256' },
    baseKey,
    { name: 'AES-GCM', length: 256 },
    false,
    ['encrypt']
  );
  var envelope = { filename: fileName, mime: fileMime, data: _b64encode(plain) };
  var envBytes = new TextEncoder().encode(JSON.stringify(envelope));
  var ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv }, key, envBytes);
  var encFile = new File([new Uint8Array(ct)], 'enc.bin', { type: 'application/octet-stream' });
  return { file: encFile, salt: _b64encode(salt), iv: _b64encode(iv) };
}
document.getElementById('e2e-create-form').addEventListener('submit', async function (e) {
  e.preventDefault();
  var fd = new FormData(e.target);
  var pwd = document.getElementById('e2e-pwd').value;
  if (!pwd) { document.getElementById('e2e-result').innerHTML = '<span class="text-rose-400">Password richiesta</span>'; return; }
  var btn = document.getElementById('e2e-create-btn');
  btn.disabled = true; btn.textContent = '⏳…';
  try {
    var itemId = fd.get('id');
    var enc = await e2eCreate('/items/' + itemId + '/download', '<?= e($item['original_name']) ?>', '<?= e($item['mime']) ?>', pwd);
    var fd2 = new FormData();
    fd2.append('_csrf', fd.get('_csrf'));
    fd2.append('payload', enc.file);
    if (fd.get('expires_at')) fd2.append('expires_at', fd.get('expires_at'));
    if (fd.get('max_views')) fd2.append('max_views', fd.get('max_views'));
    var r = await fetch('/items/' + itemId + '/share-e2e', { method: 'POST', body: fd2, credentials: 'same-origin' });
    var j = await r.json();
    if (j.ok) {
      var fullUrl = j.url + '#salt=' + encodeURIComponent(enc.salt) + '&iv=' + encodeURIComponent(enc.iv);
      document.getElementById('e2e-result').innerHTML = '<div class="text-emerald-400 mb-2">Link E2E creato. Server non può leggere il contenuto.</div><input class="input text-xs" value="' + fullUrl + '" readonly aria-label="<?= e(t('items.share.url')) ?>"><button class="btn btn-sm mt-2" onclick="PZ.copy(decodeURIComponent(\'' + encodeURIComponent(fullUrl) + '\'))">📋 Copia</button>';
      PZ.copy(fullUrl);
    } else {
      document.getElementById('e2e-result').innerHTML = '<span class="text-rose-400">' + (j.error || 'error') + '</span>';
    }
  } catch (err) {
    document.getElementById('e2e-result').innerHTML = '<span class="text-rose-400">' + String(err) + '</span>';
  }
  btn.disabled = false; btn.textContent = '🔐 <?= e(t('common.create')) ?>';
});
</script>

<script>
function openShare(id) {
  document.getElementById('share-modal').classList.remove('hidden');
  document.getElementById('share-modal').classList.add('flex');
}
function closeShare() {
  document.getElementById('share-modal').classList.add('hidden');
  document.getElementById('share-modal').classList.remove('flex');
}
document.getElementById('share-form').addEventListener('submit', function (e) {
  e.preventDefault();
  var fd = new FormData(e.target);
  fetch('/items/' + fd.get('id') + '/share', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d.ok) {
        document.getElementById('share-result').innerHTML = '<div class="text-emerald-400">' + d.url + '</div>';
        PZ.copy(d.url);
        setTimeout(function () { location.reload(); }, 1200);
      } else {
        document.getElementById('share-result').innerHTML = '<div class="text-rose-400">' + (d.error || 'error') + '</div>';
      }
    });
});
function reanalyze(id) {
  if (!PZ.confirm()) return;
  var fd = new FormData(); fd.append('_csrf', PZ.csrf());
  fetch('/items/' + id + '/reanalyze', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function () { location.reload(); });
}
function delItem(id) {
  if (!PZ.confirm('<?= e(t('items.confirm_delete', ['name' => $item['original_name']])) ?>')) return;
  var fd = new FormData(); fd.append('_csrf', PZ.csrf());
  fetch('/items/' + id + '/delete', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function () { location.href = '/items'; });
}
</script>
