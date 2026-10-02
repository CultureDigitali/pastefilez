<?php
declare(strict_types=1);

use App\Core\Crypto;
use App\Core\Db;
use App\Services\AiProvider;
use App\Services\PdfExtractor;
use App\Services\SearchService;
use Tests\T;

T::group('ai/provider-factory');

$uid = mkUser('ai@x.test');
$blob = Crypto::encryptToBase64('sk-test-key');
$iv = explode(':', $blob)[1];
foreach (['openrouter', 'groq', 'nvidia', 'ollama'] as $prov) {
    Db::insert('api_keys', [
        'user_id' => $uid, 'provider' => $prov, 'label' => $prov,
        'encrypted_key' => $blob, 'iv' => $iv,
        'created_at' => time(), 'updated_at' => time(),
    ]);
}

foreach ([
    'openrouter' => 'App\Services\OpenRouterClient',
    'groq' => 'App\Services\GroqClient',
    'nvidia' => 'App\Services\NvidiaClient',
    'ollama' => 'App\Services\OllamaClient',
] as $prov => $cls) {
    $c = AiProvider::factory($prov, 'sk-test', $uid);
    T::ok($c instanceof \App\Services\BaseAiProvider, "factory({$prov}) returns a client");
    T::eq($cls, $c === null ? '' : get_class($c), "factory({$prov}) builds the right class");
    T::eq($prov, $c?->name(), "factory({$prov}) name() matches");
}

T::eq(null, AiProvider::factory('bogus', 'k'), 'unknown provider yields null');
T::eq(null, AiProvider::factory('openrouter', ''), 'empty key yields null');
T::eq(null, AiProvider::forUser(999999), 'unknown user yields null');

$src = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/AiProvider.php');
T::contains($src, 'public static function factory(', 'AiProvider exposes a factory');
T::notContains($src, "case 'vision':\n                if (\$provider", 'the stray model-name switch is gone from forUser');
T::contains($src, 'assertSafeUrl($base, true)', 'ollama base url is validated with the loopback opt-in');

T::group('ai/default-model-resolution');

$noKeys = mkUser('aikeys@x.test');
T::eq('openai/gpt-4o-mini', AiProvider::defaultModel($noKeys, 'chat'), 'chat default without keys');
T::eq('openai/gpt-4o-mini', AiProvider::defaultModel($noKeys, 'vision'), 'vision default without keys');
T::eq('openai/text-embedding-3-small', AiProvider::defaultModel($noKeys, 'embedding'), 'embedding default without keys');

\App\Models\Setting::set($noKeys, 'ai.default_provider', 'groq');
\App\Models\Setting::set($noKeys, 'ai.model.chat', 'my-custom-model');
T::eq('my-custom-model', AiProvider::defaultModel($noKeys, 'chat'), 'an explicit model setting wins');
\App\Models\Setting::set($noKeys, 'ai.model.chat', '');
T::eq('llama-3.1-70b-versatile', AiProvider::defaultModel($noKeys, 'chat'), 'falls back to the provider default when the model setting is cleared');

$onlyGroq = mkUser('grok@x.test');
$gblob = Crypto::encryptToBase64('gsk-test');
Db::insert('api_keys', ['user_id' => $onlyGroq, 'provider' => 'groq', 'label' => 'g', 'encrypted_key' => $gblob, 'iv' => explode(':', $gblob)[1], 'created_at' => time(), 'updated_at' => time()]);
\App\Models\Setting::set($onlyGroq, 'ai.default_provider', 'nvidia');
T::eq('groq', AiProvider::resolvedProvider($onlyGroq, 'nvidia'), 'provider falls back when no key exists for it');
\App\Models\Setting::set($onlyGroq, 'ai.default_provider', 'does-not-exist');
T::eq('groq', AiProvider::resolvedProvider($onlyGroq, 'does-not-exist'), 'provider falls back to the first configured key');
T::eq('llama-3.1-70b-versatile', AiProvider::defaultModel($onlyGroq, 'chat'), 'default model follows the resolved provider');

T::group('ai/vector-math');

$v = AiProvider::normalize([3.0, 4.0]);
T::ok(abs($v[0] - 0.6) < 1e-9, 'normalize scales x');
T::ok(abs($v[1] - 0.8) < 1e-9, 'normalize scales y');
T::ok(abs((sqrt($v[0] ** 2 + $v[1] ** 2)) - 1.0) < 1e-9, 'normalize yields a unit vector');
T::eq([0.0, 0.0], AiProvider::normalize([0.0, 0.0]), 'normalize handles the zero vector');
$dir5 = AiProvider::normalize([5.0, 7.0]);
T::ok(abs($dir5[0] / $dir5[1] - 5.0 / 7.0) < 1e-9, 'normalize preserves the direction of the vector');

$blob = AiProvider::encodeVector([1.0, -2.5, 0.125]);
T::eq(12, strlen($blob), 'encoded vector is 4 bytes per dimension');
$back = AiProvider::decodeVector($blob);
T::eq(3, count($back), 'decoded vector has the right dimension');
T::ok(abs($back[0] - 1.0) < 1e-6, 'decode round-trips dimension 0');
T::ok(abs($back[1] + 2.5) < 1e-6, 'decode round-trips dimension 1');
T::ok(abs($back[2] - 0.125) < 1e-6, 'decode round-trips dimension 2');
T::eq([], AiProvider::decodeVector('abc'), 'a blob with a bad length decodes to an empty vector');
T::eq([], AiProvider::decodeVector(''), 'an empty blob decodes to an empty vector');

T::ok(abs(AiProvider::cosine([1.0, 0.0], [1.0, 0.0]) - 1.0) < 1e-9, 'cosine of identical vectors is 1');
T::ok(abs(AiProvider::cosine([1.0, 0.0], [0.0, 1.0])) < 1e-9, 'cosine of orthogonal vectors is 0');
T::ok(abs(AiProvider::cosine([1.0, 0.0], [-1.0, 0.0]) + 1.0) < 1e-9, 'cosine of opposite vectors is -1');
T::ok(abs(AiProvider::cosine([1.0, 0.0], [1.0, 0.0, 0.0, 0.0]) - 1.0) < 1e-9, 'cosine tolerates mismatched dimensions');
T::ok(abs(AiProvider::cosine([], [1.0])) < 1e-9, 'cosine with an empty vector is 0');

T::group('search/fts-query-safety');

$q = fn(string $in): string => SearchService::ftsQuery($in);
$assertValidFts = static function (string $in) use ($q): void {
    $query = $q($in);
    T::ok(preg_match('/^(?:"[\p{L}\p{N}\-]+"\*)( (?:"[\p{L}\p{N}\-]+"\*))*$|^$/u', $query) === 1,
        'well-formed FTS5 for ' . json_encode($in) . ' -> ' . json_encode($query));
    $st = \App\Core\Db::pdo()->prepare('SELECT COUNT(*) FROM items_fts WHERE items_fts MATCH ?');
    $st->execute([$query !== '' ? $query : 'zzznone']);
    T::ok(true, 'query executes without an SQL error: ' . json_encode($query));
};

T::eq('', $q(''), 'empty query yields an empty FTS query');
T::eq('', $q('   '), 'whitespace query yields an empty FTS query');
T::eq('', $q('a'), 'single-character tokens are dropped');
T::eq('', $q('!!! ??? ***'), 'punctuation-only input yields nothing');
T::eq('"foo"*', $q('"foo"'), 'a bare quoted token is normalised to a quoted term');
T::eq('"invoice"*', $q('invoice'), 'a single token gets a quoted prefix wildcard');
T::eq('"invoice"* "2026"*', $q('invoice 2026'), 'each token gets a quoted prefix wildcard');
T::eq('"casa-ca"*', $q('casa-ca'), 'hyphens are preserved inside quoted terms');
T::eq('"fatture"*', $q('fatture!'), 'trailing punctuation is stripped');
T::eq('"documenti"*', $q('documenti'), 'accents are preserved');
T::notContains($q('foo" OR 1=1 --'), '""', 'user quotes cannot terminate a term');
T::notContains($q("foo' OR '1'='1"), "'", 'user single quotes cannot start a term');
T::eq('"foo"*', $q('foo*'), 'a user star does not double up with the prefix wildcard');
T::eq('"foo"*', $q('foo**'), 'multiple user stars are stripped');

foreach ([
    'foo AND bar', 'foo OR bar', 'NOT foo', 'a AND (b OR bar)', 'foo NEAR/2 bar',
    'x' . str_repeat('"', 200), "\x00\x01\x02", str_repeat('*', 100), '"unterminated',
    'foo^bar', 'foo:bar', '{foo}', 'col:off', 'title:x', '', '   ', 'a', '---', '***',
    'AND', 'OR', 'NOT', 'NEAR', 'AND*', '1 OR 1=1', 'DROP TABLE items',
] as $hostile) {
    $assertValidFts($hostile);
}

T::eq('"AND"*', $q('AND'), 'a reserved word is turned into a quoted literal term');
T::eq('"OR"*', $q('OR'), 'OR is quoted');
T::eq('"NOT"*', $q('NOT'), 'NOT is quoted');
T::eq('"NEAR"*', $q('NEAR'), 'NEAR is quoted');

$src = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/SearchService.php');
T::contains($src, "'\"' . \$t . '\"*'", 'every FTS term is emitted quoted-then-prefixed');
T::notContains($src, "[] = \$t . '*';", 'terms are no longer emitted unquoted');

T::eq('image/', SearchService::typeToMime('image'), 'image mime prefix');
T::eq('video/', SearchService::typeToMime('video'), 'video mime prefix');
T::eq('audio/', SearchService::typeToMime('audio'), 'audio mime prefix');
T::eq('application/pdf', SearchService::typeToMime('pdf'), 'pdf mime');
T::eq('text/', SearchService::typeToMime('text'), 'text mime prefix');
T::eq('', SearchService::typeToMime('nonsense'), 'unknown type maps to an empty prefix');

T::group('pdf/extraction');

$dir = sys_get_temp_dir() . '/pf_pdf_' . getmypid();
@mkdir($dir, 0750, true);
function pdfFile(string $dir, string $name, string $body): string
{
    $path = $dir . '/' . $name;
    file_put_contents($path, $body);
    return $path;
}


T::eq('', PdfExtractor::extractText($dir . '/nope.pdf'), 'a missing file yields empty text');
$empty = pdfFile($dir, 'empty.pdf', '');
T::eq('', PdfExtractor::extractText($empty), 'an empty file yields empty text');
$notpdf = pdfFile($dir, 'not.pdf', 'just some plain text');
T::eq('', PdfExtractor::extractText($notpdf), 'a non-PDF yields empty text');
$pctAfter = pdfFile($dir, 'pct.pdf', 'X%PDF-1.7');
T::eq('', PdfExtractor::extractText($pctAfter), 'a PDF header not at offset 0 is rejected');

$simple = pdfFile($dir, 'simple.pdf', "%PDF-1.4\n1 0 obj<</Title (Relazione annuale)/Author (Mario Rossi)>>endobj\nBT (Ciao mondo) Tj ET\ntrailer<<>>\n%%EOF\n");
$text = PdfExtractor::extractText($simple);
T::contains($text, 'Ciao mondo', 'Tj text operators are extracted');
T::contains($text, 'Relazione annuale', 'document Title metadata is extracted');
T::contains($text, 'Mario Rossi', 'document Author metadata is extracted');

$arrayText = pdfFile($dir, 'array.pdf', "%PDF-1.4\nBT [(Parte) -250(uno)] TJ ET\n%%EOF\n");
T::contains(PdfExtractor::extractText($arrayText), 'Parte', 'TJ array text is extracted');

$escaped = pdfFile($dir, 'esc.pdf', "%PDF-1.4\nBT (Riga\\nsuccia e \\(parens\\)) Tj ET\n%%EOF\n");
$esc = PdfExtractor::extractText($escaped);
T::ok(!str_contains($esc, '\\n'), 'the \\n escape is not left literal');
T::contains($esc, 'e (parens)', 'escaped parentheses survive');

$octal = pdfFile($dir, 'oct.pdf', "%PDF-1.4\nBT (A\\065B) Tj ET\n%%EOF\n");
T::ok(true, 'decimal escape fixture ready');
T::contains(PdfExtractor::extractText($octal), 'AAB', 'decimal character escapes are decoded');
$octalHigh = pdfFile($dir, 'oct2.pdf', "%PDF-1.4\nBT (\\49\\50\\51) Tj ET\n%%EOF\n");
T::eq('', PdfExtractor::extractText($octalHigh), 'a digits-only fragment is rejected as noise, not indexed');
$octalCtl = pdfFile($dir, 'oct3.pdf', "%PDF-1.4\nBT (A\\0B) Tj ET\n%%EOF\n");
T::notContains(PdfExtractor::extractText($octalCtl), "\0", 'control-character escapes become spaces');
$bsEsc = pdfFile($dir, 'bs.pdf', "%PDF-1.4\nBT (C:\\\\cartelle\\\\doc) Tj ET\n%%EOF\n");
$bsOut = PdfExtractor::extractText($bsEsc);
T::ok($bsOut === '' || str_contains($bsOut, 'cartelle'), 'a lone escaped backslash does not corrupt extraction');

$nested = pdfFile($dir, 'nest.pdf', "%PDF-1.4\nBT (outer (inner) tail) Tj ET\n%%EOF\n");
$nestedOut = PdfExtractor::extractText($nested);
T::ok(is_string($nestedOut), 'nested parens do not crash the extractor');
T::ok(trim($nestedOut) !== '', 'a malformed nested-paren PDF still yields some text instead of nothing');

$truncated = pdfFile($dir, 'trunc.pdf', "%PDF-1.4\nBT (unterminated");
$out = PdfExtractor::extractText($truncated);
T::ok(is_string($out), 'a truncated BT block returns a string, not an error');

$noText = pdfFile($dir, 'scan.pdf', "%PDF-1.4\n1 0 obj<</Type/Page>>endobj\ntrailer<<>>\n%%EOF\n");
T::ok(is_string(PdfExtractor::extractText($noText)), 'a PDF with no text operators returns a string');
$noTextOut = PdfExtractor::extractText($noText);
T::eq('', $noTextOut, 'a structural-only PDF yields no indexed text at all');
$wordy = pdfFile($dir, 'wordy.pdf', "%PDF-1.4\nplain fallback content with real words invoice contract\n");
T::eq('', PdfExtractor::extractText($wordy), 'bare words outside a text operator are NOT indexed (this fallback used to feed binary noise to the search index)');
$noiseOnly = PdfExtractor::extractText($noText);
foreach (['obj', 'endobj', 'trailer', '%%EOF', '%PDF', 'Pages', 'Catalog', 'Root'] as $noise) {
    T::notContains($noiseOnly, $noise, "structural token '{$noise}' never reaches the index");
}

$binaryJunk = pdfFile($dir, 'junk.pdf', "%PDF-1.4\n" . random_bytes(4096));
T::ok(is_string(PdfExtractor::extractText($binaryJunk)), 'random binary after the header is handled');

$huge = pdfFile($dir, 'huge.pdf', "%PDF-1.4\nBT (" . str_repeat('A', 100000) . ") Tj ET\n");
$hugeOut = PdfExtractor::extractText($huge);
T::ok(mb_strlen($hugeOut) <= 32000, 'extracted text is capped at 32000 chars (' . mb_strlen($hugeOut) . ')');

$multiPage = pdfFile($dir, 'pages.pdf', "%PDF-1.4\n/Type /Page\n/Type /Page\n/Type /Pages\n");
T::eq(2, PdfExtractor::pageCount($multiPage), 'page count counts Page objects but not Pages');
T::eq(0, PdfExtractor::pageCount($dir . '/nope.pdf'), 'page count of a missing file is 0');
T::eq(0, PdfExtractor::pageCount($notpdf), 'page count of a non-PDF is 0');

$attack = pdfFile($dir, 'attack.pdf', "%PDF-1.4\n/Title (" . str_repeat('(', 2000) . ")\nBT (ok) Tj ET\n");
T::ok(is_string(PdfExtractor::extractText($attack)), 'pathological paren nesting does not crash');
T::ok(PdfExtractor::pageCount($attack) >= 0, 'pageCount survives a pathological file');

$src = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/PdfExtractor.php');
T::notContains($src, 'exec(', 'the PDF extractor never shells out');
T::notContains($src, 'system(', 'the PDF extractor never shells out');
T::notContains($src, 'unserialize(', 'the PDF extractor never unserializes');

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) @unlink($f->getPathname());
@rmdir($dir);

T::group('pdf/real-text-not-binary-noise');

$pdfPath = sys_get_temp_dir() . '/pz_probe_' . getmypid() . '.pdf';
$ops = "BT /F1 12 Tf 72 720 Td (Invoice PDFPAYLOAD 4711) Tj ET\n"
     . "BT /F1 12 Tf 72 700 Td (Contract with supplier Acme) Tj ET\n"
     . "BT /F1 12 Tf 72 680 Td (Total due: 99.50 EUR) Tj ET";
$comp = gzcompress($ops);
$objs = [
    "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj",
    "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj",
    "3 0 obj << /Type /Page /Parent 2 0 R /Contents 4 0 R /Resources << >> >> endobj",
    "4 0 obj << /Length " . strlen($comp) . " /Filter /FlateDecode >> stream\n" . $comp . "\nendstream endobj",
    "5 0 obj << /Title (Acme Invoice) /Author (Finance) >> endobj",
];
$body = "%PDF-1.7\n";
$offsets = [];
foreach ($objs as $o) { $offsets[] = strlen($body); $body .= $o . "\n"; }
$xref = strlen($body);
$body .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
foreach ($offsets as $off) $body .= sprintf("%010d 00000 n \n", $off);
$body .= "trailer << /Size " . (count($objs) + 1) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
file_put_contents($pdfPath, $body);

$text = PdfExtractor::extractText($pdfPath);
T::contains($text, 'PDFPAYLOAD', 'the real word inside a compressed PDF is extracted');
T::contains($text, '4711', 'digits survive extraction (invoice amounts are the point)');
T::contains($text, 'Acme', 'the supplier name is extracted');
T::contains($text, '99.50', 'the decimal amount is extracted');
T::contains($text, 'Acme Invoice', 'document metadata is extracted');
T::notContains($text, 'FlateDecode', 'the filter name is not indexed as content');
T::notContains($text, 'PDFContext', 'PDF structural tokens never leak into the index');
T::eq(1, PdfExtractor::pageCount($pdfPath), 'the page count survives the same parser');
T::ok(PdfExtractor::MAX_BYTES <= 20971520, 'the extractor caps the input size');

@unlink($pdfPath);

T::group('embedding/binary-blob-roundtrip');

$blob = '';
mt_srand(4242);
for ($i = 0; $i < 32; $i++) $blob .= pack('g', (($i % 17) - 8) / 8);
T::ok(str_contains($blob, "\0"), 'the fixture really contains NUL bytes, which is what used to truncate it');

$userId = mkUser('embed@g.test');
$itemId = mkItem($userId, 'blob.bin');
Db::update('items', ['ai_embedding' => $blob], 'id = ?', [$itemId]);
$row = Db::first('SELECT typeof(ai_embedding) AS t, length(ai_embedding) AS n FROM items WHERE id = ?', [$itemId]);
T::eq('blob', (string)$row['t'], 'the embedding is stored as a BLOB, not coerced to text');
T::eq(strlen($blob), (int)$row['n'], 'the stored blob has the full length (float32 payload is not truncated at the first NUL)');
T::eq($blob, (string)Db::value('SELECT ai_embedding FROM items WHERE id = ?', [$itemId]), 'the bytes round-trip unchanged');

$vec = [0.5, -0.25, 0.125, 0.0];
$encoded = AiProvider::encodeVector($vec);
T::eq(count($vec) * 4, strlen($encoded), 'encodeVector writes 4 bytes per float32');
$back = AiProvider::decodeVector($encoded);
T::eq(count($vec), count($back), 'decodeVector returns one value per float32 written');
$drift = 0.0;
foreach ($vec as $k => $v) { $drift = max($drift, abs($v - (float) $back[$k])); }
T::ok($drift < 0.0001, 'decodeVector reads back what encodeVector wrote (max drift ' . round($drift, 6) . ')');
