# Öffentlicher Index als Onepager mit Sammelkorb & Bundle-Übergabe – Grundlagen-Design

> **Fundament-Dokument (Prio 1).** Es hält die festgezurrten Architektur-Entscheidungen für das öffentliche Frontend fest und zerlegt die Umsetzung in zwei eigenständige Sub-Projekte (A, B), die jeweils ihren eigenen `spec → plan → build → merge`-Zyklus bekommen. Ersetzt die bisherige Vorstellung »Browse-Liste + Detailseiten« und gliedert die geplante Sterne-Anzeige (»Teil 2«) hier ein.

## 1. Ausgangslage & Ziel

Der öffentliche Index wird ein **Onepager**: eine einzige Seite, die den gesamten (leichten) Katalog client-seitig lädt und **on-the-fly** filtert (Kategorie, Tag, Freitext, **Sprache**). Einträge sind kompakte **Blöcke** in einer Liste – ein Eintrag braucht wenig Platz. Sterne-Bewertung (Ø + Anzahl) erscheint **inline** am Block. **Keine Detailseiten**: hat ein Eintrag mehr Details, wird der Block **in der Liste aufgeklappt/hervorgehoben**.

Ein **Sammelkorb** (Arbeitstitel »Warenkorb«) erlaubt das Auswählen mehrerer Einträge. Er zeigt sichtbar die **Anzahl** der Auswahl; ein Klick öffnet eine **Liste zum Entfernen**. Aus der Auswahl lässt sich ein **Gesamt-JSON herunterladen** oder – über den bereits existierenden Extension-Kanal – **direkt an Gestura übergeben**.

## 2. Nicht-Ziele

- **Keine Rating-Abgabe im Web** – Bewertungen werden in der Extension abgegeben (Konten `gacc_` sind extension-only, bestätigte Entscheidung). Das Web zeigt Ratings **nur lesend**.
- **Keine Detailseiten / kein `entry/[formatId]`-Routing** – Details leben im aufklappbaren Block.
- **Kein server-seitiges Lazy-Loading / keine Sprach-Dimension im Backend – vorerst.** Beides ist die dokumentierte Skalierungs-Notbremse (§7), nicht Teil der ersten Umsetzung.
- **Kein Umbau der Admin-SPA** – dieser bleibt unberührt.

## 3. Festgezurrte Architektur-Entscheidungen

| Thema | Entscheidung |
| --- | --- |
| Rendering | **Reiner Client-Onepager** (Option 1). Ganzer Katalog client-seitig, Filter im Browser. |
| SEO | Bewusster Kompromiss: schlanke **prerenderte Fallback-Liste** (alle Einträge) für Crawler; die interaktive Filterung ist client-only. |
| Skalierung | Erwartung **50–1000 Einträge** »vorerst«. Laden+Client-Filter ist dabei unkritisch (~0,2–1 MB Metadaten-JSON). Wächst es stark → §7. |
| UI-Sprache | **de + en** (vorerst), über den bestehenden Paraglide-Umschalter. |
| Inhalts-Sprache | **beliebig viele** – Einträge/Engines können in vielen Sprachen vorliegen. |
| Sprachfilter | **Unabhängig** von der UI-Sprache. **Dynamische Facette**: zeigt nur die im Katalog tatsächlich vorhandenen Sprachen (mit Anzahl). Client-seitig aus den Daten abgeleitet. |
| Detailanzeige | **Aufklappen im Block**, keine Navigation. |
| Übergabe an Extension | Nutzt den **bereits existierenden** `importFromSite`-Kanal (Betreiber-Button). A und C konvergieren auf **ein** Artefakt: das Bundle-JSON an einer gestura.eu-URL = Download **und** Übergabe-Quelle. |

## 4. Sprach-Semantik

- Ein Eintrag gilt als in Sprache `L` verfügbar, wenn seine `name`-Sprach-Map den Schlüssel `L` enthält. Ein Eintrag mit **einfachem String** als Name (keine Map) zählt als **`en`** (Format-Konvention: en-Fallback).
- Ein **mehrsprachiger** Eintrag (z. B. de+en) erscheint unter **beiden** Sprachen der Facette.
- Die Ableitung erfolgt **client-seitig** aus dem `name`-Feld, das die Listen-API bereits als rohe Sprach-Map ausliefert (`EntrySerializer` reicht `$payload['name']` unverändert durch). Kein Backend-Feld nötig (primärer Pfad).
- **Default-Auswahl** beim ersten Laden: »alle Sprachen«, wobei die aktuelle UI-Sprache (de/en) vorausgewählt ist, falls im Katalog vorhanden. (Feinheit für die Sub-Spec A.)

## 5. Sub-Projekt A – Onepager (Frontend)

**Kern.** Eine Seite lädt den Katalog (`listEntries` mit großem `perPage` bzw. ohne Paginierung) und hält ihn im Client-State. Alle Facetten filtern **im Browser**, ohne Netzwerk:

- **Facetten:** Kategorie, Tag, Freitext-Suche, Sprache. Jede Facette zeigt dynamisch nur präsente Werte (mit Anzahl); Sprache wird aus den `name`-Maps abgeleitet.
- **Blockliste:** kompakte Karten/Zeilen, je Block u. a. Name, Kurzbeschreibung, Typ (Menü/Engine), Kategorien/Tags, **Sterne (Ø + Anzahl)** inline, Install-Zähler, Sprach-Badges.
- **Aufklappen:** ein Block expandiert in der Liste und zeigt Details (Versionen, Screenshot, ggf. freigegebene Reviews); keine eigene Route. *(Ob Reviews/Kommentare hier inline erscheinen oder nur die Aggregat-Sterne, entscheidet die Sub-Spec A.)*
- **Sammelkorb (Auswahl-Tray):** je Block ein Auswahl-Toggle. Ein persistenter Indikator zeigt die **Anzahl** der Auswahl; Klick öffnet eine **Liste mit Entfernen** je Eintrag. Aktionen: **Download JSON** (Bundle, siehe B) und **An Gestura senden** (Übergabe, siehe B). Auswahl-State client-seitig (z. B. `localStorage`, damit sie über Reloads hält).
- **SEO-Fallback:** eine prerenderte statische Liste aller Einträge (ohne Interaktivität) als Crawl-Ziel; die interaktive Seite überlagert sie zur Laufzeit.
- **Ersetzt:** die bisherigen `(public)/browse`- und `(public)/entry/[formatId]`-Routen. `EntryCard` wird zum Blocklisten-Element umgebaut/ersetzt.

**Sterne (vormals »Teil 2«) fließen hier ein.** Der TS-Typ `EntryListItem` bekommt `rating: { average: number | null; count: number }` (Backend liefert es seit Sub-Projekt D). Darstellung: 5-Sterne-Reihe (gefüllt bis `Math.round(average)`) + genauer Wert + Anzahl; `count === 0` ⇒ »Noch nicht bewertet«.

## 6. Sub-Projekt B – Bundle-Format & Übergabe (Backend + Vertrag)

- **Bundle-Format** (abgespeckter Export, hier als Vertrag definiert): `{ "gesturaBundle": 1, "entries": [ <menu|engine>, … ] }`. Jeder `entries`-Eintrag ist **exakt** das bestehende Einzelformat (`gesturaMenu`/`gesturaEngine`) → die Extension validiert jeden mit dem vorhandenen `menu-exchange.js`-Validator.
- **Backend-Endpunkt** `GET /api/v1/bundle?ids=…` (oder POST bei vielen IDs): liefert die ausgewählten, veröffentlichten Einträge als Bundle-JSON. Quelle sind die `currentVersion.payload` der Einträge (die Listen-API führt nur Metadaten). Dieser Endpunkt speist **beide** Wege: Datei-Download **und** die Betreiber-Button-Übergabe.
- **Übergabe an die Extension** nutzt den bestehenden Kanal: Ein Betreiber-Button (`<a rel="gestura-menu" href="https://…/api/v1/bundle?…">`) löst in der Extension `importFromSite` aus → Background holt die JSON **same-origin** (Cap **100 KB**), legt `pendingImport` ab, öffnet den Import-Dialog.
  - **100-KB-Grenze:** Die Übergabe pro Vorgang ist gedeckelt. → **Sammelkorb-Größenkappe** mit klarer Meldung (bzw. später Anheben des Extension-Limits). Der reine Datei-Download unterliegt der Kappe nicht.
- **Extension-Repo-Vertrag (hier dokumentiert, dort umzusetzen):** Der Import-Dialog erhält einen **Bundle-Zweig** – erkennt `gesturaBundle: 1`, iteriert über `entries`, validiert jeden Eintrag einzeln, zeigt eine Sammel-Vorschau. Das Austauschformat-Schema (`schema/exchange-schema.json`) wird im Extension-Repo um den Bundle-Wrapper erweitert und hierher neu kopiert (Kopie-Regel).

## 7. Skalierungs-Notbremse (aufgeschoben, nicht gebaut)

Wenn »alles laden« unangenehm wird (deutlich > 1000 Einträge oder große Payloads):

- **Backend `languages`-Feld** auf `Entry` (abgeleitet via `PayloadAnalyzer::extractLanguages`, analog `domains`, indiziert), plus `?locale=`-Filter auf `GET /api/v1/entries` (LIKE wie `tags`/`domains`).
- **Facet-Endpunkt** (distinct Sprachen + Anzahl), damit die Sprachfacette auch ohne vollständig geladenen Katalog vollständig ist.
- Onepager wechselt von »alles client-seitig« auf server-seitiges Lazy-Loading + server-seitige Facetten. Die Client-Filter-UX bleibt gleich; nur die Datenquelle ändert sich.

## 8. Zerlegung & Reihenfolge

1. **A – Onepager (Frontend):** sofort sichtbarer Nutzen (Blockliste, Filter inkl. Sprache, Sterne, Sammelkorb-Auswahl **mit Download** – der Download kann als Zwischenlösung zunächst mehrere Einzeldateien oder ein client-seitig zusammengesetztes Bundle liefern, wenn B noch nicht steht; Feinheit für Sub-Spec A/B-Schnitt).
2. **B – Bundle + Übergabe (Backend + Vertrag):** `/api/v1/bundle`, sauberes Bundle-Format, Betreiber-Button-Übergabe; Extension-Bundle-Import als Vertrag.

Jedes Sub-Projekt bekommt seine eigene detaillierte Spec und seinen eigenen Plan; dieses Dokument ist das gemeinsame Fundament. Umsetzung wie gehabt: subagent-getrieben, Merge lokal nach `main`, **kein Push**.

## 9. Offene Detailpunkte (in den Sub-Specs zu klären, kein Blocker)

- Genaue **Sammelkorb-Größenkappe** (an der 100-KB-Übergabegrenze orientiert; Download ggf. ungekappt).
- **Schnitt A/B:** Baut A zunächst einen client-seitig zusammengesetzten Bundle-Download (aus per-Eintrag-Download-Payloads), oder wartet der Download auf B's `/api/v1/bundle`?
- **Aufklapp-Inhalt:** nur Aggregat-Sterne oder auch inline-Reviews im expandierten Block.
- **SEO-Fallback-Form:** prerenderte Liste vs. `sitemap.xml` + statische Stubs.
- **Default-Sprachauswahl** beim ersten Laden (Vorschlag §4).
