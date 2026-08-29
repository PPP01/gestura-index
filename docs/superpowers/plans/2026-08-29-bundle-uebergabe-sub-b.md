# Sub-Projekt B – Bundle-Endpunkt & Übergabe · Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein serverseitiger Bundle-Endpunkt `POST /api/v1/bundle` liefert ausgewählte veröffentlichte Einträge als `{ gesturaBundle: 1, entries: [...] }`; der Sammelkorb-Datei-Download nutzt ihn (ein Request statt N).

**Architecture:** Neuer Single-Action-Controller im bestehenden öffentlichen `/api/v1`-Bereich, gespeist durch eine neue Repository-Batch-Methode; geschützt durch einen neuen IP-Rate-Limiter. Das Frontend bekommt eine `getBundle()`-API-Funktion, und `BasketTray.download()` ersetzt seine N-Request-Schleife durch einen Aufruf. Kein Extension-Live-Handover (vertagt).

**Tech Stack:** Symfony 7.4 / PHP 8.5 / Doctrine ORM / PHPUnit (Functional-Tests) · SvelteKit / Svelte 5 Runes / TypeScript / Vitest.

**Spec:** [docs/superpowers/specs/2026-08-29-bundle-uebergabe-sub-b-design.md](../specs/2026-08-29-bundle-uebergabe-sub-b-design.md)

## Global Constraints

- **Backend-Tests:** `php backend/bin/phpunit; echo $?` — Exit-Code **0** zählt, nicht nur »OK« (Suite läuft mit `failOnDeprecation="true"`). Deploy nutzt `php85`, lokal `php`.
- **Frontend-Tests:** `npm --prefix frontend run test` (ruft `paraglide compile && vitest`). **Nie** `vite dev` gleichzeitig mit `test`/`check` laufen lassen.
- **Bundle-Wrapper-Vertrag (verbatim):** `{ "gesturaBundle": 1, "entries": [ <payload>, … ] }`. Jeder `entries`-Eintrag ist der unveränderte, bereits validierte Payload (`currentVersion.payload`).
- **Endpunkt:** `POST /api/v1/bundle`, Body `{ "ids": string[] }`. Cap **200** IDs nach Dedup. Unbekannte/nicht-veröffentlichte IDs **stillschweigend auslassen**. Reihenfolge = Anfrage-Reihenfolge der Erstnennung.
- **Öffentliche API:** cookielos, `Access-Control-Allow-Origin: *` (CorsSubscriber unverändert lassen). Kein ETag/Caching für den Bundle-Endpunkt. **Kein** Install-Zähler.
- **`schema/exchange-schema.json` NICHT ändern** (Kopie-Regel).
- **Kein Client-Secret / keine Extension-Auth** (Spec §7). Schutz nur via Rate-Limit + Cap.
- **Rate-Limiter-Konvention:** neue Limiter in **allen drei** Blöcken von `rate_limiter.yaml` ergänzen (`framework`, `when@test`, `when@dev`); Test-/Dev-Limit großzügig (1000). `RateLimiterFactoryInterface` typehinten (Parametername = Limitername).
- **Typografie in Prosa/Kommentaren/Commits:** Deutsch, Guillemets »…«, Halbgeviertstrich – (kein —), echte Umlaute. Nicht in Code-Bezeichnern/String-Literalen.
- **Paraglide-Kompilat** `frontend/src/lib/paraglide/messages.js` ist gitignored — **nie committen**; neue i18n-Keys nur in `messages/en.json`+`de.json`.

---

### Task 1: Repository-Batch-Methode `findPublishedByFormatIds`

**Files:**
- Modify: `backend/src/Repository/EntryRepository.php`
- Test: `backend/tests/Functional/BundleTest.php` (neu; hier nur der Repository-abdeckende Teil, Endpunkt-Tests folgen in Task 2)

**Interfaces:**
- Consumes: `App\Enum\EntryStatus::Published`, `App\Entity\Entry` (Felder `formatId`, `status`, `currentVersion`), `App\Entity\EntryVersion::$payload`.
- Produces: `EntryRepository::findPublishedByFormatIds(array $formatIds): array` → `list<Entry>` veröffentlichter Einträge mit **fetch-joined** `currentVersion`, in DB-Reihenfolge. Leeres Eingabe-Array → `[]` (kein DB-Zugriff).

- [ ] **Step 1: Failing test schreiben**

In neuer Datei `backend/tests/Functional/BundleTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\EntryStatus;
use App\Repository\EntryRepository;

final class BundleTest extends ApiTestCase
{
    public function testRepositoryReturnsOnlyPublishedWithVersion(): void
    {
        $this->createPublishedEntry('com.example.one');
        $hidden = $this->createPublishedEntry('com.example.hidden');
        $hidden->status = EntryStatus::Hidden;
        $this->em->flush();

        /** @var EntryRepository $repo */
        $repo = static::getContainer()->get(EntryRepository::class);

        $found = $repo->findPublishedByFormatIds(['com.example.one', 'com.example.hidden', 'com.example.ghost']);

        self::assertCount(1, $found);
        self::assertSame('com.example.one', $found[0]->formatId);
        self::assertNotNull($found[0]->currentVersion);
        self::assertSame([], $repo->findPublishedByFormatIds([]));
    }
}
```

- [ ] **Step 2: Test laufen, Fehlschlag bestätigen**

Run: `php backend/bin/phpunit --filter testRepositoryReturnsOnlyPublishedWithVersion; echo $?`
Expected: FAIL (Methode existiert nicht) / Exit ≠ 0.

- [ ] **Step 3: Methode implementieren**

In `backend/src/Repository/EntryRepository.php` (Import `EntryStatus` ist bereits vorhanden) neue Methode ergänzen:

```php
/**
 * Lädt die veröffentlichten Einträge zu den gegebenen Format-IDs samt
 * fetch-joined currentVersion (kein N+1). Reihenfolge ist DB-bestimmt;
 * der Aufrufer stellt die Anfrage-Reihenfolge her. Leeres Eingabe-Array
 * ⇒ leeres Ergebnis ohne DB-Zugriff (vermeidet ein leeres SQL-IN()).
 *
 * @param list<string> $formatIds
 * @return list<Entry>
 */
public function findPublishedByFormatIds(array $formatIds): array
{
    if ($formatIds === []) {
        return [];
    }

    return $this->createQueryBuilder('e')
        ->addSelect('v')
        ->join('e.currentVersion', 'v')
        ->andWhere('e.formatId IN (:ids)')
        ->andWhere('e.status = :published')
        ->setParameter('ids', $formatIds)
        ->setParameter('published', EntryStatus::Published)
        ->getQuery()
        ->getResult();
}
```

- [ ] **Step 4: Test laufen, grün bestätigen**

Run: `php backend/bin/phpunit --filter testRepositoryReturnsOnlyPublishedWithVersion; echo $?`
Expected: PASS / Exit 0.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Repository/EntryRepository.php backend/tests/Functional/BundleTest.php
git commit -m "Ergänze Batch-Lookup findPublishedByFormatIds fürs Bundle"
```

---

### Task 2: Bundle-Endpunkt `POST /api/v1/bundle` + `bundle`-Rate-Limiter

**Files:**
- Create: `backend/src/Controller/Api/BundleController.php`
- Modify: `backend/config/packages/rate_limiter.yaml` (drei Blöcke)
- Test: `backend/tests/Functional/BundleTest.php` (erweitern)

**Interfaces:**
- Consumes: `EntryRepository::findPublishedByFormatIds()` (Task 1), `App\Service\RateLimitGuard::consume()`, `App\Exception\ApiProblem(int $statusCode, string $title, array $extra = [], array $headers = [])`, `RateLimiterFactoryInterface $bundleLimiter`.
- Produces: Route `POST /api/v1/bundle` → `200 { gesturaBundle: 1, entries: [...] }` bzw. `400`/`429` als `application/problem+json`.

- [ ] **Step 1: Failing tests schreiben**

`backend/tests/Functional/BundleTest.php` um Endpunkt-Tests erweitern (Methoden zur bestehenden Klasse hinzufügen):

```php
    public function testBundleReturnsSelectedPublishedEntriesInRequestOrder(): void
    {
        $this->createPublishedEntry('com.example.a');
        $this->createPublishedEntry('com.example.b');

        $this->api('POST', '/api/v1/bundle', ['ids' => ['com.example.b', 'com.example.a']]);

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertSame(1, $data['gesturaBundle']);
        self::assertCount(2, $data['entries']);
        self::assertSame('com.example.b', $data['entries'][0]['id']);
        self::assertSame('com.example.a', $data['entries'][1]['id']);
    }

    public function testBundleSkipsUnknownAndUnpublishedAndDeduplicates(): void
    {
        $this->createPublishedEntry('com.example.a');
        $hidden = $this->createPublishedEntry('com.example.hidden');
        $hidden->status = EntryStatus::Hidden;
        $this->em->flush();

        $this->api('POST', '/api/v1/bundle', ['ids' => ['com.example.a', 'com.example.a', 'com.example.hidden', 'com.example.ghost']]);

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertCount(1, $data['entries']);
        self::assertSame('com.example.a', $data['entries'][0]['id']);
    }

    public function testBundleWithOnlyUnknownIdsYieldsEmptyEntries(): void
    {
        $this->api('POST', '/api/v1/bundle', ['ids' => ['com.example.ghost']]);

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertSame(1, $data['gesturaBundle']);
        self::assertSame([], $data['entries']);
    }

    /**
     * @dataProvider invalidBodies
     */
    public function testBundleRejectsInvalidBody(array|string $body): void
    {
        // roher Content, um auch nicht-Objekt-Bodies zu senden
        $this->client->request('POST', '/api/v1/bundle', server: ['CONTENT_TYPE' => 'application/json'], content: is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);
    }

    public static function invalidBodies(): array
    {
        return [
            'kein JSON-Objekt' => ['nicht json'],
            'ids fehlt' => [['foo' => 'bar']],
            'ids leer' => [['ids' => []]],
            'ids kein Array' => [['ids' => 'com.example.a']],
            'ids nicht-String-Element' => [['ids' => [123]]],
            'zu viele ids' => [['ids' => array_map(fn ($i) => "com.example.$i", range(1, 201))]],
        ];
    }

    public function testBundleResponseHasPublicCors(): void
    {
        $this->createPublishedEntry('com.example.a');
        $this->api('POST', '/api/v1/bundle', ['ids' => ['com.example.a']]);
        self::assertSame('*', $this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }
```

- [ ] **Step 2: Tests laufen, Fehlschlag bestätigen**

Run: `php backend/bin/phpunit --filter BundleTest; echo $?`
Expected: FAIL (Route/Limiter fehlen) / Exit ≠ 0.

- [ ] **Step 3: Rate-Limiter ergänzen**

In `backend/config/packages/rate_limiter.yaml` in **allen drei** Blöcken einen `bundle`-Limiter ergänzen:

- `framework.rate_limiter`: `bundle: { policy: sliding_window, limit: 120, interval: '1 hour' }`
- `when@test.framework.rate_limiter`: `bundle: { policy: sliding_window, limit: 1000, interval: '1 hour' }`
- `when@dev.framework.rate_limiter`: `bundle: { policy: sliding_window, limit: 1000, interval: '1 hour' }`

- [ ] **Step 4: Controller implementieren**

`backend/src/Controller/Api/BundleController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\RateLimitGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Baut aus einer Liste von Format-IDs ein Bundle der veröffentlichten
 * Payloads: { gesturaBundle: 1, entries: [ <payload>, … ] }. Speist den
 * Sammelkorb-Datei-Download der Website (ein Request statt N). Unbekannte
 * oder nicht-veröffentlichte IDs werden stillschweigend ausgelassen; der
 * Client gleicht angefragte gegen gelieferte IDs (entries[].id) selbst ab.
 *
 * POST mit JSON-Body statt GET, weil große Körbe als Query-String Apaches
 * LimitRequestLine überschreiten könnten. Öffentliche cookielose API mit
 * "*"-CORS (CorsSubscriber deckt den Preflight). Kein Install-Zähler.
 */
final class BundleController
{
    private const MAX_IDS = 200;

    #[Route('/api/v1/bundle', methods: ['POST'])]
    public function __invoke(
        Request $request,
        EntryRepository $entries,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $bundleLimiter,
    ): JsonResponse {
        $guard->consume($bundleLimiter, $request->getClientIp() ?? 'unknown');

        $body = json_decode($request->getContent(), true);
        if (!is_array($body) || !array_key_exists('ids', $body)) {
            throw new ApiProblem(400, 'Invalid request body');
        }
        $ids = $body['ids'];
        if (!is_array($ids) || $ids === []) {
            throw new ApiProblem(400, 'ids must be a non-empty array');
        }

        $clean = [];
        foreach ($ids as $id) {
            if (!is_string($id)) {
                throw new ApiProblem(400, 'ids must be strings');
            }
            $id = trim($id);
            if ($id !== '' && !in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }
        if (count($clean) > self::MAX_IDS) {
            throw new ApiProblem(400, 'Too many ids (max ' . self::MAX_IDS . ')');
        }
        if ($clean === []) {
            return new JsonResponse(['gesturaBundle' => 1, 'entries' => []]);
        }

        $byId = [];
        foreach ($entries->findPublishedByFormatIds($clean) as $entry) {
            $byId[$entry->formatId] = $entry->currentVersion->payload;
        }

        $out = [];
        foreach ($clean as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }

        return new JsonResponse(['gesturaBundle' => 1, 'entries' => $out]);
    }
}
```

- [ ] **Step 5: Tests laufen, grün bestätigen**

Run: `php backend/bin/phpunit --filter BundleTest; echo $?`
Expected: PASS / Exit 0.

- [ ] **Step 6: Volle Backend-Suite (Regression + Deprecation-Gate)**

Run: `php backend/bin/phpunit; echo $?`
Expected: Exit 0.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Controller/Api/BundleController.php backend/config/packages/rate_limiter.yaml backend/tests/Functional/BundleTest.php
git commit -m "Baue POST /api/v1/bundle mit bundle-Rate-Limiter"
```

---

### Task 3: Frontend-API-Funktion `getBundle`

**Files:**
- Modify: `frontend/src/lib/api.ts`
- Test: `frontend/src/lib/api.test.ts` (erweitern; dort werden `request`-basierte Funktionen bereits getestet)

**Interfaces:**
- Consumes: bestehende `request(path, init, opts)`-Hilfe, `ClientOpts`, `API_BASE`.
- Produces: `getBundle(ids: string[], opts?: ClientOpts): Promise<Bundle>` mit `interface Bundle { gesturaBundle: 1; entries: unknown[]; }`.

- [ ] **Step 1: Failing test schreiben**

In `frontend/src/lib/api.test.ts` einen Test ergänzen (Muster der vorhandenen fetch-Mock-Tests übernehmen — vorhandene Datei zuerst lesen, gleiche Mock-Form nutzen):

```ts
it('getBundle postet ids und liefert das Bundle', async () => {
	const fetchMock = vi.fn().mockResolvedValue(
		new Response(JSON.stringify({ gesturaBundle: 1, entries: [{ gesturaMenu: 1, id: 'a' }] }), {
			status: 200,
			headers: { 'content-type': 'application/json' }
		})
	);
	const bundle = await getBundle(['a', 'b'], { fetch: fetchMock });
	expect(bundle).toEqual({ gesturaBundle: 1, entries: [{ gesturaMenu: 1, id: 'a' }] });
	const [url, init] = fetchMock.mock.calls[0];
	expect(String(url)).toContain('/api/v1/bundle');
	expect(init.method).toBe('POST');
	expect(JSON.parse(init.body)).toEqual({ ids: ['a', 'b'] });
});
```

(Import von `getBundle` in der bestehenden Import-Zeile ergänzen.)

- [ ] **Step 2: Test laufen, Fehlschlag bestätigen**

Run: `npm --prefix frontend run test -- --run src/lib/api.test.ts`
Expected: FAIL (`getBundle` existiert nicht).

- [ ] **Step 3: Implementieren**

In `frontend/src/lib/api.ts` (nahe den anderen exportierten Funktionen) ergänzen:

```ts
/** Server-seitig gebautes Bundle für die gewählten IDs (ein Request). */
export interface Bundle {
	gesturaBundle: 1;
	entries: unknown[];
}

/**
 * Holt ein Bundle der veröffentlichten Payloads zu den gewählten IDs.
 * Unbekannte/nicht-veröffentlichte IDs fehlen im Ergebnis – der Aufrufer
 * gleicht angefragte gegen gelieferte IDs (entries[].id) selbst ab.
 */
export async function getBundle(ids: string[], opts: ClientOpts = {}): Promise<Bundle> {
	const res = await request(
		'/api/v1/bundle',
		{
			method: 'POST',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify({ ids })
		},
		opts
	);
	return (await res.json()) as Bundle;
}
```

- [ ] **Step 4: Test laufen, grün bestätigen**

Run: `npm --prefix frontend run test -- --run src/lib/api.test.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/lib/api.ts frontend/src/lib/api.test.ts
git commit -m "Ergänze getBundle-API-Funktion (POST /api/v1/bundle)"
```

---

### Task 4: `BasketTray.download()` auf den Endpunkt umstellen

**Files:**
- Modify: `frontend/src/lib/components/BasketTray.svelte`
- Test: `frontend/src/lib/components/BasketTray.test.ts`

**Interfaces:**
- Consumes: `getBundle` + `Bundle` (Task 3), `basket.ids`, `triggerJsonDownload`, `m.basket_download_error({ ids })`.
- Produces: keine (interne Verhaltensänderung: ein Request statt N).

- [ ] **Step 1: Test anpassen (failing)**

Bestehende `BasketTray.test.ts` zuerst lesen. Den Download-Test so umschreiben, dass er `getBundle` (statt `downloadVersion`) mockt und einen einzigen Aufruf erwartet; ein Fall mit einer im Ergebnis fehlenden ID erwartet die Fehlermeldung. Kern:

```ts
// getBundle liefert nur einen der beiden angefragten Einträge zurück
getBundle.mockResolvedValue({ gesturaBundle: 1, entries: [{ gesturaMenu: 1, id: 'a' }] });
// ... Download auslösen ...
expect(getBundle).toHaveBeenCalledTimes(1);
expect(getBundle).toHaveBeenCalledWith(['a', 'b'], expect.anything());
expect(triggerJsonDownload).toHaveBeenCalledWith(
	{ gesturaBundle: 1, entries: [{ gesturaMenu: 1, id: 'a' }] },
	'gestura-bundle.json'
);
// 'b' fehlt im Ergebnis ⇒ Fehlermeldung mit 'b'
```

(Die vorhandene `$lib/api`-Mock-Struktur der Datei übernehmen; `getBundle` zusätzlich zu bzw. statt `downloadVersion` mocken. `triggerJsonDownload` wie bisher mocken.)

- [ ] **Step 2: Test laufen, Fehlschlag bestätigen**

Run: `npm --prefix frontend run test -- --run src/lib/components/BasketTray.test.ts`
Expected: FAIL.

- [ ] **Step 3: `download()` umschreiben**

In `BasketTray.svelte` den Import `downloadVersion` durch `getBundle, type Bundle` ersetzen und `download()` ersetzen:

```ts
async function download() {
	downloading = true;
	downloadError = null;
	const requested = basket.ids;
	let bundle: Bundle;
	try {
		bundle = await getBundle(requested);
	} catch {
		downloading = false;
		downloadError = m.basket_download_error({ ids: requested.join(', ') });
		return;
	}
	downloading = false;

	if (bundle.entries.length) {
		triggerJsonDownload(bundle, 'gestura-bundle.json');
	}

	const delivered = new Set(
		bundle.entries.map((e) => String((e as { id?: unknown }).id ?? ''))
	);
	const failed = requested.filter((id) => !delivered.has(id));
	if (failed.length) {
		downloadError = m.basket_download_error({ ids: failed.join(', ') });
	}
}
```

- [ ] **Step 4: Test + check laufen, grün bestätigen**

Run: `npm --prefix frontend run test -- --run src/lib/components/BasketTray.test.ts`
Then: `npm --prefix frontend run check`
Expected: PASS bzw. keine TypeScript-Fehler.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/lib/components/BasketTray.svelte frontend/src/lib/components/BasketTray.test.ts
git commit -m "Stelle Sammelkorb-Download auf /api/v1/bundle um (ein Request)"
```

---

### Task 5: Team-Gedächtnis ergänzen

**Files:**
- Modify: `.claude/lessons.md`

**Interfaces:** keine.

- [ ] **Step 1: Lesson ergänzen**

Am Ende von `.claude/lessons.md` einen Punkt ergänzen (deutsche Typografie beachten):

```markdown
- **Bundle-Endpunkt `POST /api/v1/bundle` ist bewusst POST mit JSON-Body**, nicht GET `?ids=`: große Sammelkörbe würden als Query-String Apaches `LimitRequestLine` (Default 8190 Bytes) auf dem Shared-Hosting überschreiten. Der cross-origin-POST (`gestura.eu` → `api.gestura.eu`) ist durch den bestehenden `CorsSubscriber` gedeckt (Preflight → 204), **keine** CORS-Änderung nötig. Der Endpunkt lässt unbekannte/nicht-veröffentlichte IDs stillschweigend aus (der Client gleicht über `entries[].id` ab) und zählt **keine** Installation. Kein Client-Secret/Extension-Auth — Client-Secrets in einer MV3-Extension sind extrahierbar; Schutz nur über den `bundle`-Rate-Limiter + 200-ID-Cap (Spec §7). Der **Live**-Betreiber-Button-Handover ist vertagt und braucht eine **GET-fähige** Bundle-URL plus Extension-Änderungen (Bundle-Import-Zweig + Cross-Origin-Vertrauen).
```

- [ ] **Step 2: Commit**

```bash
git add .claude/lessons.md
git commit -m "Dokumentiere Bundle-Endpunkt-Entscheidungen in lessons.md"
```

---

## Self-Review

- **Spec coverage:** §3 Endpunkt → Task 2; Repository → Task 1; Rate-Limiter → Task 2; Frontend-Retrofit → Tasks 3+4; §6 Schema (nicht ändern) → als Constraint, keine Task; §7 kein Secret → Constraint + Task 5 Doku; §8 Extension-Vertrag → vertagt, keine Task. Abgedeckt.
- **Placeholder-Scan:** Jeder Code-Step enthält vollständigen Code; Tests konkret. Keine TODOs.
- **Typkonsistenz:** `findPublishedByFormatIds` (Task 1) wird in Task 2 mit exakt diesem Namen konsumiert; `getBundle`/`Bundle` (Task 3) in Task 4 identisch benannt; `ApiProblem`-Signatur aus dem echten Konstruktor übernommen; `RateLimiterFactoryInterface $bundleLimiter` = Limitername `bundle`.
