# Design: gestura-index Sub-Projekt 1 – Backend-Kern

- **Datum:** 2026-07-20
- **Status:** vom Nutzer freigegeben (Brainstorming abgeschlossen)
- **Übergeordnete Spec:** `/mnt/c/Programme.alt/Gestura/docs/superpowers/specs/2026-07-19-menu-index-design.md` (Phase-2-Gesamtdesign, freigegeben)

## Einordnung: Zerlegung von Phase 2

Phase 2 des Gestura-Index wird in vier Sub-Projekte zerlegt, jedes mit eigenem Spec → Plan → Umsetzung:

| # | Sub-Projekt | Inhalt |
| --- | --- | --- |
| 1 | **Backend-Kern (diese Spec)** | Doctrine-Datenmodell, öffentliche JSON-API `/api/v1`, Moderations-Statusmaschine, Rate-Limits, Schema-Validierung, Screenshot-Upload, Console-Interim-Admin |
| 2 | Deployment (minimal) | Deploy-Skript + Hosting-Verifikation (php85, Docroot, MySQL, GD/WebP), direkt nach Sub-Projekt 1 |
| 3 | Öffentliches Frontend | SvelteKit-Website (Stöbern, Detailseiten, en/de, prerendered) |
| 4 | Admin | Passkey + zweiter Faktor, Admin-Endpunkte, Admin-SPA, Audit-Log |

## Problem / Ziel

Der Backend-Kern stellt die vollständige anonyme Nutzung des Index bereit: Stöbern, Detail, Download, Update-Check, Einreichen, Aktualisieren, Löschen, Melden – ohne Konto, ohne IP-Persistenz. Moderation erfolgt bis Sub-Projekt 4 über Console-Kommandos per SSH.

## Entscheidungen aus dem Brainstorming

| Frage | Entscheidung |
| --- | --- |
| API-Ressourcen-Naming | **`/entries`** statt `/menus` – konsistent zur Entity, deckt Menüs und Engines ab, `gesturaProfile` (später) passt ohne Bruch |
| Kategorien | Feste Liste mit 10 Keys: `dev`, `shopping`, `video`, `news`, `social`, `productivity`, `search`, `reference`, `entertainment`, `other`. Labels übersetzt das Frontend; Ergänzen ist billig, Umbenennen tabu (API-Vertrag) |
| Kategorien pro Eintrag | **1 bis 3** Kategorien (Join-Tabelle, indexierbar) plus freie Tags |
| Edit-Token-Geltungsbereich | **Beides erlaubt:** Client darf beim Einreichen ein vorhandenes Token mitsenden (Eintrag hängt am selben Submitter) oder keines (Server erzeugt frisches Token, liefert es einmalig zurück) |
| Trust-Bonus für Anonyme | **Nein** – Sofort-Publish ab N Freigaben gilt nur für Konten (Phase 3). Anonyme Neueinreichungen gehen immer in die Warteschlange (geleaktes Token darf kein Spam-Freifahrtschein sein) |
| Screenshots | **Gleich im Kern** (Upload + WebP-Re-Encoding); GD-Verfügbarkeit auf dem Hosting wird in Sub-Projekt 2 früh verifiziert |
| Implementierungs-Ansatz | **Schlanke Controller + Symfony-Bordmittel** (DTOs, Validator, `opis/json-schema`); kein API Platform |
| Install-Zählung | **Abweichung von der Phase-2-Spec:** expliziter `POST …/install`-Ping nach bestätigtem Import statt Zählung beim Download-GET – der Download bleibt cachebar (ETag) und Vorschau-Abrufe zählen nicht fälschlich mit |
| URL-Kennung | **Kein separater Slug** – `formatId` (Reverse-Domain-Kennung aus dem Austauschformat) ist bereits eindeutig und URL-sicher |
| `User`/`SyncBlob` | Entstehen **noch nicht** (User → Sub-Projekt 4, SyncBlob → Phase 3; Migrationen machen das billig) |

## Datenmodell (Doctrine, MySQL/MariaDB)

Lokal MariaDB 10.11, auf dem Server MySQL – Doctrine-`server_version` entsprechend setzen.

### Entry

Ein Index-Eintrag (Menü oder Engine).

| Feld | Typ | Anmerkung |
| --- | --- | --- |
| `id` | int, PK, auto | intern |
| `formatId` | string(128), **unique** | Reverse-Domain-Kennung aus dem Format; Eindeutigkeit wird beim ersten Publizieren geprüft; öffentliche URL-Kennung |
| `type` | enum `menu` \| `engine` | muss zum Payload-Typ passen |
| `categories` | Join-Tabelle `entry_category` (`entry_id`, `category`) | 1–3 aus der Festliste; Unique(`entry_id`,`category`), Index auf `category` |
| `tags` | json | freie Tags, normalisiert (lowercase, getrimmt), max. 10 |
| `domains` | json | serverseitig aus den `patterns` des Payloads extrahiert → Domain-Gruppierung |
| `installCount` | int, default 0 | anonymer Zähler |
| `status` | enum `pending` \| `published` \| `hidden` \| `deleted` | Statusmaschine s. u. |
| `screenshotPath` | string, nullable | relativer Pfad unter `public/media/screenshots/` |
| `deprecated` | bool, default false | Autor markiert »veraltet« |
| `successorFormatId` | string(128), nullable | Nachfolger-Kennung bei Deprecation |
| `submitter` | ManyToOne → Submitter | Besitz |
| `currentVersion` | OneToOne → EntryVersion, nullable | aktuelle freigegebene Version |
| `createdAt` / `updatedAt` | datetime_immutable | |

Bewusst ohne Sterne-Felder und `sort=rating` – kommt mit Konten (Phase 3) per Migration.

### EntryVersion

| Feld | Typ | Anmerkung |
| --- | --- | --- |
| `id` | int, PK, auto | |
| `entry` | ManyToOne → Entry | |
| `semver` | string(17) | Format `\d{1,5}\.\d{1,5}\.\d{1,5}`; Unique(`entry`,`semver`); neue Version muss größer sein als die bisher höchste |
| `payload` | json | das validierte Format-JSON, opak gespeichert |
| `contentHash` | string(64), Index | SHA-256 des kanonisierten Payloads → Duplikat-Erkennung |
| `changelog` | text, nullable | max. 2000 Zeichen |
| `status` | enum `pending` \| `approved` \| `rejected` | |
| `hasTransformCode` | bool | beim Einreichen extrahiert → Skript-Badge + Moderations-Zwang |
| `submittedAt` | datetime_immutable | |

Alte freigegebene Versionen bleiben abrufbar.

### Submitter

| Feld | Typ | Anmerkung |
| --- | --- | --- |
| `id` | int, PK, auto | |
| `tokenSelector` | string(16), **unique** | Klartext-Selector des Edit-Tokens (Lookup) |
| `tokenHash` | string | Argon2id-Hash des Token-Verifiers – das Token selbst wird nie gespeichert |
| `approvedCount` | int, default 0 | Zahl freigegebener Einreichungen (Grundlage für Trust in Phase 3) |
| `banned` | bool, default false | Sperre durch Admin |
| `createdAt` | datetime_immutable | |

Ein Submitter kann mehrere Entries besitzen (Token-Wiederverwendung). In Phase 3 kommt eine nullbare `user`-Referenz hinzu (Token-Überführung ins Konto); `tokenSelector`/`tokenHash` werden dann nullable.

### Report

| Feld | Typ | Anmerkung |
| --- | --- | --- |
| `id` | int, PK, auto | |
| `entry` | ManyToOne → Entry | |
| `reason` | enum `spam` \| `broken_links` \| `misleading` \| `legal` | fester Grund |
| `comment` | text, nullable | optionaler Freitext, max. 2000 Zeichen |
| `status` | enum `open` \| `resolved` | |
| `createdAt` | datetime_immutable | |

Keine IP, kein Fingerprint, keine Kennung des Meldenden.

## API (`/api/v1`, JSON)

### Öffentliche Lese-Endpunkte

Alle mit `ETag` (aus `updatedAt`-Hash) + `Cache-Control: public, max-age=300`; liefern ausschließlich `status=published`.

| Endpunkt | Beschreibung |
| --- | --- |
| `GET /entries` | Liste; Filter `q` (Suche über Name/Beschreibung der aktuellen Version), `site` (Domain), `category`, `tag`, `type`; `sort=installs\|newest` (Default `newest`); Pagination `page`/`perPage` (Default 20, max. 50) |
| `GET /entries/{formatId}` | Detail + Liste der freigegebenen Versionen (semver, submittedAt, changelog, hasTransformCode) |
| `GET /entries/{formatId}/versions/{semver}` | liefert das Format-JSON der Version (Download) |
| `POST /entries/updates` | Body: Liste `{id, version}` (max. 200 Einträge) → Antwort nur für Einträge mit neuerer freigegebener Version, inkl. `deprecated`/`successorFormatId`. Keine Kontobindung, keine Speicherung der Anfrage |
| `POST /entries/{formatId}/install` | anonymer Install-Ping der Extension nach bestätigtem Import; erhöht `installCount`; Antwort 204 |

### Schreib-Endpunkte

Auth über `Authorization: Bearer gsti_<selector>_<verifier>`; der Server schlägt den Submitter über den Selector nach und prüft den Verifier zeitkonstant gegen den Argon2id-Hash. 401 bei fehlendem/ungültigem Token, 403 bei fremdem Eintrag oder gesperrtem Submitter.

| Endpunkt | Beschreibung |
| --- | --- |
| `POST /entries` | Einreichen: `{ payload, categories, tags, changelog? }`. Optionaler Bearer-Header = Token-Wiederverwendung (Eintrag hängt am selben Submitter); ohne Header erzeugt der Server Submitter + frisches Token und liefert das Token **einmalig** in der Antwort mit. Entry startet `pending` |
| `PUT /entries/{formatId}` | Neue Version einreichen (Payload + optional Changelog); Metadaten (Kategorien/Tags/Deprecation) änderbar. Ohne `transformCode` sofort live, mit `transformCode` → Warteschlange |
| `DELETE /entries/{formatId}` | Status → `deleted` (Soft-Delete; Payloads bleiben für Admin-Nachvollzug erhalten) |
| `POST /entries/{formatId}/report` | anonym, ohne Auth: `{ reason, comment? }`, gedrosselt |
| `POST /entries/{formatId}/screenshot` | Multipart-Upload (≤ 2 MB, PNG/JPEG/WebP), gleiche Auth wie PUT; GD dekodiert und re-enkodiert **immer** neu → WebP, max. 1280×800, Ablage `public/media/screenshots/{formatId}.webp` (gitignored), Auslieferung statisch. Niemals Fremd-URLs |

### Querschnitt

- **Fehlerformat:** einheitlich `application/problem+json` (RFC 7807) über einen Exception-Listener; Validierungsfehler mit Feld-Pointern.
- **CORS:** `Access-Control-Allow-Origin: *` für die gesamte `/api/v1` – öffentliche, cookielose API mit Bearer-Tokens; Extension (Service-Worker) und Svelte-Website greifen frei zu.
- **Body-Limit:** Einreichungs-Requests hart auf 128 KiB begrenzt (Format-`blobMax` 100 KiB + Metadaten-Puffer).

## Edit-Token (Selector/Verifier-Schema)

Format: `gsti_<selector>_<verifier>` – Selector 8 Zufallsbytes (hex, 16 Zeichen), Verifier 32 Zufallsbytes (base64url). Der Selector steht indexiert im Klartext (Lookup), der Verifier wird ausschließlich als Argon2id-Hash gespeichert (`password_hash` mit `PASSWORD_ARGON2ID` via libsodium) und zeitkonstant verifiziert. Begründung: Ein Argon2id-Hash allein ist nicht auffindbar – das Standard-Muster Selector/Verifier löst das, ohne das Token je zu speichern. Fehlversuche pro Selector und pro IP werden gedrosselt.

## Moderations-Statusmaschine

```text
POST /entries (anonym)      → Entry: pending, Version: pending
Admin approve               → Entry: published, Version: approved, submitter.approvedCount++
Admin reject                → Entry: deleted,  Version: rejected

PUT (Update, ohne Transform) → neue Version: approved, wird currentVersion (Entry-Status unverändert)
PUT (Update, mit Transform)  → neue Version: pending, Entry bleibt auf alter Version
  Admin approve/reject       → Version approved (wird current) / rejected

Sonderfälle bei PUT:
  Entry pending → neue Version ersetzt die wartende pending-Version (Entry bleibt pending)
  Entry hidden  → 409 (erst Moderationsentscheidung abwarten)
  Entry deleted → 404

Reports ≥ REPORT_HIDE_THRESHOLD (offen) → Entry: hidden (automatisch)
  Admin resolve                          → Entry: published oder deleted

DELETE (Besitzer) → Entry: deleted
Admin ban(submitter) → banned = true; alle Entries des Submitters → hidden
```

- Serverseitige Vollvalidierung bei **jeder** Einreichung, unabhängig vom Status-Pfad.
- Duplikat-Erkennung: gleicher `contentHash` einer bereits existierenden Version eines **anderen** Entrys → Ablehnung mit Hinweis.
- Trust-Pfad (Sofort-Publish ab `TRUST_THRESHOLD` Freigaben) ist in der Statusmaschine vorgesehen, greift aber erst mit Konten in Phase 3; `approvedCount` wird ab sofort gezählt.

### Interim-Admin (bis Sub-Projekt 4)

Moderation per `bin/console` über SSH – kein HTTP-Admin ohne die Zwei-Faktor-Auth aus Sub-Projekt 4:

- `index:queue` – Warteschlange anzeigen (pending Entries + pending Versionen)
- `index:approve <id>` / `index:reject <id> [--reason=…]`
- `index:reports [--open]` – Meldungen anzeigen; `index:resolve <reportId> --action=publish|delete`
- `index:ban <submitterId>` / `index:unban <submitterId>`

## Validierung

1. **JSON-Schema:** `opis/json-schema` prüft den Payload gegen `schema/exchange-schema.json` – exakt die Datei, die auch die Extension nutzt (Kopie aus dem Extension-Repo, dort autoritativ gepflegt).
2. **Zusatzregeln** (`ExchangeRulesValidator`-Service, portiert aus `js/menu-exchange.js`): eindeutige Item-IDs innerhalb eines Menüs; Nicht-Separator-Items brauchen eine Whitelist-Aktion; `searchLink` braucht `engineId` oder `https:`-URL.
3. **Typ-Konsistenz:** `gesturaMenu` ↔ `type=menu`, `gesturaEngine` ↔ `type=engine`; `formatId` im Payload muss der URL-/Entry-Kennung entsprechen.
4. **Metadaten:** 1–3 Kategorien aus der Festliste, ≤ 10 normalisierte Tags, Changelog ≤ 2000 Zeichen.
5. **SemVer-Monotonie:** neue Version > höchste vorhandene Version des Entrys.

## Rate-Limits (Symfony RateLimiter)

IPs existieren ausschließlich flüchtig im Limiter-Speicher (Cache), nie in der Datenbank.

| Limiter | Default |
| --- | --- |
| Einreichungen (`POST /entries`) pro IP | 5/Stunde |
| Updates (`PUT`) pro IP | 10/Stunde |
| Meldungen pro IP | 5/Stunde |
| Token-Fehlversuche pro Selector und pro IP | 10/Stunde |
| Install-Pings pro IP und Entry | 1/Tag |

Schwellwerte als Env-Parameter: `TRUST_THRESHOLD` (Default 3), `REPORT_HIDE_THRESHOLD` (Default 3); Limiter-Raten in `config/packages/rate_limiter.yaml`.

## Tests (PHPUnit, lokale MariaDB + dama/doctrine-test-bundle)

- **Validator-Parität:** die bösartigen Payloads aus dem Extension-Test `menu-exchange.test.mjs` werden als PHP-Testfälle portiert (`javascript:`-URLs, Riesen-JSON, SemVer-Overflow, doppelte Item-IDs, fehlende Whitelist-Aktion …) – gleiche Eingaben müssen auf beiden Seiten zum gleichen Urteil führen.
- **Statusmaschine:** alle Übergänge inkl. Transform-Ausnahme, Melde-Schwellwert-Automatik, Ban-Kaskade, Duplikat-Ablehnung, SemVer-Monotonie.
- **Auth-Grenzen:** fehlendes Token, ungültiger Verifier, fremder Eintrag, gesperrter Submitter, Token-Drosselung.
- **HTTP-Verhalten:** ETag/304, Pagination-Grenzen, Filter-Kombinationen, problem+json-Format, CORS-Header, Body-Limit, Screenshot-Re-Encoding (Ergebnis ist immer WebP ≤ Maximalmaße).

## Fehlerbehandlung

- Exception-Listener wandelt alle Fehler in `application/problem+json`; keine Stacktraces in prod.
- 400 (Schema-/Regelverletzung, mit Pointer-Liste), 401 (Token fehlt/ungültig), 403 (fremder Eintrag, gesperrt), 404 (unbekannt oder nicht `published` für anonyme Leser), 409 (Duplikat, SemVer-Konflikt, `formatId` vergeben), 413 (Body-Limit), 429 (Rate-Limit, mit `Retry-After`).
- 404 statt 403 für fremde nicht-öffentliche Einträge bei Lese-Endpunkten (kein Existenz-Leak).

## Nicht-Ziele dieses Sub-Projekts

- Kein HTTP-Admin, keine Passkey/2FA-Auth (→ Sub-Projekt 4), keine Konten, keine Sterne-Bewertungen, kein Settings-Sync (→ Phase 3), kein Frontend (→ Sub-Projekt 3), kein Deploy-Skript (→ Sub-Projekt 2), keine Extension-Änderungen (eigene Pläne im Extension-Repo).
