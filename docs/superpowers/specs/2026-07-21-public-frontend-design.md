# Sub-Projekt 3: Öffentliches Frontend – Design-Spec

> Teil von Phase 2 des gestura-index. Reihenfolge der Zerlegung: Backend-Kern (SP1, fertig) → Deployment (SP2, fertig) → **öffentliches Frontend (SP3, dieses Dokument)** → Admin-Panel (SP4).

## 1. Ziel

Eine öffentliche, zweisprachige (en/de) Website, über die jeder Gestura-Menüs und -Suchmaschinen **stöbern, ansehen und herunterladen** kann. Die Website ist in Phase 2 **rein lesend**: kein Nutzer-Login, kein Einreichen-Formular. Eingereicht und verwaltet wird ausschließlich über die Gestura-Extension (die die API direkt anspricht) – das ist ein separater Arbeitsschritt im Extension-Repo. Nutzer-Konten (Passkey) sind Phase 3, das Admin-Panel ist SP4.

Die Website konsumiert ausschließlich die bereits live laufende JSON-API unter `https://api.gestura.eu`.

## 2. Nicht-Ziele (bewusst ausgeschlossen)

- Kein Einreichen-/Update-/Lösch-Formular auf der Website (Extension bzw. Phase 3).
- Kein Login, keine Konten, keine Sterne-Bewertungen (Phase 3).
- Kein Admin/Moderation (SP4).
- Kein Build-Zeit-Prerender der Einträge (bewusst vertagt, siehe Abschnitt 5).

## 3. Tech-Stack & Rahmen

- **SvelteKit** mit **Svelte 5 (Runes erzwungen)**, **TypeScript**, `@sveltejs/adapter-static`.
- **i18n:** Paraglide JS (inlang) – Übersetzungen werden zu tree-shakebaren Funktionen kompiliert (kein Runtime-Dictionary-Laden), funktioniert mit Prerendering. Sprachen: **en** (Fallback) und **de**.
- **Icons:** `@lucide/svelte` (bereits Dependency).
- **Design:** strikt nach `docs/design-system.md` (Tokens aus `gestura-common.css`, Karten, Hell/Dunkel, Logo, Max-Width-Shell). Keine gestalterischen Alleingänge.
- **API-Basis:** Umgebungsvariable `PUBLIC_API_BASE` (Default `https://api.gestura.eu`); lokal auf den Dev-Server überschreibbar.

## 4. Sprach-Routing

- Route-Struktur mit **Sprach-Präfix**: alle Seiten liegen unter `/[lang]/…` mit `lang ∈ {en, de}`. Jede prerenderte Seite wird für beide Sprachen statisch erzeugt (beste SEO, `hreflang`, teilbare sprachspezifische Links).
- **Root-Weiche** `/`: bestimmt die Sprache aus `localStorage` (`gestura_index_lang`) bzw. `Accept-Language`, Fallback `en`, und leitet auf `/en` oder `/de` um. Diese Weiche ist client-seitig (die statische `index.html` enthält eine kleine Weiterleitungslogik – kein Flash, analog zum No-Flash-Theme-Init).
- Der **Sprach-Umschalter** im Header wechselt auf die gleiche Seite in der anderen Sprache (Pfad-Präfix tauschen), speichert die Wahl in `localStorage`.
- Prerender-Konfiguration: Paraglide-Routing so einstellen, dass `adapter-static` beide Sprach-Varianten der prerenderten Seiten erzeugt.

## 5. Rendering-Strategie (Hybrid)

- **Prerendered** (`export const prerender = true`), beide Sprachen statisch – für SEO:
  - Start (`/[lang]`)
  - Format & Schema (`/[lang]/docs`)
  - Über (`/[lang]/about`)
  - Datenschutz (`/[lang]/privacy`)
  - Impressum (`/[lang]/imprint`)
- **Client-gerendert** (`export const prerender = false`), holen Daten zur Laufzeit von der API:
  - Stöbern (`/[lang]/browse`)
  - Detail (`/[lang]/entry/[formatId]`)
- adapter-static mit `fallback` (SPA-Fallback) für die dynamischen Routen. Kein `ssr` zur Laufzeit (reine Auslieferung statischer Dateien).
- **Vertagt:** Build-Zeit-Prerender der Einträge (für perfekte SEO der Detailseiten) wird später nachgerüstet, wenn der Index genug Inhalte hat. In Phase 2 nicht nötig.

## 6. API-Client (`src/lib/api.ts`)

Ein einziger, getesteter Client kapselt alle API-Zugriffe. Er ist die einzige Stelle, die `fetch` gegen die API aufruft.

**Verifizierte API-Verträge (Stand SP1/SP2, live):**

- `GET /api/v1/entries` – Query-Parameter: `q`, `type` (`menu`|`engine`), `category`, `tag`, `site` (Domain), `sort` (Default `newest`), `page` (≥1), `perPage` (1…50, Default 20).
  Antwort: `{ items: EntryListItem[], page, perPage, total }`.
- `GET /api/v1/entries/{formatId}` – Antwort: `EntryListItem` + `versions: VersionInfo[]`.
- `GET /api/v1/entries/{formatId}/versions/{semver}` – liefert die **Austausch-JSON** der Version (das eigentliche Menü/Engine-Objekt), mit ETag/`max-age=300`. `semver` muss `\d{1,5}\.\d{1,5}\.\d{1,5}` entsprechen.
- `POST /api/v1/entries/{formatId}/install` – 204, inkrementiert den anonymen Install-Zähler.
- `POST /api/v1/entries/{formatId}/report` – Body `{ reason, comment? }`. `reason ∈ {spam, broken_links, misleading, legal}`, `comment` optional, ≤2000 Zeichen. Antwort 2xx bei Erfolg.

**TypeScript-Typen (aus der Serializer-Ausgabe abgeleitet):**

```typescript
type EntryType = 'menu' | 'engine';

interface EntryListItem {
  formatId: string;
  type: EntryType;
  name: string;
  description: string | null;
  categories: string[];
  tags: string[];
  domains: string[];
  installCount: number;
  currentVersion: string | null;   // SemVer
  deprecated: boolean;
  successorFormatId: string | null;
  screenshotUrl: string | null;    // von der API RELATIV geliefert
  updatedAt: string;               // ISO-8601
}

interface VersionInfo {
  semver: string;
  changelog: string | null;
  hasTransformCode: boolean;
  submittedAt: string;             // ISO-8601
}

interface EntryDetail extends EntryListItem {
  versions: VersionInfo[];
}

interface EntryListResponse {
  items: EntryListItem[];
  page: number;
  perPage: number;
  total: number;
}
```

**Pflichten des Clients:**

- **Screenshot-Präfix:** `screenshotUrl` kommt relativ (z. B. `/media/…`). Der Client setzt `PUBLIC_API_BASE` davor und gibt eine absolute URL zurück (bzw. `null`). Kein anderer Ort im Code baut Screenshot-URLs.
- **Fehler-Normalisierung:** Bei Nicht-2xx eine `ApiError` werfen mit `{ status: number, title: string, detail: string | null }`, gelesen aus `application/problem+json` (Felder `title`/`detail`), mit sinnvollem Fallback bei Netzwerkfehler/Nicht-JSON.
- **`fetch`-Injektion:** akzeptiert optional ein `fetch` (für SvelteKit-`load` und für Tests).

## 7. Seiten & Komponenten

### Gemeinsames Layout (`/[lang]/+layout.svelte`)
- **Header:** Logo-Kachel (`icon128.png`/`icon128-dark.png`, 36×36, `border-radius:10px`) + Schriftzug **Gestura** + Badge »Index« (Muster `.version`-Badge); Navigation (Start, Stöbern, Format & Schema); **Theme-Umschalter** (auto/hell/dunkel wie Extension, `gestura_index_theme` in localStorage); **Sprach-Umschalter** (en/de).
- **Footer:** Links zu Über, Datenschutz, Impressum, GitHub-Repo; Hinweis »funktioniert mit der Gestura-Extension«.
- Inhalt liegt in der bestehenden `.page-shell` (Max-Width) + `.container`.

### Start (`/[lang]`, prerendered)
- Hero: Pitch (was ist der Index, Verhältnis zur Extension: die Extension läuft vollständig ohne den Index).
- Großes Suchfeld → navigiert bei Enter nach `/[lang]/browse?q=…`.
- Kachel-Gitter der 10 festen Kategorien (i18n-Labels, Lucide-Icon je Kategorie) → verlinkt auf `/browse?category=…`.
- Verweis auf `/docs`.

### Stöbern (`/[lang]/browse`, client-gerendert)
- **Filterzustand lebt in der URL-Query:** `q`, `type`, `category`, `tag`, `site`, `sort`, `page`. Teilbar, Zurück-Button funktioniert.
- **Live-Suche:** Das Suchfeld reagiert während der Eingabe mit **Debounce ~250 ms**; die übrigen Filter (Typ, Kategorie, Tag, Domain, Sortierung) wirken **sofort** bei Änderung. Kein »Anwenden«-Button.
- Änderungen werden per `goto(url, { replaceState: true, keepFocus: true, noScroll: true })` in die URL geschrieben (keine History-Flut, Fokus bleibt im Feld). Ein `$effect` auf die URL-Query lädt die Ergebnisse neu.
- **Request-Sequenz-Guard:** Jeder Ladevorgang bekommt eine laufende Nummer; trifft eine Antwort ein, deren Nummer nicht mehr die aktuelle ist, wird sie verworfen (verhindert, dass eine langsame alte Antwort ein neueres Ergebnis überschreibt).
- Ergebnis-Grid aus `EntryCard`; dezenter Lade-Spinner an der Liste während laufender Requests; `EmptyState` bei null Treffern; `ErrorState` mit »Wiederholen« bei Fehler.
- Paginierung (vor/zurück + Seiteninfo) auf Basis von `total`/`perPage`.

### Detail (`/[lang]/entry/[formatId]`, client-gerendert)
- Kopf: Name, Typ-Badge (menu/engine), Kategorien + Tags, Domains, Install-Zähler, `updatedAt`. Bei `deprecated` deutlicher Hinweis; falls `successorFormatId` gesetzt, Link auf den Nachfolger.
- Screenshot (absolute URL via Client), falls vorhanden.
- Beschreibung.
- **Versionsliste:** je Version SemVer, Datum, Changelog, bei `hasTransformCode` ein Sicherheits-Badge (»enthält ausführbaren Code«).
- **Download-Bereich:**
  - Button »Herunterladen« für die aktuelle Version: holt die Versions-JSON (`GET …/versions/{semver}`), bietet sie als Datei an (Blob, Dateiname `<formatId>-<semver>.json`) **und** feuert danach `POST …/install` (Fehler des Pings werden geschluckt, nicht dem Nutzer gezeigt).
  - »Import-URL kopieren«: kopiert die Download-URL – genau diese kann die Extension per URL-Import ziehen (Phase-1-Feature).
  - Bei `hasTransformCode` der aktuellen Version: deutlicher Hinweis **vor** dem Download (Supply-Chain: enthält ausführbaren JS-Code).
- **Melden:** aufklappbares Formular; Grund-Auswahl (`spam`, `broken_links`, `misleading`, `legal` – i18n-Labels), optionaler Kommentar (≤2000 Zeichen, Client-Zähler) → `POST …/report`. Erfolg/Fehler inline.
- **404:** unbekannter `formatId` → freundliche »nicht gefunden«-Ansicht mit Link zurück zum Stöbern.

### Format & Schema (`/[lang]/docs`, prerendered)
- Erklärt das Austauschformat: zwei Typen (`gesturaMenu: 1`, `gesturaEngine: 1`), wichtige Felder (`id`, `version`, `name`/`description` als String oder Sprach-Objekt mit en-Fallback, `items` bzw. `url`/`patterns`, optionaler `transformCode`), die Kernregeln (nur `https:`-URLs, Aktions-Whitelist, SemVer, Größen-/Anzahllimits) in Prosa.
- Quelle der Inhalte: aus `schema/exchange-schema.json` **abgeleitet und ausformuliert** (nicht live geladen, kein maschinelles Schema-Rendering in Phase 2).

### Über / Datenschutz / Impressum (prerendered)
- **Über:** was der Index ist, Verhältnis zur Extension, dass er optional/kostenlos ist, Link zum Repo, Lizenz (AGPL-3.0-or-later).
- **Datenschutz:** betont Datensparsamkeit – keine IP-Speicherung, anonyme Zähler, keine Konten in dieser Phase, welche Daten die API überhaupt sieht.
- **Impressum:** Platzhalter-Struktur mit klar markierten Feldern, die der Betreiber ausfüllt (kein Erfinden von Angaben).

### Wiederverwendbare Komponenten (`src/lib/components/`)
`EntryCard`, `Badge` (Typ/Kategorie/Warnung), `Spinner`, `EmptyState`, `ErrorState`, `Pagination`, `CategoryTile`, `ThemeToggle`, `LangToggle`. Jede Komponente hat eine klare Aufgabe und ein schmales Prop-Interface.

## 8. Fehlerbehandlung & Zustände

Jede API-gebundene Seite kennt drei Zustände: **Laden** (Spinner), **Fehler** (`ErrorState`, mit »Wiederholen«), **leer** (`EmptyState`). `ApiError` (Abschnitt 6) trägt Status + Detail. 404 auf Detail wird als eigene freundliche Ansicht behandelt, nicht als generischer Fehler. Netzwerkfehler bekommen eine verständliche Meldung, keine Stacktraces.

## 9. Tests

- **Vitest + @testing-library/svelte** für die reinen Komponenten (`EntryCard`, `Badge`, `Pagination`) – Rendering und Props.
- **`api.ts`-Unit-Tests** (der wichtigste Testfokus): Screenshot-Präfix-Bildung, Query-Parameter-Serialisierung, Fehler-Normalisierung aus `problem+json`, Verhalten bei Netzwerkfehler – mit gemocktem `fetch`.
- **Filter-URL-Logik:** Serialisierung/Deserialisierung des Filterzustands in/aus der URL-Query, inkl. Debounce-Verhalten (Timer gemockt) und Sequenz-Guard.
- **`svelte-check`** muss grün sein (TypeScript).
- **Build-Rauchtest:** `npm --prefix frontend run build` erzeugt `frontend/build/` mit den prerenderten Seiten für beide Sprachen.

## 10. Abgrenzung zum Deployment

Der Frontend-Build (`frontend/build/`) wird auf das Web-Root der Index-Domain (`gestura.eu`, Docroot `…/gestura.eu/frontend`) geladen. Die Deploy-Automatisierung dafür ist **nicht** Teil von SP3 (SP2 hat nur das Backend automatisiert); ob und wie der Frontend-Upload in `deploy/` ergänzt wird, ist eine eigene kleine Folgeentscheidung nach SP3. In SP3 gilt der lokale Build als Abnahme-Kriterium.
