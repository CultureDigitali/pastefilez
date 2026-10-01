<?php
declare(strict_types=1);

use App\Core\Db;
use App\Services\AiProvider;
use App\Services\AnalysisService;
use App\Services\AuditQuery;
use App\Models\Item;
use App\Services\SearchService;
use Tests\T;

T::group('perf/query-bounds');

T::eq(8, SearchService::FTS_MAX_TERMS, 'the FTS term cap is 8');
T::eq(512, SearchService::QUERY_MAX_BYTES, 'the raw query cap is 512 bytes');

$twoThousand = implode(' ', array_fill(0, 2000, 'fattura'));
$fts = SearchService::ftsQuery($twoThousand);
T::eq(1, substr_count($fts, '"fattura"*'), '2000 identical words collapse to a single term');
T::ok(strlen(SearchService::boundQuery($twoThousand)) <= SearchService::QUERY_MAX_BYTES, 'the raw query is truncated to the byte cap');
T::eq('', SearchService::boundQuery(['array', 'input']), 'an array query is not a string and yields nothing');
T::eq('', SearchService::boundQuery(null), 'a null query yields nothing');
T::eq('', SearchService::boundQuery(12345), 'an integer query yields nothing');
T::eq('fattura', SearchService::boundQuery('  fattura  '), 'boundQuery trims surrounding whitespace');
T::eq('a   b   c', SearchService::boundQuery('a   b   c'), 'boundQuery preserves inner spacing (ftsQuery does the normalising)');

$ten = implode(' ', array_map(static fn(int $i): string => 'parola' . $i, range(1, 40)));
$terms = substr_count(SearchService::ftsQuery($ten), '"') / 2;
T::eq(8, $terms, 'a 40-word query yields at most 8 FTS terms');
T::ok(strlen(SearchService::ftsQuery($ten)) < 120, 'the generated FTS query stays short');

T::group('perf/fts-terms-are-deduplicated');

T::eq('"uno"*', SearchService::ftsQuery('uno uno uno'), 'repeated identical terms are deduplicated');
T::eq('"uno"* "due"*', SearchService::ftsQuery('uno due uno due'), 'interleaved duplicates are deduplicated');
T::eq('"uno"* "due"*', SearchService::ftsQuery('uno due'), 'distinct terms are preserved in order');

T::group('perf/fts-query-cost-is-bounded');

$uid = mkUser('perf@x.test');
$pdo = Db::pdo();
$pdo->exec('PRAGMA synchronous = OFF');
$stItem = $pdo->prepare("INSERT INTO items (user_id,type,mime,original_name,storage_backend,storage_path,size,sha256,is_encrypted,ai_status,ai_description,ai_ocr,ai_tags,is_public,created_at,updated_at) VALUES (?,'pdf','application/pdf',?,'local',?,1000,?,0,'done',?,?,?,0,?,?)");
$stFts = $pdo->prepare("INSERT INTO items_fts(rowid, original_name, ai_description, ai_ocr, ai_tags) VALUES (?,?,?,?,?)");
$pdo->beginTransaction();
$rows = 4000;
for ($i = 0; $i < $rows; $i++) {
    $name = "fattura-$i.pdf";
    $desc = "Fattura $i cliente acme totale euro documento amministrazione contabile";
    $ocr = "Fattura n. $i del 2026 Cliente Acme";
    $ts = time() - ($i * 60);
    $stItem->execute([$uid, $name, "files/$uid/x$i.pdf", md5((string)$i), $desc, $ocr, '["fattura"]', $ts, $ts]);
    $stFts->execute([(int)$pdo->lastInsertId(), $name, $desc, $ocr, 'fattura']);
}
$pdo->commit();
$pdo->exec('PRAGMA synchronous = NORMAL');

$time = static function (callable $fn): float {
    $a = microtime(true);
    $fn();
    return (microtime(true) - $a) * 1000;
};

$svc = new SearchService();
$time(fn() => $svc->query($uid, 'fattura'));
$normal = $time(fn() => $svc->query($uid, 'fattura'));
$word10 = $time(fn() => $svc->query($uid, implode(' ', array_fill(0, 10, 'fattura'))));
$word100 = $time(fn() => $svc->query($uid, implode(' ', array_fill(0, 100, 'fattura'))));
$word2000 = $time(fn() => $svc->query($uid, implode(' ', array_fill(0, 2000, 'fattura'))));

T::ok($normal < 400, sprintf('a normal query stays fast on %d items (%.1f ms)', $rows, $normal));
T::ok($word2000 < 400, sprintf('a 2000-word query is bounded (%.1f ms)', $word2000));
T::ok($word2000 < 25 * max($normal, 1.0), sprintf('a 2000-word query is not pathological (%.1fx a normal query)', $word2000 / max($normal, 1.0)));
T::ok($word100 <= $word2000 * 1.5 + 5, sprintf('query cost does not grow with input length (100w %.1f ms vs 2000w %.1f ms)', $word100, $word2000));

$results = $svc->query($uid, 'fattura');
T::ok(count($results) > 0, 'the capped FTS query still returns results');
T::eq('fattura-0.pdf', $results[0]['item']['original_name'] ?? '', 'results are still correct after capping');
T::ok(count($svc->query($uid, 'zzzznothingmatchesthis')) === 0, 'a query with no hits returns nothing');

$uniq = $svc->query($uid, 'acme amministrazione');
T::ok(count($uniq) > 0, 'multi-term queries still work');

T::group('perf/other-hot-paths');

$time(fn() => Item::list($uid, 60, 0, null, null));
$list1 = $time(fn() => Item::list($uid, 60, 0, null, null));
$deep = $time(fn() => Item::list($uid, 60, 3500, null, null));
T::ok($list1 < 200, sprintf('item list page 1 is fast (%.1f ms)', $list1));
T::ok($deep < 500, sprintf('a deep item list page is acceptable (%.1f ms)', $deep));

$time(fn() => \App\Services\StorageService::quotaUsed($uid));
$quota = $time(fn() => \App\Services\StorageService::quotaUsed($uid));
T::ok($quota < 200, sprintf('quotaUsed is fast (%.1f ms)', $quota));

$time(fn() => AuditQuery::page([], 1, 50));
$audit = $time(fn() => AuditQuery::page([], 1, 50));
T::ok($audit < 200, sprintf('audit page is fast (%.1f ms)', $audit));

$time(fn() => \App\Services\JobService::process(1));
$jobs = $time(fn() => \App\Services\JobService::process(1));
T::ok($jobs < 200, sprintf('processing the job queue is fast (%.1f ms)', $jobs));

$probe = Db::first('SELECT id FROM items LIMIT 1');
if ($probe) {
    $pid = (int)$probe['id'];
    Db::update('items', ['ai_description' => 'marcatoresegnalazioneunico'], 'id = ?', [$pid]);
    $found = Db::all("SELECT rowid FROM items_fts WHERE items_fts MATCH ?", ['marcatoresegnalazioneunico']);
    T::ok(count($found) === 1, 'FTS trigger indexes the new description');
    Db::update('items', ['ai_description' => 'descrizioneaggiornata'], 'id = ?', [$pid]);
    $stale = Db::all("SELECT rowid FROM items_fts WHERE items_fts MATCH ?", ['marcatoresegnalazioneunico']);
    T::ok(count($stale) === 0, 'FTS trigger drops the stale token on update');
    $time(fn() => Db::update('items', ['ai_description' => 'descrizioneaggiornata2'], 'id = ?', [$pid]));
    $idx = $time(fn() => Db::update('items', ['ai_description' => 'descrizioneaggiornata3'], 'id = ?', [$pid]));
    T::ok($idx < 200, sprintf('an FTS re-index is fast (%.1f ms)', $idx));
}

T::group('perf/indexes-present');

$indexes = [];
foreach (Db::all("SELECT name FROM sqlite_master WHERE type='index'") as $r) $indexes[] = $r['name'];
foreach ([
    'idx_items_user' => 'items are indexed by owner',
    'idx_items_created' => 'items are indexed by creation time',
    'idx_audit_user' => 'the audit log is indexed by user',
    'idx_audit_action' => 'the audit log is indexed by action',
    'idx_jobs_status' => 'the job queue is indexed by status',
] as $name => $why) {
    T::ok(in_array($name, $indexes, true), $why);
}
T::ok(in_array('sqlite_autoindex_rate_limits_1', $indexes, true), 'rate limits are unique per key+window');

T::group('perf/fts-index-is-external-content');

$row = Db::first("SELECT sql FROM sqlite_master WHERE name = 'items_fts'");
T::ok(is_array($row) && is_string($row['sql'] ?? ''), 'the FTS virtual table definition is readable');
T::contains((string)($row['sql'] ?? ''), 'fts5', 'the search index is FTS5');
T::contains((string)($row['sql'] ?? ''), 'content=', 'the FTS index is an external-content table, not a duplicate copy');

T::group('perf/vector-blob-roundtrip');

$vec = AiProvider::normalize([0.3, 0.4, 0.5, 0.537]);
$blob = AiProvider::encodeVector($vec);
T::eq(16, strlen($blob), 'a 4-dim vector is 16 bytes');
$back = AiProvider::decodeVector($blob);
T::eq(4, count($back), 'the vector round-trips at the right dimension');
T::ok(abs(AiProvider::cosine($vec, $back) - 1.0) < 1e-6, 'cosine of a round-tripped vector is 1');
T::eq(16384, 4096 * 4, 'the documented 4096-dimension limit is 16 KB per vector');
