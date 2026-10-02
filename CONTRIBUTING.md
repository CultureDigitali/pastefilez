# Contribuire

Grazie per il tempo. Le PR sono benvenute.

## Ambienti

Non serve Composer. Basta PHP 8.1+ con `pdo_sqlite`, `gd`, `mbstring`,
`curl`, `sodium`, `openssl`, `fileinfo`, `zip`, `intl`.

```bash
php tests/run.php                 # 1185 asserzioni, deve restare verde
php bin/cli.php install
php -S 127.0.0.1:8000 -t public
```

## Prima di aprire una PR

1. `php tests/run.php` verde.
2. `php -l` pulito sui file toccati.
3. Se tocchi la sicurezza o l'input utente, aggiungi il test che dimostra il
   comportamento corretto.
4. Aggiungi la riga in `CHANGELOG.md`.
5. Commenti nel codice: **nessuno** (il progetto non li usa).

## Stile

- Namespace `App\Core`, `App\Controllers`, `App\Services`, `App\Models`.
- Le query sempre parametrizzate. Nessuna concatenazione in SQL.
- Mai `(string)Request::input(...)`: usa `Request::inputString()`. Un array
  come valore produce un warning, e un warning trasformato in errore è un 500.
- Ownership sempre via predicati di tenant, mai `WHERE user_id` a mano.
- Stringhe UI sempre via `t('chiave')`, e la chiave in entrambi i `lang/*.json`.
- Le viste non contengono logica di business.

## Sicurezza

Non riportare vulnerabilità qui. Vedi [`SECURITY.md`](SECURITY.md).

## Commit

Messaggi in inglese, in forma imperativa, one logical change per commit:

```
fix: reject array parameters on the share endpoint
feat: count e2e shares against the user quota
```
