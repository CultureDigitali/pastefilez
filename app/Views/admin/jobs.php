<h1 class="text-2xl font-bold mb-4">⚙ <?= e(t('admin.jobs.title')) ?></h1>
<?php if (!$rows): ?>
  <div class="card p-8 text-center text-fg-2"><?= e(t('admin.jobs.empty')) ?></div>
<?php else: ?>
  <div class="card overflow-x-auto">
    <table class="t">
      <thead><tr><th scope="col">ID</th><th scope="col"><?= e(t('admin.jobs.type')) ?></th><th scope="col"><?= e(t('admin.jobs.status')) ?></th><th scope="col"><?= e(t('admin.jobs.scheduled')) ?></th><th scope="col"><?= e(t('admin.jobs.attempts')) ?></th><th scope="col">Errore</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $j): ?>
          <tr>
            <td><?= (int)$j['id'] ?></td>
            <td><?= e($j['type']) ?></td>
            <td>
              <?php $c = ['pending'=>'tag-amber','processing'=>'tag-blue','done'=>'tag-emerald','failed'=>'tag-rose'][$j['status']] ?? ''; ?>
              <span class="tag <?= e($c) ?>"><?= e($j['status']) ?></span>
            </td>
            <td class="text-xs"><?= e(date('d M H:i', (int)$j['scheduled_at'])) ?></td>
            <td><?= (int)$j['attempts'] ?></td>
            <td class="text-xs text-rose-300 max-w-md truncate"><?= e((string)($j['last_error'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
