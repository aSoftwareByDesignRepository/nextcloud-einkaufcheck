# EinkaufCheck (Nextcloud)

Wochenangebote **Eigenmarken + Obst/Gemüse**, Einkaufszettel, WhatsApp/CSV-Export, Vorrats-Alerts.

## Aktivieren (Docker-Dev)

```bash
cd /home/alex/Development/nextcloud-dev/nextcloud
sg docker -c 'docker compose exec -u www-data nextcloud php occ app:enable einkaufcheck'
```

Voraussetzung: `python3` unter `/usr/bin/python3` oder `/usr/local/bin/python3` (Fetcher in `python/`).

## Funktionen

- ALDI Nord & Lidl (JSON-Feeds, €/kg wo vorhanden)
- Kategorie Obst & Gemüse (lose Frische ohne Marke)
- Einkaufszettel pro Benutzer (DB, max. 200 Einträge)
- Export: WhatsApp-URL, Zwischenablage, CSV
- PLZ / Woche in Benutzereinstellungen
- **Vorrat / Alert:** Watchlist mit optionaler Preisobergrenze (€ und/oder €/kg); Cron alle 6 h prüft Angebote und sendet Nextcloud-Benachrichtigungen (ohne Spam bei gleichem Treffer; kg-Cap ohne €/kg = kein Treffer). Treffer nur bei Wortgrenzen / vollständigen Query-Tokens — kein Infix (`eis` ≠ `Reis`) und kein Jaccard-„halb getroffen“.
- **Access-Tür:** Open / Restricted + erlaubte Gruppen/Personen + App-Admins (Nextcloud-Admin oder gelistete Person). Gelöschte Gruppen werden aus der Allow-Liste gestrichen.
- Rate-Limits on every API route (reads and writes), Offer-Cache mit Stampede-Lock, sanitisiertes Fetcher-JSON (keine Raw-Stderr an Clients; nur `https://` URLs, kein `javascript:`)
- **Offer-Cache is shared by PLZ + week** (public retailer prices, not personal data). Live refresh is rate-limited (4 refresh / 20 fetch per user per hour) and serialized per PLZ so two users cannot stampede the same fetch.
- **GET `/api/offers`** liest nur den Cache (kein Live-Fetch, keine PLZ-Speicherung). Live-Preise: **POST `/api/offers/refresh`** (CSRF + Rate-Limit; speichert die angeforderte PLZ/Woche).
- **Produktbilder (optional, Standard aus):** URLs aus den Händler-Feeds, nur HTTPS und nur Allowlist (`*.scene7.com` mit ALDI-Pfad, `*.lidl.de`, `*.lidlplus.com`, `*.aldi-nord.de`). Kein Bild-Proxy durch Nextcloud (kein Open-Proxy). CSP `img-src` nur auf EinkaufCheck-Seiten. Ohne Toggle liegt kein `<img>` im DOM.
- **Preisverläufe:** gemeinsame Wochen-Snapshots (ALDI bundesweit, Lidl je PLZ). **GET `/api/trends`** liest nur Historie + Cache (kein Live-Fetch, keine PLZ-Speicherung, nie `offers_stale`). „Günstig“ nur bei ≥8 % und ≥5 Cent unter dem Mittel früherer Wochen — eine einzelne Woche ist kein Trend. Suche über denselben Wortgrenzen-Matcher wie Vorrat (`eis` ≠ `Reis`).
- Gleicher ungekaufter Artikel auf dem Zettel: **+ erhöht die Menge** (1–99), statt eine zweite Zeile anzulegen.

## Einstellungen

| Seite | Wer |
| --- | --- |
| Postal code | alle Nutzer |
| Stores | alle Nutzer (Händler-Status) |
| Access | App-Admins |

## Händler-Status

| Händler | Status |
| --- | --- |
| ALDI Nord | aktiv |
| Lidl | aktiv (Plus + Prospekt-Katalog) |
| Penny | vorbereitet — Angebote hinter Auth |
| REWE | vorbereitet — mTLS-Zertifikat nötig |
| Kaufland | vorbereitet — App-Basic-Auth |
| Netto | vorbereitet — Session/Login |

Kein HTML-Scraping der Prospekt-PDFs.

## Playwright E2E (Docker)

```bash
cd apps/einkaufcheck
bash scripts/e2e-prep.sh
npm install
npx playwright install chromium
npm run e2e
```

Credentials: `e2e/.env` (see `.env.example`). Workers stay at 1 so Nextcloud bruteforce does not lock the test user.

## Mutation gate (Docker)

Mutants briefly rewrite PHP under `lib/`. Bind-mounts owned by the host user are not writable as `www-data`, so run:

```bash
cd /home/alex/Development/nextcloud-dev/nextcloud
bash apps/einkaufcheck/scripts/run-mutations.sh
```

Or: `sg docker -c 'docker compose exec -u root nextcloud php -d opcache.enable_cli=0 /var/www/html/custom_apps/einkaufcheck/tests/Mutation/run-critical-mutations.php'`

