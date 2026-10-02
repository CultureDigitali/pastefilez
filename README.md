# PasteFilez

**Il tuo archivio che capisce cosa contiene.** Self-hosted, un file SQLite,
zero dipendenze.

Carichi un file. L'AI lo guarda e lo descrive. Poi lo ritrovi con le parole:
*"la fattura di marzo"* trova `IMG_4821.png`. *"il PDF del contratto con il
fornitore"* trova quello, non gli altri trecento PDF.

- 🔍 **Ricerca che capisce** — BM25 + embedding + RRF, e una chat che risponde
  sulle tue librerie (RAG).
- 🏠 **Sul tuo hosting** — PHP 8.1+ e SQLite. Niente Docker richiesto, niente
  Node, niente Composer. FTP e basta.
- 🔐 **Il server può non poter leggere** — E2E con AES-GCM nel browser, opzionale.
- 🧠 **AI locale** — Ollama supportato: analisi e ricerca senza mandare nulla fuori.
- 📦 **Zero dipendenze** — 101 route, 4 script CDN, `php -l` e via.
- 🗑️ **Cestino, bulk, webhooks, PWA, editor immagini** — tutto incluso.

![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4.svg)
![Dependencies](https://img.shields.io/badge/dependencies-none-success.svg)
![Tests](https://img.shields.io/badge/tests-1178%20passing-brightgreen.svg)
![Self-hosted](https://img.shields.io/badge/self--hosted-blue.svg)

> [!NOTE]
> Pensata per hosting shared (Netsons, OVH Shared, SiteGround, Aruba Free) e
> deployment via FTP: nessun build step, niente Node, niente Composer in
> produzione, tutte le librerie JS via jsDelivr.

**Contributing?** Read [`CONTRIBUTING.en.md`](CONTRIBUTING.en.md) (English) or
[`CONTRIBUTING.md`](CONTRIBUTING.md) (Italian). Architecture, shared
dependencies and the ways to break it are documented there.

---

## Caratteristiche

- **Upload multiplo**: drag & drop, paste da clipboard (Cmd+V), URL, file
  picker; MIME sniff lato server; quota utente (default 10 GB).
- **AI Vision** multi-provider (OpenRouter, NVIDIA NIM, Groq) con auto-fetch
  dei modelli live, test della chiave in-place, scelta del modello per
  scopo (chat / visione / embedding).
- **Ricerca ibrida**: FTS5 (BM25) + cosine similarity su embeddings,
  fusi con Reciprocal Rank Fusion (RRF).
- **Condivisione**: link univoco, password opzionale (Argon2id), scadenza,
  limite di visualizzazioni, QR code.
- **Cloud storage opzionale** (Google Drive, Dropbox) via OAuth2 PKCE; off
  by default, completamente configurabile in Settings; i token sono cifrati
  con la master key.
- **Sicurezza**: Argon2id per le password, AES-256-GCM (sodium secretbox)
  per i segreti a riposo, CSRF su tutte le mutazioni, cookie HttpOnly +
  SameSite, HSTS, CSP, X-Frame-Options DENY, rate limit, lockout, 2FA TOTP
  opzionale, audit log.
- **Cifratura file at rest** (opzionale, AES-256-GCM con chiave per file).
- **2FA TOTP** opzionale per utente (RFC 6238).
- **API REST** `/api/v1/*` con JWT breve + refresh.
- **Companion macOS** in status bar che inoltra la clipboard globale.
- **Multilingua** IT/EN con switcher; **tema** dark / light / auto.
- **Dedup SHA256**: ricaricare lo stesso file non occupa spazio due volte.
- **Cestino**: eliminazione reversibile; `php bin/cli.php purge-trash 30`
  libera i file dopo N giorni.
- **E2E encryption**: link condivisi cifrati nel browser con AES-GCM; la
  chiave non lascia mai il client (PBKDF2, salt/IV nel frammento URL).
- **Operazioni bulk**: seleziona e rianalizza, tagga o elimina in blocco.
- **Webhooks**: in uscita (HMAC-SHA256) per `item.uploaded`, `item.deleted`,
  `share.created`, `share.opened`; in entrata con token per ricevere file.
- **Estrazione testo PDF**: parser puro PHP, il testo diventa ricercabile.
- **Chat RAG**: fai una domanda in linguaggio naturale, la risposta si basa
  sui tuoi documenti e cita le fonti.
- **Ollama**: analisi e embedding locali, nessun dato esce dalla macchina.
- **PWA installabile** con share target: condividi un file dal telefono
  direttamente in PasteFilez.
- **Editor immagini**: testo, frecce, linee, rettangoli, sottolineature e
  disegno libero, salvati come nuovo PNG.
- **Ruoli**: owner / admin / operator / user con matrice dei permessi.
- **CLI** per installazione, manutenzione, backup, rotazione chiavi.

---

## Stack

- PHP 8.1+ con estensioni: `pdo_sqlite`, `sqlite3`, `gd`, `mbstring`,
  `curl`, `sodium`, `openssl`, `fileinfo`, `intl` (consigliato),
  `zip` (per i backup).
- SQLite 3.34+ (richiesto FTS5; PHP 8.x lo include già).
- Zero dipendenze Composer in produzione.
- Frontend: Tailwind + Alpine.js + HTMX via jsDelivr; QRCode.js via
  jsDelivr. Nessun bundler.

---

## Requisiti hosting

Condiviso Linux (Netsons e simili):

- PHP 8.1+ con le estensioni sopra.
- Accesso FTP (per caricare i file).
- HTTPS (Let's Encrypt o simile) — necessario per cookie secure, HSTS,
  e OAuth2 redirect.
- 200 MB disco per l'app + la `storage/` (i file degli utenti vanno nello
  stesso account, sotto `storage/files/`).
- Nessun accesso shell (tutto è eseguibile via web o via `bin/cli.php` da
  locale che agisce sui file caricati).

## Requisiti locali (per sviluppo)

PHP 8.1+, estensioni sopra, e `sqlite3` CLI opzionale per ispezione.

---

## Installazione

### Produzione (FTP su Netsons)

1. **Crea un sotto-dominio** (es. `paste.example.com`) che punti alla
   `public_html/`.
2. **Carica via FTP** il contenuto della cartella `public/` nella root
   del dominio (es. `public_html/`) e tutto il resto (`app/`,
   `lang/`, `bin/`, `storage/`) **fuori da `public_html/`**, ad esempio in
   `private/pastefilez/`.
3. **Modifica `public/index.php`** se la root dell'app non è un livello sopra
   la webroot (in Netsons: `public_html/` in `/home/<user>/domains/<domain>/public_html`
   e l'app in `/home/<user>/domains/<domain>/private/pastefilez`):
   - `require __DIR__ . '/../private/pastefilez/app/Core/autoload.php';`
   - il percorso della root è risolto in `app/Core/autoload.php`
     (`dirname(__DIR__, 2)`): se sposti `public/` fuori dall'app, correggi
     i `require` lì.
4. **Crea `.env`** partendo da `.env.example`; imposta:
   - `APP_URL=https://paste.example.com`
   - `COOKIE_SECURE=true`
   - `COOKIE_SAMESITE=Lax`
   - `APP_NAME=PasteFilez`
   - Tutto il resto rimane su default.
5. **Apri `https://paste.example.com/install`** e segui il wizard.
6. **Elimina l'accesso a `/install`** rimuovendo l'utente pubblico di quella
   rotta o, più semplicemente, ruotando la password admin e cambiando il
   `MASTER_KEY_PATH` se si vuole.

### Docker

```bash
git clone <repo> pastefilez && cd pastefilez
docker compose up -d
# apri http://localhost:8080/install
```

Il volume `./storage` conserva database, file e chiavi.

L'URL di Ollama **non** si imposta dal `.env`: è un'impostazione per utente.
Aggiungi il provider in **Settings → AI → Ollama**, lascia la chiave vuota e
metti la base URL (di default `http://127.0.0.1:11434`, quindi dall'interno
del container). Per Ollama sulla stessa macchina in dev locale usa
`http://host.docker.internal:11434`.

Se il container non raggiunge Ollama, il fallback è locale e ogni analisi
fallisce con `no_provider`.

### Sviluppo locale

```bash
cd /path/to/pastefilez
cp .env.example .env
php -S 127.0.0.1:8000 -t public
```

Apri <http://127.0.0.1:8000/install>, crea l'admin, accedi.

**Se il pulsante "Installa" non fa nulla**, è quasi sempre `APP_URL`: il form
incolla l'installazione e, con la porta sbagliata, il POST non raggiunge mai
il server (e con `display_errors` disattivato non vedi nessun errore).

```bash
php bin/cli.php set APP_URL http://127.0.0.1:8000
```

Per testare senza HTTPS tieni `COOKIE_SECURE=false`. Se la porta 8000 è
occupata, scegline un'altra e aggiornala anche in `APP_URL`.

---

## Aggiornamento

1. Backup di `storage/` e `.env`.
2. Carica i nuovi file (eccetto `storage/` e `.env`).
3. Apri la home: se serve una migration, gira automaticamente.
4. Verifica in `/admin/audit` che non ci siano errori.

---

## CLI

Tutti i comandi accettano `--help`. Esegui `php bin/cli.php` per la lista.

```bash
php bin/cli.php install                       # installazione interattiva
php bin/cli.php create-user <email>           # crea utente (genera password)
php bin/cli.php reset-password <email>        # reset password
php bin/cli.php list-users                    # lista utenti
php bin/cli.php rotate-master-key             # ruota la master key
php bin/cli.php backup /tmp/pz-backup.zip     # backup db + storage
php bin/cli.php process-jobs [n]              # processa n job AI (default 10)
php bin/cli.php vacuum                        # vacuum + pulizia log
php bin/cli.php set COOKIE_SECURE true        # scrive in .env
```

### Cron job consigliati (Netsons: pannello "Cron Jobs")

```cron
*/5 * * * *  php /home/<user>/private/pastefilez/bin/cli.php process-jobs 25
0  3 * * *  php /home/<user>/private/pastefilez/bin/cli.php vacuum
0  4 * * *  php /home/<user>/private/pastefilez/bin/cli.php backup /home/<user>/backups/pz-$(date +\%F).zip
```

---

## AI providers

Tre provider supportati, intercambiabili:

| Provider    | Chat | Vision | Embedding | Note                          |
|-------------|:----:|:------:|:---------:|-------------------------------|
| OpenRouter  |  ✓   |   ✓    |    ✓*     | catalogo enorme, costo basso  |
| NVIDIA NIM  |  ✓   |   ✓    |    ✓      | embedding NVIDIA consigliati   |
| Groq        |  ✓   |   ✓    |    ✗      | latenza bassissima, *no emb.* |

\* dipende dal modello; non tutti gli embedding di OpenRouter sono stabili.

In **Settings → AI** si inseriscono le chiavi, si testano, e si sceglie un
modello di default per ogni scopo. La chiave viene cifrata con la master
key prima di essere scritta in `storage/keys/`.

### Modelli suggeriti

- Vision: `meta/llama-3.2-90b-vision-instruct` (OpenRouter),
  `microsoft/phi-3.5-vision-instruct` (NIM), `llama-3.2-90b-vision-preview` (Groq).
- Chat: `meta/llama-3.1-70b-instruct`, `openai/gpt-4o-mini` (OpenRouter).
- Embedding: `nvidia/nv-embedqa-e5-v5` (NIM, 1024 dim) o
  `openai/text-embedding-3-small` (OpenRouter, 1536 dim).

---

## Cloud storage (opzionale)

### Google Drive

1. Crea un OAuth Client (tipo **Web application**) su
   <https://console.cloud.google.com/apis/credentials>.
2. Aggiungi `https://paste.example.com/settings/cloud/google/callback` come
   **Authorized redirect URI**.
3. Incolla Client ID e Secret in **Settings → Cloud → Google Drive**.

### Dropbox

1. Crea un'app su <https://www.dropbox.com/developers/apps> con scope
   `files.content.write` e `files.content.read`.
2. Aggiungi `https://paste.example.com/settings/cloud/google/callback` come
   redirect URI.
3. PKCE: la app genera il `code_verifier` in locale; non serve secret.

I refresh token sono cifrati con la master key in `storage/keys/`.

---

## Companion macOS

`bin/mac-clipboard-monitor/` contiene un agent Swift che gira nella status
bar di macOS, intercetta la clipboard globale, deduplica via SHA256 e
invia a `POST /api/v1/clipboard`.

### Build

```bash
cd bin/mac-clipboard-monitor
./build.sh
cp -R build/PasteFilezClipboard.app ~/Applications/
```

### Configurazione

```bash
defaults write com.pastefilez.clipboard server https://paste.example.com
defaults write com.pastefilez.clipboard api_token <API_TOKEN>
```

Il token API si genera in **Settings → API** (accessibile solo se si è
admin). L'utente deve avere i permessi `clipboard:write`.

### Auto-start al login

```bash
cp com.pastefilez.clipboard.plist ~/Library/LaunchAgents/
launchctl load -w ~/Library/LaunchAgents/com.pastefilez.clipboard.plist
```

> Richiede macOS 11+, Xcode Command Line Tools (`xcode-select --install`).

---

## Struttura del progetto

```
pastefilez/
├── app/
│   ├── Core/           # Bootstrap, Db, Router, Auth, Crypto, Session, ...
│   ├── Controllers/    # Auth, Upload, Item, Search, Share, Settings, Admin, Api/*
│   ├── Models/         # Setting, Item
│   ├── Services/       # AiProvider, StorageService, ShareService, ...
│   └── Views/          # layouts/, auth/, items/, share/, settings/, admin/, errors/
├── bin/
│   ├── cli.php         # CLI
│   └── mac-clipboard-monitor/  # companion macOS
├── lang/
│   ├── it.json
│   └── en.json
├── public/             # webroot
│   ├── index.php       # front controller
│   ├── .htaccess
│   └── assets/
├── storage/            # non in public_html/
│   ├── app.sqlite
│   ├── .key            # master key
│   ├── files/          # blob cifrati o in chiaro
│   ├── thumbs/
│   ├── keys/           # chiavi provider cifrate
│   ├── jobs.sqlite     # coda job (parte di app.sqlite in realtà)
│   └── logs/
└── docs/
```

---

## API REST

Gli endpoint sotto richiedono `Authorization: Bearer <access_token>`.
L'unica eccezione è `auth/login`.

```
POST   /api/v1/auth/login     {email, password} -> {access_token, refresh_token, expires_in}
POST   /api/v1/auth/refresh   {refresh_token}   -> {access_token, refresh_token, expires_in}
GET    /api/v1/items?folder=&type=&q=&page=
POST   /api/v1/items          multipart: file
GET    /api/v1/items/{id}
DELETE /api/v1/items/{id}
GET    /api/v1/search?q=&hybrid=true
POST   /api/v1/clipboard      multipart: file
GET    /api/v1/models
POST   /api/v1/ai/analyze     {item_id, force}
```

Il JWT è firmato HS256 con la master key, scadenza **15 minuti**
(`expires_in: 900`). Il refresh token dura **30 giorni**, viene ruotato a ogni
uso e, se riutilizzato, **revoca l'intera famiglia** di token.

```bash
# login
curl -X POST http://localhost:8000/api/v1/auth/login \
     -H 'Content-Type: application/json' \
     -d '{"email":"you@example.com","password":"..."}'
# -> {"access_token":"eyJ...","refresh_token":"...","expires_in":900}
```

Ogni token validato consuma un bucket di rate limit (600/h letture,
120/h scritture, 20/h AI, 120/h auth). Il client macOS e gli script possono
passare `?api_token=<token>` al posto dell'header Bearer.

---

## Backup

```bash
php bin/cli.php backup /tmp/pz-backup.zip
```

Lo zip contiene `storage/app.sqlite`, `storage/files/`, `storage/thumbs/`,
`storage/.key` e il tuo `.env`. **Conservalo offline e trattalo come un
segreto**: chi ha il backup può decifrare tutto. Sono esclusi
`storage/logs/` e `storage/tmp/`.

Per ripristinare:

1. Scompatta nella stessa posizione.
2. Apri la home: la migration si applica se serve.
3. Verifica `/admin/audit`.

---

## Hardening checklist

- [x] CSRF su tutte le mutazioni.
- [x] Cookie HttpOnly + SameSite Lax/Strict.
- [x] `Strict-Transport-Security` (HSTS).
- [x] `Content-Security-Policy` restrittiva.
- [x] `X-Frame-Options: DENY`.
- [x] `X-Content-Type-Options: nosniff`.
- [x] `Referrer-Policy: no-referrer`.
- [x] Argon2id (memory=64MB, time=4, threads=2).
- [x] Lockout dopo 5 tentativi in 15 min.
- [x] Rate limit su login + API.
- [x] Audit log: login, share create, settings change, admin action.
- [x] Master key 32B random, cifratura AES-256-GCM.
- [x] File opzionalmente cifrati at rest.
- [x] `display_errors=Off` in `.env.example`.
- [x] `.env`, `storage/`, `*.sqlite` protetti da `.htaccess`.
- [x] Nessun commento nel codice (sistema operativo).
- [x] PHP senza build step.
- [x] PHP 8.1+; supporta fino a 8.4.

---

## Licenza

MIT — vedi [`LICENSE`](LICENSE).

Se ti è utile, cita il progetto. Non è richiesto.
