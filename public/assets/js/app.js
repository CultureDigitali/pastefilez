(function () {
  'use strict';

  window.PZ = window.PZ || {};

  PZ.csrf = function () {
    var m = document.querySelector('meta[name="csrf-token"]');
    if (m) return m.getAttribute('content');
    var i = document.querySelector('input[name="_csrf"]');
    return i ? i.value : '';
  };

  PZ.toast = function (msg, type) {
    type = type || 'ok';
    var t = document.createElement('div');
    t.className = 'toast ' + type;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 3500);
  };

  PZ.copy = function (text) {
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () { PZ.toast(window.PZ_T && PZ_T.copied || 'Copiato', 'ok'); });
    } else {
      var ta = document.createElement('textarea');
      ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); PZ.toast('Copiato', 'ok'); } catch (e) {}
      ta.remove();
    }
  };

  PZ.fmtBytes = function (b) {
    if (!b && b !== 0) return '';
    var u = ['B','KB','MB','GB','TB']; var i = 0;
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return (Math.round(b * 100) / 100) + ' ' + u[i];
  };

  PZ.confirm = function (msg) { return window.confirm(msg || 'Sei sicuro?'); };

  PZ.uploadFile = function (file, opts) {
    opts = opts || {};
    var fd = new FormData();
    fd.append('file', file);
    fd.append('_csrf', PZ.csrf());
    return fetch(opts.url || '/upload', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, status: r.status, data: d }; }); });
  };

  PZ.theme = {
    current: localStorage.getItem('pz_theme') || 'auto',
    set: function (t) {
      this.current = t;
      localStorage.setItem('pz_theme', t);
      var eff = t;
      if (t === 'auto') eff = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      document.documentElement.setAttribute('data-theme', eff);
    }
  };

  document.addEventListener('DOMContentLoaded', function () {
    PZ.theme.set(PZ.theme.current);
    var btn = document.getElementById('theme-toggle');
    if (btn) {
      btn.addEventListener('click', function () {
        var order = ['dark', 'light', 'auto'];
        var i = order.indexOf(PZ.theme.current);
        PZ.theme.set(order[(i + 1) % order.length]);
      });
    }
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
      if (PZ.theme.current === 'auto') PZ.theme.set('auto');
    });

    var dz = document.getElementById('dropzone');
    if (dz) {
      ['dragenter', 'dragover'].forEach(function (ev) {
        dz.addEventListener(ev, function (e) { e.preventDefault(); e.stopPropagation(); dz.classList.add('dragover'); });
      });
      ['dragleave', 'drop'].forEach(function (ev) {
        dz.addEventListener(ev, function (e) { e.preventDefault(); e.stopPropagation(); dz.classList.remove('dragover'); });
      });
      dz.addEventListener('drop', function (e) {
        var files = e.dataTransfer.files;
        for (var i = 0; i < files.length; i++) PZ.handleFile(files[i]);
      });
      var fileInput = document.getElementById('file-input');
      if (fileInput) {
        fileInput.addEventListener('change', function () {
          for (var i = 0; i < fileInput.files.length; i++) PZ.handleFile(fileInput.files[i]);
        });
      }
    }

    document.addEventListener('paste', function (e) {
      if (dz && e.clipboardData) {
        var items = e.clipboardData.items;
        for (var i = 0; i < items.length; i++) {
          if (items[i].kind === 'file') {
            var f = items[i].getAsFile();
            if (f) PZ.handleFile(f, true);
          }
        }
      }
    });
  });

  PZ.handleFile = function (file, fromClipboard) {
    var dz = document.getElementById('dropzone');
    var list = document.getElementById('upload-list');
    if (!dz || !list) return;
    var row = document.createElement('div');
    row.className = 'card p-3 flex items-center gap-3';
    row.innerHTML = '<div class="spin"></div><div class="flex-1 min-w-0"><div class="truncate font-medium">' + PZ.esc(file.name || ('paste-' + Date.now())) + '</div><div class="text-xs text-fg-2">' + PZ.fmtBytes(file.size) + ' · ' + (file.type || '?') + '</div></div><div class="text-sm"></div>';
    list.prepend(row);
    var status = row.querySelector('.text-sm');
    status.textContent = window.PZ_T ? PZ_T.uploading.replace('{name}', file.name) : 'Uploading…';
    PZ.uploadFile(file).then(function (r) {
      if (r.ok && r.data.ok) {
        row.classList.add('border-emerald-500/40');
        var dupNote = '';
        if (r.data.duplicate_of) {
          dupNote = ' <span class="tag tag-amber ml-1">' + (window.PZ_T ? PZ_T.dup : 'DUPLICATE') + '</span>';
        }
        status.innerHTML = '<a class="text-brand-400" href="/items/' + r.data.id + '">' + (window.PZ_T ? PZ_T.success.replace('{name}', file.name) : 'OK') + '</a>' + dupNote;
        setTimeout(function () { window.location.href = '/items/' + r.data.id; }, 600);
      } else {
        row.classList.add('border-rose-500/40');
        status.textContent = (r.data && r.data.error) || ('HTTP ' + r.status);
      }
    }).catch(function (err) {
      row.classList.add('border-rose-500/40');
      status.textContent = String(err);
    });
  };

  PZ.esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); };

  PZ.qr = function (el, text) {
    if (window.QRCode) {
      try { new QRCode(el, { text: text, width: 220, height: 220, colorDark: '#000', colorLight: '#fff' }); return; } catch (e) {}
    }
    el.innerHTML = '<img alt="qr" src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' + encodeURIComponent(text) + '">';
  };

  PZ.lang = window.PZ_LANG || {};
  PZ.t = function (k, v) {
    var s = PZ.lang[k] || k;
    if (v) for (var k2 in v) s = s.replace('{' + k2 + '}', v[k2]);
    return s;
  };
})();
