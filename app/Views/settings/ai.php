<div class="max-w-4xl mx-auto space-y-4">
  <a href="<?= e(url('/settings')) ?>" class="text-sm text-fg-2 hover:text-fg">&larr; <?= e(t('common.back')) ?></a>
  <h1 class="text-2xl font-bold">🤖 <?= e(t('settings.tab.ai')) ?></h1>

  <?php if (empty($rows)): ?>
    <div class="card p-5 text-fg-2 text-sm"><?= e(t('ai.empty')) ?></div>
  <?php endif; ?>

  <?php foreach ($rows as $r): ?>
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
      <label for="f-provider-1"><?= e(t('ai.provider')) ?></label>
      <select class="input" name="provider" required id="f-provider-1" aria-label="<?= e(t('ai.provider')) ?>">
        <option value="openrouter">OpenRouter</option>
        <option value="nvidia">NVIDIA NIM</option>
        <option value="groq">Groq</option>
        <option value="ollama">Ollama (locale)</option>
      </select>
    </div>
    <div><label for="f-label-2"><?= e(t('ai.label')) ?></label><input class="input" name="label" id="f-label-2" aria-label="<?= e(t('ai.key.label')) ?>"></div>
    <div class="md:col-span-2"><label for="f-key-3"><?= e(t('ai.key')) ?></label><input class="input" name="key" placeholder="(vuoto per Ollama locale)" id="f-key-3" aria-label="<?= e(t('ai.key.value')) ?>"></div>
    <button class="btn btn-primary md:col-span-4 justify-center"><?= e(t('ai.add')) ?></button>
  </form>

  <form method="post" action="<?= e(url('/settings/ai/ollama-base')) ?>" class="card p-4 space-y-2">
    <h3 class="font-semibold">🦙 Ollama — Base URL</h3>
    <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
    <input class="input" name="ollama_base" value="<?= e((string)\App\Models\Setting::get($user['id'], 'ai.ollama_base', 'http://127.0.0.1:11434')) ?>" id="f-ollama-base-1" aria-label="<?= e(t('ai.ollama.url')) ?>">
    <p class="text-xs text-fg-2">Default <code>http://127.0.0.1:11434</code>. Cambialo se Ollama gira su un'altra macchina/porta. <strong>Deve essere raggiungibile dal server PasteFilez</strong> (non dal browser).</p>
    <button class="btn btn-primary"><?= e(t('common.save')) ?></button>
  </form>

  <form method="post" action="<?= e(url('/settings/ai/model-default')) ?>" class="card p-4 space-y-3">
    <h3 class="font-semibold"><?= e(t('ai.default')) ?></h3>
    <input type="hidden" name="_csrf" value="<?= e($csrf_token) ?>">
    <div class="grid md:grid-cols-4 gap-2 items-end">
      <div><label><?= e(t('ai.provider')) ?> predefinito</label>
        <select class="input" name="provider" id="f-provider-5" aria-label="<?= e(t('ai.provider')) ?>">
          <option value="">(nessuno)</option>
          <option value="openrouter" <?= ($defaults['provider']??'')==='openrouter'?'selected':'' ?>>OpenRouter</option>
          <option value="nvidia" <?= ($defaults['provider']??'')==='nvidia'?'selected':'' ?>>NVIDIA</option>
          <option value="groq" <?= ($defaults['provider']??'')==='groq'?'selected':'' ?>>Groq</option>
          <option value="ollama" <?= ($defaults['provider']??'')==='ollama'?'selected':'' ?>>Ollama</option>
        </select>
      </div>
      <div><label for="f-model-chat-2"><?= e(t('ai.default_chat')) ?></label><input class="input" name="model_chat" value="<?= e($defaults['chat'] ?? '') ?>" id="f-model-chat-2" aria-label="<?= e(t('ai.model.chat')) ?>"></div>
      <div><label for="f-model-vision-3"><?= e(t('ai.default_vision')) ?></label><input class="input" name="model_vision" value="<?= e($defaults['vision'] ?? '') ?>" id="f-model-vision-3" aria-label="<?= e(t('ai.model.vision')) ?>"></div>
      <div><label for="f-model-embedding-4"><?= e(t('ai.default_embedding')) ?></label><input class="input" name="model_embedding" value="<?= e($defaults['embedding'] ?? '') ?>" id="f-model-embedding-4"></div>
    </div>
    <button class="btn btn-primary"><?= e(t('common.save')) ?></button>
  </form>
</div>
