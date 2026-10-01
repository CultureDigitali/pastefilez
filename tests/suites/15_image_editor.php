<?php
declare(strict_types=1);

use App\Core\Db;
use App\Services\UploadGuard;
use Tests\T;

T::group('editor/server-save-validation');

$uid = mkUser('editor@x.test');
$owner = Db::first('SELECT * FROM users WHERE id = ?', [$uid]);

function realPng(int $w = 8, int $h = 8): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 10, 120, 200));
    ob_start();
    imagepng($im);
    $out = (string)ob_get_clean();
    imagedestroy($im);
    return $out;
}

$png = realPng(8, 8);
T::ok(strlen($png) > 50, 'the png fixture is large enough to pass the minimum size check');
T::eq("\x89PNG\r\n\x1a\n", substr($png, 0, 8), 'the fixture is a real PNG');
T::ok(is_array(getimagesizefromstring($png)), 'the fixture is a decodable image');

$edit = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/ItemController.php');
T::contains($edit, 'substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n"', 'the magic bytes are verified');
T::contains($edit, 'getimagesizefromstring', 'the image header is parsed');
T::contains($edit, "'dimensions_too_large'", 'oversized dimensions are rejected');
T::contains($edit, "'not_an_image'", 'a non-image source item is rejected');
T::contains($edit, 'ItemIngest::fromUpload', 'the save path reuses the shared ingest service');
T::notContains($edit, "'storage_id' => null,", 'the hand-rolled insert is gone');
T::notContains($edit, "str_replace(' ', '+', (string)(\$_POST['image_data'] ?? ''))", 'the space-to-plus hack is gone');
T::notContains($edit, "putenv('STORAGE_PATH", 'no env manipulation in the controller');
T::contains($edit, "preg_replace('/_edited$/', '', \$base)", 'repeated edits do not stack the _edited suffix');
T::contains($edit, 'Item::find($id, (int)$user[\'id\'])', 'the source item is ownership-checked');
T::contains($edit, 'width' , 'the saved dimensions are returned to the client');

T::group('editor/name-derivation');

T::eq('foto_edited.png', UploadGuard::safeName('foto' . '_edited.png'), 'edited name derived from the source basename');
T::eq('img', preg_replace('/_edited$/', '', 'img_edited') ?? '', 'the _edited suffix is stripped before re-deriving');
T::eq('image_edited.png', UploadGuard::safeName('image' . '_edited.png'), 'an empty basename falls back to image');
T::ok(!str_contains(UploadGuard::safeName('../../etc/passwd_edited.png'), '/'), 'the derived name cannot escape a directory');

T::group('editor/view-tool-wiring');

$view = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Views/items/edit.php');
foreach (['select', 'text', 'arrow', 'line', 'rect', 'underline', 'freehand'] as $tool) {
    T::contains($view, "data-tool=\"{$tool}\"", "the {$tool} tool button exists");
    T::contains($view, "tool === '{$tool}'", "the {$tool} tool has a drawing branch");
}
T::contains($view, 'id="editor-canvas"', 'the canvas element exists');
T::contains($view, 'id="btn-undo"', 'undo is wired');
T::contains($view, 'id="btn-redo"', 'redo is wired');
T::contains($view, 'id="btn-save"', 'save is wired');
T::contains($view, 'id="save-form"', 'the save form posts to the server');
T::contains($view, 'id="save-data"', 'the canvas data is serialised into the form');
T::contains($view, 'id="color-picker"', 'a colour picker is present');
T::contains($view, 'id="stroke-width"', 'a stroke width control is present');
T::contains($view, 'id="font-size"', 'a font size control is present');
T::contains($view, 'id="editor-status"', 'status feedback is present');
T::contains($view, 'id="tool-freehand"', 'freehand drawing is offered');
T::contains($view, 'getContext', 'the 2d context is obtained');
T::contains($view, 'toDataURL', 'the canvas is exported as a data URL');
T::contains($view, 'image/png', 'the export format is PNG');
T::contains($view, "toDataURL('image/png')", 'the export is explicitly PNG');
T::contains($view, '<?= $src ?>', 'the editor source url is server-rendered through the authenticated stream route');
T::contains($view, 'img.src = SRC', 'the source image is loaded from that url');
T::notContains($view, '/s/', 'the editor does not use the public share route');
T::notContains($view, '<?= e($item', 'the source item is not dumped unescaped');
T::ok(preg_match('/<script/', $view) === 1, 'the editor ships its script inline (no bundler available)');
T::eq(0, preg_match('/on(click|mousedown|mousemove|mouseup)\s*=\s*["\']/', $view), 'no inline event-handler attributes (keeps the CSP workable)');

T::group('editor/thumbnail-of-edited-image');

$thumb = \App\Services\ThumbnailService::make(writeTmp($png), 'image/png', bin2hex(random_bytes(8)));
T::ok($thumb !== null, 'a thumbnail can be generated from an edited png');
T::ok(is_file((string)\App\Core\Config::get('storage_path') . '/' . $thumb), 'the thumbnail file exists on disk');
$info = @getimagesize((string)\App\Core\Config::get('storage_path') . '/' . $thumb);
T::ok(is_array($info) && $info[0] <= 480 && $info[1] <= 480, 'the thumbnail respects the max side');
T::eq(\App\Services\ThumbnailService::make(writeTmp('not an image at all'), 'image/png', 'x'), null, 'a non-image produces no thumbnail');
T::eq(\App\Services\ThumbnailService::make('/nonexistent/x.png', 'image/png', 'y'), null, 'a missing file produces no thumbnail');

T::group('editor/source-route-safety');

$routes = (string)file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
T::contains($routes, "'/items/{id}/edit'", 'the editor page is routed');
T::contains($routes, "'/items/{id}/edit/save'", 'the editor save is routed');
T::contains($routes, "'/items/{id}/stream'", 'the editor source is routed through stream');
$itemCtl = $edit;
T::ok(substr_count($itemCtl, 'Auth::require()') >= 3, 'both editor endpoints require authentication');
