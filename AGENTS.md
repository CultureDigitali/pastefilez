# AGENTS.md

Istruzioni operative per agenti (umani o AI) che lavorano sul codice di
PasteFilez. Da leggere **prima** di toccare qualunque file.

---

## Comandi rapidi

```bash
php tests/run.php                     # test suite (1064 asserzioni, 18 suite)
php bin/cli.php                       # help
php bin/cli.php install               # installazione
php bin/cli.php list-users            # utenti
php bin/cli.php process-jobs 25       # processa coda AI
php bin/cli.php purge-trash 30        # purge cestino >30gg
php bin/cli.php vacuum                # manutenzione DB
php bin/cli.php backup /tmp/x.zip     # backup completo
php -l <file>                         # syntax check (PHP)
php -S 127.0.0.1:8000 -t public       # dev server
```

L'app gira in `public/` (webroot). Tutto il resto (`app/`, `bin/`,
`config/`, `lang/`, `storage/`) deve restare **fuori** dalla webroot
pubblica su hosting condiviso.

---

## Convenzioni di codice

- **PHP 8.1+**, no `<?=` shorthand, no `declare(strict_types=1)` in
  produzione ma lo accettiamo nei service file nuovi.
- **Zero commenti** nel codice PHP, JS, CSS, Swift. La documentazione
  narrativa vive in `docs/` o in `README.md`.
- Namespace `App\Core`, `App\Controllers`, `App\Services`, `App\Models`.
- Autoloader PSR-4 in `app/Core/autoload.php`; niente Composer in
  produzione.
- I18n: ogni stringa UI passa per `t('chiave')` e vive in `lang/<locale>.json`.
- Viste: nessuna logica di business, solo rendering. Logica nei Controller.
- Service: una classe, una responsabilità. I client AI/Cloud estendono
  `BaseAiProvider` / `BaseCloudClient`.
- JS: vanilla + Alpine + HTMX, mai React/Vue. CDN via jsDelivr.

## Schema del database

21 tabelle + 1 tabella FTS5 + `schema_meta`, create in `app/Core/Db.php::migrate()`
(migrazioni 1..8, versione corrente in `schema_meta.version`):

```
users          folders        items          item_tags
tags           api_keys       api_tokens      api_refresh_tokens
ai_models_cache cloud_tokens   jobs            audit_log
settings       sessions       rate_limits     oauth_states
webhooks       webhook_deliveries  e2e_shares  recovery_codes
fts_items      (FTS5 virtual, trigger su items)
schema_meta    (versione schema)
```

Le credenziali delle share stanno su `items` (`share_token`,
`share_password_hash`, `share_expires_at`, `share_max_views`,
`share_view_count`) — **non esiste una tabella `shares`**.
Gli embedding stanno in `items.ai_embedding` (BLOB float32 normalizzati).
Il master key è `storage/.key`, fuori dal DB.

## Nuove feature (2026)

- **Dedup SHA256**: upload duplicati riusano il blob; `duplicate_of` in JSON response.
- **Soft-delete + trash**: `/trash`, restore, purge, `purge-trash [days]` CLI.
- **E2E encryption**: `/items/{id}/share-e2e`, PBKDF2+AES-GCM, salt/IV in URL hash.
- **Bulk operations**: `/items/bulk` POST (delete, tag, reanalyze) + floating action bar.
- **Webhooks**: `/settings/webhooks/*` + `/hooks/{token}` POST. Outgoing: HMAC-SHA256, events `item.uploaded`, `item.deleted`, `share.created`, `share.opened`. Incoming: multipart file upload.
- **PDF text extraction**: `PdfExtractor` puro-PHP (no shell). `AnalysisService::describe()` estrae testo e lo indicizza.
- **RAG chat**: `/chat` + `/chat/ask`. BM25+cosine (RRF), top-8 items come contesto.
- **Ollama provider**: `OllamaClient extends BaseAiProvider`. Base URL configurabile in settings (default `http://127.0.0.1:11434`). API key opzionale.
- **PWA**: `manifest.webmanifest`, `sw.js` (cache **solo** `/assets/*`, mai contenuti privati), icons 192/512, share_target POST /upload.
- **UploadGuard**: unico punto di validazione MIME su tutti i 5 ingressi
  (`/upload`, `/upload/clipboard`, `/upload/url`, `/hooks/{token}`,
  `/api/v1/clipboard`). Blocca sempre i content attivi (`text/html`,
  `image/svg+xml`, xml) anche se il wildcard li ammette.
- **HttpClient**: unico client HTTP uscente. Allowlist schemi, IP privati /
  link-local / NAT64 bloccati (DNS risolto **prima** di connettersi), redirect
  seguiti a mano con rivalidazione a ogni hop, body cap in streaming.
  `allow_loopback` è un opt-in esplicito (usato solo da Ollama, admin-gated).
- **Ownership**: predicati di tenant. Ogni scrittura cross-tenant su item, tag,
  folder passa di qui. Non aggiungere query `WHERE user_id` a mano.
- **ItemIngest**: unico percorso di creazione item. Usato da `/upload`,
  `/upload/clipboard`, `/upload/url`, `/api/v1/items`, `/api/v1/clipboard`.
  Non duplicare la logica MIME/quota/dedup/thumb.
- **ApiThrottle**: rate limit su `/api/v1/*` con 4 tier (`read` 600/h,
  `write` 120/h, `ai` 20/h, `auth` 30/h). **L'identità viene risolta solo da
  credenziali validate** (`ApiAuth::user(true)`); per un caller non autenticato
  si usa l'IP, mai un token grezzo fornito dal client (altrimenti ruotare
  l'header azzera il bucket). In più ogni utente validato consuma anche un
  bucket per IP. Override con `RATE_LIMIT_API_{READ,WRITE,AI,AUTH}_PER_HOUR`
  (0 = default). Risponde 429 + `Retry-After`. Registrarlo con
  `$router->use(...)`. `ApiThrottle::prune()` (chiamato da `cli vacuum`) elimina
  le finestre vecchie.
- **AuditQuery**: filtro/paginazione/export CSV dell'audit log. I LIKE
  usano sempre `ESCAPE`, i filtri malformati rispondono 400 (non
  ignorati in silenzio).
- **Role**: 4 ruoli (`owner`, `admin`, `operator`, `user`) con matrice
  dei permessi in `app/Views/admin/roles.php`. `Auth::can($role, $grant)`
  e `Auth::requireGrant($grant)`.
- **SearchService::boundQuery()**: tronca la query grezza a 512 byte e
  `ftsQuery()` a 8 termini deduplicati. Senza questo una ricerca da
  2000 parole costava 171 s (DoS); ora resta ~8 ms.
- **ApiAuth**: autenticazione unica dei controller `Api/`. Non duplicare il
  parsing del bearer token. `ApiAuth::user()` accetta **solo** JWT;
  `ApiAuth::user(true)` aggiunge il fallback `?api_token=`. Usare `true` solo
  dove serviva già prima (clipboard), per non allargare i privilegi.
- **Flash**: `Session::set('flash_error', ...)` è l'unica API da usare. Il
  marker "fresco" è process-local, quindi un flash impostato nella request
  corrente **non** viene consumato dalla stessa request.
- **Image editor**: `/items/{id}/edit` + `/items/{id}/edit/save`. Canvas editor con: testo, frecce, linee, rettangoli, sottolineatura, disegno libero. Salva come nuovo item PNG.

FTS5 virtual table `fts_items` (contentless) sincronizzata da trigger su
`items`. I vettori embedding vivono in `embeddings` come BLOB float32.

Per ispezionare:

```bash
sqlite3 storage/app.sqlite ".tables"
sqlite3 storage/app.sqlite "SELECT id, original_name, mime, size, ai_status FROM items LIMIT 10"
```

## Flusso upload

1. `UploadController::store()` valida CSRF, MIME (via `finfo`), quota.
2. `StorageService::store()` scrive su `storage/files/<user>/<random>`
   (cifrato AES-256-GCM se abilitato).
3. `ThumbnailService::make()` (GD) per immagini/video → `storage/thumbs/`.
4. `Item` model inserisce la riga con `ai_status='pending'`.
5. `JobService::enqueue('ai_describe', itemId)`.
6. `JobService::process()` (inline o da `cli process-jobs`):
   - `AnalysisService::describe()` chiama il modello vision → descrizione,
     tags, OCR.
   - `AnalysisService::embed()` calcola embedding se un modello è
     configurato.

## Hardening checklist (per chi fa review)

- [x] CSRF token in form + verifica in `Csrf::validateRequest()`.
- [x] Cookie `HttpOnly`, `Secure` (in HTTPS), `SameSite=Lax`.
- [x] Header security in `Bootstrap::sendSecurityHeaders()`.
- [x] Argon2id via `Crypto::passwordHash()`.
- [x] Lockout 5/15min in `Auth::login()`.
- [x] Rate limit 60/h su `/api/v1/*` via `RateLimiter::hit()`.
- [x] Audit log su: login, login_fail, password_reset, share_create,
       share_unlock, settings_update, user_create, user_update, key_rotate.
- [x] Master key 32 byte random in `storage/.key` (0600).
- [x] File encryption at rest opzionale (toggle in Settings).
- [x] MIME check server-side via `finfo` (non solo estensione).
- [x] Path traversal neutralizzato da basename + random.
- [x] Upload size limit configurable (`UPLOAD_MAX_MB`).
- [x] `.htaccess` nega accesso a `.env`, `storage/`, `*.sqlite`, `.key`.
- [x] `php_flag engine off` è scoped con `FilesMatch` e riattivato in
      `public/.htaccess` per il front controller.
- [x] `Response::stream()` forza `attachment` + `application/octet-stream` per
      ogni MIME non `inlineSafe()`; `application/pdf` resta inline per l'iframe.

## Limitazioni note

- SQLite: locking in scrittura serializzato. OK per hosting shared con
  pochi utenti; i tre segnali che richiedono Postgres e la checklist di
  migrazione sono in `docs/DATABASE.md`.
- La ricerca full-text usa FTS5, che è SQLite-only. Migrare a Postgres
  richiede un'altra strategia di full-text.
- La coda job è opportunistica: senza cron il job `analyze` resta in
  attesa finché non c'è un upload o una visita a `/admin/jobs`.
- Vision model output non è deterministico; per i tag si applica
  dedup case-insensitive e lunghezza 2-32.
- Embedding limitato a 4096 dim (vincolo SQLite BLOB).
- FTS5 tokenizer è unicode61 con `remove_diacritics=2`.
- OAuth callback deve stare su HTTPS (Netsons Let's Encrypt OK).
- La coda job è processata in modo opportunistico: durante il
  caricamento di un nuovo file, durante l'apertura di `/admin/jobs`,
  e via cron. Se il server è molto carico, aumentare la frequenza cron.

## Struttura modifiche

- **Cambiare un provider AI**: esistono `OpenRouterClient`, `GroqClient`,
  `NvidiaClient`, `OllamaClient`; registrarlo in `AiProvider::factory()` e
  `AiProvider::defaultModel()`. Il `BASE_URLS` di `AiProvider` è la tabella dei
  default. Per Ollama, usa `ai.ollama_base` (loopback consentito, admin-gated).
- **Cambiare un modello AI di default**: `app/Services/AiProvider.php`
  + tabella `settings` (chiavi `ai_default_chat`, `ai_default_vision`,
  `ai_default_embed`).
- **Aggiungere un endpoint API**: `app/Controllers/Api/` + route in
  `public/index.php` sotto il namespace `/api/v1/`.
- **Aggiungere un provider AI**: estendere `BaseAiProvider`,
  implementare `chat()`, `vision()`, `embeddings()`, `listModels()`,
  registrarlo in `AiProvider::factory()`. Per Ollama, usa `OllamaClient`
  (base URL configurabile in `settings` tab AI, default `http://127.0.0.1:11434`).
- **Aggiungere un provider Cloud**: estendere `BaseCloudClient`,
  implementare `authorizeUrl()`, `exchangeCode()`, `upload()`,
  registrarlo in `StorageService::factory()`.
- **Aggiungere una lingua**: copiare `lang/it.json` in `lang/<ll>.json`,
  tradurre, aggiungere il toggle in `SettingsController::preferences()`.
- **Aggiungere un tipo di file supportato**: aggiornare
  `UploadController::store()` (MIME allowlist) e, se serve thumb,
  `ThumbnailService::make()`.
- **Modificare l'editor immagini**: `app/Views/items/edit.php` (JS canvas),
  `ItemController::edit/editSave`. Tool: text, arrow, line, rect, underline,
  freehand. Salva come nuovo PNG via `canvas.toDataURL()`.
- **Modificare RAG**: `app/Services/RagService::ask()` combina BM25 + cosine
  (RRF, k=60), top-8 items, chiama chat model con contesto.
- **Modificare webhooks**: `WebhookService::dispatch()` chiamato da
  `UploadController` per `item.uploaded`. Outgoing: HMAC-SHA256 secret opzionale.
  Incoming: `/hooks/{token}` POST multipart, crea item direttamente.

## Test manuali minimi

```bash
# 1. Login OK
curl -c /tmp/c.txt -b /tmp/c.txt http://127.0.0.1:8000/login
# (prendere _csrf dal form, postarlo)

# 2. Upload
curl -b /tmp/c.txt -F "_csrf=..." -F "file=@/etc/hosts" http://127.0.0.1:8000/upload

# 3. Search
curl -b /tmp/c.txt "http://127.0.0.1:8000/search?q=...&hybrid=1"

# 4. Share con password
curl -b /tmp/c.txt -F "_csrf=..." -F "item_id=1" -F "password=foo" http://127.0.0.1:8000/items/1/share

# 5. API
curl -X POST http://127.0.0.1:8000/api/v1/auth/login \
     -H "Content-Type: application/json" \
     -d '{"email":"x","password":"y"}'
```

## Script utili

```bash
# pulizia completa (cancella tutto tranne codice e config)
rm -rf storage/app.sqlite* storage/.key storage/files/* storage/thumbs/* storage/keys/* storage/logs/*
php bin/cli.php install
```

## Regole nate dai review (2026)

Queste sono regressioni reali trovate e corrette. Non reintrodurle.

- **Mai `(string)Request::input(...)` o `(string)Request::query(...)`**: un
  parametro array (`?x[]=1`) produce "Array to string conversion". Usa
  `Request::inputString()` / `Request::queryString()`. C'è un test che lo verifica.
- **`exit` non esegue `finally`**: per pulire un file temporaneo che precede
  un `exit`, usa `register_shutdown_function()`.
- **`strtotime()` ritorna `false`**: controlla `=== false` prima di passarlo a un
  parametro `?int`. Per le date usa `Request::timestamp()`.
- **Contatori di sicurezza**: mai read-modify-write. Usa
  `UPDATE ... SET c = c + 1 WHERE ... AND c < limite` e verifica `rowCount()`.
  (`SharePublicController::consumeView()` è il riferimento.)
- **Trigger FTS5**: il comando `delete` su una tabella contentful richiede i
  **valori vecchi**, non `''`. Passare `''` lascia token obsoleti nell'indice e
  la ricerca restituisce risultati falsi per sempre.
- **Response che decifrano**: passa da `Response::stored()`, che materializza il
  plaintext in `storage/tmp/` e lo cancella via `register_shutdown_function()`.
  `storage/tmp` è escluso dai backup e contato in `quotaUsed()`.
- **Import mancanti**: un `use` dimenticato è un fatal a runtime, non un errore
  di lint. C'è un test che risolve ogni riferimento a classe nel codebase.
- **Provider AI**: le API esterne cambiano forma. `listModels()` deve essere
  wrappato in try/catch e validare i campi con `is_scalar()` prima di
  `strtolower()`. OpenRouter restituisce `architecture` come oggetto.
- **`config.write`**: solo `owner`. Le impostazioni globali del server
  (`smtp`, `encryption`, `cloudSave`, `storage-backend`) non sono da `admin`.
  L'installer crea un `owner`; `php bin/cli.php set-role` è il recovery.
- **Ultimo owner**: non demotibile tramite la UI, pena lockout irreversibile
  delle impostazioni globali. Recovery solo da CLI.

## Cose da NON fare

- Non aggiungere Composer in produzione. Il progetto non lo richiede.
- Non mettere la `storage/` dentro la webroot pubblica.
- Non loggare password, chiavi, token. `Logger` già li redatta, ma
  occhio a non passare dati sensibili al `Logger::info()`.
- Non disabilitare `display_errors` durante il debug in produzione
  senza poi riattivarlo. Usa il log in `storage/logs/`.
- Non modificare la master key senza fare `rotate-master-key` prima,
  altrimenti le chiavi provider e i token cloud restano cifrati con
  la vecchia chiave e diventano illeggibili.
