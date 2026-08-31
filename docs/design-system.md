# Design-System: Gestura-Look für die Index-Website

Die Index-Website übernimmt das Design der Gestura-Extension (Options-Seite als Referenz). **Autoritative Quelle** ist das Extension-Repo (`/mnt/c/Programme.alt/Gestura/`); die hier liegenden Kopien werden bei Design-Änderungen dort neu übernommen.

## Übernommen (bereits im Repo)

| Was | Von (Extension) | Nach (hier) |
| --- | --- | --- |
| Design-Tokens + Basis-Styles (Buttons, Inputs, Toggles, Checkboxen, Tooltips, Logo-Kachel) | `css/common.css` | `frontend/src/lib/styles/gestura-common.css` (Kopie, nicht hier weiterentwickeln) |
| Logo (hell/dunkel, mehrere Größen) | `icons/icon{16,32,48,128}[-dark].png` | `frontend/src/lib/assets/logo/` + `frontend/static/favicon.png` |
| Menü-Icon-Set (50 kuratierte Lucide-SVGs) | `js/menu-icons.js` | `frontend/src/lib/menu-icons.ts` (Kopie, nicht hier weiterentwickeln) |
| Darstellung des In-Page-Menüs (Rahmen, Liste, Items, Separator, Themes) | `js/content.js` (`ContentContextMenu.generateStyles()`) + `js/context-menu.js` (`FmContextMenu.styles`) | `frontend/src/lib/components/MenuPreview.svelte` |
| Vorschau-Bühne (Karo-Raster + Chip-Label) | `js/components/css-editor-page.js` (`.preview-panel`/`.preview-label`/`.preview-stage`) | ebenda |

## Kernregeln

- **Themes:** Dark ist Default (`:root`), Light via `[data-theme="light"]` auf `<html>`. Drei Modi wie in der Extension: `auto` (folgt `prefers-color-scheme`), `light`, `dark`; Wahl in `localStorage` (`gestura_index_theme`). No-Flash-Init liegt inline in `frontend/src/app.html`.
- **Farben:** ausschließlich über die CSS-Variablen aus `gestura-common.css` (`--accent-color`, `--bg-primary/secondary/tertiary`, `--text-primary/secondary/muted`, `--danger/success/warning-color` …). Keine hartkodierten Farben in Komponenten.
- **Icons:** Lucide – in der Extension als Inline-SVGs (`js/icons.js`), hier über das npm-Paket `@lucide/svelte`. Strichstärke 2, `stroke="currentColor"`. Sektions-Icons in Akzentfarbe (`.section-icon`-Muster); farbige Icon-Kacheln (40×40, `border-radius: 12px`, Tönung via `oklch(from var(--icon-color) l c h / 12%)`) nach dem Muster aus `option.css`.
- **Logo im Header:** `icon128.png` (hell) / `icon128-dark.png` (dunkel) in der `.logo-img`-Kachel (36×36, `border-radius: 10px`, heller/dunkler Verlauf) neben dem Schriftzug **Gestura** plus Badge (Muster `.version`-Badge; auf der Website z. B. »Index«).
- **Karten/Sektionen:** `border-radius: 20px`, Hintergrund `--bg-secondary`, `1px solid var(--section-border)`, Schatten `--section-shadow 0 1px 3px` – als `.card` in `site.css`. Zeilen darin nach dem `.setting-row`-Muster (14px vertikales Padding, `border-top: 1px solid var(--border-color)`).
- **Typografie:** `'Segoe UI', system-ui, sans-serif`, Basis 14px; Überschriften wie Options-Seite (`h1` 1.7em/700, Sektions-`h2` 1.25em mit Icon).

## Live-Vorschau von Menüs

Die Detailkarte eines Menü-Eintrags zeigt **keine Bilddatei**, sondern das echte
In-Page-Menü: `MenuPreview.svelte` rendert den Roh-Payload der aktuellen Version
mit den Styles der Extension. Regeln dazu:

- **Treue vor Schönheit.** Die Vorschau bildet `buildItems()` aus `js/content.js`
  nach: Items ohne Aktion (bzw. `none`) fehlen, ein Label fällt auf den
  Aktionsnamen zurück, ein Icon-Name außerhalb des kuratierten Sets lässt das
  Icon-Feld **leer** – genau wie im echten Menü. Nichts davon wird kaschiert;
  wer im Index einen leeren Icon-Platz sieht, sieht ihn in der Extension auch.
- **Keine Fremd-URLs.** `icon: "favicon"` rendert das Monogramm aus
  `js/favicon-util.js`, nicht das echte Favicon: ein Favicon-Abruf trüge die
  Besucher-IP zum fremden Server. Die Extension zeigt dasselbe Monogramm als
  ersten Zustand.
- **Hell/Dunkel gedreht.** Die Extension hat Hell als Basis und Dunkel als
  Ausnahme; auf der Website ist es wie überall umgekehrt (`:root` = dunkel,
  `[data-theme="light"]` überschreibt), damit die Vorschau auch ohne
  JavaScript zum Seiten-Theme passt. Die Farbwerte des Menüs selbst sind
  unverändert.
- **Die Bühne ist die Website, nicht die Karte.** Im Dunkelmodus liegt sie
  deshalb *unter* der Karten-Helligkeit (`--stage-bg: #08080a`): Gesturas Menü
  ist mit `rgba(30,30,32,.95)` ≈ `#1d1d1f` selbst dunkel und verschwindet auf
  einem Untergrund in Kartenhelligkeit – der schwarze Schatten der Extension
  trägt auf Dunkel nichts. Reale dunkle Seiten liegen ebenfalls tiefer
  (GitHub `#0d1117`, YouTube `#0f0f0f`), die dunkle Bühne ist also zugleich die
  realistischere. Im Hellmodus tragen die Tokens den Kontrast bereits; dort
  bleibt es beim Muster der Extension.
- Einzige Zutat, die es in der Extension nicht gibt: eine Höhenbegrenzung
  (`max-height` + Scrollen), damit ein 100-Item-Menü die Karte nicht sprengt.

## Der Inhalt-Kasten: was importiere ich?

Neben der Vorschau (»wie sieht es aus«) beantwortet der Kasten **Inhalt** in
der linken Spalte die andere Frage: **was steckt drin**. Beide speisen sich aus
demselben Payload-Request.

- **Menü:** jeder Eintrag mit Label und Ziel darunter – URL, `Suchmaschine: <id>`
  oder der Aktionsname. Ziel-URLs sind **Text, keine Links**: der Index soll kein
  Weiterleiter für eingereichte Fremd-URLs werden (dieselbe Wahl trifft der
  Import-Dialog der Extension).
- **Bewusst andere Filterung als die Vorschau:** hier stehen **alle** Items,
  auch die, die im echten Menü nie erscheinen (keine bzw. `none`-Aktion) – sie
  werden als solche markiert. Importiert werden sie schließlich mit.
- **Suchmaschine:** URL-Vorlage mit hervorgehobener Stelle des Suchbegriffs
  (`%s`-Form und Präfix-Form, siehe `js/search-url.js`), die gesetzten
  Verhaltens-Flags als Chips und – zugeklappt hinter einer Warnung – der
  mitgelieferte `transformCode` im Klartext. Wer ausführbaren Code importiert,
  muss ihn vorher lesen können; die Extension zeigt ihn im Import-Dialog
  ebenfalls.
- Die volle Beschreibung steht hier, weil sie in der Kartenzeile auf eine Zeile
  gekürzt ist.

## Die eine gewollte Abweichung: Max-Width

Auf großen Monitoren (4K) zerfällt das Extension-Layout (Navigation klebt links außen). Die Website legt deshalb **alles** – Header, Navigation, Inhalt – in eine zentrierte Shell:

- `.page-shell`: `max-width: 1200px` (`--page-max-width`), zentriert; liegt bereits im Root-Layout.
- `.container`: `max-width: 900px` (`--content-max-width`) für Inhaltsspalten, wie in der Extension.

Ansonsten gilt: **keine gestalterischen Alleingänge** – Logos, Hell/Dunkel, Boxen, Icons, Abstände wie in der Extension.
