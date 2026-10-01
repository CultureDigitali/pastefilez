<div class="max-w-3xl mx-auto space-y-4">
  <h1 class="text-2xl font-bold">💬 <?= e(t('chat.title')) ?></h1>
  <p class="text-fg-2 text-sm"><?= e(t('chat.subtitle')) ?></p>

  <div class="card p-4 space-y-3">
    <textarea id="chat-q" aria-label="<?= e(t('chat.title')) ?>" class="input" rows="3" placeholder="<?= e(t('chat.placeholder')) ?>"></textarea>
    <div class="flex items-center gap-2">
      <button id="chat-send" class="btn btn-primary"><?= e(t('chat.ask')) ?></button>
      <span id="chat-status" class="text-sm text-fg-2"></span>
    </div>
  </div>

  <div id="chat-answer" class="card p-5 hidden">
    <div class="prose text-sm whitespace-pre-wrap" id="chat-answer-text"></div>
    <div id="chat-sources" class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-2"></div>
  </div>
</div>

<script>
function csrf() {
  var m = document.querySelector('meta[name="csrf"]');
  return m ? m.getAttribute('content') : '';
}
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
var btn = document.getElementById('chat-send');
var box = document.getElementById('chat-q');
var status = document.getElementById('chat-status');
var ans = document.getElementById('chat-answer');
var ansText = document.getElementById('chat-answer-text');
var ansSrc = document.getElementById('chat-sources');
btn.addEventListener('click', function () {
  var q = box.value.trim();
  if (!q) return;
  btn.disabled = true; status.textContent = '<?= e(t('chat.thinking')) ?>';
  var fd = new FormData();
  fd.append('_csrf', csrf());
  fd.append('q', q);
  fetch('/chat/ask', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      btn.disabled = false; status.textContent = '';
      if (!j.ok) { status.textContent = j.error || 'error'; return; }
      ans.classList.remove('hidden');
      ansText.textContent = j.answer || '';
      ansSrc.innerHTML = '';
      (j.sources || []).forEach(function (s) {
        var it = s.item;
        var div = document.createElement('a');
        div.href = '/items/' + it.id;
        div.className = 'card p-2 text-xs truncate hover:border-brand-500/40 block';
        div.title = it.original_name;
        var thumb = it.thumbnail_path ? 'background-image:url(/items/' + it.id + '/thumb)' : '';
        div.innerHTML = '<div class="thumb aspect-square mb-1" style="' + thumb + ';background-size:cover;background-position:center"></div>' + esc(it.original_name);
        ansSrc.appendChild(div);
      });
    })
    .catch(function (e) { btn.disabled = false; status.textContent = String(e); });
});
box.addEventListener('keydown', function (e) {
  if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') btn.click();
});
</script>
