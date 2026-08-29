# TODO – Live-»An Gestura senden« (Bundle-Import in der Extension)

> **Zweck:** Umsetzungs-Checkliste für den **vertagten** Live-Handover aus Sub-Projekt B. B v1 hat in diesem Repo den Backend-Endpunkt `POST /api/v1/bundle` und den Datei-Download gebaut; der »An Gestura senden«-Button in [`BasketTray.svelte`](../frontend/src/lib/components/BasketTray.svelte) ist bewusst **deaktiviert**, bis die hier gelisteten Punkte stehen.
>
> **Vertrag (Quelle der Wahrheit):** [Sub-B-Spec §8](superpowers/specs/2026-08-29-bundle-uebergabe-sub-b-design.md) und [Fundament-Doc §6](superpowers/specs/2026-08-27-oeffentlicher-index-onepager-design.md). Diese TODO bündelt das nur in abhakbare Schritte – bei Widerspruch gilt die Spec.
>
> **Zwei Repos:** Der Live-Handover braucht Arbeit im **Extension-Repo** (`/mnt/c/Programme.alt/Gestura/`, GPL-3, plain JS, kein Build) **und** in **diesem Repo** (Index). Die Blöcke sind unten getrennt.

## Warum vertagt (Kurzfassung)

Der Betreiber-Button-Kanal der Extension erzwingt **Same-Origin** zwischen dem `<a rel="gestura-menu" href>` und der Seite, und der Import-Dialog kennt heute nur die **Einzelformate** `gesturaMenu`/`gesturaEngine`. Für ein Bundle von `gestura.eu` an `api.gestura.eu` (getrennte Origins) fehlen daher: Bundle-Parsing **und** eine Cross-Origin-Lösung **und** eine GET-fähige Bundle-URL.

---

## Block A – Extension-Repo (`/mnt/c/Programme.alt/Gestura/`)

### A1 · Schema um den Bundle-Wrapper erweitern (autoritativ)
- [ ] In `js/exchange-schema.json` den Wrapper `{ "gesturaBundle": 1, "entries": [ <gesturaMenu|gesturaEngine>, … ] }` als Typ ergänzen (jeder `entries`-Eintrag = bestehendes Einzelformat, per `$ref`).
- [ ] Danach die Kopie nach `gestura-index/schema/exchange-schema.json` **neu herüberkopieren** (Kopie-Regel – im Index-Repo nie direkt editieren).
- **Abnahme:** Schema validiert ein Beispiel-Bundle; die Index-Kopie ist byte-gleich.

### A2 · Validator: Bundle erkennen und je Eintrag prüfen
- [ ] `js/menu-exchange.js` (Referenz-Validator) um einen Bundle-Zweig ergänzen: `gesturaBundle: 1` erkennen, über `entries` iterieren, **jeden** Eintrag einzeln mit der bestehenden Einzel-Validierung prüfen (Aktions-Whitelist, `https:`-only, Größen-/Anzahllimits, SemVer, eindeutige Item-IDs).
- [ ] Teil-Ergebnis zurückgeben: welche Einträge gültig/ungültig sind (für die Sammel-Vorschau), statt beim ersten Fehler abzubrechen.
- **Abnahme:** Unit-Tests (vitest) für ein gemischtes Bundle (gültig + ein ungültiger Eintrag) – gültige werden akzeptiert, ungültige einzeln gemeldet.

### A3 · Import-Dialog: Bundle-Zweig + Sammel-Vorschau
- [ ] Im Import-Dialog (`<menu-import-dialog>`; Aufhänger `js/components/options-page.js` `#checkPendingImport`, das `pendingImport` aus `chrome.storage.session` zieht) einen Bundle-Fall ergänzen: bei `gesturaBundle: 1` **über `entries` iterieren** und eine **Sammel-Vorschau** zeigen (Liste mit je Favicon/Name/Transform-Warnung/Chrome-only-Hinweis, wie bei Einzel-Import).
- [ ] Pro Eintrag die bestehende Wahl »Standard ersetzen« vs. »neu hinzufügen« anbieten (bzw. sammel-tauglich bündeln).
- [ ] Ungültige Einträge in der Vorschau markieren, Import der übrigen zulassen.
- **Abnahme:** Manuelles Einspielen eines 3er-Bundles zeigt drei Vorschau-Zeilen; Import legt drei Einträge an; ein ungültiger Eintrag blockiert die anderen nicht.

### A4 · Cross-Origin-Lösung (eine der beiden Varianten wählen)
Heute: `js/content.js` fängt Klicks auf `a[rel~="gestura-menu"]` nur bei **Same-Origin** ab; `js/background.js` `importFromSite` prüft Origin nochmals (Defense-in-Depth), holt die JSON mit **100-KB-Kappe**, `credentials: 'omit'`.

- [ ] **Variante 1 (empfohlen):** Gestura-Index-Origin als **vertrauenswürdige Quelle** zulassen – konfigurierbare Ausnahme von der Same-Origin-Regel **nur** für den Host der Index-API (z. B. `api.gestura.eu`, als Konstante/Einstellung). Origin-Prüfung in `content.js` **und** `background.js` entsprechend anpassen; alles andere bleibt Same-Origin.
- [ ] **Variante 2 (Alternative):** Übergabe über einen **Same-Origin-Pfad unter `gestura.eu`** (siehe Index-Block B2) – dann keine Extension-Origin-Ausnahme nötig.
- **Abnahme:** Ein Klick auf den Index-Button lädt das Bundle ohne »Cross-origin import blocked«; ein beliebiger fremder Cross-Origin-`rel="gestura-menu"`-Link bleibt weiterhin blockiert (Regression).

---

## Block B – Index-Repo (dieses Repo)

### B1 · GET-fähige Bundle-URL für den Betreiber-Button
Der Betreiber-Button ist ein `<a href>`, das die Extension per **GET** (`fetch`, `credentials: 'omit'`) holt. Der aktuelle Endpunkt ist **POST** (bewusst, wegen URL-Längenlimit beim Datei-Download).

- [ ] **GET-Variante** bereitstellen: entweder `GET /api/v1/bundle?ids=…` (size-gekappt, da 100-KB-Kappe der Übergabe ohnehin greift) **oder** eine kurzlebige, GET-bare Bundle-URL (z. B. server-seitig hinterlegtes Korb-Token → `GET /api/v1/bundle/{token}`).
- [ ] Antwort weiterhin `{ gesturaBundle: 1, entries: [...] }`; **100-KB-Kappe** serverseitig durchsetzen (413/400 mit klarer Meldung, wenn das Bundle zu groß ist).
- **Abnahme:** `curl` gegen die GET-URL liefert das Bundle; ein zu großes Bundle wird sauber abgelehnt.

### B2 · (nur falls A4-Variante 2) Same-Origin-Auslieferung unter `gestura.eu`
- [ ] `/api/v1/bundle` (bzw. die GET-Variante) unter dem Website-Origin `gestura.eu` erreichbar machen (Reverse-Proxy/Rewrite auf dem statischen Frontend-Docroot). **Achtung:** Shared-Hosting-Risiko (mod_proxy evtl. nicht verfügbar) – vor Variante 2 verifizieren.

### B3 · Sammelkorb-Größenkappe im Frontend
- [ ] In [`BasketTray.svelte`](../frontend/src/lib/components/BasketTray.svelte) vor dem **Live-Senden** (nicht beim Datei-Download!) prüfen, ob das Bundle die **100-KB**-Übergabekappe überschreiten würde, und mit klarer Meldung deckeln (»Auswahl zu groß fürs Senden – als Datei herunterladen«). Neue i18n-Keys nur in `messages/en.json`+`de.json`.

### B4 · »An Gestura senden«-Button aktivieren
- [ ] Erst wenn A1–A4 **und** B1 (ggf. B2/B3) stehen: den heute `disabled` Button in [`BasketTray.svelte`](../frontend/src/lib/components/BasketTray.svelte) live schalten – erzeugt/navigiert den `rel="gestura-menu"`-Betreiber-Button auf die GET-Bundle-URL.
- [ ] Tooltip/`basket_send_soon`-Text entfernen bzw. durch echten Aktions-Text ersetzen.

---

## Reihenfolge / Abhängigkeiten

```
A1 (Schema) ─┐
A2 (Validator) ─┤─→ A3 (Import-Dialog)
B1 (GET-URL) ───┼─→ A4 (Cross-Origin) ─→ B4 (Button live)
                └─→ B3 (Größenkappe) ───┘
B2 nur bei A4-Variante 2.
```

**Wachstumspfad (nicht jetzt):** Falls Körbe regelmäßig > 100 KB werden, statt eines fertigen Bundles ein **ID-Manifest** (`{ ids: [...] }`) ausliefern, das die Extension **Eintrag für Eintrag** unter der Kappe nachlädt (kappenfrei, dafür N Requests). Siehe Fundament-Doc §6.

## Definition of Done (Live-Handover)

- [ ] Ein Klick auf »An Gestura senden« auf `gestura.eu` öffnet in der Extension die Sammel-Vorschau des ausgewählten Korbs.
- [ ] Gültige Einträge importierbar, ungültige einzeln gemeldet; `transformCode`-Warnung sichtbar.
- [ ] Fremde Cross-Origin-`rel="gestura-menu"`-Links bleiben blockiert (kein Sicherheits-Regress).
- [ ] Zu große Körbe werden vor dem Senden abgefangen (Datei-Download bleibt kappenfrei).
