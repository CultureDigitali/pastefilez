<div class="max-w-5xl mx-auto space-y-3">
  <div class="flex items-center gap-3">
    <a href="<?= e(url('/items/' . $item['id'])) ?>" class="text-sm text-fg-2 hover:text-fg">&larr; <?= e(t('common.back')) ?></a>
    <h1 class="text-2xl font-bold">✏️ <?= e(t('editor.title')) ?></h1>
  </div>

  <div class="card p-3">
    <div class="flex flex-wrap items-center gap-2 text-sm">
      <button id="tool-select" class="btn btn-sm active-tool" data-tool="select" title="Seleziona">🖱</button>
      <button id="tool-text" class="btn btn-sm" data-tool="text" title="Testo">T</button>
      <button id="tool-arrow" class="btn btn-sm" data-tool="arrow" title="Freccia">➜</button>
      <button id="tool-line" class="btn btn-sm" data-tool="line" title="Linea">╱</button>
      <button id="tool-rect" class="btn btn-sm" data-tool="rect" title="Rettangolo">▭</button>
      <button id="tool-underline" class="btn btn-sm" data-tool="underline" title="Sottolinea">U̲</button>
      <button id="tool-freehand" class="btn btn-sm" data-tool="freehand" title="Disegno libero">✏</button>
      <span class="border-l border-border h-5 mx-1"></span>
      <label class="text-fg-2">Colore</label>
      <input type="color" id="color-picker" aria-label="<?= e(t('editor.color')) ?>" value="#ff0000" class="w-8 h-8 rounded cursor-pointer">
      <label class="text-fg-2">Spessore</label>
      <select id="stroke-width" aria-label="<?= e(t('editor.stroke')) ?>" class="input input-sm w-20">
        <option value="2">2px</option>
        <option value="3" selected>3px</option>
        <option value="5">5px</option>
        <option value="8">8px</option>
      </select>
      <span class="border-l border-border h-5 mx-1"></span>
      <label class="text-fg-2">Font size</label>
      <select id="font-size" aria-label="<?= e(t('editor.fontsize')) ?>" class="input input-sm w-20">
        <option value="14">14</option>
        <option value="18" selected>18</option>
        <option value="24">24</option>
        <option value="32">32</option>
        <option value="48">48</option>
        <option value="64">64</option>
      </select>
      <span class="border-l border-border h-5 mx-1"></span>
      <button id="btn-undo" class="btn btn-sm" title="Annulla (Ctrl+Z)">↩</button>
      <button id="btn-redo" class="btn btn-sm" title="Rifai (Ctrl+Y)">↪</button>
      <span class="border-l border-border h-5 mx-1"></span>
      <button id="btn-save" class="btn btn-primary btn-sm" title="Salva come nuovo file">💾 Salva</button>
    </div>
  </div>

  <div class="card p-2 overflow-auto" style="max-height:70vh">
    <canvas id="editor-canvas" class="mx-auto cursor-crosshair" style="max-width:100%;height:auto"></canvas>
  </div>

  <div id="editor-status" class="text-xs text-fg-2"></div>
  <form id="save-form" method="post" action="<?= e(url('/items/' . $item['id'] . '/edit/save')) ?>" class="hidden">
    <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
    <input type="hidden" name="image_data" id="save-data">
  </form>
</div>

<script>
(function(){
  var SRC = '<?= $src ?>';
  var CSRF = '<?= e($csrf_token) ?>';
  var SAVE_URL = '<?= e(url("/items/" . $item["id"] . "/edit/save")) ?>';
  var canvas = document.getElementById('editor-canvas');
  var ctx = canvas.getContext('2d');
  var img = new Image();
  img.crossOrigin = 'anonymous';
  var W = 0, H = 0;
  var tool = 'select';
  var color = '#ff0000';
  var strokeW = 3;
  var fontSize = 18;
  var drawing = false;
  var startX = 0, startY = 0;
  var history = [];
  var redoStack = [];
  var currentPath = [];
  var baseImage = null;

  function saveState() {
    history.push(canvas.toDataURL());
    if (history.length > 50) history.shift();
    redoStack = [];
  }

  function restoreState(dataUrl) {
    var img2 = new Image();
    img2.onload = function() { ctx.clearRect(0,0,W,H); ctx.drawImage(img2,0,0); };
    img2.src = dataUrl;
  }

  function setTool(t) {
    tool = t;
    canvas.style.cursor = t === 'select' ? 'default' : (t === 'text' ? 'text' : 'crosshair');
    document.querySelectorAll('[data-tool]').forEach(function(b) {
      b.classList.toggle('active-tool', b.getAttribute('data-tool') === t);
    });
  }

  document.querySelectorAll('[data-tool]').forEach(function(b) {
    b.addEventListener('click', function() { setTool(this.getAttribute('data-tool')); });
  });

  document.getElementById('color-picker').addEventListener('input', function() { color = this.value; });
  document.getElementById('stroke-width').addEventListener('change', function() { strokeW = parseInt(this.value); });
  document.getElementById('font-size').addEventListener('change', function() { fontSize = parseInt(this.value); });

  document.getElementById('btn-undo').addEventListener('click', function() {
    if (history.length === 0) return;
    redoStack.push(canvas.toDataURL());
    restoreState(history.pop());
  });
  document.getElementById('btn-redo').addEventListener('click', function() {
    if (redoStack.length === 0) return;
    history.push(canvas.toDataURL());
    restoreState(redoStack.pop());
  });

  document.addEventListener('keydown', function(e) {
    if ((e.metaKey || e.ctrlKey) && e.key === 'z') { e.preventDefault(); document.getElementById('btn-undo').click(); }
    if ((e.metaKey || e.ctrlKey) && e.key === 'y') { e.preventDefault(); document.getElementById('btn-redo').click(); }
  });

  img.onload = function() {
    W = img.naturalWidth;
    H = img.naturalHeight;
    canvas.width = W;
    canvas.height = H;
    ctx.drawImage(img, 0, 0);
    baseImage = canvas.toDataURL();
    saveState();
  };
  img.src = SRC;

  function getPos(e) {
    var r = canvas.getBoundingClientRect();
    var scaleX = W / r.width;
    var scaleY = H / r.height;
    var clientX = e.touches ? e.touches[0].clientX : e.clientX;
    var clientY = e.touches ? e.touches[0].clientY : e.clientY;
    return { x: (clientX - r.left) * scaleX, y: (clientY - r.top) * scaleY };
  }

  function drawArrow(fromX, fromY, toX, toY) {
    var headLen = Math.max(12, strokeW * 4);
    var angle = Math.atan2(toY - fromY, toX - fromX);
    ctx.strokeStyle = color;
    ctx.lineWidth = strokeW;
    ctx.lineCap = 'round';
    ctx.beginPath();
    ctx.moveTo(fromX, fromY);
    ctx.lineTo(toX, toY);
    ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(toX, toY);
    ctx.lineTo(toX - headLen * Math.cos(angle - Math.PI / 6), toY - headLen * Math.sin(angle - Math.PI / 6));
    ctx.moveTo(toX, toY);
    ctx.lineTo(toX - headLen * Math.cos(angle + Math.PI / 6), toY - headLen * Math.sin(angle + Math.PI / 6));
    ctx.stroke();
  }

  canvas.addEventListener('mousedown', onDown);
  canvas.addEventListener('touchstart', function(e) { e.preventDefault(); onDown(e); }, { passive: false });
  canvas.addEventListener('mousemove', onMove);
  canvas.addEventListener('touchmove', function(e) { e.preventDefault(); onMove(e); }, { passive: false });
  canvas.addEventListener('mouseup', onUp);
  canvas.addEventListener('touchend', onUp);

  function onDown(e) {
    var p = getPos(e);
    if (tool === 'text') {
      var txt = prompt('Testo:');
      if (!txt) return;
      saveState();
      ctx.font = fontSize + 'px -apple-system, BlinkMacSystemFont, sans-serif';
      ctx.fillStyle = color;
      ctx.textBaseline = 'top';
      ctx.fillText(txt, p.x, p.y);
      return;
    }
    drawing = true;
    startX = p.x;
    startY = p.y;
    if (tool === 'freehand') {
      currentPath = [{ x: p.x, y: p.y }];
    }
    if (tool === 'select') drawing = false;
  }

  function onMove(e) {
    if (!drawing) return;
    var p = getPos(e);
    if (tool === 'freehand') {
      currentPath.push({ x: p.x, y: p.y });
      ctx.strokeStyle = color;
      ctx.lineWidth = strokeW;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.beginPath();
      var prev = currentPath[currentPath.length - 2];
      ctx.moveTo(prev.x, prev.y);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      return;
    }
    restoreState(history[history.length - 1]);
    ctx.strokeStyle = color;
    ctx.lineWidth = strokeW;
    ctx.lineCap = 'round';
    if (tool === 'arrow') drawArrow(startX, startY, p.x, p.y);
    else if (tool === 'line') {
      ctx.beginPath(); ctx.moveTo(startX, startY); ctx.lineTo(p.x, p.y); ctx.stroke();
    } else if (tool === 'rect') {
      ctx.strokeRect(startX, startY, p.x - startX, p.y - startY);
    } else if (tool === 'underline') {
      ctx.beginPath(); ctx.moveTo(startX, p.y); ctx.lineTo(p.x, p.y); ctx.stroke();
    }
  }

  function onUp(e) {
    if (!drawing) return;
    drawing = false;
    if (tool === 'freehand' && currentPath.length > 1) {
      saveState();
      currentPath = [];
      return;
    }
    if (tool === 'select') return;
    saveState();
  }

  document.getElementById('btn-save').addEventListener('click', function() {
    var dataUrl = canvas.toDataURL('image/png');
    document.getElementById('save-data').value = dataUrl;
    var fd = new FormData();
    fd.append('_csrf', CSRF);
    fd.append('image_data', dataUrl);
    document.getElementById('editor-status').textContent = 'Salvataggio...';
    fetch(SAVE_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(j) {
        if (j.ok) {
          window.location.href = '/items/' + j.id;
        } else {
          document.getElementById('editor-status').textContent = 'Errore: ' + (j.error || 'sconosciuto');
        }
      })
      .catch(function(err) {
        document.getElementById('editor-status').textContent = 'Errore di rete: ' + String(err);
      });
  });
})();
</script>

<style>
.active-tool { background: rgba(14,165,233,0.2); border-color: rgba(14,165,233,0.5); }
#editor-canvas { border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; }
</style>
