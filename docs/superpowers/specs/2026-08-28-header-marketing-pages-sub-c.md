# Sub-Projekt C – Header-Angleichung + Marketing-/SEO-Seiten (C1–C5)

**Datum:** 2026-08-28
**Status:** freigegeben (Design), wartet auf Plan
**Vorgänger:** Sub-Projekt A (Onepager-Frontend, gemergt `d766150`)

## 1. Ziel

Den `gestura-index`-Frontend um die fünf im Design-Handoff festgelegten
Marketing-/SEO-Seiten (C1–C5) erweitern und den gemeinsamen **Header** auf den
Handoff-Stand bringen. Die Seiten sind **prerendered** (SEO); der bestehende
Onepager-Katalog zieht dabei von `/` auf `/index` um, damit `/` zur
Marketing-Startseite (C1) wird.

Autoritative Referenz: `docs/design_handoff_gestura_index/` (README.md +
`screenshots/1i`–`1n` + `Gestura Index.dc.html`). Die Copy im Handoff ist final
gemeint (DE); EN wird im Zuge dieser Umsetzung ergänzt.

## 2. Global Constraints (gelten für jede Task)

- **Sprache/Typografie in Prosa/Kommentaren/Commits:** Deutsch, Guillemets »…«
  (nie „…" / "…"), Halbgeviertstrich – (nie —), echte Umlaute ä/ö/ü/ß. Gilt
  **nicht** für Code-Bezeichner und String-Literale, wo Syntax es verlangt.
- **Kein Push.** Alles bleibt lokal auf `main`; Merge am Ende lokal `--no-ff`.
- **Commits im eigenen Scope**, nie Bulk-Commit des ganzen Arbeitsbaums.
- **Paraglide-Kompilat `frontend/src/lib/paraglide/messages.js` ist gitignored –
  NIE committen.** Nur `messages/en.json` + `messages/de.json` committen.
- **Nie `vite dev` gleichzeitig mit `npm run check`/`test`** (Paraglide-Compile-
  Konflikt).
- **`frontend/.env.local` ist schreibgeschützt** (`sensitive-files-guard`) – nicht
  editieren.
- **Design-Tokens verbatim** aus `gestura-common.css` / Handoff-README §Design
  Tokens. Keine gestalterischen Alleingänge; Abweichungen nur die hier
  ausdrücklich genannten.
- **C4-Konkurrenzspalten sind Platzhalter** (ERW. A/B/C, »–«, Warnbanner). **Keine
  erfundenen Konkurrenz-Fakten.**
- **Store-Badges (Chrome/Edge/Firefox) sind offizielle Vendor-Assets** – als
  Bilddateien einbinden, **nicht** als SVG nachbauen (Markenschutz).
- Abschluss jeder Task: `npm --prefix frontend run check` (0 Fehler/0 Warnungen)
  + `npm --prefix frontend run test -- --run` (grün, sauber). Commit-Message endet
  mit `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

## 3. Routing & Rendering

| Pfad | Inhalt | Rendering |
|---|---|---|
| `/` | **C1** Marketing-Startseite | prerendered (`ssr=true`, `prerender=true`) |
| `/was-ist-gestura` | **C2** »Was ist Gestura« | prerendered |
| `/maus-gesten` | **C3** »Was sind Maus-Gesten« | prerendered |
| `/vergleich` | **C4** »Gestura im Vergleich« | prerendered |
| `/beispiele` | **C5** »Beispiele« | prerendered |
| `/index` | **Onepager-Katalog** (aus Sub-A umgezogen) | client-only (`ssr=false`, `prerender=false`) |

**Slugs sprach-neutral**, nur Locale-Präfix der Paraglide-URL-Strategie
(`/de/was-ist-gestura`, `/en/was-ist-gestura`, …). Kein pro-Sprache-Slug.

**Umzug des Onepagers (Sub-A):**
- `src/routes/(public)/+page.svelte` + `+page.ts` → `src/routes/(public)/index/+page.svelte` + `index/+page.ts` (Inhalt unverändert außer internen Link-Zielen).
- `(public)/+layout.svelte`: der `wide`-Switch prüft künftig `page.route.id === '/(public)/index'` (statt `'/(public)'`).
- Redirects aktualisieren/ergänzen:
  - `/browse` → `/index` (Query-Mapping unverändert übernehmen).
  - `/entry/[formatId]` → `/index?highlight=<formatId>`.
  - `/gestura` → `/` (Alt-Landing wird durch C1/C2 ersetzt).
- Alle bestehenden »Zum Index«/»Alle Einträge ansehen«-Verweise zeigen auf `/index`.
- Bestehende Routen `/about`, `/privacy`, `/imprint`, `/docs` bleiben unverändert (Footer-Links).

**SEO:** Jede prerenderte C-Seite setzt in `<svelte:head>` einen eindeutigen
`<title>` und `<meta name="description">` (DE/EN via Paraglide). `/` zusätzlich
ein knappes Open-Graph-Set (`og:title`, `og:description`) optional, wenn ohne
externe Assets machbar.

## 4. Header (handoff-konform, gilt für alle Seiten)

Ersetzt den Sub-A-Zwischenstand in `Header.svelte`.

- **Marke links:** Logo-Gradient-Kachel 36×36/r10 (hell/dunkel umschaltend) +
  Schriftzug »Gestura« 17px/700 + **Badge »INDEX«** (10.5px/700, accent-tint, r6)
  – Badge **nur auf `/index`** sichtbar (per `page.route.id`).
- **Marketing-Nav** (13px): `Start · Was ist Gestura · Maus-Gesten · Vergleich ·
  Beispiele · Index`. Aktiver Link `text-primary`/600 (Abgleich per
  `page.route.id`); »Index«-Link in accent.
- **Rechts:** **DE/EN-Segmented** (ein Pill-Container, zwei Segmente; aktiv =
  accent-tint) – ersetzt `LangToggle` (Logik `localizeHref` + `data-sveltekit-reload`
  bleibt) · **Theme-Toggle** restyled auf 32px/r9/1px-Border (3-Zustand-Logik
  Auto/Hell/Dunkel bleibt) · **GitHub-Icon-Button** (Lucide `Github`) →
  `https://github.com/PPP01/Gestura` (`target="_blank"`, `rel="noopener noreferrer"`).
- **Responsiv (schmal):** Nav klappt kompakt (horizontal scrollbar ODER Toggle-
  Menü); Marke + rechte Aktionen bleiben immer sichtbar. Kein Funktionsverlust.
- **Footer:** GitHub-Link von `PPP01/gestura-index` auf `PPP01/Gestura` umstellen
  (konsistent mit Header/Entscheidung »Extension-Repo«). Übrige Footer-Struktur
  bleibt.

## 5. Seiteninhalte

Copy final aus dem Handoff (DE), EN im Zuge dieser Umsetzung übersetzt. Alle
Texte als Paraglide-Keys in **beiden** JSON. Tokens/Radien/Schatten aus
Handoff-README §Design Tokens.

### C1 – Marketing-Startseite (`/`, Screenshots 1i/1j)
- **Hero** (Grid `1.1fr .9fr`, mobil gestapelt):
  - Trust-Pill (success-Tint, Shield-Icon): »Open Source · AGPL · ohne Tracking«.
  - **H1** 42px/700, zweiter Teilsatz in accent: »Dein Browser gehorcht aufs Wort. **Oder auf die Geste.**«
  - Subline 16px/1.6: »Gestura bringt Maus-Gesten, Super-Drag, Wheel- und Rocker-Gesten und Bereichsauswahl in deinen Browser – mit eigenen Suchmaschinen und Website-Menüs. Anonym nutzbar, ohne Konto.«
  - **Drei offizielle Store-Badges** (Abweichung vom 2-Button-Original) – Chrome, Edge, Firefox – höhen-normiert (~54px), je verlinkt (Ziele s. §6), `target="_blank" rel="noopener"`, mit `alt`-Text. Container mit `id="install"` (Sprungziel für C5).
  - Textlink darunter: »Menüs & Suchmaschinen entdecken →« → `/index`.
  - **Browser-Mock** (rechts): eigenes SVG – Fensterleiste (3 Punkte + URL-Bar), accent-Gestenstrich (5px, L-Form ↓→ mit Pfeilspitze + Startpunkt-Kreis), Aktions-Chip »↓ → Tab schließen«, `icon128.png` unten links. Selbstgebaut, keine Fremdgrafik.
- **Feature-Grid** 3×2 (Karten r20, Icon-Kachel 40px, Titel 14.5px/700, Text 12.5px):
  Maus-Gesten · Super-Drag · Wheel- & Rocker-Gesten · Bereichsauswahl · Eigene
  Suchmaschinen · Website-Menüs (Copy aus 1i übernehmen). Überschrift »Alles drin,
  was schnelle Hände brauchen«.
- **Vertrauens-Streifen** (success-getönte Karte): Anonym nutzbar · Keine E-Mail
  nötig · Keine Tracking-Daten · Open Source (AGPL) · rechts »Chrome · Edge · Firefox«.
- **Index-Teaser** »Frisch aus dem Index«: 3 echte Einträge, **client-seitig nach
  Hydration** über die Listen-API geladen (Sortierung »Neueste«, `perPage=3`),
  Skeleton-Fallback während des Ladens, Fehler still (Teaser verschwindet). Nutzt
  die vorhandene `EntryBlock`-Komponente. Link »Alle Einträge ansehen →« → `/index`.
  (Die Zahl »128« aus dem Screenshot ist Platzhalter → tatsächliche Gesamtzahl aus
  der API-Antwort einsetzen, sonst neutral »Alle Einträge ansehen →«.)

### C2 – »Was ist Gestura« (`/was-ist-gestura`, Screenshot 1k)
900px-Lesespalte. Intro-Absatz (aus 1k). Drei »Für …«-Karten (Für Vielsurfer /
Für Recherchierende / Für Datenschutzbewusste). Drei alternierende
Text+Screenshot-Sektionen (Maus-Gesten & Gestenspur / Eigene Suchmaschinen /
Website-Menüs) mit **Screenshot-Platzhalter-Boxen** (1.5px dashed, Bild-Icon,
Bildunterschrift). Abschluss: Datensparsamkeits-Banner (success-getönt).

### C3 – »Was sind Maus-Gesten« (`/maus-gesten`, Screenshot 1l)
Intro-Absatz. **4×2-Grid Gesten-Karten**; jede: **selbstgebautes SVG-Diagramm**
150×80 (Startpunkt-Kreis r6 accent, Strich 5px round-cap, Pfeilspitze), darunter
Mono-Kürzel (accent) + Aktions-Label:
Zurück (←) · Vorwärts (→) · Neuer Tab (↓) · Tab schließen (↓ →) · Nach oben
scrollen (↑) · Seite neu laden (↓ ↑) · Rocker-Geste »L + R« (Maus-Silhouette,
Tasten) · Wheel-Geste »R + Rad« (Maus-Silhouette, Rad + Pfeile). Fußnote: »Alle
Zuordnungen sind Beispiele – in Gestura ist jede Geste frei belegbar.«

### C4 – »Gestura im Vergleich« (`/vergleich`, Screenshot 1m)
Intro-Absatz. **Warnbanner** (warning-Tint, Alert-Icon): »Die Spalten ›Erweiterung
A/B/C‹ sind bewusst Platzhalter – sie werden vor Veröffentlichung mit belegten,
aktuellen Angaben gefüllt.« **Matrix** (Grid `2.2fr 1.2fr 1fr 1fr 1fr`):
- Kopf: MERKMAL · **GESTURA** (Logo-Kachel + accent) · ERW. A · ERW. B · ERW. C.
- Gestura-Spalte hervorgehoben (bg `rgba(accent,.07)` + accent-Border links/rechts
  über volle Höhe), Zellen mit grünem Häkchen + Kurznotiz (echte Fakten aus 1m).
- **Platzhalterspalten A/B/C: durchgehend »–« (muted).**
- 10 Merkmal-Zeilen: Firefox-Unterstützung (»Chrome · Edge · Firefox«) · Kein
  Tracking (»keine IP-Speicherung«) · Anonyme Nutzung (»keine E-Mail-Pflicht«) ·
  Kostenlos (»dauerhaft«) · Open Source (»AGPL-3.0«) · Eigene Suchmaschinen (»frei
  definierbar«) · Website-Menüs (»pro Domain«) · Teilbarer Index (»gestura.app«) ·
  Leichtgewichtig (»reines JS«) · Rocker- & Wheel-Gesten (»inklusive«). Fußnote:
  »Stand: Platzhalter-Matrix, 10 Merkmale. Quellenangaben je Zelle folgen.«

### C5 – »Beispiele« (`/beispiele`, Screenshot 1n)
Intro-Absatz. **2×2-Showcase-Karten**, kuratiert/hartcodiert (4 Karten aus 1n):
Preisvergleich-Menü (Super-Drag ↓) · Wikipedia-Schnellsuche (Auswahl + →) · GitHub
Dev-Menü (Rechtsklick-Menü) · News-Radar (↓ → Menü). Je Karte: **animierte-
Vorschau-Platzhalter** (210px Gradient-Fläche, Play-Kreis 52px, Label »Animierte
Vorschau (GIF / Video-Frame)«, Gesten-Chip oben links mono/accent), darunter
Icon-Kachel (Kategoriefarbe) + Name + Typ-Badge + Beschreibung + CTAs
»Installieren« → `/#install` und »Zum Index →« → `/index`.

## 6. Store-Links & Assets

- **Chrome Web Store:** `https://chromewebstore.google.com/detail/gestura-mouse-gestures/ddcendiamegpalekoneonjkenhcamjnj`
- **Microsoft Edge:** `https://microsoftedge.microsoft.com/addons/detail/gestura-mausgesten/dhjkaagkfcmgddieogodeioopogfpghn`
- **Firefox AMO:** `https://addons.mozilla.org/firefox/addon/gestura-mouse-gestures/` (Locale-neutral; AMO leitet nach Browser-Sprache um)
- **GitHub (Extension):** `https://github.com/PPP01/Gestura`
- **Logo-Tile-Assets:** `docs/design_handoff_gestura_index/assets/icon128-tile.png` +
  `icon128-darktile.png` nach `frontend/src/lib/assets/logo/` kopieren; im Header
  verwenden. Für den Hero-Deko-Einsatz `icon128.png` (freigestellte Hand).
- **Store-Badges:** offizielle Vendor-Badges in `frontend/src/lib/assets/stores/`
  (`chrome-webstore.*`, `microsoft-edge.*`, `firefox-addon.*`) – **werden vom
  Nutzer bereitgestellt** (SVG bevorzugt, sonst PNG @2×). Fallback beim Umsetzen:
  Versuch, sie per `curl` von den offiziellen Marken-Seiten zu holen. Fehlen die
  Dateien, bleibt der Badge-Slot mit Text-Fallback-Button bestehen, bis die Assets
  da sind (klar geloggt, kein Blocker für die restliche Seite).
- **Icons:** Lucide über `@lucide/svelte` (bereits im Projekt): search, github, sun,
  moon, download, external-link, shield, mouse, move, scan, zap, globe, code,
  shopping-cart, play, alert-triangle, arrow-right, check, image u. a.

## 7. i18n

- Alle C-Copy + neue Nav-Labels als Keys in `messages/de.json` **und**
  `messages/en.json` (EN aus DE übersetzt).
- **Tote Keys aufräumen:** `hero_title`, `hero_sub`, `home_categories`,
  `home_docs_cta` entfernen (nicht mehr referenziert nach dem Umzug). `hero_tagline`
  nur entfernen, wenn nirgends mehr genutzt (vorher grep verifizieren).
- Nav-Keys neu/überarbeitet: `nav_home` (»Start«), `nav_what` (»Was ist Gestura«),
  `nav_gestures` (»Maus-Gesten«), `nav_compare` (»Vergleich«), `nav_examples`
  (»Beispiele«), `nav_index` (»Index«). Alt-Keys `nav_browse`/`nav_docs`/
  `nav_get_gestura` entfernen, falls nach Umbau ungenutzt.

## 8. Tests

Fokus auf Verhalten, nicht auf statisches Markup:
- **Routing/Redirects:** `/browse`→`/index` (inkl. Query-Mapping), `/entry/[id]`→
  `/index?highlight=…`, `/gestura`→`/`. Onepager unter `/index` lädt.
- **Header:** rendert die sechs Nav-Links mit korrekten `href`; aktiver Link je
  Route korrekt markiert; INDEX-Badge nur auf `/index`; GitHub-Link zeigt auf
  `PPP01/Gestura`; DE/EN-Segmented markiert die aktive Locale.
- **C1-Teaser:** lädt 3 Einträge (gemockte API), zeigt Skeleton davor, verschwindet
  bei Fehler; Store-Badges verlinken auf die drei korrekten URLs.
- **C4:** 10 Merkmal-Zeilen; Platzhalterspalten enthalten »–«; Warnbanner vorhanden.
- **C3:** acht Gesten-Karten mit Label + Kürzel.
- **C5:** vier Showcase-Karten; »Zum Index«→`/index`, »Installieren«→`/#install`.
- **Prerender:** `npm --prefix frontend run build` erzeugt statische HTML für
  `/`, `/was-ist-gestura`, `/maus-gesten`, `/vergleich`, `/beispiele` (und
  `/de/…`,`/en/…`), ohne Prerender-Fehler; `/index` als SPA-Fallback.

## 9. Task-Schnitt (Vorschlag für den Plan)

1. **Onepager-Umzug** `/`→`/index` + Layout-`wide`-Switch + Redirects (browse/
   entry/gestura) + Link-Ziele + Tests.
2. **Header** handoff-konform (Logo-Tile, INDEX-Badge, Marketing-Nav + aktiv-
   Zustand, DE/EN-Segmented, Theme-Toggle-Restyle, GitHub-Button, responsiv) +
   Footer-GitHub-Fix + geteilte Store-Badge-Komponente + Tests.
3. **C1** Startseite (Hero + Badges + Browser-Mock-SVG + Feature-Grid + Vertrauens-
   Streifen + Index-Teaser) + i18n + SEO + Tests.
4. **C2** »Was ist Gestura« + i18n + SEO + Tests.
5. **C3** »Maus-Gesten« (Gesten-SVGs) + i18n + SEO + Tests.
6. **C4** »Vergleich« (Matrix, Platzhalter) + i18n + SEO + Tests.
7. **C5** »Beispiele« (Showcase-Karten) + i18n + SEO + Tests.

Geteilte Primitive (Sektions-Wrapper, Icon-Kachel-Überschrift, Screenshot-/
Vorschau-Platzhalter) entstehen dort, wo zuerst gebraucht (Task 2/3), und werden
von späteren Tasks konsumiert.

## 10. Bewusste Abweichungen vom Handoff

- **C1-Hero:** drei offizielle Store-Badges statt zwei Text-Buttons (Nutzer-Wunsch;
  Edge hat eigenes Listing getrennt von Chrome).
- **Theme-Toggle:** 3-Zustand (Auto/Hell/Dunkel) statt reinem Hell/Dunkel – die
  bestehende, bessere Logik aus Sub-A bleibt, nur optisch angeglichen.
- Onepager auf `/index` statt `/` (Routing-Konsequenz aus »Start = C1«).

## 11. Nicht in diesem Sub-Projekt

- Echte Screenshots/GIFs für C2/C5 (Platzhalter bleiben).
- Echte C4-Konkurrenzdaten.
- Sub-Projekt B (Backend `/api/v1/bundle` + »An Gestura senden«).
