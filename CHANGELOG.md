# Changelog

Formato basato su [Keep a Changelog](https://keepachangelog.com/it/1.1.0/).
Versioning semantico.

## [1.0.0]

Prima release pubblica.

### Aggiunto
- Upload multiplo: drag & drop, paste da clipboard, da URL, file picker.
- MIME sniff lato server con blocco dei contenuti attivi (`text/html`,
  `image/svg+xml`, script eseguibili).
- Quota per utente, configurabile.
- Ricerca ibrida: FTS5 (BM25) + cosine similarity su embedding, fusi con RRF.
- Chat RAG: retrieval ibrido, top-8 documenti come contesto, citazione fonti.
- Analisi AI multi-provider: OpenRouter, NVIDIA NIM, Groq, Ollama.
  Modelli live auto-fetch, test chiave in-place, modello per scopo.
- Condivisione: link univoco, password Argon2id, scadenza, limite di
  visualizzazioni (conteggio atomico), QR code.
- Condivisione E2E: cifratura AES-256-PBKDF2 lato browser, il server
  riceve solo ciphertext.
- Estrazione testo PDF in puro PHP.
- Cestino: soft-delete, ripristino, purga con refcount sul blob.
- Operazioni bulk: rianalizza, tagga, elimina.
- Dedup SHA256 con riuso del blob.
- Webhooks in uscita (HMAC-SHA256) e in entrata (token).
- Editor immagini: testo, frecce, linee, rettangoli, sottolineature, disegno.
- PWA installabile con share target.
- API REST `/api/v1/*` con JWT breve e refresh con rotazione e rilevamento reuse.
- 2FA TOTP.
- Ruoli owner / admin / operator / user con matrice dei permessi.
- Cifratura file at rest opzionale (AES-256-GCM).
- Companion macOS che inoltra la clipboard.
- Multilingua IT/EN, tema dark/light/auto.
- CLI: install, create-user, set-role, reset-password, list-users,
  process-jobs, purge-trash, vacuum, backup, rotate-master-key, set.

### Sicurezza
- Argon2id, lockout con backoff esponenziale, rate limit a 5 tier.
- CSRF su tutte le mutazioni, `Sec-Fetch-Site` per il share target PWA.
- SSRF: allowlist schemi, DNS risolto prima di connettersi, fail-closed,
  blocco IP privati / link-local / NAT64, redirect rivalidati a ogni hop.
- Refresh token su tabella separata con rotazione e revoca di famiglia.
- E2E: chiave e materiale crittografico restano nel frammento URL.
- Header: CSP, HSTS, X-Frame-Options DENY, nosniff, no-referrer.
- Log di audit su login, share, impostazioni, azioni admin.
- 1185 asserzioni di test automatici.
