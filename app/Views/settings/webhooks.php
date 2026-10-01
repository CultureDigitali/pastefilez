<div class="space-y-4">
  <div class="card p-5">
    <h2 class="font-semibold mb-2">🔗 Incoming (ricevi file da servizi esterni)</h2>
    <p class="text-xs text-fg-2 mb-3">Genera un URL univoco. I servizi esterni (CI, log shipper, microservizi) possono POSTare un file a quell'URL e verrà creato un item nel tuo account.</p>
    <form id="wh-in-form" class="flex gap-2 mb-3">
      <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
      <input class="input flex-1" name="name" placeholder="Nome (es. CI logs)" required id="f-name-1" aria-label="<?= e(t('ai.webhook.name')) ?>">
      <button class="btn btn-primary">+ Genera URL</button>
    </form>
  </div>

  <div class="card p-5">
    <h2 class="font-semibold mb-2">📤 Outgoing (notifiche verso l'esterno)</h2>
    <p class="text-xs text-fg-2 mb-3">Quando si verifica un evento, facciamo una POST al tuo URL con un body JSON. Opzionale: HMAC-SHA256 nel header X-PZ-Signature.</p>
    <form id="wh-out-form" class="space-y-2 mb-3">
      <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
      <input class="input w-full" name="name" placeholder="Nome (es. Slack notifier)" required id="f-name-2" aria-label="<?= e(t('ai.webhook.name')) ?>">
      <input class="input w-full" name="target_url" placeholder="https://hooks.slack.com/..." type="url" required id="f-target-url-3" aria-label="<?= e(t('ai.webhook.url')) ?>">
      <input class="input w-full" name="secret" placeholder="Secret HMAC (opzionale)" id="f-secret-4" aria-label="<?= e(t('ai.webhook.secret')) ?>">
      <div class="flex flex-wrap gap-2 text-sm">
        <label for="f-events--1" class="flex items-center gap-1"><input type="checkbox" name="events[]" value="item.uploaded" id="f-events--1"> item.uploaded</label>
        <label for="f-events--2" class="flex items-center gap-1"><input type="checkbox" name="events[]" value="item.deleted" id="f-events--2"> item.deleted</label>
        <label for="f-events--3" class="flex items-center gap-1"><input type="checkbox" name="events[]" value="share.created" id="f-events--3"> share.created</label>
        <label for="f-events--4" class="flex items-center gap-1"><input type="checkbox" name="events[]" value="share.opened" id="f-events--4"> share.opened</label>
      </div>
      <button class="btn btn-primary">+ Aggiungi</button>
    </form>
  </div>

  <div class="card p-5">
    <h2 class="font-semibold mb-2">Webhook configurati</h2>
    <?php if (empty($webhooks)): ?>
      <p class="text-fg-2 text-sm">Nessun webhook.</p>
    <?php else: ?>
      <div class="space-y-2">
      <?php foreach ($webhooks as $w): ?>
        <div class="p-3 bg-surface-2 rounded-lg flex items-center gap-2" data-wh-id="<?= (int)$w['id'] ?>">
          <div class="flex-1 min-w-0">
            <div class="font-medium text-sm"><?= e($w['name']) ?> <span class="tag ml-1"><?= e($w['direction'] === 'in' ? 'IN' : 'OUT') ?></span></div>
            <div class="text-xs text-fg-2 truncate">
              <?php if ($w['direction'] === 'in'): ?>
                POST <?= e(url('hooks/' . $w['token'])) ?>
              <?php else: ?>
                → <?= e($w['target_url']) ?> · eventi: <?= e(implode(',', json_decode($w['events'] ?? '[]', true) ?: [])) ?>
                <?php if ($w['last_status']): ?> · ultimo: <?= (int)$w['last_status'] ?><?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
          <button class="btn btn-sm btn-danger js-wh-del" data-id="<?= (int)$w['id'] ?>">×</button>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
function csrf() {
  var m = document.querySelector('meta[name="csrf"]');
  return m ? m.getAttribute('content') : '';
}
function postForm(url, fd) {
  return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
}
document.getElementById('wh-in-form').addEventListener('submit', function (e) {
  e.preventDefault();
  var fd = new FormData(e.target);
  postForm('/settings/webhooks/incoming', fd).then(function (j) {
    if (j.ok) {
      alert('URL generato:\n\nPOST ' + j.url + '\n\nConservalo: il token non sarà mostrato di nuovo.');
      location.reload();
    } else alert(j.error);
  });
});
document.getElementById('wh-out-form').addEventListener('submit', function (e) {
  e.preventDefault();
  var fd = new FormData(e.target);
  postForm('/settings/webhooks/outgoing', fd).then(function (j) {
    if (j.ok) location.reload(); else alert(j.error);
  });
});
document.querySelectorAll('.js-wh-del').forEach(function (b) {
  b.addEventListener('click', function () {
    if (!confirm('Eliminare il webhook?')) return;
    var fd = new FormData();
    fd.append('_csrf', csrf());
    fd.append('id', b.getAttribute('data-id'));
    postForm('/settings/webhooks/delete', fd).then(function (j) { if (j.ok) location.reload(); else alert(j.error); });
  });
});
</script>
