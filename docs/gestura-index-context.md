# Gestura & Gestura-Index – Kontext-Briefing (für die gestura-index-Sitzung)

> Dieses Dokument setzt eine frische Claude-Code-Sitzung im **gestura-index**-Repo
> sofort ins Bild. Kopiere es beim Setup nach `gestura-index/docs/` (aus WSL:
> `cp /mnt/c/Programme.alt/Gestura/docs/gestura-index-context.md ~/gestura-index/docs/`).

## Was Gestura ist

Gestura ist eine **Manifest-V3-Browser-Extension** (Chrome/Edge/Firefox) für
Mausgesten, Super-Drag, Wheel-/Rocker-Gesten und Bereichsauswahl – ein
eigenständiger persönlicher Fork von FlowMouse, gepflegt von einem einzigen
Entwickler. Plain JavaScript, **kein Build-Schritt**, Lit-Web-Components fürs UI,
vitest für Tests. Das Repo liegt (Windows) unter `c:\Programme.alt\Gestura`, aus
WSL erreichbar unter `/mnt/c/Programme.alt/Gestura/`.

Nutzer konfigurieren u. a. Gesten-Aktionen, **eigene Suchmaschinen** und
**Website-Menüs** (In-Page-Menüs mit Links/Suchen pro Website).

## Was gestura-index ist (DIESES Projekt)

Ein **optionales, kostenloses Zusatzfeature**: ein Backend-Dienst + eine Website,
über die Nutzer Gestura-**Menüs und Suchmaschinen teilen** und (später) ihre
Settings browserübergreifend synchronisieren. Die Extension läuft **vollständig
ohne** – nichts an ihr hängt am Backend.

Eigenes, **öffentliches GitHub-Monorepo** `gestura-index`:
`backend/` (Symfony JSON-API) · `frontend/` (SvelteKit: öffentliche Website +
Admin) · `schema/` (geteilter Format-Vertrag) · `deploy/`.

## Die Schnittstelle: das Austauschformat

- Portables JSON, zwei Typen: `gesturaMenu: 1` (Menüs), `gesturaEngine: 1`
  (Suchmaschinen/Links). Felder u. a.: `id` (reverse-domain), `version` (SemVer),
  `name`/`description` (String **oder** `{lang: ...}` mit en-Fallback), `items`
  bzw. `url`, `patterns`, optionaler `transformCode` (JS) bei Engines.
- **Autoritative Vertragsdatei:** `schema/exchange-schema.json` (aus dem
  Extension-Repo kopiert). Die Extension hat einen **Laufzeit-Validator**
  (`js/menu-exchange.js`) *und* dasselbe JSON-Schema. **Das Backend muss identisch
  validieren** (Aktions-Whitelist, `https:`-only URLs, Größen-/Anzahllimits,
  SemVer). Regeln, die JSON-Schema nicht ausdrücken kann (eindeutige Item-IDs,
  „searchLink braucht engineId oder url", „Nicht-Separator braucht Whitelist-
  Aktion"), stehen als Klartext in der `description` des Schemas – der
  Referenz-Validator ist `js/menu-exchange.js`.
- **Phase 1 ist fertig** (in der Extension, gemergt in `main` + `firefox-build`):
  Export/Import von Menüs+Engines per Datei/URL/Betreiber-Button, Import-Vorschau
  (inkl. Favicon, Transform-Warnung, Chrome-only-Hinweis), sowie beim Import eines
  Standard-Eintrags die Wahl **„Standard ersetzen" vs. „neu hinzufügen"**.

## Phase-2-Umfang (was jetzt gebaut wird)

- **Symfony JSON-API** (~12 Endpunkte): Stöbern/Suchen (nach Domain, Kategorie,
  Tag), Detail + Versionen, Download (anonymer Install-Zähler, **keine
  IP-Speicherung**), Update-Check, Einreichen/Aktualisieren/Löschen
  (Konto-Session **oder** anonymer Edit-Token), Melden, Bewerten.
- **Datenmodell (Doctrine/MySQL):** `Entry` (menu|engine), `EntryVersion`
  (SemVer, validiertes JSON, Changelog), `Submitter` (Konto **oder** Argon2id-Hash
  des anonymen Edit-Tokens – Token selbst nie gespeichert), `Report`, `User`,
  `SyncBlob`.
- **Moderation – Hybrid nach Vertrauen:** neue Einreicher → Warteschlange;
  vertrauenswürdige (ab N freigegebenen) publizieren sofort; Updates live, aber
  serverseitig voll validiert; **Einreichungen mit `transformCode` immer in die
  Warteschlange** (Supply-Chain-Schutz).
- **Admin:** Svelte-SPA gegen die API, **Zwei-Faktor-Pflicht** (Passkey + zweiter
  Faktor), kurze Session, erneute Bestätigung vor destruktiven Aktionen,
  Audit-Log.
- **Frontend:** SvelteKit mit `adapter-static` – öffentliche Seiten **prerendered**
  (SEO), Admin als client-only SPA; konsumiert nur die API. Start-Sprachen en/de.
- **Bilder:** optional 1 Screenshot pro Eintrag, serverseitig neu enkodiert
  (→ WebP, feste Maximalgröße), nie Fremd-URLs.

**Phase 3 (später, nicht jetzt):** Passkey-Konten, Sterne-Bewertungen, „Meine
Daten", **E2E-verschlüsselter Settings-Sync**, Token-Überführung ins Konto.

## Bereits festgezurrte Entscheidungen

- **Stack:** Symfony (reine JSON-API, kein Twig-Frontend) + **Svelte 5**-Frontend.
  Svelte lebt **nur** hier; die Extension bleibt Lit/plain JS.
- **Datensparsamkeit by design:** keine E-Mail-Pflicht, keine IP-Persistenz,
  anonyme Zähler, vollständige Selbstauskunft + sofortiges Löschrecht.
- **Auth:** ausschließlich **Passkey** (WebAuthn-Bundle), keine Passwörter.
  Anonymer Besitz via geheimem **Edit-Token** (clientseitig gespeichert).
- **Krypto:** **E2E / Zero-Knowledge** für Privates (Settings-Sync); öffentliche
  Index-Inhalte sind naturgemäß Klartext.
- **Bewertungen:** Sterne nur mit Konto (1/Eintrag) + anonyme aggregierte
  Install-Zähler.
- **Taxonomie:** feste Kategorien + freie Tags + Domain-Gruppierung.
- **Sprachen:** Index-Frontend + Admin starten mit **en/de**; die Extension behält
  ihre ~40 Locales; das Format erlaubt beliebige Sprachen.
- **Format-Vertrag:** liegt im Extension-Repo, wird nach `schema/` übernommen
  (Kopie; bei Formatänderungen – selten in Phase 2 – neu kopieren).

## Hosting / Deployment (bestätigt)

- Shared-Linux-Hosting (sieht nach ALL-INKL aus), **SSH-Zugang**, **PHP 8.5.3**
  (CLI-Binary heißt **`php85`**, nicht `php`), **Composer 2.9.8**, MySQL.
- Deploy: SSH/rsync + `php85 composer install --no-dev -o` +
  `php85 bin/console doctrine:migrations:migrate`. **Docroot muss auf
  `backend/public/` zeigen** (Subdomain, z. B. `index.<domain>`, oder Alias).
  Secrets in `.env.local` außerhalb des Repos. Frontend = statischer Build,
  hochgeladen ins Web-Root der Index-Domain.

## Autoritative Referenzen (aus WSL unter `/mnt/c/Programme.alt/Gestura/`)

- Design-Spec: `docs/superpowers/specs/2026-07-19-menu-index-design.md`
- Format-Vertrag: `js/exchange-schema.json`
- Referenz-Validator: `js/menu-exchange.js`
- Phase-1-Plan (Referenz): `docs/superpowers/plans/2026-07-19-menu-index-phase1.md`

## Wie in Phase 2 starten

Im `gestura-index`-Repo: **brainstorming → writing-plans → subagent-driven
execution** (gleicher Ablauf wie Phase 1). Das Design-Spec ist grobkörnig – der
Brainstorming-Durchlauf zurrt die Details fest (konkrete Endpunkte,
Doctrine-Entities, Moderations-Statusmaschine, WebAuthn-Flow, Deploy-Skript).
