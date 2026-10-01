<?php
declare(strict_types=1);

use App\Core\Db;
use App\Core\Session;
use Tests\T;

T::group('flash-messages');

$_SESSION = [];
Session::set('flash_error', 'boom');
T::eq(null, Session::flash('error'), 'set-then-read in the same request yields nothing');
T::eq('boom', Session::flash('error'), 'Session::set(flash_error) is readable on the next request');
T::eq(null, Session::flash('error'), 'flash message consumed on read');

$_SESSION = [];
Session::set('flash_success', 'yay');
T::eq(null, Session::flash('success'), 'flash_success fresh-guarded');
T::eq('yay', Session::flash('success'), 'flash_success readable');
T::eq(null, Session::flash('success'), 'flash_success consumed');

$_SESSION = [];
Session::set('flash_error', 'a');
Session::set('flash_success', 'b');
T::eq(null, Session::flash('error'), 'error fresh-guarded');
T::eq(null, Session::flash('success'), 'success fresh-guarded');
T::eq('a', Session::flash('error'), 'error isolated from success');
T::eq('b', Session::flash('success'), 'success isolated from error');

$_SESSION = [];
Session::set('flash_error', 'boom');
T::eq('boom', Session::get('flash_error'), 'Session::get still works');
T::eq(null, Session::get('_flash_error'), 'legacy key namespace is empty');

$_SESSION = [];
Session::flash('error', 'direct');
T::eq(null, Session::flash('error'), 'legacy setter is fresh-guarded too');
T::eq('direct', Session::flash('error'), 'legacy Session::flash(type,msg) setter still works');

$_SESSION = [];
Session::set('flash_error', 'toforget');
Session::forget('flash_error');
T::eq(null, Session::flash('error'), 'forget removes flash');

$_SESSION = [];
Session::set('flash_error', 'x' . str_repeat('y', 5000));
T::ok(strlen((string)Session::flash('error')) < 1000, 'oversized flash truncated for layout safety');

T::group('flash-messages/same-request-guard');

$_SESSION = [];
Session::set('flash_error', 'set-this-request');
T::eq(null, Session::flash('error'), 'flash set in this request is NOT consumed by this request');
T::eq('set-this-request', Session::flash('error'), 'flash survives to the next request');
T::eq(null, Session::flash('error'), 'and is consumed exactly once');

$_SESSION = [];
Session::flash('success', 'via-helper');
T::eq(null, Session::flash('success'), 'helper setter also marks fresh');
T::eq('via-helper', Session::flash('success'), 'helper-set flash readable next request');

$_SESSION = [];
Session::set('flash_error', 'e1');
Session::set('flash_success', 's1');
T::eq(null, Session::flash('error'), 'error fresh-guarded');
T::eq(null, Session::flash('success'), 'success fresh-guarded');
T::eq('e1', Session::flash('error'), 'error readable on next read');
T::eq('s1', Session::flash('success'), 'success readable on next read');

$_SESSION = [];
Session::set('flash_error', 'one');
T::eq(null, Session::flash('error'), 'fresh read returns nothing');
T::eq('one', Session::flash('error'), 'next read returns the value');
T::eq(null, Session::flash('error'), 'still nothing on a third read');
