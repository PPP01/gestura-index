# Sub-Projekt B – Bundle-Endpunkt & Übergabe (Backend + Vertrag) · Design

> **Fundament:** [`2026-08-27-oeffentlicher-index-onepager-design.md`](2026-08-27-oeffentlicher-index-onepager-design.md), Abschnitt 6.
> Dieses Dokument vertieft Sub-Projekt B zur umsetzungsreifen Spec und zurrt die im Fundament-Doc (§9) offen gelassenen Punkte fest.

## 1. Ziel & Reichweite von B v1

B v1 liefert in **diesem Repo** den serverseitigen Bundle-Endpunkt und stellt den bestehenden Sammelkorb-Download darauf um. Der **Live-**»An Gestura senden«-Handover wird **bewusst vertagt**, weil er von zwei noch ungebauten Extension-Änderungen abhängt (siehe §8). Diese Entscheidung wurde beim Brainstorming getroffen (Variante »Backend + Datei-Download«).

**In B v1 enthalten:**

1. `POST /api/v1/bundle` – baut aus einer Liste von Format-IDs ein Bundle aus den veröffentlichten Payloads.
2. Frontend-Retrofit: `BasketTray.download()` nutzt den Endpunkt (**ein** Request statt N).
3. `gesturaBundle`-Wrapper als dokumentierter Vertrag (Schema-Kopie bleibt unangetastet, siehe §6).
4. Extension-Vertrag als Dokumentation (§8), **nicht** hier implementiert.

**Nicht in B v1 (dokumentierte Folgearbeiten, §8):**

- Extension-Bundle-Import-Zweig + Cross-Origin-Vertrauen (Extension-Repo).
- GET-Variante des Bundle-Endpunkts für den Live-Betreiber-Button.
- Sammelkorb-Größenkappe (100 KB) fürs Live-Senden.
- ID-Manifest-Wachstumspfad.

Der »An Gestura senden«-Button in [`BasketTray.svelte`](../../../frontend/src/lib/components/BasketTray.svelte) bleibt **unverändert deaktiviert** (»coming soon«).

## 2. Globale Rahmenbedingungen (verbatim, gelten für jede Task)

- **Backend:** Symfony 7.4 LTS, PHP 8.5, reine JSON-API. Lokal `php` / `php backend/bin/phpunit` (Exit-Code prüfen: `; echo $?`). Deploy `php85`.
- **Frontend:** SvelteKit, **Svelte 5 mit Runes**, **TypeScript**, `adapter-static`. i18n **de/en** über Paraglide; neue Keys nur in `messages/en.json`+`de.json` (Kompilat `messages.js` ist gitignored, **nie committen**).
- **Bundle-Wrapper-Vertrag:** `{ "gesturaBundle": 1, "entries": [ <gesturaMenu|gesturaEngine>, … ] }`. Jeder `entries`-Eintrag ist **exakt** das bestehende, bereits validierte Einzelformat.
- **Öffentliche API** ist cookielos, `Access-Control-Allow-Origin: *` (CorsSubscriber, unverändert). Kein Konto, keine IP-Persistenz.
- **Typografie in Prosa/Kommentaren/Commits:** Deutsch, Guillemets »…«, Halbgeviertstrich – (kein —). Umlaute echt.

## 3. Endpunkt `POST /api/v1/bundle`

### Request

```http
POST /api/v1/bundle
Content-Type: application/json

{ "ids": ["com.example.menu", "org.foo.engine", …] }
```

**Warum POST mit JSON-Body statt GET `?ids=`:** Ein Sammelkorb kann viele IDs enthalten; als Query-String (reverse-domain-IDs à ~40 Zeichen) überschreitet das bei ~200 IDs Apaches `LimitRequestLine` (Default 8190 Bytes) auf dem Shared-Hosting. Der JSON-Body kennt dieses Limit nicht. Der cross-origin-POST (`gestura.eu` → `api.gestura.eu`) ist durch den bestehenden `CorsSubscriber` gedeckt: OPTIONS-Preflight → 204, öffentliche Verzweigung erlaubt `POST` und Header `Content-Type`. **Keine CORS-Änderung nötig.**

### Validierung (alle → `400 application/problem+json`)

- Body ist kein gültiges JSON-Objekt → 400 »Invalid request body«.
- `ids` fehlt, ist kein Array, oder ist leer → 400 »ids must be a non-empty array«.
- Ein `ids`-Element ist kein String → 400 »ids must be strings«.
- Nach Dedup **> 200** IDs → 400 »Too many ids (max 200)«.

Der Cap 200 schützt vor missbräuchlich großen Requests; er ist bewusst großzügig (ein realer Sammelkorb liegt weit darunter).

### Verarbeitung

1. IDs trimmen, **deduplizieren**, **Reihenfolge der Erstnennung erhalten** (deterministische Datei).
2. `EntryRepository::findPublishedByFormatIds($ids)` lädt alle Einträge mit Status `Published` und gesetzter `currentVersion` (Join-Fetch auf `currentVersion`, kein N+1).
3. Ergebnis in die **angefragte Reihenfolge** bringen; unbekannte / nicht-veröffentlichte IDs werden **stillschweigend ausgelassen** (kein Fehler).
4. Response bauen:

```json
{ "gesturaBundle": 1, "entries": [ <currentVersion.payload>, … ] }
```

Jeder `payload` ist das validierte Austauschformat-JSON (unverändert, wie `VersionDownloadController` es einzeln liefert).

### Antwortverhalten

- Gültige IDs, ≥ 1 Treffer → **200** mit gefülltem `entries`.
- Gültige IDs, **0 Treffer** (alle unbekannt/unveröffentlicht) → **200** mit `entries: []`. Der Client meldet dann alle als übersprungen.
- **Kein ETag / kein Caching:** Ein Bundle ist pro Auswahl individuell; Caching brächte kaum Treffer und POST ist ohnehin nicht cachebar. Response ohne `Cache-Control`/`ETag`.
- **Kein Install-Zähler:** Der Bundle-Download zählt **nicht** als Installation (der Install-Ping bleibt der separate `POST …/install`-Pfad). B v1 ändert die Zählsemantik nicht.

### Rate-Limit

Neuer Limiter `bundle` (sliding_window, **120 / 1 hour**, auf Client-IP), analog `sync_write`. Verhindert Katalog-Scraping über wiederholte Bundle-Requests. Ergänzung in **allen drei** Blöcken von `rate_limiter.yaml` (framework / when@test / when@dev) mit großzügigem Test-/Dev-Limit (1000), gemäß Team-Konvention. Anwendung im Controller via `RateLimiterFactoryInterface $bundleLimiter` + bestehender `RateLimitGuard`-Mechanik; 429 bei Überschreitung.

### Controller & Repository

- **Controller:** `App\Controller\Api\BundleController` (Single-Action, `__invoke`, `#[Route('/api/v1/bundle', methods: ['POST'])]`), Muster wie `VersionDownloadController` / `EntrySubmitController` (Repository-Injection, `ApiProblem` für Fehler, `JsonResponse`).
- **Repository:** neue Methode

```php
/**
 * @param list<string> $formatIds
 * @return list<Entry> veröffentlichte Einträge mit geladener currentVersion,
 *                     in DB-Reihenfolge (Aufrufer stellt Anfrage-Reihenfolge her)
 */
public function findPublishedByFormatIds(array $formatIds): array
```

DQL: `SELECT e, v FROM Entry e JOIN e.currentVersion v WHERE e.formatId IN (:ids) AND e.status = :published`. Die Reihenfolge stellt der Controller über eine Map `formatId → Entry` her.

## 4. Frontend-Retrofit (Datei-Download)

- Neue Funktion in [`frontend/src/lib/api.ts`](../../../frontend/src/lib/api.ts):

```ts
export interface Bundle { gesturaBundle: 1; entries: unknown[]; }

/** Holt ein server-seitig gebautes Bundle für die gewählten IDs (ein Request). */
export async function getBundle(ids: string[], opts: ClientOpts = {}): Promise<Bundle>;
```

  → `POST /api/v1/bundle` mit `{ ids }`, `content-type: application/json`.

- [`BasketTray.svelte`](../../../frontend/src/lib/components/BasketTray.svelte) `download()`: ersetzt die N-Schleife durch **einen** `getBundle(basket.ids)`-Aufruf.
  - **Übersprungene IDs:** angefragte IDs minus die in `entries[].id` enthaltenen → gleiche Fehlermeldung `basket_download_error` wie heute. Jeder Payload trägt seine `id`; für unbekannte Typen defensiv per `String((e as {id?:unknown}).id)` lesen.
  - Bei ≥ 1 Eintrag: `triggerJsonDownload(bundle, 'gestura-bundle.json')` (unverändert).
  - Netzwerk-/Serverfehler (`ApiError`) → `downloadError` setzen (Meldung), keine Datei.
- `downloadVersion` (Einzel-Download) **bleibt** erhalten; nur der Korb nutzt es nicht mehr.

## 5. Tests

**Backend (PHPUnit, Funktionstest wie bestehende Api-Tests):**

- 200, korrektes Bundle bei gemischten Menu/Engine-IDs; `entries`-Reihenfolge = Anfrage-Reihenfolge.
- Unbekannte / `pending` (unveröffentlichte) IDs werden ausgelassen; übrige geliefert.
- Dedup: doppelte ID erscheint genau einmal.
- 400 bei fehlendem/leerem/nicht-Array `ids`, nicht-String-Element, > 200 IDs, kaputtem JSON.
- 200 mit `entries: []` bei ausschließlich unbekannten IDs.
- CORS-Header `*` auf der Antwort vorhanden.
- (Rate-Limit-Drosselung testet weiterhin `RateLimitGuardTest` isoliert; der `bundle`-Limiter bekommt im when@test-Block das großzügige Limit.)

**Frontend (Vitest + testing-library):**

- `getBundle` sendet POST mit `{ ids }` und parst das Bundle.
- `BasketTray.download()` ruft `getBundle` **einmal**, triggert genau einen Datei-Download.
- Teilweise fehlende IDs (nicht in `entries`) → `basket_download_error` mit den fehlenden IDs.
- Serverfehler → Fehlermeldung, kein Download.

## 6. Schema / Format-Vertrag

Der `gesturaBundle`-Wrapper wird **hier als Vertrag festgeschrieben** (§2), aber `schema/exchange-schema.json` wird in **diesem** Repo **nicht geändert** – es ist eine Kopie; autoritative Quelle ist das Extension-Repo (`js/exchange-schema.json`), Änderung dort und Neu-Kopie (CLAUDE.md-Regel »hier nie direkt ändern«).

Begründung, dass das trägt: Das Backend **produziert** Bundles aus bereits (bei Einreichung) validierten Payloads und **validiert keine eingehenden** Bundles. Es braucht den Wrapper also nicht maschinenlesbar. Wenn der Extension-Bundle-Import-Zweig gebaut wird (§8), wandert der Wrapper in die autoritative Schema-Datei und wird nach `schema/` neu kopiert.

## 7. Sicherheit: warum kein Client-Secret / keine Extension-Authentifizierung

**Entscheidung:** Der Bundle-Endpunkt bleibt offen wie die übrige öffentliche API – **kein** Shared-Secret, kein API-Key, keine »nur Gestura-Extension«-Authentifizierung. Schutz ausschließlich über IP-Rate-Limit (`bundle`, §3) und den 200-ID-Cap.

**Begründung (durabel festgehalten, damit die Frage nicht erneut aufgemacht wird):**

- **Client-Secrets sind extrahierbar.** Die Extension ist client-seitiges MV3-JavaScript; jedes eingebettete Secret/Key/Signaturmaterial ist aus dem Store-Paket oder per DevTools world-readable → auslesbar und replaybar. Man kann den *Nutzer* authentifizieren, nie den *Client*. Ein Secret-Tausch böte nur Obfuskation, keine echte Authentifizierung.
- **Keine verifizierbare Extension-Attestation.** Der Server kann nicht beweisen lassen, dass ein Request »aus einer Gestura-Extension« stammt. `Origin: chrome-extension://<id>` ist außerhalb eines echten Browsers frei fälschbar; mTLS/signierte Requests scheitern am selben Extraktionsproblem.
- **Es würde den echten B-v1-Konsumenten brechen.** Der Endpunkt wird in B v1 primär von der **Website** (Sammelkorb-Datei-Download) aufgerufen – keine Extension. »Nur Extensions« würde das Feature aussperren.
- **Die Daten sind ohnehin öffentlich.** Ein Bundle bündelt nur veröffentlichte Payloads, die einzeln über `versions/{semver}` als öffentlicher GET abrufbar sind. Es gibt nichts zu schützen, was nicht schon offen ist – konsistent mit »alles anonym nutzbar, öffentliche Index-Inhalte sind Klartext« und der `Access-Control-Allow-Origin: *`-Anforderung.

Fremdnutzung öffentlicher Daten durch Dritt-Clients ist technisch nicht verhinderbar; die verhältnismäßige Grenze ist das Rate-Limit, nicht ein Scheingeheimnis.

## 8. Extension-Vertrag (dokumentiert, im Extension-Repo umzusetzen)

Damit »An Gestura senden« später **live** wird, braucht die Extension:

1. **Bundle-Zweig im Import-Dialog:** `gesturaBundle: 1` erkennen, über `entries` iterieren, **jeden** Eintrag einzeln mit `menu-exchange.js` validieren, Sammel-Vorschau anzeigen. Heute kennt der Dialog nur die Einzelformate `gesturaMenu`/`gesturaEngine`.
2. **Cross-Origin-Lösung:** Der Betreiber-Button-Kanal (`content.js` + `importFromSite` in `background.js`) erzwingt **Same-Origin** zwischen `<a rel="gestura-menu" href>` und Seite. Da `gestura.eu` (Website) und `api.gestura.eu` (API) getrennte Origins sind, muss die Extension entweder den Gestura-Index-Origin als **vertrauenswürdige Quelle** zulassen (konfigurierbare Ausnahme von der Same-Origin-Regel) **oder** die Übergabe erfolgt über einen **same-origin-Pfad unter `gestura.eu`**. Entscheidung liegt beim Extension-Sub-Projekt.
3. **GET-fähige URL:** Der Betreiber-Button ist ein `<a href>`, das die Extension per `fetch` (GET, `credentials: 'omit'`) holt. Der Live-Handover braucht daher eine **GET-Variante** des Bundle-Endpunkts (oder eine kurzlebige, GET-bare Bundle-URL), size-gekappt auf **100 KB**. Diese GET-Variante ist **nicht** Teil von B v1.

Solange (1)–(3) nicht stehen, bleibt der Button deaktiviert.

## 9. Zerlegung (für die Plan-Phase)

1. **Repository-Methode** `findPublishedByFormatIds` + Test.
2. **Bundle-Controller** `POST /api/v1/bundle` (Validierung, Wrapper-Bau, Reihenfolge, Auslassen) + Funktionstests.
3. **Rate-Limiter** `bundle` in allen drei Blöcken + Controller-Verdrahtung.
4. **Frontend** `getBundle` + `BasketTray.download()`-Retrofit + Vitest.
5. **Doku:** `lessons.md`-Eintrag (Bundle-Endpunkt, POST-Begründung, Cross-Origin-Merker) + ggf. `deploy`-Hinweis, dass keine neue Origin-/CORS-Konfig nötig ist.

Umsetzung wie gehabt: subagent-getrieben, Merge lokal nach `main`, **kein Push**.

## 10. Festgezurrte Detailentscheidungen

- **Übergabe-Form B v1:** fertiges Bundle (kein ID-Manifest). ✔
- **Methode:** `POST` mit JSON-Body (URL-Längenlimit vermeiden). ✔
- **Cap:** max. 200 IDs/Request → 400. ✔
- **Unbekannte IDs:** stillschweigend auslassen, Client gleicht ab. ✔
- **Caching/Install-Zähler:** keins bzw. unverändert. ✔
- **Schema-Kopie:** hier unangetastet, Vertrag dokumentiert. ✔
- **Live-Handover:** vertagt; GET-Variante + Extension-Änderungen als Folgearbeit. ✔
