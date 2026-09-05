# Gestura & Gestura-Index – Kontext-Briefing (für die gestura-index-Sitzung)

> Dieses Dokument setzt eine frische Claude-Code-Sitzung im **gestura-index**-Repo
> sofort ins Bild. Es entstand als Kopie aus dem Extension-Repo, wird inzwischen
> aber **hier** gepflegt – im Extension-Repo gibt es die Datei nicht mehr.
>
> **Stand: 5. September 2026.** Was einmal »Phase-2-Umfang« hieß, ist gebaut;
> die Abschnitte darunter sind entsprechend als Ist-Stand zu lesen.

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
über die Nutzer Gestura-**Menüs und Suchmaschinen teilen** und ihre Settings
browserübergreifend **synchronisieren**. Die Extension läuft **vollständig
ohne** – nichts an ihr hängt am Backend.

Eigenes, **öffentliches GitHub-Monorepo** `gestura-index`:
`backend/` (Symfony JSON-API) · `frontend/` (SvelteKit: öffentliche Website +
Admin) · `schema/` (geteilter Format-Vertrag, Kopie) · `deploy/` (versionierte
Releases) · `exchange/` (Übergabekanal zum Extension-Repo).

## Die Schnittstelle: das Austauschformat

- Portables JSON, zwei Typen: `gesturaMenu: 1` (Menüs), `gesturaEngine: 1`
  (Suchmaschinen/Links). Felder u. a.: `id` (reverse-domain), `version` (SemVer),
  `name`/`description` (String **oder** `{lang: ...}` mit en-Fallback), `items`
  bzw. `url`, `patterns`, optionaler `transformCode` (JS) bei Engines.
- **Autoritativ ist das Extension-Repo**, nicht dieses hier: der Format-Vertrag
  lebt in `/mnt/c/Programme.alt/Gestura/js/exchange-schema.json`.
  `schema/exchange-schema.json` ist eine **Kopie** davon und wird hier nie direkt
  editiert – bei Formatänderungen dort ändern und neu herüberkopieren.
  Die Extension hat einen **Laufzeit-Validator**
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

## Phase-2-Umfang (gebaut)

- **Symfony JSON-API** (inzwischen rund 70 Routen, siehe `backend/README.md`):
  Stöbern/Suchen (nach Domain, Kategorie,
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

**Phase 3 (gebaut):** anonyme Konten, Sterne-Bewertungen, »Meine Daten«,
E2E-verschlüsselter Settings-Sync, Token-Überführung ins Konto.

## Der Extension-Vertrag (R2/R3, apiLevel 3)

Über das Austauschformat hinaus gibt es einen **zweiten**, eigenen Vertrag
zwischen Extension und Index – Endpunkte, Bodies, Fehlercodes:
`/mnt/c/Programme.alt/Gestura/docs/gestura-eu-api.md` (autoritativ, direkt
lesen), Kopie zum Mitlesen in `docs/gestura-eu-api.md`.

- **Level 2:** `POST /api/v1/updates` – Update-Check, anonym, ohne Kennung.
- **Level 3:** vier `/api/v1/sync/*`-Endpunkte – anonymer Settings-Sync,
  adressiert über einen aus dem Nutzergeheimnis abgeleiteten **Locator**, der
  serverseitig nur als SHA-256 abgelegt wird. Der Server sieht ausschließlich
  Chiffrate; er entschlüsselt nichts und merged nichts – das Zusammenführen
  macht die Extension über eine lokal gehaltene Basis und stützt sich dabei auf
  den `basePayloadHash`-Konflikt (412) des Servers.

Beide Level sind umgesetzt. **Kanal für alles, was diese Grenze betrifft:**
`exchange/AUSTAUSCH.md` – vorher hineinsehen, danach eine Zeile hinterlassen.
Bei Mehrdeutigkeiten im Vertrag dort nachfragen, statt sie zu entscheiden.

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
- **Versionierte Releases:** `deploy/deploy.sh vX.Y.Z` bringt einen annotierten
  Git-Tag nach `releases/<tag>/`; `current` zeigt aufs aktive Release,
  geteilter Zustand liegt in `shared/` (`.env.local`, `media/`, `log/`).
  `rollback.sh` schaltet zurück, `smoke.sh` prüft, `gc.sh` räumt auf.
- **Ein gemeinsames Docroot für beide Domains** (`gestura.eu`,
  `api.gestura.eu`): `current/backend/public/`. Der Frontend-Build liegt im
  Release ebenfalls dort – nur so erreicht die Extension
  `https://gestura.eu/api/v1/updates` ohne Umleitung, und der Vertrag verbietet
  jedes `3xx` auf den API-Pfaden.
- Secrets ausschließlich in `shared/.env.local`. Details und Runbook:
  `deploy/README.md`.

## Autoritative Referenzen (aus WSL unter `/mnt/c/Programme.alt/Gestura/`)

- **API-Vertrag Extension ↔ Index:** `docs/gestura-eu-api.md`
- Format-Vertrag: `js/exchange-schema.json`
- Referenz-Validator: `js/menu-exchange.js`
- Design-Spec: `docs/superpowers/specs/2026-07-19-menu-index-design.md`
- Phase-1-Plan (Referenz): `docs/superpowers/plans/2026-07-19-menu-index-phase1.md`

## Arbeitsweise

**brainstorming → writing-plans → subagent-driven execution.** Pläne und Specs
landen unter `docs/superpowers/`; die dort liegenden Dateien sind Momentaufnahmen
ihrer Umsetzung und werden nachträglich nicht mehr fortgeschrieben.

Was ein neues Teammitglied sonst teuer bezahlt, steht in `.claude/lessons.md` –
diese Datei wird laufend gepflegt und ist beim Einstieg die zweite Pflichtlektüre
nach diesem Briefing.
