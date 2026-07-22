# Sub-Projekt 4b: Admin-SPA (Svelte-5-Frontend fürs Admin-Panel) – Design-Spec

> Teil von Phase 2. Reihenfolge: Backend-Kern (SP1 ✓) → Deployment (SP2 ✓) → öffentliches Frontend (SP3 ✓) → Admin-Backend (SP4a ✓) → **Admin-SPA (SP4b, dieses Dokument)**.

## 1. Ziel

Ein client-only Svelte-5-Admin-Panel im bestehenden SP3-Frontend, das die vorhandene `/api/admin`-API (SP4a) vollständig bedienbar macht: WebAuthn-/Passkey-Login inkl. Step-up, eigene Passkey-Verwaltung, komplette Moderation, Nutzerverwaltung und Audit-Log-Einsicht. Volle API-Parität in v1. Design strikt nach dem übernommenen Gestura-Design-System (keine gestalterischen Alleingänge).

## 2. Nicht-Ziele

- Keine Änderungen an der `/api/admin`-API oder am öffentlichen Frontend/den öffentlichen Endpunkten.
- Kein serverseitiges Rendering des Admin-Bereichs (rein client-seitig).
- Keine Phase-3-Themen (End-Nutzer-Konten, Settings-Sync, Bewertungen).
- Kein eigener zweiter Faktor jenseits von WebAuthn (das Auth-Modell steckt vollständig in SP4a).

## 3. Architektur & Routing (festgezurrt)

- **Gleiche SvelteKit-App** (Ansatz A). Neue Route-Gruppe `frontend/src/routes/admin/` mit `admin/+layout.ts`: `export const prerender = false` und `export const ssr = false` → rein client-seitig. Öffentliche Seiten bleiben prerendered.
- **`svelte.config`**: `@sveltejs/adapter-static` mit `fallback: '200.html'`, damit `/admin`-Deep-Links client-seitig auflösen. Öffentliche Routen behalten `prerender = true`; nur der Admin-Zweig opted out.
- **Code-Splitting**: Admin-Routen werden per Route-Splitting nur beim Besuch geladen; öffentliche Besucher laden keinen Admin-Code.
- **Auslieferung**: SPA auf `gestura.eu`; API-Aufrufe gegen `https://api.gestura.eu` (überschreibbar via `PUBLIC_API_BASE`). RP-ID `gestura.eu` (deckt `api.gestura.eu` ab).
- **Credentialed Requests**: JEDER Admin-Request nutzt `credentials: 'include'` (httpOnly-Session-Cookie `Domain=.gestura.eu`) **und** den Header `X-Requested-With: XMLHttpRequest` (vom Server als CSRF-Schutz auf zustandsändernden `/api/admin`-Requests erzwungen).
- **i18n**: wie SP3 über Paraglide JS mit URL-Locale-Präfix (`/de/admin`, `/en/admin`); en Fallback. Alle sichtbaren Strings über Paraglide-Messages.

## 4. Auth- & Sicherheits-Flow-UX (festgezurrt)

- **Login** (`/admin/login`): `POST /api/admin/auth/options` → `startAuthentication` (usernameless/discoverable, ohne allowCredentials) → `POST /api/admin/auth/login` mit der Assertion → Server setzt Session-Cookie → Weiterleitung zum Dashboard. Fehler (401/kein Passkey) → lokalisierte Meldung.
- **Route-Guard**: `admin/+layout.ts` (bzw. ein authentifizierter Unter-Layout) lädt `GET /api/admin/auth/me`. Bei **401** → Redirect nach `/admin/login`. Der aktuelle Nutzer (`email`, `role`, `credentialCount`, `stepUpFresh`) liegt in einem Runes-`$state`-Session-Store. Öffentliche Auth-/Register-Routen liegen außerhalb des Guards.
- **Step-up (reaktiv)**: ein zentraler `stepUp()`-Orchestrator. Destruktive Aktionen werden normal ausgeführt; liefert der Server **403** mit `{stepUpRequired: true}`, öffnet ein Modal »Passkey erneut bestätigen« → `POST /api/admin/stepup/options` → `startAuthentication` → `POST /api/admin/stepup` → **automatischer Retry** der ursprünglichen Aktion. Kein prophylaktischer Zwang im Normalfall.
- **Backup-Passkey-Pflicht (≥ 2)**: Nach erfolgreicher Registrierung fordert das Panel unmittelbar den **zweiten** Passkey an (Cross-Device/QR bequem vom selben Rechner). Destruktive Aktionen sind serverseitig mit **409 `{backupRequired: true}`** gesperrt, solange < 2 Passkeys; die SPA zeigt dann einen klaren Hinweis »Mein Konto → Passkey hinzufügen« und ein Dauer-Banner bei `credentialCount < 2`.
- **Registrierung** (`/admin/register?token=…`, öffentlich): `POST /api/admin/register/options` mit dem Token → `startRegistration` → `POST /api/admin/register` → Account aktiv → direkt Aufforderung zum 2. Passkey → Login.
- **Session-Ablauf/Logout**: jeder 401 (Idle-Timeout oder serverseitige Deaktivierung durch den `AdminUserChecker`) → Store leeren → Login. `POST /api/admin/auth/logout` beendet die Session serverseitig.

## 5. Screens (volle Parität, rollenabhängig)

Shell mit fester **Seitenleiste** (auf Mobile eingeklappt), oben Titel + Rolle + Theme- und Sprach-Umschalter (SP3-Komponenten wiederverwenden). Navigationspunkte nach Rolle (Server erzwingt zusätzlich):

- **Warteschlange** (`/admin/queue`): offene Einträge und Versionen (`GET /api/admin/queue`), `transformCode` hervorgehoben. Aktionen: Eintrag freigeben/ablehnen, Version freigeben/ablehnen (reject = Step-up).
- **Eintrag-Detail** (`/admin/entries/{id}`): `GET /api/admin/entries/{id}` – Metadaten, Versionsliste, offene Meldungen; Freigabe/Ablehnung von Eintrag und Versionen.
- **Meldungen** (`/admin/reports`): `GET /api/admin/reports`; `POST …/resolve` mit `{publish: bool}`.
- **Submitter** (`/admin/submitters`): Ban/Unban (`POST …/ban|/unban`; ban = Step-up).
- **Nutzer** (`/admin/users`, **nur `admin`**): Liste (`GET`), einladen (`POST` mit `{displayName,email,role}` → E-Mail), deaktivieren (`/disable`, Step-up), reaktivieren (`/enable`, Step-up), erneut einladen (`/reinvite`).
- **Audit** (`/admin/audit`, **nur `admin`**): paginiertes Log (`GET /api/admin/audit?page&perPage`).
- **Mein Konto** (`/admin/account`): eigene Passkeys auflisten (`GET /credentials`), hinzufügen (Options + `startRegistration` + `POST`), umbenennen (`PATCH`), entfernen (`POST …/remove`; serverseitig < 2 → 409, Step-up-geschützt).

Ein `moderator` sieht nur Warteschlange/Eintrag/Meldungen/Submitter/Mein Konto; `admin` zusätzlich Nutzer/Audit. `ROLE_ADMIN` erbt Moderationsrechte.

## 6. API-Client & State (festgezurrt)

- **`src/lib/admin/api.ts`**: zentrale `apiFetch`-Funktion – Basis `PUBLIC_API_BASE`, `credentials: 'include'`, Header `Content-Type: application/json` + `X-Requested-With: XMLHttpRequest`; parst `application/problem+json` in einen typisierten `AdminApiError` (Status, `title`/`detail`, plus Flags `stepUpRequired`/`backupRequired`/`retryAfter` aus den Extra-Feldern). Typisierte Wrapper-Funktionen pro Endpunkt.
- **Session-Store** (`src/lib/admin/session.svelte.ts`): Runes-`$state` mit dem aktuellen Nutzer; `loadMe()`, `logout()`, Ableitung `isAdmin`/`needsBackup`.
- **Step-up-Orchestrator** (`src/lib/admin/stepup.ts`): `withStepUp(fn)` – führt `fn` aus, fängt 403 `stepUpRequired`, fährt die Step-up-Ceremony, retryt einmal.
- **WebAuthn-Helfer** (`src/lib/admin/webauthn.ts`): dünne Wrapper um `@simplewebauthn/browser` `startRegistration`/`startAuthentication`, gefüttert mit dem Options-JSON der API und zurückgebend, was `POST /register|/login|/stepup|/credentials` erwartet. **Umsetzungshinweis:** vor der Implementierung per kleinem Spike verifizieren, dass das `webauthn-lib`-Options-/Antwort-JSON mit dem I/O-Format von `@simplewebauthn/browser` round-trippt (ggf. minimale Feld-Anpassung im Wrapper, nicht am Server).

## 7. Fehlerbehandlung & i18n

- problem+json → lokalisierte, nutzerfreundliche Meldungen. **401** → Store leeren + Login. **403 `stepUpRequired`** → Step-up-Flow. **409 `backupRequired`** → Hinweis/Banner. **429** → »später erneut versuchen« mit `retryAfter`. Sonstige/500 → generische Fehlermeldung (Toast oder inline).
- Alle Strings in `messages/en.json` + `messages/de.json`; Zugriff via Paraglide (`import { m } from '$lib/paraglide/messages.js'`). Deutsche Texte mit Guillemets »…« und Halbgeviertstrich –.

## 8. Tests

- **Vitest + `@testing-library/svelte`**. `@simplewebauthn/browser` **und** `fetch` werden gemockt – kein echter Authenticator (spiegelt den `FakeWebAuthnCeremony` des Backends). Deterministisch.
- Abgedeckt: Login-Flow (Options→Assertion→me), Route-Guard bei 401, Step-up-Retry (403→Ceremony→Retry), Backup-Passkey-Prompt/Banner (409/`credentialCount<2`), rollenbasierte Navigation (moderator vs admin), sowie die Kernaktion jedes Screens gegen eine gemockte API (approve/reject, resolve, ban/unban, invite/disable/enable, Passkey add/remove inkl. <2-Sperre, Audit-Pagination).
- Der Admin-`api.ts`-Client und der Step-up-Orchestrator erhalten eigene Unit-Tests (problem+json-Parsing inkl. der Flags, Retry-Logik).

## 9. Definition of Done / Verifikation

- `npm --prefix frontend run check` (svelte-check) → 0 Fehler.
- `npm --prefix frontend run test -- --run` (Vitest) → grün.
- `npm --prefix frontend run build` → erfolgreich, erzeugt den statischen `200.html`-Fallback für `/admin`; öffentliche Seiten weiterhin prerendered.
- Alle sichtbaren Strings lokalisiert (en/de). Design-Tokens/Karten/Icons aus dem SP3-System, keine hartkodierten Farben.

## 10. Deployment-Notizen

- Kein neuer Server-Bedarf: der statische Build wird wie SP3 ins Web-Root der Index-Domain geladen; der `200.html`-Fallback deckt die client-seitigen `/admin`-Routen ab (Apache-Rewrite auf den Fallback für unbekannte Pfade unterhalb `/admin`, sofern nötig).
- Voraussetzung serverseitig (bereits durch SP4a/Deploy abgedeckt): credentialed CORS für `https://gestura.eu`, `SESSION_COOKIE_DOMAIN=.gestura.eu`, `WEBAUTHN_RP_ID=gestura.eu`, `MAILER_DSN`. Bootstrap-Admin via `php85 bin/console index:admin:create …`.
