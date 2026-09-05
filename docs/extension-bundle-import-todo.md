# TODO – Live-»An Gestura senden« (Bundle-Import in der Extension)

> **Stand: 5. September 2026.** Der Live-Handover ist **inline** gelöst und auf beiden Seiten umgesetzt. Diese Datei verfolgt nur noch den **Rest**: Die Schema-Kopie ist seit dem Extension-Merge nach `main` **entblockt** (siehe unten), die Menü→Engine-Regel bleibt blockiert. Ursprünglich war sie die vollständige Umsetzungs-Checkliste; die erledigten Blöcke sind unten als solche markiert, statt sie zu löschen.
>
> **Vertrag (Quelle der Wahrheit):** Der Extension-Bericht vom 30.08.2026 sowie das Extension-`README.md`, Abschnitt **»For site operators«** (autoritativ dokumentiert). Ergänzend [Sub-B-Spec §8](superpowers/specs/2026-08-29-bundle-uebergabe-sub-b-design.md) und [Fundament-Doc §6](superpowers/specs/2026-08-27-oeffentlicher-index-onepager-design.md). Bei Widerspruch gilt der Extension-Bericht / das README.
>
> **Zwei Repos:** Extension-Repo (`/mnt/c/Programme.alt/Gestura/`, GPL-3, plain JS, kein Build) und dieses Repo (Index).

## Der Übergabe-Vertrag (Kurzfassung)

Die Seite holt ihr Bundle selbst und reicht es per DOM-Event an die Extension weiter – die Extension fetcht auf diesem Weg **nichts**:

```js
btn.addEventListener('click', async () => {
    const bundle = await getBundle(basket.ids);      // bestehender POST /api/v1/bundle
    document.dispatchEvent(new CustomEvent('gestura:import', {
        detail: JSON.stringify(bundle),              // ← String, kein Objekt
    }));
});
```

Sechs Fallen (Extension-Bericht §2): (1) `detail` **muss** ein String sein; (2) `data-gestura-inline` gehört auf den **Button selbst**, nicht auf einen Container; (3) der Klick muss echt sein (`isTrusted`); (4) das Fenster ist **15 s** offen und nimmt **einen** Payload – der Fetch muss in der Frist zurück sein; (5) ein SvelteKit-Routenwechsel schließt das Fenster nicht (nur der Timeout); (6) der eigene Klick-Handler läuft weiter (die Extension unterdrückt die Propagation bewusst nicht). Alle URLs in den Einträgen müssen `https:` sein; die Übergabe selbst darf über `http://localhost` laufen.

**Transport-Limits:** 100 KB je Eintrag, 1 MB je Bundle, 200 Einträge. Der *echte* Engpass ist aber nicht der Transport, sondern `chrome.storage.sync` (8192 Bytes je Item; nur Deltas werden gespeichert, importierte Menüs sind Vollkopien) – siehe §5-Punkt unten.

## Status-Überblick

| Punkt | Ort | Status |
| --- | --- | --- |
| Bundle-Import-Dialog + Sammel-Vorschau | Extension | ✅ erledigt (Bericht §1) |
| Inline-Kanal `gestura:import` (Hinweg) | Extension | ✅ erledigt |
| Rückweg `gestura:import-result` | Extension | ✅ erledigt (Nachtrag der Extension-Seite; nach dem Lesen entfernt – `git show a071294^:docs/gestura-import-rueckmeldung.md`) |
| **Rückweg auswerten** (Status + Zähler, 15-s-Fallback) | Index | ✅ erledigt – [BasketTray.svelte](../frontend/src/lib/components/BasketTray.svelte) |
| Menü→Engine-Abhängigkeit (Import-Seite) | Extension | ✅ erledigt (Bericht §3) |
| Speicheranzeige + Import-Gate | Extension | ✅ erledigt (Bericht §4/5) |
| **Button live schalten** (B4) | Index | ✅ erledigt – [BasketTray.svelte](../frontend/src/lib/components/BasketTray.svelte) |
| **Weicher Größen-Hinweis** (B3, neu gefasst) | Index | ✅ erledigt – Hinweis ab ~5 KB, kein Deckel |
| GET-fähige Bundle-URL (B1) | Index | ❌ **gestrichen** – Inline-Übergabe braucht kein GET |
| Same-Origin-Reverse-Proxy (B2) | Index | ❌ **gestrichen** – entfällt mit der Inline-Übergabe |
| **Schema-Kopie erneuern** | Index | 🔓 **entblockt (2026-09-05)** – liegt auf Extension-`main`, offen |
| **Submission-Regel Menü→Engine** | Index | ⏳ **blockiert** – braucht Liste der eingebauten Engine-IDs |
| Extension gemergt? | Extension | ✅ **ja** – auf `main` und im Tag `v2.8.0` (Bundle-Commit `117eb11`) |
| Extension veröffentlicht? | Extension | ⚠️ **offen** – lokales `main` steht 76 Commits vor `gestura/main`; ob `v2.8.0` in den Stores ist, lässt sich von hier nicht feststellen |

## ⚠️ Wichtiger Vorbehalt

**Gemergt ist die Arbeit** – der Bundle-Commit `117eb11` ist über `main` erreichbar und im Tag `v2.8.0` enthalten (`manifest.json` steht ebenfalls auf 2.8.0). Damit ist der frühere Vorbehalt »liegt auf einem Branch« erledigt.

**Offen bleibt die Auslieferung.** Das lokale `main` im Extension-Repo steht 76 Commits vor seinem Remote `gestura/main`, ist also nicht gepusht; ob `v2.8.0` tatsächlich in den Stores liegt, ist von diesem Repo aus nicht feststellbar. Solange ein Browser eine ältere Fassung hat, versteht er das `gestura:import`-Event nicht – der »An Gestura senden«-Button feuert dort ins Leere, und der **Datei-Download bleibt der funktionierende Weg**. Der Button ist bewusst schon live (Vertrag ist eingefroren); die neutrale Bestätigungsmeldung (»…öffnet sich kein Fenster, ist Gestura evtl. nicht installiert…«) fängt den Zustand ab.

Außerdem: Im Extension-Repo gibt es **kein jsdom** – der Import-Dialog und der gesamte Übergabekanal sind reviewed, aber **nicht automatisiert getestet** (getestet sind nur Validator, Bundle-Prüfung, Speicher-Rechnung). Die manuelle Browser-Abnahme steht aus. Wenn hier die Live-Übergabe gebaut wird, ist das faktisch der erste vollständige Test des Kanals – **abweichendes Verhalten melden, statt drumherum zu bauen** (die Fehlerwahrscheinlichkeit liegt eher im Extension-Code).

---

## 🔓 Entblockt seit 2026-09-05: Schema-Kopie erneuern

Die Bedingung ist erfüllt – der Bundle-Wrapper liegt committet auf Extension-`main` (`117eb11`, auch im Tag `v2.8.0`). Der Kopiervorgang steht damit an.

Der Unterschied zur Kopie hier (`schema/exchange-schema.json`) ist genau der Bundle-Teil:

- `$defs.bundle` samt `gesturaBundle`-Typ und dem Eintrag in `oneOf`
- `x-gestura.types.bundle`
- zwei neue Limits: `bundleEntriesMax: 200`, `bundleBlobMax: 1048576`
- ein ergänzter Satz in der `description` (»A gesturaBundle wraps entries that are each exactly one of the single formats…«)

Zu tun:

- [ ] `js/exchange-schema.json` nach `schema/exchange-schema.json` **neu herüberkopieren** (Kopie-Regel – im Index-Repo nie direkt editieren).
- [ ] Backend-Validierung gegen den Bundle-Wrapper prüfen/ergänzen, falls nötig.

> **Das ist keine reine Doku-Änderung.** Die Datei wird zur **Laufzeit** gelesen: `ExchangeValidator` lädt sie über `#[Autowire('%kernel.project_dir%/../schema/exchange-schema.json')]` und baut daraus zwei typspezifische Teilschemata; `ExchangeFormatTest` gleicht die PHP-Konstanten gegen sie ab und bricht bei Drift. Die Kopie braucht deshalb einen eigenen Testlauf und gehört nicht nebenbei in einen Doku-Commit.

## Blockiert: Submission-Regel »Menü → eigene Suchmaschine« (Bericht §3)

Ein `searchLink`-Menüpunkt kann statt einer URL eine `engineId` tragen. Zeigt sie auf eine **eigene** Engine, die der Nutzer nicht hat, verweigert die Extension jetzt das Menü (früher verschwand der Eintrag stillschweigend). Für den Index heißt das:

- [ ] **Einreichung:** ein Menü mit einer `engineId`, die weder eingebaut ist noch als eigener Eintrag im Index existiert, ablehnen **oder** in die Moderation schicken (Entscheidung offen).
- [ ] **Korb:** legt jemand ein solches Menü hinein, muss die zugehörige Engine automatisch mitwandern – sonst kommt das Menü beim Nutzer gesperrt an.
- **Warum blockiert:** Beides setzt voraus, dass wir eine `engineId` als »eingebaut vs. unbekannt« klassifizieren können. Dafür fehlt uns die **Liste der eingebauten Engine-IDs** aus der Extension. Seit dem 2026-09-05 gibt es dafür einen geregelten Weg: im lokalen Logbuch `exchange/AUSTAUSCH.md` anfragen (nicht im Repo, siehe [extension-austausch.md](extension-austausch.md) – die Extension-Seite pflegt den Vertrag und beantwortet Rückfragen dort). Sobald sie vorliegt: eigener Brainstorm (Reject vs. Moderation; wie/wo wir die Liste pflegen; Korb-Mitwandern im Frontend). [ExchangeValidator.php:163](../backend/src/Service/ExchangeValidator.php#L163) erzwingt heute nur die Struktur-Regel (`searchLink` braucht `engineId` **oder** https-`url`), **nicht** die Referenz-Auflösung.

## Optionaler Ausbau (nicht jetzt)

- [ ] Listen-API könnte je Eintrag eine geschätzte Speichergröße mitliefern; dann könnte der Korb live (schon beim Stöbern) mitrechnen, statt erst beim Klick. Bewusst zurückgestellt – der weiche Hinweis schätzt beim Klick aus dem echten Bundle.

## Praxis-Empfehlung für Katalog-Inhalte

Wo ein Menüeintrag eine Suche ist, ist `engineId` gegenüber einer ausgeschriebenen `url` rund **44 % billiger** im `storage.sync` **und** respektiert die Engine-Einstellungen des Nutzers. Für **eingebaute** Engines kostet die Referenz null zusätzlichen Speicher. Bei eigenen Katalog-Menüs also `engineId` bevorzugen, wo möglich.

## Definition of Done (Live-Handover, gesamt)

- [x] Klick auf »An Gestura senden« auf `gestura.eu` reicht das Bundle inline an die Extension (Vertrag §2).
- [x] Zu große Auswahl bekommt einen weichen Hinweis auf den Datei-Download (§5); die Extension bleibt das eigentliche Gate.
- [x] Rückmeldung `gestura:import-result` wird ausgewertet (imported/cancelled/failed + Zähler), mit eigenem 15-s-Fallback, falls sie ausbleibt.
- [x] Extension gemergt (`main`, Tag `v2.8.0`).
- [ ] Extension veröffentlicht → Kanal in echtem Browser manuell abgenommen.
- [ ] Schema-Kopie erneuert – **entblockt, offen** (eigener Testlauf nötig, siehe oben).
- [ ] Menü→Engine-Submission-Regel umgesetzt (nach Erhalt der Engine-ID-Liste).
