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
T::contains(PdfExtractor::extractText($octalHigh), '123', 'multi-digit decimal escapes decode');
$octalCtl = pdfFile($dir, 'oct3.pdf', "%PDF-1.4\nBT (A\\0B) Tj ET\n%%EOF\n");
T::notContains(PdfExtractor::extractText($octalCtl), "\0", 'control-character escapes become spaces');
$bsEsc = pdfFile($dir, 'bs.pdf', "%PDF-1.4\nBT (C:\\\\cartelle\\\\doc) Tj ET\n%%EOF\n");
T::contains(PdfExtractor::extractText($bsEsc), 'cartelle', 'escaped backslashes survive');

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
T::contains(PdfExtractor::extractText($wordy), 'invoice', 'the fallback still recovers real words');
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
