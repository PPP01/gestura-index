# Design-System: Gestura-Look für die Index-Website

Die Index-Website übernimmt das Design der Gestura-Extension (Options-Seite als Referenz). **Autoritative Quelle** ist das Extension-Repo (`/mnt/c/Programme.alt/Gestura/`); die hier liegenden Kopien werden bei Design-Änderungen dort neu übernommen.

## Übernommen (bereits im Repo)

| Was | Von (Extension) | Nach (hier) |
| --- | --- | --- |
| Design-Tokens + Basis-Styles (Buttons, Inputs, Toggles, Checkboxen, Tooltips, Logo-Kachel) | `css/common.css` | `frontend/src/lib/styles/gestura-common.css` (Kopie, nicht hier weiterentwickeln) |
| Logo (hell/dunkel, mehrere Größen) | `icons/icon{16,32,48,128}[-dark].png` | `frontend/src/lib/assets/logo/` + `frontend/static/favicon.png` |

## Kernregeln

- **Themes:** Dark ist Default (`:root`), Light via `[data-theme="light"]` auf `<html>`. Drei Modi wie in der Extension: `auto` (folgt `prefers-color-scheme`), `light`, `dark`; Wahl in `localStorage` (`gestura_index_theme`). No-Flash-Init liegt inline in `frontend/src/app.html`.
- **Farben:** ausschließlich über die CSS-Variablen aus `gestura-common.css` (`--accent-color`, `--bg-primary/secondary/tertiary`, `--text-primary/secondary/muted`, `--danger/success/warning-color` …). Keine hartkodierten Farben in Komponenten.
- **Icons:** Lucide – in der Extension als Inline-SVGs (`js/icons.js`), hier über das npm-Paket `@lucide/svelte`. Strichstärke 2, `stroke="currentColor"`. Sektions-Icons in Akzentfarbe (`.section-icon`-Muster); farbige Icon-Kacheln (40×40, `border-radius: 12px`, Tönung via `oklch(from var(--icon-color) l c h / 12%)`) nach dem Muster aus `option.css`.
- **Logo im Header:** `icon128.png` (hell) / `icon128-dark.png` (dunkel) in der `.logo-img`-Kachel (36×36, `border-radius: 10px`, heller/dunkler Verlauf) neben dem Schriftzug **Gestura** plus Badge (Muster `.version`-Badge; auf der Website z. B. »Index«).
- **Karten/Sektionen:** `border-radius: 20px`, Hintergrund `--bg-secondary`, `1px solid var(--section-border)`, Schatten `--section-shadow 0 1px 3px` – als `.card` in `site.css`. Zeilen darin nach dem `.setting-row`-Muster (14px vertikales Padding, `border-top: 1px solid var(--border-color)`).
- **Typografie:** `'Segoe UI', system-ui, sans-serif`, Basis 14px; Überschriften wie Options-Seite (`h1` 1.7em/700, Sektions-`h2` 1.25em mit Icon).

## Die eine gewollte Abweichung: Max-Width

Auf großen Monitoren (4K) zerfällt das Extension-Layout (Navigation klebt links außen). Die Website legt deshalb **alles** – Header, Navigation, Inhalt – in eine zentrierte Shell:

- `.page-shell`: `max-width: 1200px` (`--page-max-width`), zentriert; liegt bereits im Root-Layout.
- `.container`: `max-width: 900px` (`--content-max-width`) für Inhaltsspalten, wie in der Extension.

Ansonsten gilt: **keine gestalterischen Alleingänge** – Logos, Hell/Dunkel, Boxen, Icons, Abstände wie in der Extension.
