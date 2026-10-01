<div class="max-w-md mx-auto card p-8 mt-12">
  <h1 class="text-xl font-bold mb-1"><?= e(t('share.e2e.title')) ?></h1>
  <p class="text-fg-2 text-sm mb-4"><?= e(t('share.e2e.subtitle', ['size' => \bytes_to_human($size), 'src' => $source])) ?></p>
  <form id="e2e-form" class="space-y-3">
    <input id="e2e-pass" type="password" class="input" placeholder="<?= e(t('share.password_placeholder')) ?>" autocomplete="off" autofocus aria-label="<?= e(t('share.password_placeholder')) ?>">
    <button class="btn btn-primary w-full" type="submit">🔓 <?= e(t('share.e2e.unlock')) ?></button>
  </form>
  <div id="e2e-err" class="text-rose-400 text-sm mt-3 hidden"></div>
  <p class="text-xs text-fg-2 mt-6"><?= e(t('share.e2e.notice')) ?></p>
</div>
<script>
(function () {
  var form = document.getElementById('e2e-form');
  var errBox = document.getElementById('e2e-err');
  function showErr(msg) { errBox.textContent = msg; errBox.classList.remove('hidden'); }
  function hideErr() { errBox.classList.add('hidden'); }

  function b64ToBytes(b64) {
    var bin = atob(b64.replace(/-/g, '+').replace(/_/g, '/'));
    var out = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out;
  }
  function bytesToB64(bytes) {
    var s = '';
    for (var i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
    return btoa(s);
  }
  function bytesToStr(bytes) {
    return new TextDecoder().decode(bytes);
  }
  function strToBytes(s) { return new TextEncoder().encode(s); }

  async function deriveKey(pass, saltBytes) {
    var baseKey = await crypto.subtle.importKey('raw', strToBytes(pass), { name: 'PBKDF2' }, false, ['deriveKey']);
    return crypto.subtle.deriveKey(
      { name: 'PBKDF2', salt: saltBytes, iterations: 200000, hash: 'SHA-256' },
      baseKey,
      { name: 'AES-GCM', length: 256 },
      false,
      ['decrypt']
    );
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    hideErr();
    var pass = document.getElementById('e2e-pass').value;
    if (!pass) { showErr('<?= e(t('share.e2e.empty_password')) ?>'); return; }
    var hash = location.hash.replace(/^#/, '');
    var params = {};
    hash.split('&').forEach(function (kv) {
      var p = kv.split('=');
      if (p.length === 2) params[p[0]] = decodeURIComponent(p[1]);
    });
    if (!params.salt || !params.iv) {
      showErr('<?= e(t('share.e2e.missing_params')) ?>');
      return;
    }
    var salt = b64ToBytes(params.salt);
    var iv = b64ToBytes(params.iv);
    var btn = form.querySelector('button');
    btn.disabled = true; btn.textContent = '⏳…';
    try {
      var r = await fetch('/s-e2e/<?= e($token) ?>/payload', { credentials: 'omit' });
      if (!r.ok) throw new Error('fetch_failed');
      var ct = new Uint8Array(await r.arrayBuffer());
      var key = await deriveKey(pass, salt);
      var plain = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: iv }, key, ct);
      var decoded = JSON.parse(bytesToStr(new Uint8Array(plain)));
      var fileBytes = b64ToBytes(decoded.data);
      var blob = new Blob([fileBytes], { type: decoded.mime || 'application/octet-stream' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = decoded.filename || 'file';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 5000);
      btn.textContent = '✓ <?= e(t('share.e2e.downloaded')) ?>';
    } catch (err) {
      btn.disabled = false; btn.textContent = '🔓 <?= e(t('share.e2e.unlock')) ?>';
      showErr('<?= e(t('share.e2e.wrong_password')) ?>');
    }
  });
})();
</script>
