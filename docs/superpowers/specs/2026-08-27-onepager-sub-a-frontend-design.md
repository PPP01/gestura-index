# Sub-Projekt A – Onepager-Frontend – Design-Spec

> Leitet sich aus dem Fundament `2026-08-27-oeffentlicher-index-onepager-design.md` ab. A liefert den öffentlichen Onepager (Blockliste, Client-Facetten inkl. Sprache, inline-Sterne, Sammelkorb mit client-seitigem Bundle-Download). **B** (Backend-`/api/v1/bundle` + Übergabe an die Extension) und **C** (SEO-/Info-Content) folgen separat. Rein Frontend – **kein Backend-Change**.

## 1. Ziel

Eine einzige öffentliche Seite (die lokalisierte Wurzel `/de`, `/en`) lädt den gesamten Katalog client-seitig und filtert **on-the-fly** nach Kategorie, Tag, Sprache und Freitext. Einträge sind kompakte **Blöcke** mit **inline-Sternen**; Details klappen **im Block** auf (keine Detailseite). Ein **Sammelkorb** sammelt ausgewählte Einträge (persistenter Zähler, Panel zum Entfernen) und lädt sie als **ein Bundle-JSON** herunter.

## 2. Nicht-Ziele

- **Keine Rating-Abgabe im Web** (bleibt Extension); Sterne nur lesend.
- **Keine `/api/v1/bundle`-Nutzung und keine »An Gestura senden«-Aktion** – das ist B. In A ist »An Gestura senden« sichtbar, aber deaktiviert mit Hinweis »kommt bald«.
- **Kein Backend-Change** – keine Sprach-Dimension, kein `?locale=`, kein Facet-Endpunkt (Skalierungs-Notbremse aus dem Fundament §7).
- **Keine SEO-/Info-Inhaltsseiten** (»Was ist Gestura« …) – das ist C.
- Kein Umbau der Admin-SPA.

## 3. Routen-Restrukturierung

- Der **Onepager wird die lokalisierte Wurzel** (`/de`, `/en`; die nackte `/` leitet wie bisher auf die lokalisierte URL). Er ersetzt die bisherige Hero-/Kachel-Startseite.
- **Entfernt:** `(public)/browse/` und `(public)/entry/[formatId]/` (samt `+page.ts`/Tests).
- **Deep-Link-Erhalt:** `/browse?...` und `/entry/{formatId}` leiten per Redirect auf den Onepager mit passend vorbelegten Facetten (z. B. `/browse?category=dev` → Onepager mit gewähltem Kategorie-Facet; `/entry/{formatId}` → Onepager, der diesen Block scrollt/aufklappt bzw. per Facet/Anker hervorhebt). Die Kategorie-Kacheln der alten Startseite verlinken künftig auf `/<locale>?category=…`.
- **Hero** schrumpft zu einer schlanken Kopfzeile im Onepager (Titel + Freitext-Suche); die erklärenden Inhalte wandern nach C.
- **Bleiben unverändert:** `privacy`, `imprint`, `docs`, `about`, `gestura` (einige werden später Teil von C).

## 4. Datenladen

- Der Onepager lädt den Katalog über `listEntries` **progressiv** und hält ihn im Client-State (Svelte-5-Runes).
- **Einstellbare Erstlade-Obergrenze** `PUBLIC_INDEX_INITIAL_LOAD` (Default **1000**): so viele Einträge werden eifrig geladen; der Rest wird per Lazy-Load (Hintergrund/Scroll) nachgezogen, bis der Katalog vollständig ist. In 100er-Schritten reduzierbar per Env/Build.
- Die Listen-API deckelt `perPage` heute auf **50**. **Entscheidung A:** den Deckel serverseitig auf einen größeren Wert für den Onepager anheben ist ein *Backend*-Change und damit außerhalb von A – daher paginiert A bei **50** durch (1000 = 20 Requests, gebündelt/parallelisiert mit Obergrenze an Nebenläufigkeit). *(Falls sich das als zu viele Requests erweist, wird das Anheben des `perPage`-Deckels ein kleiner eigener Backend-Schritt – nicht in A.)*
- Ladezustände: Skeleton/Spinner bis zur ersten Seite; danach inkrementelles Rendern; ein dezenter »lade weitere …«-Hinweis, solange nachgeladen wird. Fehler: `ErrorState` mit Retry; bereits geladene Einträge bleiben nutzbar.

## 5. Facetten (client-seitig)

- **Set:** Freitext-Suche · Typ (Menü/Engine) · **Kategorie** (feste 10 aus `categories.ts`, als Chips) · **Tags** (dynamische Facette aus den Daten, Top-N + »mehr«) · **Sprache** (dynamische Facette) · **Domain/Site** (Freitext) · **Sortierung** (neueste / meiste Installs / beste Bewertung).
- **Dynamische Facetten** (Tags, Sprache) werden **aus dem geladenen Katalog abgeleitet**:
  - Sprache: aus `name` je Eintrag – ist `name` eine Sprach-Map, zählen deren Schlüssel; ist `name` ein String, zählt `en` (Format-Konvention). Mehrsprachige Einträge erscheinen unter jeder ihrer Sprachen.
  - Tags: Vereinigung aller `tags` mit Häufigkeit.
- **Verknüpfung:** innerhalb einer Facette **ODER**, über Facetten hinweg **UND**. Freitext-Suche filtert client-seitig über `name`/`description` (aufgelöst zur aktiven Anzeige-Sprache) und ggf. `formatId`.
- **Anzahl je Option:** zeigt, wie viele Einträge die Option **im aktuell gefilterten Satz** (die übrigen Facetten angewandt, die eigene ausgenommen) träfe. *(Exakte Zählsemantik – »eigene Facette ausgenommen« – ist Umsetzungsdetail; Standard-Faceted-Search.)*
- **Sprach-Default** folgt der URL-Locale: `/de` ⇒ Deutsch vorgewählt, `/en` ⇒ Englisch; der Filter bleibt frei änderbar.
- **URL-State:** die aktive Facettenauswahl steht in der URL-Query (`?category=…&tag=…&lang=…&q=…&type=…&sort=…&site=…`), client-seitig geschrieben/gelesen **ohne** Navigation/Reload (teil- und deep-linkbar; die `/browse`-Redirects mappen darauf). `browse-state.ts` (`parseQuery`/`toSearchParams`/`debounce`/`Sequence`) wird dafür wiederverwendet/erweitert.

## 6. Blockliste & Aufklappen

- **Block** (Weiterentwicklung von `EntryCard`): kompakt – Name (aufgelöst zur Anzeige-Sprache), Typ-Badge, Kurzbeschreibung (1–2 Zeilen), Kategorie-/Sprach-Badges, **inline-Sterne** (§8), Install-Zähler, **Auswahl-Toggle** (§7). **Kein** Link auf eine Detailseite.
- **Aufklappen:** Klick auf den Block (bzw. eine »Details«-Affordanz) expandiert **inline**; mehrere Blöcke dürfen gleichzeitig offen sein, erneuter Klick schließt. Inhalt: Versionen (SemVer, Changelog, `hasTransformCode`-Hinweis), Screenshot (falls vorhanden), Homepage/Domains.
- **Reviews** sind eine **zweite, nachladende Aufklapp-Ebene** innerhalb der Details: erst auf Nutzer-Aktion wird `GET /api/v1/entries/{formatId}/reviews` (paginiert) geladen und angezeigt (Sterne + Kommentar + Datum, anonym). Leer/Fehler dezent.

## 7. Sammelkorb (Auswahl-Tray)

- **Auswahl-Toggle** je Block legt den Eintrag in den Korb (Auswahl = Menge von `formatId`).
- **Persistenter Indikator** (schwebende Leiste unten bzw. Button in der Kopfzeile) mit **Anzahl**; ausgeblendet, solange leer.
- **Panel** (Klick auf den Indikator): Liste der gewählten Einträge (Name, Typ), je Eintrag **Entfernen**, plus **»alle entfernen«**.
- **Aktionen:**
  - **»Als JSON herunterladen«** (funktioniert in A): für jede gewählte `formatId` wird der Payload der `currentVersion` über den bestehenden Endpunkt `GET /api/v1/entries/{formatId}/versions/{semver}` geholt (liefert das bare Austausch-Objekt), daraus wird **`{ "gesturaBundle": 1, "entries": [ …payloads… ] }`** zusammengesetzt und via `triggerJsonDownload(bundle, 'gestura-bundle.json')` (aus `download.ts`) heruntergeladen. Fehlgeschlagene Einzel-Abrufe werden gemeldet (Teil-Bundle mit Hinweis oder Abbruch – Umsetzungsdetail).
  - **»An Gestura senden«**: in A sichtbar, aber **deaktiviert** mit Hinweis (»kommt mit dem nächsten Update«) – die echte Übergabe ist B.
- **Persistenz:** die Auswahl liegt in `localStorage` und übersteht Reloads und Filterwechsel. Einträge, die nicht mehr im Katalog sind, werden beim Laden still aus der Auswahl entfernt.
- Das Bundle-Format `{ gesturaBundle: 1, entries: [...] }` ist im Fundament als Vertrag festgelegt; A erzeugt es exakt so (jeder `entries`-Eintrag = bestehendes Einzelformat).

## 8. Sterne-Darstellung (vormals »Teil 2«)

- `EntryListItem` (in `lib/api.ts`) bekommt `rating: { average: number | null; count: number }` – das Backend liefert es seit Sub-Projekt D; der TS-Typ wird nachgezogen.
- Anzeige am Block: **fünf Lucide-`Star`-Icons**, gefüllt bis `Math.round(average)`, daneben der genaue Wert (locale-formatiert) und die Anzahl (»4,3 · 12«). Bei `count === 0`: dezentes »Noch nicht bewertet«, keine Sterne.
- Sortierung »beste Bewertung« nutzt `rating.average` (Einträge ohne Bewertung nach hinten).

## 9. i18n

Neue Keys in `messages/en.json` **und** `de.json` für: Facetten-Labels (Sprache, »mehr«, »alle Sprachen«), Sortier-Optionen, Sammelkorb (Zähler, »herunterladen«, »an Gestura senden«, »kommt bald«, »entfernen«, »alle entfernen«, leer), Sterne (»Noch nicht bewertet«, »N Bewertungen«), Aufklapp-/Reviews-Texte. Das Paraglide-Kompilat `src/lib/paraglide/messages.js` ist **gitignored** – nicht committen (wird von `test`/`check`/`build` erzeugt).

## 10. Wiederverwendung / Ersatz

- **Ersetzt:** `browse/+page.svelte`, `entry/[formatId]/` (Routen weg); `EntryCard` wird zum Block umgebaut (oder durch eine neue `EntryBlock`-Komponente ersetzt, `EntryCard.test.ts` entsprechend).
- **Wiederverwendet:** `categories.ts` (Kategorie-Facette), `browse-state.ts` (URL-State/Debounce/Sequence), `download.ts` (`triggerJsonDownload`), `api.ts` (`listEntries`, `getEntry`/Versions-Download, `EntryListItem`+`rating`), Komponenten `Badge`, `EmptyState`, `ErrorState`, `Spinner`, Header/Footer/LangToggle/ThemeToggle.
- **Entfällt:** `Pagination` auf dem Onepager (Scroll/Lazy statt Seiten) – bleibt aber im Repo, falls anderweitig genutzt.

## 11. Tests (Vitest; kein `vite dev` parallel)

- **Facetten:** Kategorie/Tag/Sprache/Typ/Suche filtern client-seitig korrekt; ODER innerhalb, UND über Facetten; dynamische Sprach-/Tag-Facette wird aus Testdaten korrekt abgeleitet (inkl. String-`name` ⇒ `en`, mehrsprachig in mehreren Facetten); Anzahl-Anzeige plausibel.
- **Sortierung:** neueste / Installs / beste Bewertung (inkl. `count 0` nach hinten).
- **Sterne:** 5-Sterne-Reihe + Wert + Anzahl; `count 0` ⇒ »Noch nicht bewertet«.
- **Sammelkorb:** Toggle fügt hinzu/entfernt; Zähler stimmt; Panel entfernt/leert; Persistenz über Reload (localStorage-Mock); nicht mehr vorhandene `formatId` wird beim Laden entfernt.
- **Bundle-Download:** aus gewählten Einträgen wird `{ gesturaBundle:1, entries:[...] }` korrekt zusammengesetzt (per-Eintrag-Fetch gemockt), `triggerJsonDownload` mit richtigem Inhalt/Namen aufgerufen; »An Gestura senden« ist deaktiviert.
- **Aufklappen/Reviews:** Block klappt auf; Reviews werden erst on-demand geladen (Fetch gemockt), Leer-/Fehlerfall.
- **Redirects:** `/browse?category=x` bzw. `/entry/{id}` mappen auf den Onepager mit vorbelegtem Facet/Zustand.

## 12. Offene Feinheiten (Umsetzungsdetail, kein Blocker)

- Konkretes **Layout** (Facetten als Sidebar vs. Kopfleiste, Mobile-Collapse) – visuell bei der Umsetzung.
- Exakte **Zählsemantik** der Facetten-Anzahlen (eigene Facette ausgenommen).
- Verhalten bei **Teil-Fehlern** im Bundle-Download (Teil-Bundle vs. Abbruch).
- Form der **`/entry/{id}`-Hervorhebung** (Scroll+Auto-Expand vs. Anker).
