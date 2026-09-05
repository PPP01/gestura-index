# gestura-index Backend

Symfony-7.4-JSON-API des Gestura-Index. Reine API, kein Twig-Frontend – der statische SvelteKit-Build liegt im Release daneben in `public/`.

Specs: `../docs/superpowers/specs/`. Vertrag mit der Extension: `/mnt/c/Programme.alt/Gestura/docs/gestura-eu-api.md` (autoritativ, nie kopieren), Kopie zum Mitlesen in `../docs/gestura-eu-api.md`.

## Entwicklung

```bash
composer install                     # im Ordner backend/
php -S localhost:8000 -t public      # Dev-Server einzeln
../dev.sh                            # Backend + Frontend zusammen (Auto-Port ab 8000)
php bin/phpunit; echo $?             # Tests (MariaDB gestura_index_test, dama-Rollback)
```

> **Exit-Code prüfen, nicht den Text lesen:** Die Suite läuft mit `failOnDeprecation="true"` und kann »OK« drucken und trotzdem mit 1 enden.

Lokale DB-Zugangsdaten liegen in `.env.local` / `.env.test.local` (nicht im Repo).

## Antwortformen

Fehler sind RFC 7807 (`application/problem+json`) – **außer** auf den Locator-Sync-Endpunkten, denen der Extension-Vertrag `{ "error": "<code>" }` als `application/json` vorschreibt. Eine Exception liefert ihre eigene Form über `App\Exception\RendersOwnApiResponse`; `ProblemJsonSubscriber` fragt danach, statt Feature-Exceptions namentlich zu kennen.

CORS: `*` und cookielos für alles unter `/api/`, **außer** `^/api/admin` – dort credentialed mit fixem Origin plus `X-Requested-With`-Pflicht als CSRF-Schutz.

## Öffentliche API (`/api/v1`)

### Katalog

| Methode | Pfad | Auth | Zweck |
| --- | --- | --- | --- |
| GET | `/entries` | – | Stöbern (q, site, category, tag, type, sort, page, perPage) |
| GET | `/entries/{formatId}` | – | Detail + freigegebene Versionen (**ohne** `items` – Listen bleiben klein) |
| GET | `/entries/{formatId}/versions/{semver}` | – | Format-JSON herunterladen; zählt **nichts** |
| POST | `/entries/{formatId}/install` | – | Install-Ping nach bestätigtem Import (anonym, keine IP-Speicherung) |
| POST | `/bundle` | – | Sammelkorb: mehrere Einträge in einem Rutsch (POST wegen `LimitRequestLine`) |
| GET | `/entries/{formatId}/screenshot` | – | Screenshot ausliefern |
| GET | `/pages` | – | Sichtbarkeits-Flags der schaltbaren Marketing-Seiten |

### Einreichen und Pflegen

| Methode | Pfad | Auth | Zweck |
| --- | --- | --- | --- |
| POST | `/entries` | optional Token | Einreichen (erzeugt ggf. Edit-Token) |
| PUT | `/entries/{formatId}` | Token | Neue Version / Metadaten |
| DELETE | `/entries/{formatId}` | Token | Soft-Delete |
| POST | `/entries/{formatId}/screenshot` | Token | Screenshot (WebP-Re-Encoding, nie Fremd-URLs) |
| POST | `/entries/{formatId}/report` | – | Melden (fester Grund) |

### Bewertungen

| Methode | Pfad | Auth | Zweck |
| --- | --- | --- | --- |
| GET | `/entries/{formatId}/reviews` | – | Freigegebene Kommentare |
| GET / PUT / DELETE | `/entries/{formatId}/rating` | Konto | Eigene Bewertung lesen, setzen, löschen |

### Extension-Vertrag (apiLevel 3)

| Methode | Pfad | Auth | Zweck |
| --- | --- | --- | --- |
| POST | `/updates` | – | Update-Check (Liste id+version; Antwort nur für Einträge mit Neuigkeit) |
| POST | `/sync/list` | Locator im Body | Stände eines Locators – Meta-Blobs, **nie** die Nutzlast |
| PUT | `/sync/state` | Locator im Body | Stand anlegen/ersetzen; `basePayloadHash` → 412 bei Konflikt |
| POST | `/sync/get` | Locator im Body | Nutzlast eines Standes |
| POST | `/sync/delete` | Locator im Body | Einen Stand oder (ohne `stateId`) alle löschen |

Der Locator ist ein Bearer-Token, reist ausschließlich im **Body** und wird nur als SHA-256 abgelegt. **Request-Bodies dürfen nicht protokolliert werden.** Kein `3xx` auf diesen Pfaden – der Client schickt `redirect: "error"`.

## Konto-API (`/api/account`, cookielos, Bearer `gacc_…`)

| Methode | Pfad | Zweck |
| --- | --- | --- |
| POST | `/api/account` | Anonymes Konto anlegen |
| GET | `/api/account/me` | Kontostatus |
| GET | `/api/account/data` | Selbstauskunft (alle eigenen Daten) |
| DELETE | `/api/account` | Sofortiges Löschen |
| POST | `/api/account/claims` | Edit-Token ins Konto überführen |
| GET / PUT / DELETE | `/api/account/sync/{collection}` | Kontogebundener Settings-Sync (`settings`, `menus`, `engines`) |

> **Nicht verwechseln:** `/api/account/sync/{collection}` ist der **kontogebundene** Sync aus dem Juli-Plan (`SyncBlob`). `/api/v1/sync/*` ist der **anonyme, locator-adressierte** Sync des Extension-Vertrags (`SyncState`). Sie teilen kein Datenmodell, keine Auth und keinen Endpunkt – nur das Wort.

## Admin-API (`/api/admin`, Session-Cookie, Passkey)

Registrierung/Login/Step-up über WebAuthn (`/auth/*`, `/register*`, `/stepup*`, `/credentials*`), Moderation (`/queue`, `/entries/{id}/approve|reject`, `/versions/{id}/approve|reject`, `/reports`, `/reports/{id}/resolve`, `/comments*`), Nutzerverwaltung (`/users*`), Seiten-Sichtbarkeit (`/pages*`) und das Audit-Log (`/audit`).

Destruktive Aktionen verlangen **zwei** Gates gleichzeitig: frisches Step-up (Assertion < 5 Minuten) **und** mindestens zwei registrierte Passkeys. Idle-Timeout 30 Minuten, deterministisch im `AdminSessionAuthenticator` erzwungen.

## Konsolen-Kommandos

```bash
# Moderation
php bin/console index:queue
php bin/console index:approve <formatId> | index:reject <formatId>
php bin/console index:reports | index:resolve <id> --action=publish|delete
php bin/console index:ban <submitterId> [--unban]
php bin/console index:comments:queue | index:comments:approve <id> | index:comments:reject <id>

# Betrieb (auf der Zielumgebung mit php85)
php bin/console index:sync:prune [tage]      # Aufbewahrung der Sync-Stände (Default 365) – als Cron PFLICHT
php bin/console index:account:prune [tage]   # inaktive Konten (Default 365)
php bin/console index:admin:create           # eingeladenen Admin anlegen + Einladung verschicken
php bin/console index:mail:test              # Mailer prüfen
php bin/console index:seed                   # kuratierten Basisstock einspielen
```

`index:sync:prune` ist nicht optional: der Vertrag sagt dem Nutzer 12 Monate Aufbewahrung zu und zeigt sie ihm an. Cron-Zeile in [`../deploy/README.md`](../deploy/README.md).
