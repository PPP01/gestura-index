# Handoff: Gestura Index – Onepager + Marketing-/SEO-Seiten

## Overview
Website-Design für den **Gestura Index** (gestura.app): ein Onepager-Katalog zum Entdecken, Filtern und Sammeln von Gestura-Menüs und -Suchmaschinen, plus fünf Marketing-/SEO-Seiten (C1 Startseite, C2 „Was ist Gestura“, C3 „Was sind Maus-Gesten“, C4 Vergleich, C5 Beispiele). Die Website übernimmt den Look der Browser-Extension: Dark ist Default, Light als Variante.

## About the Design Files
Die Dateien in diesem Paket sind **Design-Referenzen in HTML** (Multi-Artboard-Canvas), keine Produktionsdateien. Aufgabe: die gezeigten Screens **in der Zielumgebung des Projekts nachbauen** (bestehendes Framework/Patterns verwenden; falls noch keine Umgebung existiert: geeignetes Framework wählen, z. B. statisches SSG oder leichtgewichtiges SPA – die Seite braucht SEO, also SSR/SSG bevorzugen). Die `.dc.html`-Dateien nutzen ein proprietäres Template-Format (`{{ }}`-Platzhalter, `<sc-for>`-Schleifen, `<dc-import>`); sie sind als **Struktur- und Stil-Referenz** zu lesen, nicht direkt lauffähig zu übernehmen. Alle Styles stehen inline an den Elementen – exakte Werte einfach dort ablesen.

## Fidelity
**High-fidelity.** Farben, Typografie, Abstände, Radien und Copy sind final gemeint und sollen pixelgenau übernommen werden (mit den Libraries/Patterns der Codebase). Platzhalter sind nur: Screenshots (gestrichelte Boxen), animierte Vorschauen (C5) und die Konkurrenz-Spalten der Vergleichstabelle (C4).

## Artboard-Übersicht (IDs im Canvas)
- **1a** Onepager Desktop Dark, Facetten als Kopfleiste (Variante A)
- **1b** Onepager Desktop Dark, Facetten als linke Sidebar (Variante B) — eine der beiden Varianten wird gewählt
- **1c** Zustand: Suche/Filter aktiv, Eintrag inline aufgeklappt (Versionen, „enthält Skript“-Warnung, Screenshot, Domains) + zweite aufklappbare Ebene „Bewertungen“
- **1d** Sammelkorb: Panel offen (3 Einträge, Aktionen) und Leerzustand
- **1e** Kein-Treffer-Zustand + Lade-Skeleton (Nachladen)
- **1f** Onepager Desktop Light
- **1g / 1h** Onepager Mobile (390 px) Dark / Light
- **1i / 1j** C1 Marketing-Startseite Dark / Light
- **1k** C2 „Was ist Gestura“ (redaktionell, 900-px-Spalte)
- **1l** C3 „Was sind Maus-Gesten“ (Gesten-Diagramm-Karten)
- **1m** C4 „Gestura im Vergleich“ (Matrix, Konkurrenz-Spalten = Platzhalter!)
- **1n** C5 „Beispiele“ (Showcase-Karten)

## Design Tokens
Typografie: `'Segoe UI', system-ui, sans-serif`; Basis 14px; h1 Onepager 24px/700, h1 Marketing 32–42px/700; Sektions-h2 17.5px/700 mit vorangestelltem Akzent-Icon (17px) bzw. Icon-Kachel 34px.

| Token | Dark | Light |
|---|---|---|
| accent | `#5b9cf6` | `#4285f4` |
| accent-hover | `#4a8ae8` | `#3367d6` |
| accent-tint (Chips/Badges) | `rgba(91,156,246,.16)` | `rgba(66,133,244,.12)` |
| bg-primary | `#121216` | `#f0f2f5` |
| bg-secondary (Karten) | `rgba(255,255,255,.055)` | `#ffffff` |
| border (fein) | `rgba(255,255,255,.09)` | `rgba(0,0,0,.07–.08)` |
| divider (Innenzeilen, border-top) | `rgba(255,255,255,.07–.08)` | `rgba(0,0,0,.06–.07)` |
| text-primary | `#ececf1` | `#222222` |
| text-secondary | `#a1a1aa` | `#666666` |
| text-muted | `#71717a` | `#999999` |
| danger / success / warning | `#ef5350` / `#4caf50` / `#e6a117` | dito (success Light `#3d8b40`) |
| star-fill / star-base | `#e6a117` / `rgba(255,255,255,.18)` | `#e6a117` / `rgba(0,0,0,.15)` |
| elevated panel (Sammelkorb) | `#1c1c22` | – |

Kategoriefarben (Icon-Kacheln & Badges, Hintergrund = Farbe + `1f` Hex-Alpha ≈ 12 %):
Dev `#8b5cf6` · Shopping `#e6a117` · Video `#ef5350` · News `#fb8c4e` · Social `#ec4899` · Produktivität `#4caf50` · Suche `#5b9cf6` · Referenz `#2bb8a8` · Unterhaltung `#d4b106` · Sonstiges `#8a8a93`

Radien: Karten/Sektionen 20px · Ergebnisliste mobil 18px · Suchfeld 14px (mobil 13px) · Buttons 10–12px · Icon-Kacheln 40×40/r12 (klein 30–34/r9–11) · Logo-Kachel 36×36/r10 · Chips/Badges pill (20px/999) · Checkbox 20×20/r6 · kbd-Hint r6.
Schatten: Karten Dark `0 6px 24px rgba(0,0,0,.22)`, Light `0 3–4px 12–18px rgba(0,0,0,.04–.06)`; Primär-Button `0 8px 24px rgba(accent,.35)`; schwebender Pill `0 12px 32px rgba(accent,.4)`; Panel `0 20px 50px rgba(0,0,0,.5)`.
Layout: zentrierte Shell `max-width:1200px` (Seite) bzw. `900px` (Textspalten, C2/C3-Intro); auch auf 4K zentriert, nie randlos.

## Screens / Komponenten (Kernpunkte)
**Header (alle Seiten):** Logo-Kachel 36px (Asset `icon128-tile.png`) + Schriftzug „Gestura“ 17px/700 + Badge „INDEX“ (10.5px/700, accent-tint, r6; nur auf Index-Seiten) · rechts DE/EN-Segmented (aktiv accent-tint), Theme-Toggle (Sonne/Mond, 32px, r9, 1px Border), GitHub-Icon-Button. Marketing-Seiten: Nav-Links 13px, aktiver Link text-primary/600, „Index“-Link accent.

**Suchfeld:** 50px hoch (Desktop), r14, bg-secondary, 1px Border, Lupe links, „/“-kbd-Hint rechts. Fokus-Zustand: Border `rgba(accent,.55)` + Ring `0 0 0 3px rgba(accent,.15)`.

**Facetten (2 Varianten):** Kopfleiste = Karte mit 3 Zeilen (KATEGORIE-Chips mit Icon+Anzahl, TAGS-Chips „#tag n“ + „+ n weitere“, Zeile TYP-Segmented / SPRACHE-Chips / Sortier-Dropdown rechts), Labels 10.5px/600, letter-spacing .08em, muted. Sidebar = 268px Karte, Gruppen mit border-top-Trennern, Kategorien als vertikale Zeilen (Icon, Name, Anzahl rechts), „alle zurücksetzen“ oben rechts.

**Ergebnis-Block (Kern-Komponente, siehe `Block.dc.html`):** kompakte Zeile, border-top-Trenner. Links Auswahl-Toggle (20px, unselektiert: Border + Plus-Icon; selektiert: accent-gefüllt + Häkchen) · Icon-Kachel 40×40/r12 in Kategoriefarbe (12 %-Tint) · Name 14px/600 + Typ-Badge („Menü“ accent-tint / „Suchmaschine“ success-tint) · 1 Zeile Beschreibung 12.5px, ellipsis · Kategorie-Badges (farbig getönt) + Sprach-Badges (outlined, uppercase) · rechts: 5-Sterne-Inline-Rating (Basis-Sterne muted, Overlay gold, Breite = rating/5, per overflow:hidden geclippt) + „4,3 · 12“ + Install-Zähler mit Download-Icon · Chevron. Mobil: rechte Spalte bricht per flex-wrap unter den Text.

**Aufgeklappter Block (1c):** Zeile bekommt accent-Tint-Hintergrund `rgba(91,156,246,.05)`, Chevron dreht nach oben (accent). Detailbereich (Einzug 68px links): Grid `1fr 300px` – links VERSIONEN-Box (r12, Zeilen: SemVer-Chip mono, Datum, Changelog-Zeile; Warnung „enthält Skript“ = warning-Tint-Pill mit Alert-Icon) und HOMEPAGE & DOMAINS (Link accent + Domain-Chips mono); rechts Screenshot-Platzhalter 300×180 (1.5px dashed). Darunter zweite Ebene „Bewertungen (n)“: eigener Aufklapp-Header + Button „Bewertung schreiben“ (ghost, accent-Border), Einträge = Sterne + „Anonym · vor n Tagen“ + Kommentar.

**Sammelkorb (1d):** schwebender Pill unten rechts (Desktop) / unten mittig (Mobil), accent, „n ausgewählt“, immer sichtbar (0 = neutraler Pill). Panel 390px, bg `#1c1c22`, r20: Header „Auswahl“ + Zähler-Badge + „alle entfernen“ (Trash) · Zeilen mit Mini-Icon-Kachel 30px, Name, Typ, Entfernen-X · Footer: Primär-Button „Als JSON herunterladen“ (aktiv) + „An Gestura senden“ (disabled, opacity .6, cursor not-allowed, Tooltip „kommt bald“) + Hinweis „Die Auswahl bleibt lokal in deinem Browser gespeichert.“ Leerzustand: Layers-Icon-Kachel 52px, Titel + Erklärtext.

**Zustände (1e):** Kein Treffer = zentrierte Karte (Icon-Kachel 56px, Titel mit Suchbegriff, Hinweistext, Ghost-Button „Filter zurücksetzen“). Laden = Skeleton-Zeilen (Formen der Block-Zeile in `rgba(255,255,255,.06–.09)`, Puls-Animation 1.5s ease-in-out, gestaffelte Delays 0/.2/.4s).

**C1 Hero (1i/1j):** Grid `1.1fr .9fr`. Links: Trust-Pill (success-Tint, Shield-Icon), H1 42px mit accent-farbigem Teilsatz, Subline 16px/1.6, CTAs: „Für Chrome / Edge installieren“ (accent, gefüllt) + „Für Firefox installieren“ (accent-Outline), darunter Textlink „Menüs & Suchmaschinen entdecken →“. Rechts: Browser-Mock (Fensterleiste mit 3 Punkten + URL-Bar) mit Gesten-Strich (accent, 5px, L-Form ↓→ mit Pfeilspitze, Startpunkt) + Aktions-Chip „↓ → Tab schließen“ + `icon128.png` unten links. Danach: Feature-Grid 3×2 (Icon-Kachel 40px, Titel 14.5px/700, Text 12.5px), Vertrauens-Streifen (success-getönte Karte: Anonym / keine E-Mail / kein Tracking / AGPL / „Chrome · Edge · Firefox“), Index-Teaser (3 Blocks + „Alle 128 Einträge ansehen →“).

**C3 Gesten-Karten (1l):** 4er-Grid; jede Karte: SVG-Diagramm 150×80 (Startpunkt-Kreis r6 accent, Strich 5px round-cap, Pfeilspitze), darunter mono-Kürzel (accent, z. B. „↓ →“) und Aktions-Label. Rocker/Wheel: Maus-Silhouette (muted Stroke) mit accent-gefüllter Taste bzw. Rad + Rad-Pfeile. Fußnote: „Alle Zuordnungen sind Beispiele …“

**C4 Matrix (1m):** Hinweis-Banner (warning-Tint, Alert-Icon): Spalten A–C sind Platzhalter, KEINE erfundenen Konkurrenz-Fakten eintragen. Tabelle: Grid `2.2fr 1.2fr 1fr 1fr 1fr`; Gestura-Spalte hervorgehoben (bg `rgba(accent,.07)` + 1px accent-Border links/rechts über volle Höhe, Zellen: grünes Häkchen + Kurznotiz); Platzhalter-Zellen „–“ muted. 10 Merkmal-Zeilen (Firefox-Support, kein Tracking, anonym, kostenlos, AGPL, eigene Suchmaschinen, Website-Menüs, teilbarer Index, leichtgewichtig, Rocker/Wheel).

**C5 Showcase (1n):** 2×2-Karten: Vorschau-Bereich 210px (Gradient-Fläche, Play-Kreis 52px, Label „Animierte Vorschau (GIF / Video-Frame)“, Gesten-Chip oben links mono/accent), darunter Icon-Kachel + Name + Typ-Badge, Beschreibung, CTAs „Installieren“ (gefüllt) + „Zum Index →“ (Outline).

**Footer (alle Seiten):** border-top, 12px muted: „© 2026 Gestura · Open Source (AGPL-3.0)“ links; rechts Links Datenschutz / Impressum / Doku / GitHub + DE/EN + Theme-Icon.

## Interactions & Behavior
- **Live-Filterung ohne Seitenwechsel:** Suche (Debounce), Kategorie-/Tag-/Sprach-Chips (Mehrfachauswahl, mit Anzahl je Facette; Sprach-Facette zeigt nur real vorhandene Sprachen), Typ-Segmented (Alle/Menüs/Suchmaschinen), Sortierung (Neueste/Beliebteste/Beste Bewertung). Aktive Filter erscheinen als entfernbare accent-Chips + „alle zurücksetzen“. URL-Sync der Filter empfohlen (SEO/Teilen).
- **Block aufklappen:** inline, Accordion; zweite unabhängige Aufklapp-Ebene „Bewertungen“ innerhalb des Details.
- **Sammeln:** Toggle je Block ↔ Sammelkorb-Pill (Zähler live). Panel: Eintrag entfernen, alle entfernen, „Als JSON herunterladen“ (generiert Gestura-kompatibles JSON), „An Gestura senden“ deaktiviert mit Tooltip „kommt bald“. Auswahl in `localStorage` persistieren.
- **Theme-Toggle:** Dark ⇄ Light (Dark Default), Persistenz + `prefers-color-scheme`-Initialwert. **Sprach-Umschalter:** DE Default, EN als zweite Sprache (Inhalte hier nur DE ausgearbeitet).
- **Nachladen:** „Mehr laden“-Button bzw. Infinite Scroll mit Skeleton-Zeilen (Puls-Animation) und Hinweis „Weitere Einträge werden geladen …“.
- Hover: Chips/Buttons leicht aufhellen (accent-hover für gefüllte Buttons); Fokus: sichtbarer Ring `0 0 0 3px rgba(accent,.15)` + accent-Border (wie Suchfeld) – auf allen interaktiven Elementen (a11y).

## State Management
`query`, `activeCategory[]`, `activeTags[]`, `activeLangs[]`, `type` (all|menu|search), `sort`, `expandedId`, `reviewsExpandedId`, `basket[]` (persistiert), `basketOpen`, `theme`, `lang`, `page/cursor`, `loading`, `resultCount`. Daten: Einträge mit `name, type, desc, categories[], langs[], rating, ratingCount, installs, versions[{semver, date, changelog, hasScript}], domains[], homepage, screenshot, reviews[{stars, date, text}]`.

## Assets
- `assets/icon128-tile.png` – Logo-Kachel (Gradient-Kachel mit Hand), Header/Tabelle
- `assets/icon128-darktile.png` – dunkle Kachel-Variante
- `assets/icon128.png` – freigestellte Hand (Hero-Deko)
- `assets/gestura-16.png` – Favicon-Größe
- `assets/gestura-source2.png` – große Quell-Grafik der Hand
- Icons: **Lucide**, stroke-width 2, `currentColor` (im Mock als Inline-SVG nachgezeichnet – im Zielprojekt echte Lucide-Icons verwenden: search, sun, moon, github, x, plus, check, chevron-down/up, download, external-link, layers, trash-2, alert-triangle, shield, mouse, move, scan, zap, globe, code, shopping-cart, play, newspaper, users, book, film, more-horizontal, list, arrow-right, eye-off, star)

## Files
- `Gestura Index.dc.html` – Haupt-Canvas mit allen Artboards 1a–1n (Design-Referenz)
- `Block.dc.html` – Ergebnis-Block-Komponente (Design-Referenz; Props: `item`, Theme-Objekt `t`)
- `screenshots/` – ein PNG je Artboard (1a–1n, Dateiname = Artboard-ID + Inhalt); primäre visuelle Referenz, exakte Werte in den .dc.html-Dateien nachschlagen

## Offene Punkte
- Entscheidung Facetten-Layout: 1a Kopfleiste vs. 1b Sidebar
- Echte Screenshots/GIFs für 1c, C2, C5 einsetzen
- C4-Konkurrenz-Spalten nur mit belegten Angaben füllen
- C2–C5 existieren als Dark/Desktop; Light/Mobile analog aus den Tokens ableiten
