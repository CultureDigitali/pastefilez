# Database

## State attuale: SQLite

PasteFilez usa SQLite in WAL mode. È una scelta deliberata, non un
limite da rimuovere subito:

- il deploy è **un file**. Nessun servizio da gestire, nessuna porta,
  nessun backup separato. `php bin/cli.php backup` produce un archivio
  che include il DB e i file.
- il target dichiarato è **hosting condiviso** (Netsons), dove SQLite è
  l'unica opzione realmente disponibile.
- le scritture sono **a bassa concorrenza**: l'utente tipico è uno, le
  scritture arrivano da upload manuali e dalla coda job.

## Limiti misurati

Riferimento: `php tests/run.php`, suite `18_performance.php`.

| Carico | Query | Lista | `quotaUsed` |
|---|---:|---:|---:|
| 20.000 item | ~9 ms | ~2 ms | ~2 ms |
| 4.000 item, ricerca patologica | ~8 ms | — | — |

Il vincolo reale non è la velocità in lettura ma il **locking in
scrittura**: SQLite serializza le scritture. Con molti utenti che
caricano file contemporaneamente si possono vedere `database is locked`.

## Mitigazioni già in atto

- `PRAGMA journal_mode = WAL` — letture non bloccano le scritture.
- `PRAGMA busy_timeout` non impostato: vedi `Db::init()`. **Se in futuro
  si verificano lock, questo è il primo intervento.**
- `PRAGMA synchronous = NORMAL` — buon compromesso WAL/durabilità.
- Le scritture passano da transazioni brevi in `Db::insert/update/delete`.
- `cli vacuum` compatta il DB e ripulisce `rate_limits`, `sessions`, job
  conclusi, `oauth_states` e `recovery_codes` scaduti.

## Quando Postgres diventa necessario

Verifica tutti i tre i punti:

1. `database is locked` compare nei log (`storage/logs/app.log`).
2. Il numero di utenti attivi contemporaneamente supera ~20.
3. Il profilo I/O del provider richiede più di un processo PHP.

## Se si migra

`app/Core/Db.php` è l'unico punto di contatto con SQLite. Gli altri
strati usano solo `Db::run/first/all/value/insert/update/delete`, che
sono API PDO standard. Gli ostacoli noti:

- **`items_fts`**: FTS5 è SQLite-only. Serve un'altra strategia di
  ricerca full-text (tsvector + GIN, oppure indice trigram).
- **Identificatori**: `Db::insert/update/delete` costruiscono SQL con
  gli identificatori interpolati (validati da `Db::ident()`). Postgres
  richiede quoting con doppi apici, non backtick.
- **BLOB embedding**: `AiProvider::encodeVector` produce float32
  little-endian, che Postgres non legge nativamente. Va convertito in
  `real[]` o `bytea` con endianness documentato.
- **Date**: gli `int` epoch sono innocui e si portano senza modifiche.
- **Upsert**: `ON CONFLICT(key, window_start)` in `RateLimiter` usa la
  sintassi SQLite.

Nessuna di queste è bloccante, ma **non è un lavoro di un pomeriggio**,
e per l'hosting condiviso di riferimento non è richiesto. Finché non
si presentano i tre segnali sopra, SQLite è la scelta corretta.
