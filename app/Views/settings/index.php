<h1 class="text-2xl font-bold mb-4">⚙ <?= e(t('settings.title')) ?></h1>
<div class="tabs">
  <a class="<?= $tab==='profile'?'active':'' ?>" href="?tab=profile"><?= e(t('settings.tab.profile')) ?></a>
  <a class="<?= $tab==='security'?'active':'' ?>" href="?tab=security"><?= e(t('settings.tab.security')) ?></a>
  <a class="<?= $tab==='preferences'?'active':'' ?>" href="?tab=preferences"><?= e(t('settings.tab.preferences')) ?></a>
  <a class="<?= $tab==='ai'?'active':'' ?>" href="?tab=ai"><?= e(t('settings.tab.ai')) ?></a>
  <?php if (!empty($is_admin)): ?>
  <a class="<?= $tab==='cloud'?'active':'' ?>" href="?tab=cloud"><?= e(t('settings.tab.cloud')) ?></a>
  <a class="<?= $tab==='smtp'?'active':'' ?>" href="?tab=smtp"><?= e(t('settings.tab.smtp')) ?></a>
  <?php endif; ?>
  <a class="<?= $tab==='sessions'?'active':'' ?>" href="?tab=sessions"><?= e(t('settings.tab.sessions')) ?></a>
  <a class="<?= $tab==='webhooks'?'active':'' ?>" href="<?= e(url('/settings/webhooks')) ?>">🔗 <?= e(t('settings.tab.webhooks')) ?></a>
</div>

<?php if ($tab === 'profile'): ?>
  <form method="post" action="<?= e(url('/settings/profile')) ?>" class="card p-5 max-w-xl space-y-4">
    <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
    <div><label for="f-display-name-1"><?= e(t('install.name')) ?></label><input class="input" name="display_name" value="<?= e($user['display_name'] ?? '') ?>" id="f-display-name-1"></div>
    <div><label>Email</label><input class="input" type="email" name="email" value="<?= e($user['email']) ?>" required id="f-email-2" aria-label="<?= e(t('install.email')) ?>"></div>
    <button class="btn btn-primary"><?= e(t('common.save')) ?></button>
  </form>
<?php elseif ($tab === 'security'): ?>
  <div class="grid lg:grid-cols-2 gap-4">
    <form method="post" action="<?= e(url('/settings/password')) ?>" class="card p-5 space-y-4">
      <h2 class="font-semibold">Cambia password</h2>
      <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
      <div><label>Attuale</label><input class="input" type="password" name="current" required id="f-current-3" aria-label="<?= e(t('settings.password.current')) ?>"></div>
      <div><label>Nuova (min 12)</label><input class="input" type="password" name="password" minlength="12" required id="f-password-4" aria-label="<?= e(t('settings.password.new')) ?>"></div>
      <button class="btn btn-primary"><?= e(t('common.save')) ?></button>
    </form>
    <form method="post" action="<?= e(url('/settings/totp/disable')) ?>" class="card p-5 space-y-3" onsubmit="return PZ.confirm('Disattivare 2FA?')">
      <h2 class="font-semibold">2FA (TOTP)</h2>
      <?php if ((int)$user['totp_enabled'] === 1): ?>
        <p class="text-emerald-400 text-sm">✓ <?= e(t('settings.totp.enabled')) ?></p>
        <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
        <input class="input" name="code" pattern="\d{6}" placeholder="Codice a 6 cifre" required id="f-code-5" aria-label="<?= e(t('settings.totp.code')) ?>">
        <button class="btn btn-danger"><?= e(t('settings.totp.disable')) ?></button>
      <?php else: ?>
        <p class="text-fg-2 text-sm">2FA non attivo.</p>
        <a class="btn btn-primary" href="<?= e(url('/settings/totp')) ?>"><?= e(t('settings.totp.enable')) ?></a>
      <?php endif; ?>
    </form>
  </div>
<?php elseif ($tab === 'preferences'): ?>
  <form method="post" action="<?= e(url('/settings/preferences')) ?>" class="card p-5 max-w-xl space-y-4">
    <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
    <div>
      <label for="f-language-6"><?= e(t('settings.preferences.lang')) ?></label>
      <select class="input" name="language" id="f-language-6">
        <option value="it" <?= $user['language']==='it'?'selected':'' ?>>Italiano</option>
        <option value="en" <?= $user['language']==='en'?'selected':'' ?>>English</option>
      </select>
    </div>
    <div>
      <label for="f-theme-7"><?= e(t('settings.preferences.theme')) ?></label>
      <select class="input" name="theme" id="f-theme-7">
        <option value="dark" <?= ($user['theme']??'dark')==='dark'?'selected':'' ?>><?= e(t('settings.preferences.theme.dark')) ?></option>
        <option value="light" <?= ($user['theme']??'')==='light'?'selected':'' ?>><?= e(t('settings.preferences.theme.light')) ?></option>
        <option value="auto" <?= ($user['theme']??'')==='auto'?'selected':'' ?>><?= e(t('settings.preferences.theme.auto')) ?></option>
      </select>
    </div>
    <button class="btn btn-primary"><?= e(t('common.save')) ?></button>
  </form>
  <?php if (!empty($is_admin)): ?>
  <form method="post" action="<?= e(url('/settings/encryption')) ?>" class="card p-5 max-w-xl mt-4 space-y-3">
    <h2 class="font-semibold"><?= e(t('settings.preferences.encryption')) ?></h2>
    <p class="text-xs text-fg-2"><?= e(t('settings.preferences.encryption_help')) ?></p>
    <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
    <label for="f-enabled-1" class="flex items-center gap-2 text-sm">
      <input type="checkbox" name="enabled" value="1" <?= \App\Core\Config::get('encrypt_files')?'checked':'' ?> id="f-enabled-3">
      Abilita cifratura AES-256-GCM dei file a riposo
    </label>
    <button class="btn"><?= e(t('common.save')) ?></button>
  </form>
  <?php endif; ?>
<?php elseif ($tab === 'ai'): ?>
  <div class="space-y-4">
    <p class="text-sm text-fg-2">Configura le chiavi API per OpenRouter, NVIDIA NIM e Groq. Clicca "Testa" per verificare, "Recupera modelli" per scoprire quelli disponibili.</p>
    <?php foreach ($rows ?? [] as $r): ?>
      <div class="card p-4">
        <div class="flex items-center gap-3">
          <div class="font-semibold text-lg"><?= e(ucfirst($r['provider'])) ?></div>
          <div class="text-fg-2 text-xs"><?= e($r['label'] ?? '') ?></div>
          <div class="ml-auto flex gap-2 text-xs">
            <?php if ($r['last_test_status']): ?>
              <span class="tag <?= $r['last_test_status']==='ok'?'tag-emerald':'tag-rose' ?>"><?= e($r['last_test_status']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div class="mt-3 flex gap-2 flex-wrap">
          <form method="post" action="<?= e(url('/settings/ai/' . (int)$r['id'] . '/test')) ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
            <button class="btn btn-sm"><?= e(t('ai.test')) ?></button>
          </form>
          <form method="post" action="<?= e(url('/settings/ai/' . (int)$r['id'] . '/refresh-models')) ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
            <button class="btn btn-sm"><?= e(t('ai.refresh')) ?></button>
          </form>
          <form method="post" action="<?= e(url('/settings/ai/' . (int)$r['id'] . '/delete')) ?>" onsubmit="return PZ.confirm()">
            <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
            <button class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
          </form>
        </div>
        <?php $models = $models[$r['provider']] ?? []; if ($models): ?>
          <details class="mt-3">
            <summary class="cursor-pointer text-sm text-brand-400"><?= e(t('ai.models')) ?> (<?= count($models) ?>)</summary>
            <table class="t mt-2">
              <thead><tr><th scope="col">ID</th><th scope="col"><?= e(t('ai.model.vision')) ?></th><th scope="col"><?= e(t('ai.model.embed')) ?></th><th scope="col"><?= e(t('ai.model.context')) ?></th></tr></thead>
              <tbody>
                <?php foreach ($models as $m): ?>
                  <tr>
                    <td class="font-mono text-xs"><?= e($m['model_id']) ?></td>
                    <td><?= (int)$m['supports_vision'] ? '✓' : '' ?></td>
                    <td><?= (int)$m['supports_embedding'] ? '✓' : '' ?></td>
                    <td class="text-fg-2 text-xs"><?= e((string)($m['context_length'] ?? '')) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </details>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <form method="post" action="<?= e(url('/settings/ai')) ?>" class="card p-4 grid md:grid-cols-4 gap-2 items-end">
      <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
      <div>
        <label for="f-provider-8"><?= e(t('ai.provider')) ?></label>
        <select class="input" name="provider" required id="f-provider-8" aria-label="<?= e(t('ai.provider')) ?>">
          <option value="openrouter">OpenRouter</option>
          <option value="nvidia">NVIDIA NIM</option>
          <option value="groq">Groq</option>
          <option value="ollama">Ollama (locale)</option>
        </select>
      </div>
      <div><label for="f-label-9"><?= e(t('ai.label')) ?></label><input class="input" name="label" id="f-label-9" aria-label="<?= e(t('ai.key.label')) ?>"></div>
      <div class="md:col-span-2"><label for="f-key-10"><?= e(t('ai.key')) ?></label><input class="input" name="key" placeholder="(vuoto per Ollama locale)" id="f-key-10" aria-label="<?= e(t('ai.key.value')) ?>"></div>
      <div class="md:col-span-4">
        <label>Base URL Ollama</label>
        <input class="input" name="ollama_base" form="ai-ollama-base" value="<?= e(\App\Models\Setting::get($user['id'], 'ai.ollama_base', 'http://127.0.0.1:11434')) ?>" id="f-ollama-base-4" aria-label="<?= e(t('ai.ollama.url')) ?>">
        <p class="text-xs text-fg-2 mt-1">Default http://127.0.0.1:11434. Cambialo se Ollama gira su un'altra macchina o porta (es. http://nas:11434).</p>
      </div>
      <button class="btn btn-primary md:col-span-4 justify-center"><?= e(t('ai.add')) ?></button>
    </form>

    <form method="post" id="ai-ollama-base" action="<?= e(url('/settings/ai/ollama-base')) ?>" class="hidden"></form>

    <form method="post" action="<?= e(url('/settings/ai/model-default')) ?>" class="card p-4 space-y-3">
      <h3 class="font-semibold"><?= e(t('ai.default')) ?></h3>
      <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
      <div class="grid md:grid-cols-4 gap-2 items-end">
        <div><label><?= e(t('ai.provider')) ?> predefinito</label>
          <select class="input" name="provider" id="f-provider-12" aria-label="<?= e(t('ai.provider')) ?>">
            <option value="">(nessuno)</option>
            <option value="openrouter" <?= ($defaults['provider']??'')==='openrouter'?'selected':'' ?>>OpenRouter</option>
            <option value="nvidia" <?= ($defaults['provider']??'')==='nvidia'?'selected':'' ?>>NVIDIA</option>
            <option value="groq" <?= ($defaults['provider']??'')==='groq'?'selected':'' ?>>Groq</option>
            <option value="ollama" <?= ($defaults['provider']??'')==='ollama'?'selected':'' ?>>Ollama</option>
          </select>
        </div>
        <div><label for="f-model-chat-5"><?= e(t('ai.default_chat')) ?></label><input class="input" name="model_chat" value="<?= e($defaults['chat'] ?? '') ?>" id="f-model-chat-5" aria-label="<?= e(t('ai.model.chat')) ?>"></div>
        <div><label for="f-model-vision-6"><?= e(t('ai.default_vision')) ?></label><input class="input" name="model_vision" value="<?= e($defaults['vision'] ?? '') ?>" id="f-model-vision-6" aria-label="<?= e(t('ai.model.vision')) ?>"></div>
        <div><label for="f-model-embedding-7"><?= e(t('ai.default_embedding')) ?></label><input class="input" name="model_embedding" value="<?= e($defaults['embedding'] ?? '') ?>" id="f-model-embedding-7"></div>
      </div>
      <button class="btn btn-primary"><?= e(t('common.save')) ?></button>
    </form>
  </div>
<?php elseif ($tab === 'cloud'): ?>
  <?php
    $google = \App\Core\Db::first('SELECT * FROM cloud_tokens WHERE user_id = ? AND provider = ?', [$user['id'], 'google']);
    $dropbox = \App\Core\Db::first('SELECT * FROM cloud_tokens WHERE user_id = ? AND provider = ?', [$user['id'], 'dropbox']);
  ?>
  <?php if (!empty($is_admin)): ?>
  <form method="post" action="<?= e(url('/settings/cloud')) ?>" class="card p-5 space-y-4">
    <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
    <h2 class="font-semibold">Google Drive</h2>
    <div><label>Client ID</label><input class="input" name="google_client_id" value="<?= e(\App\Core\Config::get('google.client_id')) ?>" id="f-google-client-id-8" aria-label="<?= e(t('cloud.google.client_id')) ?>"></div>
    <div><label>Client Secret</label><input class="input" name="google_client_secret" value="<?= e(\App\Core\Config::get('google.client_secret')) ?>" id="f-google-client-secret-9" aria-label="<?= e(t('cloud.google.client_secret')) ?>"></div>
    <div><label>Redirect URI</label><input class="input" name="google_redirect" value="<?= e(\App\Core\Config::get('google.redirect')) ?>" placeholder="<?= e(rtrim((string)\App\Core\Config::get('app_url'),'/')) ?>/settings/cloud/google/callback" id="f-google-redirect-10" aria-label="<?= e(t('cloud.google.redirect')) ?>"></div>
    <h2 class="font-semibold pt-4">Dropbox</h2>
    <div><label>Client ID</label><input class="input" name="dropbox_client_id" value="<?= e(\App\Core\Config::get('dropbox.client_id')) ?>" id="f-dropbox-client-id-11" aria-label="<?= e(t('cloud.dropbox.client_id')) ?>"></div>
    <div><label>Client Secret</label><input class="input" name="dropbox_client_secret" value="<?= e(\App\Core\Config::get('dropbox.client_secret')) ?>" id="f-dropbox-client-secret-12" aria-label="<?= e(t('cloud.dropbox.client_secret')) ?>"></div>
    <div><label>Redirect URI</label><input class="input" name="dropbox_redirect" value="<?= e(\App\Core\Config::get('dropbox.redirect')) ?>" placeholder="<?= e(rtrim((string)\App\Core\Config::get('app_url'),'/')) ?>/settings/cloud/dropbox/callback" id="f-dropbox-redirect-13" aria-label="<?= e(t('cloud.dropbox.redirect')) ?>"></div>
    <p class="text-xs text-fg-2"><?= e(t('cloud.redirect_help')) ?></p>
    <button class="btn btn-primary"><?= e(t('common.save')) ?></button>
  </form>
  <?php endif; ?>

  <div class="card p-5 mt-4 space-y-3">
    <h3 class="font-semibold"><?= e(t('cloud.choose_backend')) ?></h3>
    <form method="post" action="<?= e(url('/settings/storage-backend')) ?>" class="flex gap-2 items-end">
      <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
      <select class="input" name="backend" id="f-backend-22" aria-label="<?= e(t('cloud.backend')) ?>">
        <option value="local" <?= $user['storage_backend']==='local'?'selected':'' ?>><?= e(t('cloud.backend.local')) ?></option>
        <option value="google" <?= $user['storage_backend']==='google'?'selected':'' ?>><?= e(t('cloud.backend.google')) ?></option>
        <option value="dropbox" <?= $user['storage_backend']==='dropbox'?'selected':'' ?>><?= e(t('cloud.backend.dropbox')) ?></option>
      </select>
      <button class="btn"><?= e(t('common.save')) ?></button>
    </form>
  </div>

  <div class="grid md:grid-cols-2 gap-4 mt-4">
    <div class="card p-4">
      <h3 class="font-semibold"><?= e(t('cloud.google')) ?></h3>
      <?php if ($google): ?>
        <p class="text-emerald-400 text-sm">✓ <?= e(t('cloud.connected', ['email' => $google['account_email'] ?? 'OK'])) ?></p>
        <form method="post" action="<?= e(url('/settings/cloud/google/disconnect')) ?>" class="mt-2">
          <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
          <button class="btn btn-danger btn-sm"><?= e(t('cloud.disconnect')) ?></button>
        </form>
      <?php else: ?>
        <p class="text-fg-2 text-sm"><?= e(t('cloud.disconnected')) ?></p>
        <a class="btn btn-primary btn-sm mt-2" href="<?= e(url('/settings/cloud/google/connect')) ?>"><?= e(t('cloud.connect')) ?></a>
      <?php endif; ?>
    </div>
    <div class="card p-4">
      <h3 class="font-semibold"><?= e(t('cloud.dropbox')) ?></h3>
      <?php if ($dropbox): ?>
        <p class="text-emerald-400 text-sm">✓ <?= e(t('cloud.connected', ['email' => $dropbox['account_email'] ?? 'OK'])) ?></p>
        <form method="post" action="<?= e(url('/settings/cloud/dropbox/disconnect')) ?>" class="mt-2">
          <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
          <button class="btn btn-danger btn-sm"><?= e(t('cloud.disconnect')) ?></button>
        </form>
      <?php else: ?>
        <p class="text-fg-2 text-sm"><?= e(t('cloud.disconnected')) ?></p>
        <a class="btn btn-primary btn-sm mt-2" href="<?= e(url('/settings/cloud/dropbox/connect')) ?>"><?= e(t('cloud.connect')) ?></a>
      <?php endif; ?>
    </div>
  </div>
<?php elseif ($tab === 'smtp'): ?>
  <?php $smtp = (array)\App\Core\Config::get('smtp'); ?>
  <?php if (!empty($is_admin)): ?>
  <form method="post" action="<?= e(url('/settings/smtp')) ?>" class="card p-5 max-w-xl space-y-3">
    <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
    <label for="f-enabled-2" class="flex items-center gap-2 text-sm"><input type="checkbox" name="enabled" value="1" <?= $smtp['enabled']?'checked':'' ?> id="f-enabled-14"> <?= e(t('smtp.enabled')) ?></label>
    <div><label for="f-host-15"><?= e(t('smtp.host')) ?></label><input class="input" name="host" value="<?= e($smtp['host']) ?>" id="f-host-15"></div>
    <div class="grid grid-cols-2 gap-2">
      <div><label for="f-port-16"><?= e(t('smtp.port')) ?></label><input class="input" name="port" value="<?= e((string)$smtp['port']) ?>" id="f-port-16"></div>
      <div><label for="f-encryption-25"><?= e(t('smtp.enc')) ?></label>
        <select class="input" name="encryption" id="f-encryption-25">
          <option value="tls" <?= $smtp['encryption']==='tls'?'selected':'' ?>>TLS</option>
          <option value="ssl" <?= $smtp['encryption']==='ssl'?'selected':'' ?>>SSL</option>
          <option value="">none</option>
        </select>
      </div>
    </div>
    <div><label for="f-user-17"><?= e(t('smtp.user')) ?></label><input class="input" name="user" value="<?= e($smtp['user']) ?>" id="f-user-17"></div>
    <div><label for="f-pass-18"><?= e(t('smtp.pass')) ?></label><input class="input" type="password" name="pass" value="<?= e($smtp['pass']) ?>" id="f-pass-18"></div>
    <div class="grid grid-cols-2 gap-2">
      <div><label for="f-from-19"><?= e(t('smtp.from')) ?></label><input class="input" name="from" value="<?= e($smtp['from']) ?>" id="f-from-19"></div>
      <div><label for="f-from-name-20"><?= e(t('smtp.from_name')) ?></label><input class="input" name="from_name" value="<?= e($smtp['from_name']) ?>" id="f-from-name-20"></div>
    </div>
    <div><label>Email di test (opzionale)</label><input class="input" name="test_to" type="email" id="f-test-to-30" aria-label="<?= e(t('smtp.test_to')) ?>"></div>
    <div class="flex gap-2">
      <button class="btn btn-primary"><?= e(t('common.save')) ?></button>
      <button class="btn" name="test" value="1"><?= e(t('smtp.test')) ?></button>
    </div>
  </form>
  <?php else: ?>
  <div class="card p-5 text-fg-2 text-sm"><?= e(t('errors.403.title')) ?></div>
  <?php endif; ?>
<?php elseif ($tab === 'sessions'): ?>
  <?php
    $sessions = \App\Core\Auth::sessions((int)$user['id']);
    $tokens = \App\Core\Db::all('SELECT id, name, last_used_at, created_at, expires_at FROM api_tokens WHERE user_id = ? ORDER BY id DESC', [$user['id']]);
  ?>
  <div class="grid lg:grid-cols-2 gap-4">
    <div class="card p-4">
      <h3 class="font-semibold mb-3"><?= e(t('settings.sessions.title')) ?></h3>
      <table class="t">
        <thead><tr><th scope="col">IP</th><th scope="col">Browser</th><th scope="col">Attività</th><th scope="col"></th></tr></thead>
        <tbody>
        <?php foreach ($sessions as $s): ?>
          <tr>
            <td class="font-mono text-xs"><?= e($s['ip']) ?></td>
            <td class="text-xs text-fg-2"><?= e(mb_substr($s['ua'], 0, 50)) ?></td>
            <td class="text-xs"><?= e(date('d M H:i', (int)$s['last_seen_at'])) ?></td>
            <td>
              <form method="post" action="<?= e(url('/settings/sessions/' . $s['id'] . '/revoke')) ?>" onsubmit="return PZ.confirm()">
                <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
                <button class="btn btn-sm btn-danger"><?= e(t('settings.sessions.revoke')) ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card p-4">
      <h3 class="font-semibold mb-3"><?= e(t('settings.api_tokens.title')) ?></h3>
      <form method="post" action="<?= e(url('/settings/api-tokens')) ?>" class="flex gap-2 mb-3">
        <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
        <input class="input flex-1" name="name" placeholder="<?= e(t('settings.api_tokens.name')) ?>" required id="f-name-21" aria-label="<?= e(t('ai.webhook.name')) ?>">
        <button class="btn btn-primary"><?= e(t('settings.api_tokens.create')) ?></button>
      </form>
      <table class="t">
        <thead><tr><th scope="col">Nome</th><th scope="col">Creato</th><th scope="col">Ultimo uso</th><th scope="col"></th></tr></thead>
        <tbody>
        <?php foreach ($tokens as $tk): ?>
          <tr>
            <td class="text-sm"><?= e($tk['name']) ?></td>
            <td class="text-xs text-fg-2"><?= e(date('d M Y', (int)$tk['created_at'])) ?></td>
            <td class="text-xs text-fg-2"><?= $tk['last_used_at'] ? e(date('d M H:i', (int)$tk['last_used_at'])) : e(t('common.never')) ?></td>
            <td>
              <form method="post" action="<?= e(url('/settings/api-tokens/' . (int)$tk['id'] . '/delete')) ?>" onsubmit="return PZ.confirm()">
                <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
                <button class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
