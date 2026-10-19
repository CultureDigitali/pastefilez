# Contributing to PasteFilez

Thanks for the time. Pull requests are welcome — including ones that just fix a
typo in the docs.

## Setup

No Composer, no `npm install`. You need PHP 8.1+ with:

```
pdo_sqlite  gd  mbstring  curl  sodium  openssl  fileinfo  zip  intl
```

```bash
cp .env.example .env
php bin/cli.php install
php tests/run.php          # 1240 assertions, must stay green
php -S 127.0.0.1:8000 -t public
```

Then open <http://127.0.0.1:8000/install> and create the first user.

If the "Install" button does nothing, `APP_URL` is almost certainly wrong:

```bash
php bin/cli.php set APP_URL http://127.0.0.1:8000
```

## Before you open the PR

1. `php tests/run.php` is green. It runs in a temp sandbox and touches nothing
   real, so it is safe.
2. `php -l` is clean on the files you touched.
3. If you change security-relevant or user-input behaviour, add the test that
   demonstrates the new behaviour.
4. Add a line to `CHANGELOG.md`.
5. No comments in the code. This project uses them as an operating system, not
   as documentation — documentation lives in the docs.

## Architecture

```
public/index.php  →  Controller  →  Service  →  Model / Core
                                   ↓
                                 View
```

A controller does not write SQL. A service does not know about HTTP.

| To add | Where |
| --- | --- |
| a web route | `public/index.php` + `app/Controllers/XxxController.php` |
| an API route | `public/index.php` + `app/Controllers/Api/XxxApiController.php` |
| business logic | `app/Services/XxxService.php` (static, one responsibility) |
| data access | `app/Models/` (`Item`, `Role`, `Setting`) — named queries only |
| markup | `app/Views/<domain>/<page>.php` |
| UI strings | `lang/it.json` **and** `lang/en.json`, never a literal |

### The four shared dependencies — do not bypass them

1. **`UploadGuard`** — the single MIME validator. All five upload entry points
   go through it. It hard-blocks active content (`text/html`, `image/svg+xml`,
   `text/x-php`, …).
2. **`ItemIngest`** — the single item-creation path. Do not re-implement
   dedup, quota, or thumbnail logic anywhere else.
3. **`Ownership`** — the tenant predicates. Every write to `items`, `tags` or
   `folders` carries `user_id`.
4. **`ApiThrottle`** — registered once as middleware in `public/index.php`.
   Do not call it from a controller.

## The three ways to break it

These are real regressions that already happened. There is a test for each.

- **Never `(string)Request::input(...)`.** An array parameter (`?x[]=1`)
  triggers "Array to string conversion", and a warning escalated to an error is
  a 500. Use `Request::inputString()` / `Request::queryString()`.
- **Never read-modify-write a counter.** Use
  `UPDATE ... SET c = c + 1 WHERE ... AND c < limit`, then check `rowCount()`.
  `SharePublicController::consumeView()` is the reference.
- **`exit` does not run `finally`.** To delete a temp file that precedes an
  `exit`, use `register_shutdown_function()`.

Two more, from the security reviews:

- **`Db::update()` on `items` must carry `user_id`** in the `WHERE`. If you
  write to `items`, go through `Ownership`.
- **A missing `use` statement is a runtime fatal**, not a lint error. The suite
  resolves every qualified class reference; keep it that way.

## Style

- Namespace `App\Core`, `App\Controllers`, `App\Services`, `App\Models`.
- Queries always parameterised. No string concatenation in SQL.
- `LIKE` filters always use `ESCAPE`.
- Views contain markup, not business logic.

## Commits

English, imperative, one logical change per commit:

```
fix: reject array parameters on the share endpoint
feat: count e2e shares against the user quota
```

## Security

Do not report vulnerabilities here. See [`SECURITY.md`](SECURITY.md).