# Admin-gesteuerte Seiten-Sichtbarkeit (mit echtem 404) – Design

**Datum:** 2026-08-29
**Status:** freigegeben (Design), wartet auf Plan
**Kontext:** Erweiterung der bestehenden Admin um das Aktivieren/Deaktivieren einzelner Marketing-Seiten (C2–C5). Deaktivierte Seiten liefern ein **echtes HTTP 404** (auch für Suchmaschinen), nicht nur einen clientseitigen Soft-404. Aktive Seiten behalten ihr SEO-Prerendering.

## 1. Problem & Kernentscheidung

Die Marketing-Seiten sind **statisch prerendered** (adapter-static, `fallback: 200.html`); zur Laufzeit rendert sie kein Server. Ein clientseitiges »404« wäre ein Soft-404 (echter Status bleibt 200) – für SEO wertlos. Ein **echtes** 404 muss vom Server kommen.

Die Docroot der Index-Domain zeigt auf `backend/public/` (Symfony-Front-Controller); die `.htaccess` liefert existierende Dateien direkt aus, alles andere geht an `index.php`. Daher: **Symfony bewacht die schaltbaren Seiten** – liefert bei »an« die prerenderte HTML (200, volle SEO-Wirkung), bei »aus« ein echtes 404. Gewählter Ansatz **A** (SEO im aktiven Zustand erhalten).

## 2. Scope

- **Schaltbare Seiten (Kandidaten, fest im Code):** `was-ist-gestura` (C2), `maus-gesten` (C3), `vergleich` (C4), `beispiele` (C5).
- **Nicht schaltbar:** C1 Startseite (`/`) und Katalog (`/index`).
- **Nicht in Scope:** genereller Key-Value-Settings-Store (bewusst schlank auf diesen Zweck), Sub-Projekt B, weitere Admin-Features.

## 3. Backend – Datenmodell & Endpunkte (Symfony 7.4)

### 3.1 Entity
`App\Entity\PageSetting`:
- `pageKey: string` (unique, z. B. `vergleich`) – Identität.
- `enabled: bool` (default `true`).
- `updatedAt: \DateTimeImmutable`, `updatedBy: ?string` (E-Mail/Kennung des Admins).

Repository `PageSettingRepository`: `findAllIndexed(): array<string,bool>` (Key→enabled), `findByKey(string): ?PageSetting`.

Migration: `CREATE TABLE page_setting (...)` + Seed der vier Keys mit `enabled=true`. **Invariante:** Nur die vier Kandidaten-Keys existieren; unbekannte Keys werden von den Endpunkten mit 404 abgelehnt (Whitelist im Code, `App\PageVisibility\TOGGLEABLE_PAGES`-Konstante).

### 3.2 Öffentlicher Endpunkt
`GET /api/v1/pages` → `200 { "was-ist-gestura": true, "maus-gesten": true, "vergleich": true, "beispiele": false }`.
- Cookielos, `Cache-Control: public, max-age=60` (kurz, damit Toggles zeitnah greifen).
- Liegt unter `Controller/Api/`, CORS `*` (wie die übrigen `/api/v1`-Endpunkte).
- Liefert IMMER alle vier Kandidaten-Keys (auch wenn eine Zeile fehlt → default `true`).

### 3.3 Admin-Endpunkte
- `GET /api/admin/pages` → `200 [ { pageKey, enabled, updatedAt, updatedBy } ]` (alle vier).
- `PATCH /api/admin/pages/{key}` mit Body `{ "enabled": bool }` → `204`.
  - Firewall `^/api/admin` (Passkey-Session), CSRF-Header `X-Requested-With` (automatisch via `AdminCsrfSubscriber`).
  - **Rolle `ROLE_ADMIN`** (Site-Konfiguration) – in `security.yaml` `access_control` ergänzen (`^/api/admin/pages` → `ROLE_ADMIN`).
  - Unbekannter `{key}` (nicht in der Whitelist) → `404` (`ApiProblem`).
  - Mutation in `wrapInTransaction`, danach `AuditLogger::log($actor, $enabled ? 'page.enable' : 'page.disable', 'page_setting', $key)`.
  - Kein Step-up/Backup-Gate (nicht destruktiv).

### 3.4 Marketing-Serving-Controller (der 404-Kern)
`App\Controller\MarketingPageController` – matcht **genau** die schaltbaren Slugs inkl. Locale-Präfix:
- Route-Pattern: `/{locale}/{slug}` mit `requirements: locale = 'de|en'`, `slug = 'was-ist-gestura|maus-gesten|vergleich|beispiele'`, `methods: ['GET']`.
- Logik: Flag für `slug` prüfen. **an** ⇒ die prerenderte HTML-Datei aus dem Build-Verzeichnis ausliefern (`Content-Type: text/html`, Status 200, `Cache-Control` moderat). **aus** ⇒ **echtes 404** mit einer schlanken, im Gestura-Look gehaltenen 404-HTML (Status 404, `noindex`).
- Build-Pfad konfigurierbar (Parameter `app.frontend_build_dir`, default `%kernel.project_dir%/../frontend/build`); die HTML liegt unter `<build>/<locale>/<slug>.html` bzw. `<build>/<locale>/<slug>/index.html` (Implementer verifiziert das tatsächliche adapter-static-Layout und nutzt das vorhandene).
- Fehlt die Build-Datei (z. B. im reinen Backend-Dev ohne Build): 404 mit klarer Log-Warnung (kein 500).

### 3.5 `.htaccess`
Vor der »Datei existiert«-Regel eine Rewrite-Regel, die die vier Slugs (inkl. `de|en`-Präfix) an `index.php` leitet, damit Symfony sie bewacht statt Apache sie direkt auszuliefern:
```
RewriteRule ^(de|en)/(was-ist-gestura|maus-gesten|vergleich|beispiele)/?$ index.php [L]
```
**Achtung (lessons):** `backend/public/.htaccess` ist recipe-managed – die Regel und ihr Zweck werden in `.claude/lessons.md` dokumentiert, damit ein `symfony/framework-bundle`-Recipe-Update sie nicht still verwirft.

## 4. Frontend – öffentliche Seiten (SvelteKit/Svelte 5)

### 4.1 Nav-Ausblendung
`Header.svelte` holt clientseitig (nach Hydration) `GET /api/v1/pages` und blendet Nav-Links deaktivierter Seiten aus. Kurzer Sichtbarkeits-Moment beim ersten Laden ist akzeptabel. Bei Fehler der Abfrage: alle Links zeigen (fail-open in der Nav; das echte Gating macht ohnehin der Server).

### 4.2 Dev-Parität (nur Entwicklungsmodus)
Jede schaltbare Seite bekommt ein `+page.ts`-`load`, das **nur wenn `dev === true`** (`import { dev } from '$app/environment'`) `GET /api/v1/pages` holt und bei »aus« `throw error(404, ...)` wirft. Im Prod-Build ist `dev=false` ⇒ `load` tut nichts ⇒ die Seite wird normal prerendered; das Laufzeit-Gating macht Symfony. So verhalten sich Dev und Prod gleich, ohne den Prerender zu vergiften. `prerender = true; ssr = true` bleiben.

### 4.3 404-Seite
Ein `+error.svelte` (öffentliche Gruppe) im Gestura-Look für den Dev-`error(404)` (und generelle Fehler). Der Symfony-404-Controller nutzt eine dazu passende, schlanke inline-HTML.

## 5. Admin-UI (SvelteKit-SPA)

Neue Sektion `admin/pages/`:
- Sidebar-Eintrag »Seiten« (`admin_nav_pages`), `adminOnly: true` (ROLE_ADMIN).
- Seite lädt `GET /api/admin/pages` (`onMount` + `$state`), zeigt die vier Seiten mit Name + Toggle-Schalter. Umschalten ⇒ `PATCH /api/admin/pages/{key}` + Refetch (Muster wie `reports`). Kein Step-up.
- API-Wrapper in `frontend/src/lib/admin/api.ts`: `pages()`, `setPageEnabled(key, enabled)`.
- i18n-Keys (de/en): `admin_nav_pages`, `admin_pages_title`, `admin_pages_hint`, Seiten-Anzeigenamen, Toggle-Labels, Fehler-/Erfolgstexte.

## 6. Deploy

Der Frontend-Deploy ist noch nicht geskriptet. Diese Aufgabe legt fest/dokumentiert (in `deploy/README.md`): der Build landet im Web-Root (`backend/public/` bzw. dessen Frontend-Bereich), **plus** die `.htaccess`-Regel aus §3.5. `MarketingPageController` liest die HTML aus dem Build-Pfad (`app.frontend_build_dir`). Keine Änderung an `deploy.sh` erzwungen, aber der Mechanismus wird beschrieben. Lokal liest der Controller aus `frontend/build/` (nach `npm run build`).

## 7. Tests

**Backend (phpunit):**
- `GET /api/v1/pages`: liefert alle vier Keys; spiegelt Toggles.
- `PATCH /api/admin/pages/{key}`: setzt Flag, schreibt Audit, verlangt ROLE_ADMIN + CSRF; unbekannter Key → 404.
- `MarketingPageController`: enabled → 200 + HTML-Inhalt; disabled → 404; fehlende Build-Datei → 404 (kein 500). (Testet mit einem temporären Build-Fixture-Verzeichnis über `app.frontend_build_dir`.)

**Frontend (vitest):**
- `Header`: blendet deaktivierte Nav-Links aus (gemockte `/api/v1/pages`); zeigt alle bei Fetch-Fehler.
- Admin `pages/`: lädt Liste, Toggle ruft PATCH + refetch.
- Dev-`load`-Gate: bei »aus« wird `error(404)` geworfen (Test mit `dev`-Mock + gemocktem Fetch).

## 8. Nicht-Ziele / bewusste Vereinfachungen

- Kein genereller Settings-Store; nur die vier Seiten-Flags.
- Keine Zeitplanung/geplante Sichtbarkeit.
- Der kurze Nav-Flash beim ersten Laden wird akzeptiert (kein SSR der Nav-Flags nötig; das echte Gating macht der Server).
- C1/`/index` bleiben immer aktiv.
