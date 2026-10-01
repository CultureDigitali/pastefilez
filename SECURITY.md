# Sicurezza

## Versioni supportate

| Versione | Riceve aggiornamenti |
| --------- | -------------------- |
| 1.0.x     | sì                   |

## Come segnalare una vulnerabilità

**Non aprire una issue pubblica.**

Scrivi a `security@example.com` con:

- descrizione del problema e impatto atteso;
- passaggi per riprodurlo;
- la versione (tag git) e la configurazione;
- eventuale PoC.

Rispondiamo entro 3 giorni lavorativi. Confermiamo la ricezione, valutiamo
entro 7 giorni, e concordiamo un piano di disclosure con 90 giorni prima della
pubblicazione. Le correzioni critiche vengono pubblicate il prima possibile.

## Dove finiscono i dati

- I file stanno in `storage/files/<user>/`, **fuori dalla webroot**.
- Le credenziali API e i token cloud sono cifrati con una master key da 32
  byte generata in `storage/.key` (mode 0600).
- Con `ENCRYPT_FILES_AT_REST=true` i file sono cifrati con AES-256-GCM; il
  plaintext viene materializzato solo in `storage/tmp/` per il tempo di una
  risposta e cancellato subito dopo.
- Con la condivisione E2E, la cifratura avviene nel browser: il server
  riceve e conserva solo ciphertext. La chiave sta nel frammento dell'URL e
  non viene mai inviata.
- **Attenzione**: se usi un provider AI esterno, le immagini caricate vengono
  inviate a quel provider per l'analisi. Usa Ollama o la condivisione E2E per
  file sensibili.

## Backup

`php bin/cli.php backup <dest.zip` include il database **e `storage/.key`**:
chi possiede il backup può decifrare tutto. Trattalo come un segreto. Il
backup esclude `storage/logs/` e `storage/tmp/`.
