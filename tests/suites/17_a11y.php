<?php
declare(strict_types=1);

use Tests\T;

$root = dirname(__DIR__, 2);
$viewsRoot = $root . '/app/Views/';
$layouts = ['layouts/main.php', 'layouts/centered.php'];
$pages = ['install/index.php'];

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewsRoot, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $files[] = ltrim(str_replace($viewsRoot, '', $f->getPathname()), '/');
    }
}
sort($files);
T::ok(count($files) > 25, 'the view tree was discovered (' . count($files) . ' files)');

$nameable = static function (string $tag): bool {
    return in_array(strtolower($tag), ['button', 'a', 'input', 'select', 'textarea', 'img', 'nav', 'form', 'table', 'main', 'html', 'label', 'option'], true);
};

$problems = ['button' => [], 'control' => [], 'img' => [], 'h1' => [], 'lang' => [], 'table' => [], 'nav' => []];

function self_is_inside_label(string $src, string $needle): bool
{
    $pos = strpos($src, $needle);
    if ($pos === false) return false;
    $before = substr($src, 0, $pos);
    $lastOpen = strripos($before, '<label');
    if ($lastOpen === false) return false;
    $lastClose = strripos($before, '</label>');
    return $lastClose === false || $lastOpen > $lastClose;
}

foreach ($files as $rel) {
    $src = (string)file_get_contents($viewsRoot . $rel);
    $stripped = preg_replace('/<\?php.*?\?>/s', '', $src) ?? $src;
    $stripped = preg_replace('/<\?=.+?\?>/s', 'X', $stripped) ?? $stripped;
    $stripped = preg_replace('/\s+/', ' ', $stripped) ?? $stripped;

    foreach (['button', 'a', 'img', 'input', 'select', 'textarea', 'nav', 'form', 'table', 'main', 'label', 'option'] as $tag) {
        if (!preg_match_all('/<' . $tag . '\b([^>]*)>/i', $stripped, $m, PREG_SET_ORDER)) continue;
        foreach ($m as $tagMatch) {
            $attrs = $tagMatch[1];
            $selfClosing = str_ends_with(rtrim($attrs), '/');
            $inner = '';
            if (!$selfClosing) {
                $openPos = strpos($stripped, $tagMatch[0]);
                $after = substr($stripped, $openPos + strlen($tagMatch[0]));
                $close = stripos($after, '</' . $tag . '>');
                $inner = $close === false ? '' : substr($after, 0, $close);
            }
            $hasLabel = preg_match('/aria-label\s*=\s*["\'][^"\']+["\']/', $attrs) === 1
                || preg_match('/aria-labelledby\s*=\s*["\'][^"\']+["\']/', $attrs) === 1;
            $hasTitle = preg_match('/title\s*=\s*["\'][^"\']+["\']/', $attrs) === 1;
            $text = trim(preg_replace('/\s+/', ' ', strip_tags($inner)) ?? '');
            $phpLabel = preg_match('/<\?=/', $inner) === 1;
            $isSubmit = preg_match('/type\s*=\s*["\']submit["\']/', $attrs) === 1;

            if ($tag === 'button' && $text === '' && !$phpLabel && !$hasLabel && !$hasTitle) {
                $problems['button'][] = $rel;
            }
            if ($tag === 'img' && preg_match('/alt\s*=/', $attrs) !== 1) {
                $problems['img'][] = $rel;
            }
            if (in_array($tag, ['input', 'select', 'textarea'], true)) {
                $type = preg_match('/type\s*=\s*["\']([^"\']+)["\']/', $attrs, $tm) === 1 ? strtolower($tm[1]) : '';
                if ($type === 'hidden' || $type === 'submit' || $type === 'button') continue;
                $hasId = preg_match('/\bid\s*=\s*["\']([^"\']+)["\']/', $attrs, $im) === 1;
                $labelFor = $hasId && preg_match('/<label[^>]*for\s*=\s*["\']' . preg_quote($im[1], '/') . '["\']/i', $stripped) === 1;
                $labelWrapped = self_is_inside_label($stripped, $tagMatch[0]);
                $ph = preg_match('/placeholder\s*=\s*["\'][^"\']+["\']/', $attrs) === 1;
                if (!$labelFor && !$labelWrapped && !$hasLabel && !$hasTitle) {
                    $problems['control'][] = $rel . ' :: <' . $tag . '> ' . substr(preg_replace('/\s+/', ' ', $attrs) ?? '', 0, 70);
                }
                if ($ph && !$labelFor && !$labelWrapped && !$hasLabel) {
                    $problems['control'][] = $rel . ' :: placeholder-only <' . $tag . '> ' . substr($attrs, 0, 50);
                }
            }
            if ($tag === 'table') {
                $hasCaption = preg_match('/<caption/i', $stripped) === 1;
                $thScope = preg_match('/<th[^>]*scope\s*=/i', $stripped) === 1;
                if (!$hasCaption && !$thScope) $problems['table'][] = $rel;
            }
            if ($tag === 'nav' && !$hasLabel && preg_match('/aria-label/', $attrs) !== 1) {
                $problems['nav'][] = $rel;
            }
        }
    }

    if (in_array($rel, $pages, true)) {
        if (preg_match('/<html[^>]*\blang\s*=\s*["\'][^"\']*["\']/i', $stripped) !== 1) $problems['lang'][] = $rel;
    }
    if (in_array($rel, $layouts, true)) {
        if (preg_match('/<html[^>]*\blang\s*=\s*["\'][^"\']*["\']/i', $stripped) !== 1) $problems['lang'][] = $rel;
        $h1 = preg_match_all('/<h1\b/i', $stripped);
        if ($h1 > 0) $problems['h1'][] = $rel . " ({$h1} h1 in a layout)";
    }
}

$report = static function (string $label, array $items) use (&$problems): void {
    $u = array_values(array_unique($items));
    T::eq([], $u, $label . ($u ? ' -> ' . implode(' | ', array_slice($u, 0, 8)) : ''));
};

$report('every <button> has an accessible name', $problems['button']);
$report('every <img> has an alt attribute', $problems['img']);
$report('every form control has a label (not just a placeholder)', $problems['control']);
$report('every data table has a caption or th scope', $problems['table']);
$report('every <nav> is labelled', $problems['nav']);
$report('every layout and standalone page declares a language', $problems['lang']);
$report('layouts do not hardcode an h1', $problems['h1']);

$main = (string)file_get_contents($viewsRoot . 'layouts/main.php');
$centered = (string)file_get_contents($viewsRoot . 'layouts/centered.php');

$css = (string)file_get_contents($root . '/public/assets/css/app.css');

T::group('a11y/skip-link-and-focus');

foreach (['main.php' => $main, 'centered.php' => $centered] as $name => $src) {
    T::contains($src, '<main', $name . ' has a main landmark');
}
T::contains($main, 'aria-label', 'the main navigation is labelled');
T::contains($main, 'skip', 'the authenticated layout offers a skip link');
T::contains($css, ':focus-visible', 'focus styling is defined for keyboard users');
T::contains($css, 'prefers-reduced-motion', 'animation respects reduced-motion');
T::contains($css, 'prefers-color-scheme', 'the stylesheet respects the OS colour scheme');
T::contains((string)file_get_contents($root . '/public/assets/js/app.js'), 'prefers-color-scheme', 'the auto theme follows the OS colour scheme');
T::contains($css, 'prefers-contrast', 'the stylesheet adapts to increased-contrast preferences');
T::contains($css, '.skip-link', 'the skip link is styled');
T::contains($main, 'class="skip-link', 'the skip link uses the skip-link class');

T::ok(strlen($css) > 100, 'the stylesheet is present');
T::contains($css, ':focus-visible', 'the stylesheet defines a visible focus ring');
T::notContains($css, 'outline:\s*none', 'the stylesheet never removes the focus outline outright');
T::notContains($css, 'outline:\s*none\s*;', 'the stylesheet never removes the focus outline outright');

T::group('a11y/lang-parity');

$it2 = json_decode((string)file_get_contents($root . '/lang/it.json'), true);
$en = json_decode((string)file_get_contents($root . '/lang/en.json'), true);
T::eq(array_keys($it2), array_keys($en), 'the two languages expose the same keys');
T::ok(count($it2) > 300, 'the translation catalogue is substantial (' . count($it2) . ' keys)');
foreach (['admin.roles.title', 'admin.audit.filter', 'common.reset', 'admin.roles.grant'] as $k) {
    T::ok(isset($it2[$k]) && $it2[$k] !== '', "the new admin key {$k} is translated in it");
    T::ok(isset($en[$k]) && $en[$k] !== '', "the new admin key {$k} is translated in en");
}
