# TODO – Live-»An Gestura senden« (Bundle-Import in der Extension)

> **Stand: 30. August 2026.** Der Live-Handover ist inzwischen **inline** gelöst und auf beiden Seiten weitgehend umgesetzt. Diese Datei verfolgt nur noch den **Rest** und die zwei blockierten Punkte. Ursprünglich war sie die vollständige Umsetzungs-Checkliste; die erledigten Blöcke sind unten als solche markiert, statt sie zu löschen.
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
| Inline-Kanal `gestura:import` | Extension | ✅ erledigt |
| Menü→Engine-Abhängigkeit (Import-Seite) | Extension | ✅ erledigt (Bericht §3) |
| Speicheranzeige + Import-Gate | Extension | ✅ erledigt (Bericht §4/5) |
| **Button live schalten** (B4) | Index | ✅ erledigt – [BasketTray.svelte](../frontend/src/lib/components/BasketTray.svelte) |
| **Weicher Größen-Hinweis** (B3, neu gefasst) | Index | ✅ erledigt – Hinweis ab ~5 KB, kein Deckel |
| GET-fähige Bundle-URL (B1) | Index | ❌ **gestrichen** – Inline-Übergabe braucht kein GET |
| Same-Origin-Reverse-Proxy (B2) | Index | ❌ **gestrichen** – entfällt mit der Inline-Übergabe |
| **Schema-Kopie erneuern** | Index | ⏳ **wartet** – autoritative Quelle noch auf Extension-Branch |
| **Submission-Regel Menü→Engine** | Index | ⏳ **blockiert** – braucht Liste der eingebauten Engine-IDs |
| Extension released? | Extension | ⚠️ **nein** – fertig auf Branch, nicht gemergt/gepusht/released |

## ⚠️ Wichtiger Vorbehalt

Die Extension-Arbeit liegt fertig und reviewed auf einem Branch (24 Commits über `main`), ist aber **nicht gemergt, nicht gepusht, nicht released**. Bis das geschieht, versteht **kein** ausgelieferter Browser das `gestura:import`-Event – der »An Gestura senden«-Button feuert ins Leere, und der **Datei-Download bleibt der einzige real funktionierende Weg**. Der Button ist bewusst schon live (Vertrag ist eingefroren); die neutrale Bestätigungsmeldung (»…öffnet sich kein Fenster, ist Gestura evtl. nicht installiert…«) fängt den Zustand ab.

Außerdem: Im Extension-Repo gibt es **kein jsdom** – der Import-Dialog und der gesamte Übergabekanal sind reviewed, aber **nicht automatisiert getestet** (getestet sind nur Validator, Bundle-Prüfung, Speicher-Rechnung). Die manuelle Browser-Abnahme steht aus. Wenn hier die Live-Übergabe gebaut wird, ist das faktisch der erste vollständige Test des Kanals – **abweichendes Verhalten melden, statt drumherum zu bauen** (die Fehlerwahrscheinlichkeit liegt eher im Extension-Code).

---

## Offen: Schema-Kopie erneuern

Das Extension-`js/exchange-schema.json` hat jetzt `$defs.bundle` und zwei neue Limits (`bundleEntriesMax: 200`, `bundleBlobMax: 1048576`).

- [ ] Sobald die Extension nach `main` gemergt ist: `js/exchange-schema.json` nach `schema/exchange-schema.json` **neu herüberkopieren** (Kopie-Regel – im Index-Repo nie direkt editieren).
- [ ] Backend-Validierung gegen den Bundle-Wrapper prüfen/ergänzen, falls nötig.
- **Warum wartend:** Der Bundle-Vertrag gilt zwar als eingefroren, liegt aber autoritativ noch auf dem Extension-Branch (`feat/menu-item-labels`), nicht auf Extension-`main`. Eine Kopie vom Branch würde einen noch änderbaren Stand einfrieren. Entscheidung: erst nach dem Extension-Merge kopieren.

## Blockiert: Submission-Regel »Menü → eigene Suchmaschine« (Bericht §3)

Ein `searchLink`-Menüpunkt kann statt einer URL eine `engineId` tragen. Zeigt sie auf eine **eigene** Engine, die der Nutzer nicht hat, verweigert die Extension jetzt das Menü (früher verschwand der Eintrag stillschweigend). Für den Index heißt das:

- [ ] **Einreichung:** ein Menü mit einer `engineId`, die weder eingebaut ist noch als eigener Eintrag im Index existiert, ablehnen **oder** in die Moderation schicken (Entscheidung offen).
- [ ] **Korb:** legt jemand ein solches Menü hinein, muss die zugehörige Engine automatisch mitwandern – sonst kommt das Menü beim Nutzer gesperrt an.
- **Warum blockiert:** Beides setzt voraus, dass wir eine `engineId` als »eingebaut vs. unbekannt« klassifizieren können. Dafür fehlt uns die **Liste der eingebauten Engine-IDs** aus der Extension. Sobald sie vorliegt: eigener Brainstorm (Reject vs. Moderation; wie/wo wir die Liste pflegen; Korb-Mitwandern im Frontend). [ExchangeValidator.php:163](../backend/src/Service/ExchangeValidator.php#L163) erzwingt heute nur die Struktur-Regel (`searchLink` braucht `engineId` **oder** https-`url`), **nicht** die Referenz-Auflösung.

## Optionaler Ausbau (nicht jetzt)

- [ ] Listen-API könnte je Eintrag eine geschätzte Speichergröße mitliefern; dann könnte der Korb live (schon beim Stöbern) mitrechnen, statt erst beim Klick. Bewusst zurückgestellt – der weiche Hinweis schätzt beim Klick aus dem echten Bundle.

## Praxis-Empfehlung für Katalog-Inhalte

Wo ein Menüeintrag eine Suche ist, ist `engineId` gegenüber einer ausgeschriebenen `url` rund **44 % billiger** im `storage.sync` **und** respektiert die Engine-Einstellungen des Nutzers. Für **eingebaute** Engines kostet die Referenz null zusätzlichen Speicher. Bei eigenen Katalog-Menüs also `engineId` bevorzugen, wo möglich.

## Definition of Done (Live-Handover, gesamt)

- [x] Klick auf »An Gestura senden« auf `gestura.eu` reicht das Bundle inline an die Extension (Vertrag §2).
- [x] Zu große Auswahl bekommt einen weichen Hinweis auf den Datei-Download (§5); die Extension bleibt das eigentliche Gate.
- [ ] Extension gemergt/released → Kanal in echtem Browser manuell abgenommen.
- [ ] Schema-Kopie erneuert (nach Extension-`main`).
- [ ] Menü→Engine-Submission-Regel umgesetzt (nach Erhalt der Engine-ID-Liste).
