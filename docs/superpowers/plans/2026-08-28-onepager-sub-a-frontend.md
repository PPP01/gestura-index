# Onepager-Frontend (Sub-Projekt A) – Umsetzungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Der öffentliche Index wird ein client-seitiger Onepager: ganzer Katalog progressiv geladen, on-the-fly gefiltert (Kategorie, Tag, Sprache, Typ, Freitext, Site), kompakte Blöcke mit inline-Sternen und Aufklappen (Details + nachladende Reviews), Sammelkorb mit client-seitigem Bundle-Download.

**Architecture:** Rein Frontend (SvelteKit, Svelte 5 Runes, TS), **kein Backend-Change**. Reine Logik (Sprach-Auflösung, Facetten, progressiver Loader) liegt in testbaren TS-Modulen; Svelte-Komponenten komponieren sie. Die lokalisierte Wurzel (`/de`, `/en`) wird client-only (`prerender=false, ssr=false`) gerendert und ersetzt die bisherige Hero-/Kachel-Startseite; `browse`/`entry`-Routen werden zu Deep-Link-Redirects.

**Tech Stack:** SvelteKit 2 + Svelte 5 (Runes), TypeScript, Vitest + @testing-library/svelte, Paraglide (de/en), Lucide-Icons, `$env/dynamic/public`.

## Global Constraints

- **Kein Backend-Change** – keine neue API, kein `?locale=`, kein Facet-Endpunkt. Die Listen-API liefert `rating: {average, count}` bereits; `name`/`description` kommen roh als String **oder** Sprach-Map durch.
- **Antworten/Kommentare/Texte auf Deutsch**, Guillemets »…«, Halbgeviertstrich –, UTF-8-Umlaute. Gilt nicht für Code-Bezeichner.
- **Paraglide-Kompilat `src/lib/paraglide/messages.js` ist gitignored – niemals committen.** Nur `messages/en.json` + `messages/de.json` committen. Neue Keys IMMER in **beiden** JSON-Dateien.
- **Kein `vite dev` parallel zu `npm run check`/`npm run test`** (Paraglide-Kompilat-Konflikt). Der Nutzer bestätigt: kein Dev-Server läuft.
- **Kein Push, kein Commit-Bündeln fremder Änderungen** – jeder Task committet nur seine eigenen Dateien.
- **Svelte 5 Runes** (`$state`, `$derived`, `$props`, `$effect`) – kein Legacy-Reactive.
- **Bundle-Format-Vertrag:** `{ "gesturaBundle": 1, "entries": [ <payload>, … ] }`; jeder `entries`-Eintrag ist exakt das bare Austausch-Objekt der `currentVersion` (aus `downloadVersion`).
- **Sprach-Semantik:** Eintrag ist in Sprache `L` verfügbar, wenn seine `name`-Map den Schlüssel `L` hat; String-`name` zählt als `en`. Mehrsprachige Einträge erscheinen unter jeder ihrer Sprachen.
- **`npm run test` = `paraglide compile && vitest`**; `npm run check` = `paraglide compile && svelte-kit sync && svelte-check`. Jede Task läuft beide am Ende grün.

---

## Dateistruktur (Überblick)

**Neu:**
- `frontend/src/lib/localized.ts` – `LocalizedString`, `resolveLocalized`, `entryLanguages` (+ Test)
- `frontend/src/lib/catalog.ts` – progressiver Katalog-Loader + `INITIAL_LOAD` (+ Test)
- `frontend/src/lib/facets.ts` – Filtern/Sortieren/Facetten-Ableitung/Optionszähler (+ Test)
- `frontend/src/lib/basket.svelte.ts` – persistenter Auswahl-Store (+ Test)
- `frontend/src/lib/components/StarRating.svelte` – 5-Sterne-Anzeige (+ Test)
- `frontend/src/lib/components/EntryBlock.svelte` – Blocklisten-Element mit Aufklappen (+ Test)
- `frontend/src/lib/components/BasketTray.svelte` – Sammelkorb-Indikator + Panel + Download (+ Test)
- `frontend/src/routes/(public)/onepager.test.ts` – Seiten-/Integrationstest der Wurzel

**Geändert:**
- `frontend/src/lib/api.ts` – `EntryListItem` (rating + LocalizedString), `ReviewItem`/`listReviews`
- `frontend/src/lib/browse-state.ts` – Onepager-Filter-Parse/Serialize (ergänzt, bestehende Funktionen bleiben)
- `frontend/src/lib/browse-state.test.ts` – Tests für die neuen Funktionen
- `frontend/src/routes/(public)/+page.svelte` – wird der Onepager
- `frontend/src/routes/(public)/+page.ts` – **neu**, `prerender=false; ssr=false`
- `frontend/messages/en.json` + `frontend/messages/de.json` – neue Keys (über mehrere Tasks)

**Entfernt:**
- `frontend/src/routes/(public)/browse/+page.svelte` + `+page.ts` (→ Redirect-Shell in T7)
- `frontend/src/routes/(public)/entry/[formatId]/+page.svelte` + `+page.ts` (→ Redirect-Shell in T7)
- `frontend/src/lib/components/EntryCard.svelte` + `EntryCard.test.ts`

**Bleibt (wiederverwendet):** `download.ts`, `categories.ts`, `browse-state.ts` (`Sequence`/`debounce`), `Badge`, `EmptyState`, `ErrorState`, `Spinner`, Header/Footer/LangToggle/ThemeToggle, `Pagination` (ungenutzt, verbleibt).

---

### Task 1: Fundament-Typen, Sprach-Helfer & Teardown der Alt-Routen

**Files:**
- Create: `frontend/src/lib/localized.ts`
- Test: `frontend/src/lib/localized.test.ts`
- Modify: `frontend/src/lib/api.ts`
- Delete: `frontend/src/routes/(public)/browse/+page.svelte`, `frontend/src/routes/(public)/browse/+page.ts`, `frontend/src/routes/(public)/entry/[formatId]/+page.svelte`, `frontend/src/routes/(public)/entry/[formatId]/+page.ts`, `frontend/src/lib/components/EntryCard.svelte`, `frontend/src/lib/components/EntryCard.test.ts`

**Interfaces:**
- Produces: `LocalizedString`, `resolveLocalized(value, locale)`, `entryLanguages(value)` (localized.ts); `EntryListItem` mit `name: LocalizedString`, `description: LocalizedString | null`, `rating: { average: number | null; count: number }`; `ReviewItem { stars: number; comment: string | null; createdAt: string }`, `ReviewListResponse`, `listReviews(formatId, page, opts)` (api.ts).

- [ ] **Step 1: Failing test für localized.ts schreiben**

Create `frontend/src/lib/localized.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { resolveLocalized, entryLanguages } from './localized';

describe('resolveLocalized', () => {
	it('gibt einen einfachen String unverändert zurück', () => {
		expect(resolveLocalized('Hello', 'de')).toBe('Hello');
	});
	it('wählt die passende Sprache aus einer Map', () => {
		expect(resolveLocalized({ en: 'Hi', de: 'Hallo' }, 'de')).toBe('Hallo');
	});
	it('fällt auf en zurück, wenn die Locale fehlt', () => {
		expect(resolveLocalized({ en: 'Hi', fr: 'Salut' }, 'de')).toBe('Hi');
	});
	it('nimmt den ersten Wert, wenn weder Locale noch en existieren', () => {
		expect(resolveLocalized({ fr: 'Salut' }, 'de')).toBe('Salut');
	});
	it('gibt für null/undefined einen leeren String zurück', () => {
		expect(resolveLocalized(null, 'de')).toBe('');
		expect(resolveLocalized(undefined, 'de')).toBe('');
	});
});

describe('entryLanguages', () => {
	it('zählt einen String-Namen als en', () => {
		expect(entryLanguages('Hello')).toEqual(['en']);
	});
	it('liefert alle Map-Schlüssel', () => {
		expect(entryLanguages({ en: 'Hi', de: 'Hallo' }).sort()).toEqual(['de', 'en']);
	});
	it('liefert [] für eine leere Map', () => {
		expect(entryLanguages({})).toEqual([]);
	});
});
```

- [ ] **Step 2: Test ausführen, Fehlschlag bestätigen**

Run: `npm --prefix frontend run test -- --run src/lib/localized.test.ts`
Expected: FAIL (`Cannot find module './localized'`).

- [ ] **Step 3: localized.ts implementieren**

Create `frontend/src/lib/localized.ts`:

```ts
/** Ein Textfeld, das entweder ein einfacher String oder eine Sprach-Map ist. */
export type LocalizedString = string | Record<string, string>;

/**
 * Löst ein LocalizedString zur Anzeige-Locale auf.
 * String → unverändert; Map → locale, sonst en-Fallback, sonst erster Wert;
 * null/undefined → leerer String.
 */
export function resolveLocalized(
	value: LocalizedString | null | undefined,
	locale: string
): string {
	if (value == null) return '';
	if (typeof value === 'string') return value;
	if (value[locale] != null) return value[locale];
	if (value.en != null) return value.en;
	const first = Object.values(value)[0];
	return first ?? '';
}

/**
 * Ermittelt die im Namensfeld vorhandenen Sprachen (Format-Konvention:
 * einfacher String = en-Fallback).
 */
export function entryLanguages(value: LocalizedString | null | undefined): string[] {
	if (value == null) return [];
	if (typeof value === 'string') return ['en'];
	return Object.keys(value);
}
```

- [ ] **Step 4: Test ausführen, Erfolg bestätigen**

Run: `npm --prefix frontend run test -- --run src/lib/localized.test.ts`
Expected: PASS.

- [ ] **Step 5: api.ts – EntryListItem erweitern und Reviews-Client ergänzen**

In `frontend/src/lib/api.ts`:

- Import ergänzen (oben, nach `import { env } ...`):

```ts
import type { LocalizedString } from './localized';
```

- `EntryListItem` ersetzen (die Felder `name` und `description` retypen, `rating` ergänzen):

```ts
/** Ein Eintrag in der Listen-/Kartenansicht (Serializer::toListItem). */
export interface EntryListItem {
	formatId: string;
	type: EntryType;
	/** Roh aus der API: String oder Sprach-Map (nicht vor-aufgelöst). */
	name: LocalizedString;
	description: LocalizedString | null;
	categories: string[];
	tags: string[];
	domains: string[];
	installCount: number;
	rating: { average: number | null; count: number };
	currentVersion: string | null;
	deprecated: boolean;
	successorFormatId: string | null;
	screenshotUrl: string | null;
	updatedAt: string;
}
```

- Am Dateiende (vor dem letzten Export-Block ist egal, ans Ende hängen) den Reviews-Client ergänzen:

```ts
/** Ein freigegebener, anonymer Review-Eintrag (ReviewListController). */
export interface ReviewItem {
	stars: number;
	comment: string | null;
	createdAt: string;
}

export interface ReviewListResponse {
	items: ReviewItem[];
	page: number;
	perPage: number;
	total: number;
}

/** Lädt die freigegebenen Reviews eines Eintrags (paginiert, anonym). */
export async function listReviews(
	formatId: string,
	page = 1,
	opts: ClientOpts = {}
): Promise<ReviewListResponse> {
	const res = await request(
		`/api/v1/entries/${encodeURIComponent(formatId)}/reviews?page=${page}`,
		{ method: 'GET' },
		opts
	);
	return (await res.json()) as ReviewListResponse;
}
```

- [ ] **Step 6: Alt-Routen und EntryCard entfernen**

Run:

```bash
cd frontend
git rm src/routes/'(public)'/browse/+page.svelte src/routes/'(public)'/browse/+page.ts
git rm src/routes/'(public)'/entry/'[formatId]'/+page.svelte src/routes/'(public)'/entry/'[formatId]'/+page.ts
git rm src/lib/components/EntryCard.svelte src/lib/components/EntryCard.test.ts
cd ..
```

Grund: `EntryCard` und die Detailseite sind die einzigen Konsumenten des alten String-`name`-Typs; die Wurzel-Hero (`(public)/+page.svelte`) nutzt nur `m.*` und ist vom Typ-Wechsel unberührt. Nach dem Entfernen kompiliert der Baum mit dem neuen `LocalizedString`-Typ.

- [ ] **Step 7: Voller Check + Test grün**

Run: `npm --prefix frontend run check && npm --prefix frontend run test -- --run`
Expected: `svelte-check` 0 Fehler; alle verbleibenden Tests PASS (die gelöschten EntryCard-Tests sind weg, `api.test.ts` unverändert grün, `localized.test.ts` grün).
Falls `api.test.ts` ein `EntryListItem`-Literal ohne `rating` enthält, dort `rating: { average: null, count: 0 }` ergänzen (nur echte Testdaten-Literale anpassen).

- [ ] **Step 8: Commit**

```bash
git add frontend/src/lib/localized.ts frontend/src/lib/localized.test.ts frontend/src/lib/api.ts frontend/src/routes frontend/src/lib/components frontend/src/lib/api.test.ts
git commit -m "$(cat <<'EOF'
Lege Onepager-Fundament: LocalizedString + Reviews-Client

Sprach-Auflösung (resolveLocalized/entryLanguages) und der rohe
name/description-Typ (String oder Sprach-Map) sind die Basis fuer die
client-seitige Sprachfacette des Onepagers. rating liegt der Listen-API
bereits bei; nur der TS-Typ wird nachgezogen. Die alten browse-/entry-
Routen und EntryCard entfallen (werden durch Onepager + Redirects
ersetzt) – so bleibt kein Konsument des alten String-name-Typs.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Katalog-Loader, Facetten-Logik & Onepager-URL-State

**Files:**
- Create: `frontend/src/lib/catalog.ts`, `frontend/src/lib/catalog.test.ts`
- Create: `frontend/src/lib/facets.ts`, `frontend/src/lib/facets.test.ts`
- Modify: `frontend/src/lib/browse-state.ts`, `frontend/src/lib/browse-state.test.ts`

**Interfaces:**
- Consumes: `EntryListItem`, `EntryQuery`, `EntryListResponse`, `listEntries`, `ClientOpts` (api.ts); `resolveLocalized`, `entryLanguages` (localized.ts); `EntryType` (api.ts).
- Produces:
  - catalog.ts: `INITIAL_LOAD: number`; `loadCatalog(cb: CatalogCallbacks, options?: CatalogOptions): Promise<void>` mit `CatalogCallbacks { onBatch(items, loaded, total); onComplete(); onError(message) }` und `CatalogOptions { initialLoad?; perPage?; list?; signal? }`.
  - browse-state.ts: `OnepagerSort = 'newest' | 'installs' | 'best'`; `OnepagerFilter { q?; type?; categories: string[]; tags: string[]; langs: string[]; site?; sort: OnepagerSort; highlight? }`; `parseOnepagerFilter(searchParams): OnepagerFilter`; `onepagerSearchParams(filter): URLSearchParams`.
  - facets.ts: `FacetOption { value: string; count: number }`; `filterEntries(items, filter, locale)`; `sortEntries(items, sort)`; `languageFacet(items)`; `tagFacet(items)`; `categoryFacet(items)`; `optionCount(items, filter, dimension, value, locale)` mit `dimension: 'categories' | 'tags' | 'langs'`.

- [ ] **Step 1: Failing test für browse-state-Erweiterung schreiben**

An `frontend/src/lib/browse-state.test.ts` anhängen (bestehende Tests unverändert lassen):

```ts
import { parseOnepagerFilter, onepagerSearchParams } from './browse-state';

describe('parseOnepagerFilter', () => {
	it('liest CSV-Mehrfachwerte und Einzelwerte', () => {
		const f = parseOnepagerFilter(
			new URLSearchParams('q=abc&type=menu&category=dev,news&tag=x,y&lang=de,en&site=example.com&sort=best')
		);
		expect(f.q).toBe('abc');
		expect(f.type).toBe('menu');
		expect(f.categories).toEqual(['dev', 'news']);
		expect(f.tags).toEqual(['x', 'y']);
		expect(f.langs).toEqual(['de', 'en']);
		expect(f.site).toBe('example.com');
		expect(f.sort).toBe('best');
	});
	it('liefert leere Arrays und Default-Sort ohne Parameter', () => {
		const f = parseOnepagerFilter(new URLSearchParams(''));
		expect(f.categories).toEqual([]);
		expect(f.tags).toEqual([]);
		expect(f.langs).toEqual([]);
		expect(f.sort).toBe('newest');
		expect(f.q).toBeUndefined();
	});
	it('ignoriert ungültige type-/sort-Werte', () => {
		const f = parseOnepagerFilter(new URLSearchParams('type=bogus&sort=bogus'));
		expect(f.type).toBeUndefined();
		expect(f.sort).toBe('newest');
	});
});

describe('onepagerSearchParams', () => {
	it('serialisiert Arrays als CSV und lässt Leeres weg', () => {
		const params = onepagerSearchParams({
			q: 'abc',
			type: 'engine',
			categories: ['dev'],
			tags: [],
			langs: ['de', 'en'],
			site: undefined,
			sort: 'installs'
		});
		expect(params.get('q')).toBe('abc');
		expect(params.get('type')).toBe('engine');
		expect(params.get('category')).toBe('dev');
		expect(params.get('tag')).toBeNull();
		expect(params.get('lang')).toBe('de,en');
		expect(params.get('site')).toBeNull();
		expect(params.get('sort')).toBe('installs');
	});
	it('lässt den Default-Sort newest weg', () => {
		const params = onepagerSearchParams({ categories: [], tags: [], langs: [], sort: 'newest' });
		expect(params.get('sort')).toBeNull();
	});
	it('round-trip erhält den Filter', () => {
		const original = {
			q: 'z',
			type: 'menu' as const,
			categories: ['dev', 'news'],
			tags: ['x'],
			langs: ['de'],
			site: 'a.com',
			sort: 'best' as const
		};
		const back = parseOnepagerFilter(onepagerSearchParams(original));
		expect(back.categories).toEqual(original.categories);
		expect(back.tags).toEqual(original.tags);
		expect(back.langs).toEqual(original.langs);
		expect(back.sort).toBe('best');
	});
});
```

- [ ] **Step 2: Test ausführen, Fehlschlag bestätigen**

Run: `npm --prefix frontend run test -- --run src/lib/browse-state.test.ts`
Expected: FAIL (`parseOnepagerFilter is not a function`).

- [ ] **Step 3: browse-state.ts erweitern**

An `frontend/src/lib/browse-state.ts` anhängen (bestehende Funktionen `parseQuery`/`toSearchParams`/`Sequence`/`debounce` unverändert lassen):

```ts
export type OnepagerSort = 'newest' | 'installs' | 'best';
const SORTS: OnepagerSort[] = ['newest', 'installs', 'best'];

/** Client-Filterzustand des Onepagers (Mehrfachwerte je Facette). */
export interface OnepagerFilter {
	q?: string;
	type?: EntryType;
	categories: string[];
	tags: string[];
	langs: string[];
	site?: string;
	sort: OnepagerSort;
	highlight?: string;
}

function csv(searchParams: URLSearchParams, key: string): string[] {
	const raw = searchParams.get(key);
	if (!raw) return [];
	return raw
		.split(',')
		.map((s) => s.trim())
		.filter((s) => s !== '');
}

/** Liest den Onepager-Filter aus den URL-Query-Parametern. */
export function parseOnepagerFilter(searchParams: URLSearchParams): OnepagerFilter {
	const str = (k: string) => {
		const v = searchParams.get(k);
		return v && v.trim() !== '' ? v : undefined;
	};
	const type = str('type');
	const sort = str('sort');
	return {
		q: str('q'),
		type: type && (TYPES as string[]).includes(type) ? (type as EntryType) : undefined,
		categories: csv(searchParams, 'category'),
		tags: csv(searchParams, 'tag'),
		langs: csv(searchParams, 'lang'),
		site: str('site'),
		sort: sort && (SORTS as string[]).includes(sort) ? (sort as OnepagerSort) : 'newest',
		highlight: str('highlight')
	};
}

/** Serialisiert den Onepager-Filter in kanonische Query-Parameter. */
export function onepagerSearchParams(filter: OnepagerFilter): URLSearchParams {
	const params = new URLSearchParams();
	if (filter.q) params.set('q', filter.q);
	if (filter.type) params.set('type', filter.type);
	if (filter.categories.length) params.set('category', filter.categories.join(','));
	if (filter.tags.length) params.set('tag', filter.tags.join(','));
	if (filter.langs.length) params.set('lang', filter.langs.join(','));
	if (filter.site) params.set('site', filter.site);
	if (filter.sort && filter.sort !== 'newest') params.set('sort', filter.sort);
	if (filter.highlight) params.set('highlight', filter.highlight);
	return params;
}
```

Hinweis: `TYPES` und der `EntryType`-Import existieren bereits oben in der Datei.

- [ ] **Step 4: browse-state-Test grün**

Run: `npm --prefix frontend run test -- --run src/lib/browse-state.test.ts`
Expected: PASS (alte + neue Tests).

- [ ] **Step 5: Failing test für facets.ts schreiben**

Create `frontend/src/lib/facets.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import {
	filterEntries,
	sortEntries,
	languageFacet,
	tagFacet,
	categoryFacet,
	optionCount
} from './facets';
import type { EntryListItem } from './api';
import type { OnepagerFilter } from './browse-state';

function entry(over: Partial<EntryListItem>): EntryListItem {
	return {
		formatId: 'com.example.a',
		type: 'menu',
		name: 'A',
		description: null,
		categories: [],
		tags: [],
		domains: [],
		installCount: 0,
		rating: { average: null, count: 0 },
		currentVersion: '1.0.0',
		deprecated: false,
		successorFormatId: null,
		screenshotUrl: null,
		updatedAt: '2026-01-01T00:00:00Z',
		...over
	};
}

const base: OnepagerFilter = { categories: [], tags: [], langs: [], sort: 'newest' };

describe('filterEntries', () => {
	const items = [
		entry({ formatId: 'a', name: { en: 'Alpha', de: 'Alfa' }, categories: ['dev'], tags: ['git'] }),
		entry({ formatId: 'b', name: 'Beta', categories: ['news'], tags: ['git', 'rss'] }),
		entry({ formatId: 'c', name: { de: 'Gamma' }, categories: ['dev'], tags: ['rss'] })
	];

	it('ODER innerhalb einer Facette (mehrere Kategorien)', () => {
		const r = filterEntries(items, { ...base, categories: ['dev', 'news'] }, 'en');
		expect(r.map((e) => e.formatId).sort()).toEqual(['a', 'b', 'c']);
	});
	it('UND über Facetten hinweg (Kategorie + Tag)', () => {
		const r = filterEntries(items, { ...base, categories: ['dev'], tags: ['rss'] }, 'en');
		expect(r.map((e) => e.formatId)).toEqual(['c']);
	});
	it('Sprachfacette: String-Name zählt als en, Map nach Schlüsseln', () => {
		expect(filterEntries(items, { ...base, langs: ['en'] }, 'en').map((e) => e.formatId).sort()).toEqual(['a', 'b']);
		expect(filterEntries(items, { ...base, langs: ['de'] }, 'en').map((e) => e.formatId).sort()).toEqual(['a', 'c']);
	});
	it('Freitext über aufgelösten Namen', () => {
		expect(filterEntries(items, { ...base, q: 'alfa' }, 'de').map((e) => e.formatId)).toEqual(['a']);
	});
	it('Freitext über formatId', () => {
		expect(filterEntries(items, { ...base, q: 'b' }, 'en').map((e) => e.formatId).sort()).toEqual(['b']);
	});
	it('type filtert', () => {
		const mixed = [entry({ formatId: 'm', type: 'menu' }), entry({ formatId: 'e', type: 'engine' })];
		expect(filterEntries(mixed, { ...base, type: 'engine' }, 'en').map((e) => e.formatId)).toEqual(['e']);
	});
	it('site filtert über Domains (Teilstring)', () => {
		const d = [entry({ formatId: 'd', domains: ['shop.example.com'] })];
		expect(filterEntries(d, { ...base, site: 'example' }, 'en')).toHaveLength(1);
		expect(filterEntries(d, { ...base, site: 'other' }, 'en')).toHaveLength(0);
	});
});

describe('sortEntries', () => {
	const items = [
		entry({ formatId: 'old', updatedAt: '2026-01-01T00:00:00Z', installCount: 5, rating: { average: 4.5, count: 2 } }),
		entry({ formatId: 'new', updatedAt: '2026-06-01T00:00:00Z', installCount: 1, rating: { average: null, count: 0 } }),
		entry({ formatId: 'mid', updatedAt: '2026-03-01T00:00:00Z', installCount: 9, rating: { average: 3.0, count: 8 } })
	];
	it('newest nach updatedAt absteigend', () => {
		expect(sortEntries(items, 'newest').map((e) => e.formatId)).toEqual(['new', 'mid', 'old']);
	});
	it('installs nach installCount absteigend', () => {
		expect(sortEntries(items, 'installs').map((e) => e.formatId)).toEqual(['mid', 'old', 'new']);
	});
	it('best nach rating.average, Einträge ohne Bewertung nach hinten', () => {
		expect(sortEntries(items, 'best').map((e) => e.formatId)).toEqual(['old', 'mid', 'new']);
	});
	it('mutiert das Eingabe-Array nicht', () => {
		const copy = [...items];
		sortEntries(items, 'installs');
		expect(items.map((e) => e.formatId)).toEqual(copy.map((e) => e.formatId));
	});
});

describe('Facetten-Ableitung', () => {
	const items = [
		entry({ name: { en: 'A', de: 'A' }, tags: ['git', 'rss'], categories: ['dev'] }),
		entry({ name: 'B', tags: ['git'], categories: ['dev', 'news'] }),
		entry({ name: { de: 'C' }, tags: [], categories: ['news'] })
	];
	it('languageFacet zählt Sprachen', () => {
		expect(languageFacet(items)).toEqual(
			expect.arrayContaining([
				{ value: 'de', count: 2 },
				{ value: 'en', count: 2 }
			])
		);
	});
	it('tagFacet zählt Tags absteigend', () => {
		expect(tagFacet(items)).toEqual([
			{ value: 'git', count: 2 },
			{ value: 'rss', count: 1 }
		]);
	});
	it('categoryFacet zählt nur präsente Kategorien', () => {
		const f = categoryFacet(items);
		expect(f).toEqual(
			expect.arrayContaining([
				{ value: 'dev', count: 2 },
				{ value: 'news', count: 2 }
			])
		);
		expect(f.find((o) => o.value === 'shopping')).toBeUndefined();
	});
});

describe('optionCount', () => {
	const items = [
		entry({ formatId: 'a', categories: ['dev'], tags: ['git'] }),
		entry({ formatId: 'b', categories: ['dev'], tags: ['rss'] }),
		entry({ formatId: 'c', categories: ['news'], tags: ['git'] })
	];
	it('zählt eine Option unter Anwendung der übrigen Facetten, eigene ausgenommen', () => {
		// tag=git aktiv; Anzahl der category=dev-Option ignoriert die category-Facette selbst,
		// wendet aber tag=git an: a (dev,git) trifft, c (news,git) nicht dev -> 1.
		const filter: OnepagerFilter = { ...base, tags: ['git'], categories: ['news'] };
		expect(optionCount(items, filter, 'categories', 'dev', 'en')).toBe(1);
	});
});
```

- [ ] **Step 6: Test ausführen, Fehlschlag bestätigen**

Run: `npm --prefix frontend run test -- --run src/lib/facets.test.ts`
Expected: FAIL (`Cannot find module './facets'`).

- [ ] **Step 7: facets.ts implementieren**

Create `frontend/src/lib/facets.ts`:

```ts
import type { EntryListItem } from './api';
import type { OnepagerFilter, OnepagerSort } from './browse-state';
import { resolveLocalized, entryLanguages } from './localized';
import { CATEGORIES } from './categories';

export interface FacetOption {
	value: string;
	count: number;
}

type Dimension = 'categories' | 'tags' | 'langs';

function hasIntersection(a: string[], b: string[]): boolean {
	return a.some((x) => b.includes(x));
}

/** Prüft, ob ein Eintrag den Filter erfüllt (ODER innerhalb, UND über Facetten). */
export function entryMatchesFilter(
	item: EntryListItem,
	filter: OnepagerFilter,
	locale: string
): boolean {
	if (filter.type && item.type !== filter.type) return false;
	if (filter.categories.length && !hasIntersection(item.categories, filter.categories)) return false;
	if (filter.tags.length && !hasIntersection(item.tags, filter.tags)) return false;
	if (filter.langs.length && !hasIntersection(entryLanguages(item.name), filter.langs)) return false;
	if (filter.site) {
		const needle = filter.site.toLowerCase();
		if (!item.domains.some((d) => d.toLowerCase().includes(needle))) return false;
	}
	if (filter.q) {
		const needle = filter.q.toLowerCase();
		const haystack = [
			resolveLocalized(item.name, locale),
			resolveLocalized(item.description, locale),
			item.formatId
		]
			.join(' ')
			.toLowerCase();
		if (!haystack.includes(needle)) return false;
	}
	return true;
}

export function filterEntries(
	items: EntryListItem[],
	filter: OnepagerFilter,
	locale: string
): EntryListItem[] {
	return items.filter((item) => entryMatchesFilter(item, filter, locale));
}

/** Sortiert (kopiert, mutiert die Eingabe nicht). */
export function sortEntries(items: EntryListItem[], sort: OnepagerSort): EntryListItem[] {
	const copy = [...items];
	if (sort === 'installs') {
		copy.sort((a, b) => b.installCount - a.installCount);
	} else if (sort === 'best') {
		copy.sort((a, b) => {
			const av = a.rating.average;
			const bv = b.rating.average;
			if (av === null && bv === null) return 0;
			if (av === null) return 1; // ohne Bewertung nach hinten
			if (bv === null) return -1;
			if (bv !== av) return bv - av;
			return b.rating.count - a.rating.count;
		});
	} else {
		copy.sort((a, b) => b.updatedAt.localeCompare(a.updatedAt));
	}
	return copy;
}

function countBy(items: EntryListItem[], values: (i: EntryListItem) => string[]): Map<string, number> {
	const m = new Map<string, number>();
	for (const item of items) {
		for (const v of values(item)) m.set(v, (m.get(v) ?? 0) + 1);
	}
	return m;
}

export function languageFacet(items: EntryListItem[]): FacetOption[] {
	const m = countBy(items, (i) => entryLanguages(i.name));
	return [...m.entries()].map(([value, count]) => ({ value, count })).sort((a, b) => b.count - a.count);
}

export function tagFacet(items: EntryListItem[]): FacetOption[] {
	const m = countBy(items, (i) => i.tags);
	return [...m.entries()].map(([value, count]) => ({ value, count })).sort((a, b) => b.count - a.count);
}

/** Kategorie-Facette in fester CATEGORIES-Reihenfolge, nur präsente Werte. */
export function categoryFacet(items: EntryListItem[]): FacetOption[] {
	const m = countBy(items, (i) => i.categories);
	return CATEGORIES.filter((c) => m.has(c)).map((c) => ({ value: c, count: m.get(c)! }));
}

function dimensionValues(item: EntryListItem, dimension: Dimension): string[] {
	if (dimension === 'categories') return item.categories;
	if (dimension === 'tags') return item.tags;
	return entryLanguages(item.name);
}

/**
 * Anzahl der Einträge, die die Option `value` in `dimension` träfe – unter
 * Anwendung der übrigen Facetten, die eigene ausgenommen (Standard-Faceted-Search).
 */
export function optionCount(
	items: EntryListItem[],
	filter: OnepagerFilter,
	dimension: Dimension,
	value: string,
	locale: string
): number {
	const without: OnepagerFilter = { ...filter, [dimension]: [] } as OnepagerFilter;
	return items.filter(
		(item) => entryMatchesFilter(item, without, locale) && dimensionValues(item, dimension).includes(value)
	).length;
}
```

- [ ] **Step 8: facets-Test grün**

Run: `npm --prefix frontend run test -- --run src/lib/facets.test.ts`
Expected: PASS.

- [ ] **Step 9: Failing test für catalog.ts schreiben**

Create `frontend/src/lib/catalog.test.ts`:

```ts
import { describe, it, expect, vi } from 'vitest';
import { loadCatalog } from './catalog';
import type { EntryListItem, EntryListResponse, EntryQuery } from './api';

function item(id: string): EntryListItem {
	return {
		formatId: id,
		type: 'menu',
		name: id,
		description: null,
		categories: [],
		tags: [],
		domains: [],
		installCount: 0,
		rating: { average: null, count: 0 },
		currentVersion: '1.0.0',
		deprecated: false,
		successorFormatId: null,
		screenshotUrl: null,
		updatedAt: '2026-01-01T00:00:00Z'
	};
}

/** Fake-list mit `total` Einträgen, perPage-Seiten. */
function fakeList(total: number) {
	return vi.fn(async (q: EntryQuery): Promise<EntryListResponse> => {
		const perPage = q.perPage ?? 50;
		const page = q.page ?? 1;
		const start = (page - 1) * perPage;
		const items = Array.from({ length: Math.max(0, Math.min(perPage, total - start)) }, (_, i) =>
			item(`e${start + i}`)
		);
		return { items, page, perPage, total };
	});
}

describe('loadCatalog', () => {
	it('lädt alle Seiten und meldet Fortschritt + Abschluss', async () => {
		const list = fakeList(120);
		const batches: number[] = [];
		let completed = false;
		await loadCatalog(
			{
				onBatch: (_items, loaded) => batches.push(loaded),
				onComplete: () => (completed = true),
				onError: () => {}
			},
			{ perPage: 50, initialLoad: 1000, list }
		);
		expect(completed).toBe(true);
		expect(batches.at(-1)).toBe(120);
		expect(list).toHaveBeenCalledTimes(3); // 50 + 50 + 20
	});

	it('meldet Fehler und ruft onComplete nicht', async () => {
		const list = vi.fn(async () => {
			throw new Error('boom');
		});
		let error: string | null = null;
		let completed = false;
		await loadCatalog(
			{ onBatch: () => {}, onComplete: () => (completed = true), onError: (m) => (error = m) },
			{ perPage: 50, list }
		);
		expect(error).toBe('boom');
		expect(completed).toBe(false);
	});

	it('bricht bei abort ab', async () => {
		const list = fakeList(500);
		const controller = new AbortController();
		controller.abort();
		let completed = false;
		await loadCatalog(
			{ onBatch: () => {}, onComplete: () => (completed = true), onError: () => {} },
			{ perPage: 50, list, signal: controller.signal }
		);
		expect(completed).toBe(false);
		expect(list).not.toHaveBeenCalled();
	});
});
```

- [ ] **Step 10: Test ausführen, Fehlschlag bestätigen**

Run: `npm --prefix frontend run test -- --run src/lib/catalog.test.ts`
Expected: FAIL (`Cannot find module './catalog'`).

- [ ] **Step 11: catalog.ts implementieren**

Create `frontend/src/lib/catalog.ts`:

```ts
import { env } from '$env/dynamic/public';
import { listEntries, type EntryListItem, type EntryListResponse, type EntryQuery, type ClientOpts } from './api';

const PER_PAGE = 50;

/** Erstlade-Obergrenze (Default 1000, in 100er-Schritten per Env reduzierbar). */
export const INITIAL_LOAD = Math.max(100, Number(env.PUBLIC_INDEX_INITIAL_LOAD) || 1000);

export interface CatalogCallbacks {
	/** Nach jeder geladenen Seite: neue Items dieser Seite, insgesamt geladen, Gesamtzahl. */
	onBatch: (items: EntryListItem[], loaded: number, total: number) => void;
	/** Katalog vollständig geladen. */
	onComplete: () => void;
	/** Ladefehler (bereits geladene Items bleiben nutzbar). */
	onError: (message: string) => void;
}

export interface CatalogOptions {
	initialLoad?: number;
	perPage?: number;
	/** Injizierbar für Tests; Default listEntries. */
	list?: (query: EntryQuery, opts?: ClientOpts) => Promise<EntryListResponse>;
	signal?: AbortSignal;
}

/**
 * Lädt den Katalog progressiv: erste Seite bestimmt total; bis initialLoad
 * eifrig, der Rest sequenziell nachgeladen. Jede Seite meldet onBatch;
 * onComplete am Ende, onError bei Fehlschlag.
 */
export async function loadCatalog(cb: CatalogCallbacks, options: CatalogOptions = {}): Promise<void> {
	const perPage = options.perPage ?? PER_PAGE;
	const initialLoad = options.initialLoad ?? INITIAL_LOAD;
	const list = options.list ?? listEntries;
	const signal = options.signal;

	if (signal?.aborted) return;

	try {
		let loaded = 0;
		const first = await list({ page: 1, perPage, sort: 'newest' });
		if (signal?.aborted) return;
		loaded += first.items.length;
		cb.onBatch(first.items, loaded, first.total);

		const totalPages = Math.max(1, Math.ceil(first.total / perPage));
		for (let page = 2; page <= totalPages; page++) {
			if (signal?.aborted) return;
			const res = await list({ page, perPage, sort: 'newest' });
			if (signal?.aborted) return;
			loaded += res.items.length;
			cb.onBatch(res.items, loaded, first.total);
			// initialLoad steuert nur, wie viel als "eifrig" gilt; wir laden hier
			// bis zur Vollständigkeit sequenziell weiter (der Rest = Lazy-Phase).
			void initialLoad;
		}
		if (signal?.aborted) return;
		cb.onComplete();
	} catch (e) {
		cb.onError(e instanceof Error ? e.message : String(e));
	}
}
```

Hinweis: `initialLoad` ist in dieser A-Version die dokumentierte, per Env konfigurierbare Grenze für die eifrige Phase; da der Loader ohnehin bis zur Vollständigkeit nachlädt, dient sie als Schwelle für die Fortschrittsanzeige (»lädt weitere …« ab loaded ≥ initialLoad) – die Seite (Task 5) wertet sie aus. Der Wert bleibt zentral in `INITIAL_LOAD`.

- [ ] **Step 12: catalog-Test grün + voller Check**

Run: `npm --prefix frontend run test -- --run src/lib/catalog.test.ts src/lib/facets.test.ts src/lib/browse-state.test.ts && npm --prefix frontend run check`
Expected: PASS; `svelte-check` 0 Fehler.

- [ ] **Step 13: Commit**

```bash
git add frontend/src/lib/catalog.ts frontend/src/lib/catalog.test.ts frontend/src/lib/facets.ts frontend/src/lib/facets.test.ts frontend/src/lib/browse-state.ts frontend/src/lib/browse-state.test.ts
git commit -m "$(cat <<'EOF'
Ergaenze Katalog-Loader, Facetten-Logik und Onepager-URL-State

Reine, unit-getestete Bausteine des Onepagers: progressiver Loader
(perPage=50 bis zur Vollstaendigkeit, INITIAL_LOAD per Env), Facetten
(Filtern ODER-innerhalb/UND-ueber, Sortierung inkl. beste-Bewertung mit
unbewertet-nach-hinten, dynamische Sprach-/Tag-/Kategorie-Facette,
Optionszaehler mit eigener Facette ausgenommen) und CSV-Mehrfachwert-
URL-State. Noch ohne UI.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: StarRating-Komponente & persistenter Sammelkorb-Store

**Files:**
- Create: `frontend/src/lib/components/StarRating.svelte`, `frontend/src/lib/components/StarRating.test.ts`
- Create: `frontend/src/lib/basket.svelte.ts`, `frontend/src/lib/basket.test.ts`
- Modify: `frontend/messages/en.json`, `frontend/messages/de.json`

**Interfaces:**
- Consumes: `getLocale` (paraglide), Lucide `Star`.
- Produces:
  - `StarRating.svelte` – Props `{ average: number | null; count: number }`.
  - `basket.svelte.ts` – `basket` mit `ids: string[]` (getter), `count` (getter), `has(id)`, `toggle(id)`, `remove(id)`, `clear()`, `reconcile(valid: Set<string>)`.
  - i18n-Keys: `rating_none`, `rating_count` (Parameter `{count}`).

- [ ] **Step 1: i18n-Keys für Sterne ergänzen (beide Dateien)**

In `frontend/messages/en.json` ergänzen:

```json
	"rating_none": "Not yet rated",
	"rating_count": "{count} ratings",
```

In `frontend/messages/de.json` ergänzen:

```json
	"rating_none": "Noch nicht bewertet",
	"rating_count": "{count} Bewertungen",
```

(Beim Einfügen auf gültiges JSON achten – Komma-Trennung; Reihenfolge egal.)

- [ ] **Step 2: Failing test für StarRating schreiben**

Create `frontend/src/lib/components/StarRating.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/svelte';
import StarRating from './StarRating.svelte';

describe('StarRating', () => {
	it('zeigt "Noch nicht bewertet" bei count 0', () => {
		render(StarRating, { average: null, count: 0 });
		expect(screen.getByText(/not yet rated|noch nicht bewertet/i)).toBeInTheDocument();
	});
	it('zeigt Wert und Anzahl bei vorhandener Bewertung', () => {
		render(StarRating, { average: 4.3, count: 12 });
		// locale-formatierter Wert (4.3 / 4,3) taucht auf
		expect(screen.getByText(/4[.,]3/)).toBeInTheDocument();
		expect(screen.getByText(/12/)).toBeInTheDocument();
	});
	it('rendert fünf Stern-Icons', () => {
		const { container } = render(StarRating, { average: 3, count: 5 });
		expect(container.querySelectorAll('svg').length).toBe(5);
	});
});
```

- [ ] **Step 3: Test ausführen, Fehlschlag bestätigen**

Run: `npm --prefix frontend run test -- --run src/lib/components/StarRating.test.ts`
Expected: FAIL (`Cannot find module './StarRating.svelte'`).

- [ ] **Step 4: StarRating.svelte implementieren**

Create `frontend/src/lib/components/StarRating.svelte`:

```svelte
<script lang="ts">
	import { Star } from '@lucide/svelte';
	import { getLocale } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';

	let { average, count }: { average: number | null; count: number } = $props();

	const rounded = $derived(average === null ? 0 : Math.round(average));
	const valueLabel = $derived(
		average === null
			? ''
			: average.toLocaleString(getLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 })
	);
</script>

{#if count === 0}
	<span class="rating-none">{m.rating_none()}</span>
{:else}
	<span class="rating" title={m.rating_count({ count })}>
		<span class="stars" aria-hidden="true">
			{#each [1, 2, 3, 4, 5] as i}
				<Star size={14} fill={i <= rounded ? 'currentColor' : 'none'} />
			{/each}
		</span>
		<span class="rating-value">{valueLabel}</span>
		<span class="rating-count">· {count}</span>
	</span>
{/if}

<style>
	.rating {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		font-size: 0.85em;
	}
	.stars {
		display: inline-flex;
		color: var(--accent-color, #5b9cf6);
	}
	.rating-count,
	.rating-none {
		color: var(--text-muted);
		font-size: 0.85em;
	}
</style>
```

- [ ] **Step 5: StarRating-Test grün**

Run: `npm --prefix frontend run test -- --run src/lib/components/StarRating.test.ts`
Expected: PASS.

- [ ] **Step 6: Failing test für basket-Store schreiben**

Create `frontend/src/lib/basket.test.ts`:

```ts
import { describe, it, expect, beforeEach } from 'vitest';
import { basket } from './basket.svelte';

beforeEach(() => {
	localStorage.clear();
	basket.clear();
});

describe('basket', () => {
	it('toggle fügt hinzu und entfernt', () => {
		expect(basket.has('a')).toBe(false);
		basket.toggle('a');
		expect(basket.has('a')).toBe(true);
		expect(basket.count).toBe(1);
		basket.toggle('a');
		expect(basket.has('a')).toBe(false);
		expect(basket.count).toBe(0);
	});
	it('remove und clear', () => {
		basket.toggle('a');
		basket.toggle('b');
		basket.remove('a');
		expect(basket.ids).toEqual(['b']);
		basket.clear();
		expect(basket.count).toBe(0);
	});
	it('persistiert nach localStorage', () => {
		basket.toggle('x');
		expect(JSON.parse(localStorage.getItem('gestura-basket') ?? '[]')).toEqual(['x']);
	});
	it('reconcile entfernt nicht mehr vorhandene IDs', () => {
		basket.toggle('a');
		basket.toggle('b');
		basket.reconcile(new Set(['a']));
		expect(basket.ids).toEqual(['a']);
	});
});
```

- [ ] **Step 7: Test ausführen, Fehlschlag bestätigen**

Run: `npm --prefix frontend run test -- --run src/lib/basket.test.ts`
Expected: FAIL (`Cannot find module './basket.svelte'`).

- [ ] **Step 8: basket.svelte.ts implementieren**

Create `frontend/src/lib/basket.svelte.ts`:

```ts
import { browser } from '$app/environment';

const KEY = 'gestura-basket';

function loadInitial(): string[] {
	if (!browser) return [];
	try {
		const raw = localStorage.getItem(KEY);
		if (!raw) return [];
		const parsed = JSON.parse(raw);
		return Array.isArray(parsed) ? parsed.filter((x): x is string => typeof x === 'string') : [];
	} catch {
		return [];
	}
}

let ids = $state<string[]>(loadInitial());

function persist() {
	if (!browser) return;
	try {
		localStorage.setItem(KEY, JSON.stringify(ids));
	} catch {
		/* Speicher voll / privat – Auswahl bleibt für die Sitzung im State. */
	}
}

/** Persistenter Auswahl-Store (Menge von formatId). */
export const basket = {
	get ids(): string[] {
		return ids;
	},
	get count(): number {
		return ids.length;
	},
	has(id: string): boolean {
		return ids.includes(id);
	},
	toggle(id: string): void {
		ids = ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id];
		persist();
	},
	remove(id: string): void {
		ids = ids.filter((x) => x !== id);
		persist();
	},
	clear(): void {
		ids = [];
		persist();
	},
	/** Entfernt IDs, die nicht mehr im Katalog sind. */
	reconcile(valid: Set<string>): void {
		const next = ids.filter((id) => valid.has(id));
		if (next.length !== ids.length) {
			ids = next;
			persist();
		}
	}
};
```

- [ ] **Step 9: basket-Test grün + voller Check**

Run: `npm --prefix frontend run test -- --run src/lib/basket.test.ts && npm --prefix frontend run check`
Expected: PASS; `svelte-check` 0 Fehler.

- [ ] **Step 10: Commit**

```bash
git add frontend/src/lib/components/StarRating.svelte frontend/src/lib/components/StarRating.test.ts frontend/src/lib/basket.svelte.ts frontend/src/lib/basket.test.ts frontend/messages/en.json frontend/messages/de.json
git commit -m "$(cat <<'EOF'
Ergaenze StarRating-Anzeige und persistenten Sammelkorb-Store

StarRating zeigt fuenf Lucide-Sterne (gefuellt bis Math.round(average)),
den locale-formatierten Wert und die Anzahl; count 0 => "Noch nicht
bewertet". Der basket-Store haelt die Auswahl (Menge von formatId) in
localStorage, uebersteht Reloads und raeumt via reconcile nicht mehr
vorhandene Eintraege ab.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: EntryBlock-Komponente (Block + Aufklappen + Reviews)

**Files:**
- Create: `frontend/src/lib/components/EntryBlock.svelte`, `frontend/src/lib/components/EntryBlock.test.ts`
- Modify: `frontend/messages/en.json`, `frontend/messages/de.json`

**Interfaces:**
- Consumes: `EntryListItem`, `EntryDetail`, `getEntry`, `listReviews`, `ReviewItem` (api.ts); `resolveLocalized`, `entryLanguages` (localized.ts); `categoryLabel` (categories.ts); `basket` (basket.svelte.ts); `StarRating`, `Badge` (components); `getLocale`, `localizeHref` (paraglide); Lucide `ChevronDown`, `Check`, `Plus`, `TriangleAlert`.
- Produces: `EntryBlock.svelte` – Props `{ entry: EntryListItem; open?: boolean }` (`open` erlaubt Auto-Expand aus der Highlight-Logik in Task 7).

- [ ] **Step 1: i18n-Keys für Block/Reviews ergänzen (beide Dateien)**

In `frontend/messages/en.json`:

```json
	"block_details": "Details",
	"block_add": "Add to selection",
	"block_remove": "Remove from selection",
	"block_show_reviews": "Show reviews",
	"block_reviews_empty": "No reviews yet",
	"block_reviews_error": "Could not load reviews",
	"block_screenshot_alt": "Screenshot of {name}",
	"block_versions": "Versions",
	"block_domains": "Sites",
```

In `frontend/messages/de.json`:

```json
	"block_details": "Details",
	"block_add": "Zur Auswahl hinzufügen",
	"block_remove": "Aus Auswahl entfernen",
	"block_show_reviews": "Bewertungen anzeigen",
	"block_reviews_empty": "Noch keine Bewertungen",
	"block_reviews_error": "Bewertungen konnten nicht geladen werden",
	"block_screenshot_alt": "Screenshot von {name}",
	"block_versions": "Versionen",
	"block_domains": "Seiten",
```

- [ ] **Step 2: Failing test für EntryBlock schreiben**

Create `frontend/src/lib/components/EntryBlock.test.ts`:

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import EntryBlock from './EntryBlock.svelte';
import type { EntryListItem, EntryDetail, ReviewListResponse } from '$lib/api';
import { basket } from '$lib/basket.svelte';

const getEntry = vi.fn();
const listReviews = vi.fn();
vi.mock('$lib/api', async (orig) => {
	const actual = await orig<typeof import('$lib/api')>();
	return { ...actual, getEntry: (...a: unknown[]) => getEntry(...a), listReviews: (...a: unknown[]) => listReviews(...a) };
});

const entry: EntryListItem = {
	formatId: 'com.example.menu',
	type: 'menu',
	name: { en: 'Example', de: 'Beispiel' },
	description: { en: 'A sample', de: 'Ein Beispiel' },
	categories: ['dev'],
	tags: ['git'],
	domains: ['example.com'],
	installCount: 42,
	rating: { average: 4.5, count: 8 },
	currentVersion: '1.0.0',
	deprecated: false,
	successorFormatId: null,
	screenshotUrl: null,
	updatedAt: '2026-07-01T00:00:00Z'
};

const detail: EntryDetail = {
	...entry,
	versions: [{ semver: '1.0.0', changelog: 'Initial', hasTransformCode: false, submittedAt: '2026-07-01T00:00:00Z' }]
};

beforeEach(() => {
	localStorage.clear();
	basket.clear();
	getEntry.mockReset();
	listReviews.mockReset();
});

describe('EntryBlock', () => {
	it('zeigt aufgelösten Namen, Sterne und Install-Zähler', () => {
		render(EntryBlock, { entry });
		expect(screen.getByText('Example')).toBeInTheDocument(); // Test-Locale = en (baseLocale)
		expect(screen.getByText(/4[.,]5/)).toBeInTheDocument();
		expect(screen.getByText(/42/)).toBeInTheDocument();
	});

	it('Auswahl-Toggle legt in den Korb und wieder heraus', async () => {
		render(EntryBlock, { entry });
		const toggle = screen.getByRole('button', { name: /add to selection|zur auswahl/i });
		await fireEvent.click(toggle);
		expect(basket.has('com.example.menu')).toBe(true);
	});

	it('lädt Details erst beim Aufklappen', async () => {
		getEntry.mockResolvedValue(detail);
		render(EntryBlock, { entry });
		expect(getEntry).not.toHaveBeenCalled();
		await fireEvent.click(screen.getByRole('button', { name: /details/i }));
		await waitFor(() => expect(getEntry).toHaveBeenCalledWith('com.example.menu'));
		await waitFor(() => expect(screen.getByText('1.0.0')).toBeInTheDocument());
	});

	it('lädt Reviews erst on demand', async () => {
		getEntry.mockResolvedValue(detail);
		const reviews: ReviewListResponse = {
			items: [{ stars: 5, comment: 'Great', createdAt: '2026-07-02T00:00:00Z' }],
			page: 1,
			perPage: 20,
			total: 1
		};
		listReviews.mockResolvedValue(reviews);
		render(EntryBlock, { entry });
		await fireEvent.click(screen.getByRole('button', { name: /details/i }));
		await waitFor(() => expect(getEntry).toHaveBeenCalled());
		expect(listReviews).not.toHaveBeenCalled();
		await fireEvent.click(screen.getByRole('button', { name: /show reviews|bewertungen anzeigen/i }));
		await waitFor(() => expect(listReviews).toHaveBeenCalledWith('com.example.menu', 1));
		await waitFor(() => expect(screen.getByText('Great')).toBeInTheDocument());
	});
});
```

- [ ] **Step 3: Test ausführen, Fehlschlag bestätigen**

Run: `npm --prefix frontend run test -- --run src/lib/components/EntryBlock.test.ts`
Expected: FAIL (`Cannot find module './EntryBlock.svelte'`).

- [ ] **Step 4: EntryBlock.svelte implementieren**

Create `frontend/src/lib/components/EntryBlock.svelte`:

```svelte
<script lang="ts">
	import {
		getEntry,
		listReviews,
		type EntryListItem,
		type EntryDetail,
		type ReviewItem
	} from '$lib/api';
	import { resolveLocalized, entryLanguages } from '$lib/localized';
	import { categoryLabel } from '$lib/categories';
	import { basket } from '$lib/basket.svelte';
	import { getLocale } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import Badge from './Badge.svelte';
	import StarRating from './StarRating.svelte';
	import Spinner from './Spinner.svelte';
	import { ChevronDown, Check, Plus, TriangleAlert } from '@lucide/svelte';

	let { entry, open = false }: { entry: EntryListItem; open?: boolean } = $props();

	let expanded = $state(open);
	let detail = $state<EntryDetail | null>(null);
	let detailLoading = $state(false);
	let detailError = $state<string | null>(null);

	let reviewsOpen = $state(false);
	let reviews = $state<ReviewItem[] | null>(null);
	let reviewsLoading = $state(false);
	let reviewsError = $state<string | null>(null);

	const locale = $derived(getLocale());
	const displayName = $derived(resolveLocalized(entry.name, locale) || entry.formatId);
	const displayDesc = $derived(resolveLocalized(entry.description, locale));
	const langs = $derived(entryLanguages(entry.name));
	const selected = $derived(basket.has(entry.formatId));
	const typeLabel = $derived(entry.type === 'menu' ? m.type_menu() : m.type_engine());

	async function toggleExpand() {
		expanded = !expanded;
		if (expanded && detail === null && !detailLoading) {
			detailLoading = true;
			detailError = null;
			try {
				detail = await getEntry(entry.formatId);
			} catch (e) {
				detailError = e instanceof Error ? e.message : String(e);
			} finally {
				detailLoading = false;
			}
		}
	}

	async function toggleReviews() {
		reviewsOpen = !reviewsOpen;
		if (reviewsOpen && reviews === null && !reviewsLoading) {
			reviewsLoading = true;
			reviewsError = null;
			try {
				const res = await listReviews(entry.formatId, 1);
				reviews = res.items;
			} catch (e) {
				reviewsError = e instanceof Error ? e.message : String(e);
			} finally {
				reviewsLoading = false;
			}
		}
	}

	// Auto-Expand, wenn open-Prop zur Laufzeit true wird (Highlight aus Task 7).
	$effect(() => {
		if (open && !expanded) toggleExpand();
	});
</script>

<article class="card block" class:selected>
	<div class="block-main">
		<button class="block-select" onclick={() => basket.toggle(entry.formatId)}
			aria-pressed={selected}
			aria-label={selected ? m.block_remove() : m.block_add()}
			title={selected ? m.block_remove() : m.block_add()}>
			{#if selected}<Check size={18} />{:else}<Plus size={18} />{/if}
		</button>
		<div class="block-body">
			<div class="block-head">
				<strong>{displayName}</strong>
				<Badge text={typeLabel} />
				{#if entry.deprecated}<Badge text={m.badge_deprecated()} variant="warning" />{/if}
			</div>
			{#if displayDesc}<p class="block-desc">{displayDesc}</p>{/if}
			<div class="block-tags">
				{#each entry.categories.slice(0, 3) as cat}<Badge text={categoryLabel(cat)} />{/each}
				{#each langs as lang}<Badge text={lang.toUpperCase()} />{/each}
			</div>
			<div class="block-foot">
				<StarRating average={entry.rating.average} count={entry.rating.count} />
				<span class="installs">{entry.installCount} {m.installs()}</span>
				<button class="block-details" onclick={toggleExpand} aria-expanded={expanded}>
					{m.block_details()} <ChevronDown size={14} class={expanded ? 'flip' : ''} />
				</button>
			</div>
		</div>
	</div>

	{#if expanded}
		<div class="block-expand">
			{#if detailLoading}
				<Spinner />
			{:else if detailError}
				<p class="err">{detailError}</p>
			{:else if detail}
				{#if detail.screenshotUrl}
					<img class="shot" src={detail.screenshotUrl} alt={m.block_screenshot_alt({ name: displayName })} loading="lazy" />
				{/if}
				{#if detail.domains.length}
					<p><strong>{m.block_domains()}:</strong> {detail.domains.join(', ')}</p>
				{/if}
				<h4>{m.block_versions()}</h4>
				<ul class="versions">
					{#each detail.versions as v (v.semver)}
						<li>
							<strong>{v.semver}</strong>
							{#if v.hasTransformCode}<Badge text={m.badge_transform()} variant="warning" icon={TriangleAlert} />{/if}
							<span class="muted">{new Date(v.submittedAt).toLocaleDateString(locale)}</span>
							{#if v.changelog}<div class="changelog">{v.changelog}</div>{/if}
						</li>
					{/each}
				</ul>

				<button class="block-details" onclick={toggleReviews} aria-expanded={reviewsOpen}>
					{m.block_show_reviews()} <ChevronDown size={14} class={reviewsOpen ? 'flip' : ''} />
				</button>
				{#if reviewsOpen}
					{#if reviewsLoading}
						<Spinner />
					{:else if reviewsError}
						<p class="err">{m.block_reviews_error()}</p>
					{:else if reviews && reviews.length}
						<ul class="reviews">
							{#each reviews as r, i (i)}
								<li>
									<StarRating average={r.stars} count={1} />
									{#if r.comment}<p>{r.comment}</p>{/if}
									<span class="muted">{new Date(r.createdAt).toLocaleDateString(locale)}</span>
								</li>
							{/each}
						</ul>
					{:else}
						<p class="muted">{m.block_reviews_empty()}</p>
					{/if}
				{/if}
			{/if}
		</div>
	{/if}
</article>

<style>
	.block {
		display: flex;
		flex-direction: column;
		gap: 10px;
	}
	.block.selected {
		outline: 2px solid var(--accent-color, #5b9cf6);
	}
	.block-main {
		display: flex;
		gap: 12px;
		align-items: flex-start;
	}
	.block-select {
		flex: 0 0 auto;
		width: 36px;
		height: 36px;
		border-radius: 10px;
		border: 1px solid var(--border-color);
		background: transparent;
		color: inherit;
		cursor: pointer;
		display: flex;
		align-items: center;
		justify-content: center;
	}
	.block.selected .block-select {
		background: var(--accent-color, #5b9cf6);
		color: #fff;
		border-color: transparent;
	}
	.block-body {
		flex: 1 1 auto;
		min-width: 0;
	}
	.block-head {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
	}
	.block-desc {
		color: var(--text-secondary);
		display: -webkit-box;
		-webkit-line-clamp: 2;
		line-clamp: 2;
		-webkit-box-orient: vertical;
		overflow: hidden;
		margin: 4px 0;
	}
	.block-tags {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}
	.block-foot {
		display: flex;
		align-items: center;
		gap: 12px;
		flex-wrap: wrap;
		margin-top: 6px;
	}
	.installs {
		color: var(--text-muted);
		font-size: 0.85em;
	}
	.block-details {
		margin-left: auto;
		background: transparent;
		border: none;
		color: var(--accent-color, #5b9cf6);
		cursor: pointer;
		display: inline-flex;
		align-items: center;
		gap: 4px;
	}
	:global(.block-details .flip) {
		transform: rotate(180deg);
	}
	.block-expand {
		border-top: 1px solid var(--border-color);
		padding-top: 10px;
	}
	.shot {
		max-width: 100%;
		border-radius: 12px;
		border: 1px solid var(--border-color);
	}
	.versions,
	.reviews {
		list-style: none;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.changelog {
		color: var(--text-secondary);
	}
	.muted {
		color: var(--text-muted);
		font-size: 0.85em;
	}
	.err {
		color: var(--danger-color, #e5484d);
	}
</style>
```

- [ ] **Step 5: EntryBlock-Test grün + voller Check**

Run: `npm --prefix frontend run test -- --run src/lib/components/EntryBlock.test.ts && npm --prefix frontend run check`
Expected: PASS; `svelte-check` 0 Fehler.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/lib/components/EntryBlock.svelte frontend/src/lib/components/EntryBlock.test.ts frontend/messages/en.json frontend/messages/de.json
git commit -m "$(cat <<'EOF'
Ergaenze EntryBlock: kompakter Block mit Aufklappen und Reviews

Der Block loest Name/Beschreibung zur Anzeige-Sprache auf, zeigt inline
Sterne, Sprach- und Kategorie-Badges und einen Auswahl-Toggle (Korb).
Details (Versionen, Screenshot, Domains) werden erst beim Aufklappen via
getEntry geladen; Reviews sind eine zweite, nachladende Ebene ueber
listReviews. Kein Detailseiten-Routing mehr.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Onepager-Wurzel (Seite, Facetten-UI, URL-State, Laden)

**Files:**
- Create: `frontend/src/routes/(public)/+page.ts`
- Modify: `frontend/src/routes/(public)/+page.svelte` (Hero → Onepager)
- Create: `frontend/src/routes/(public)/onepager.test.ts`
- Modify: `frontend/messages/en.json`, `frontend/messages/de.json`

**Interfaces:**
- Consumes: `loadCatalog`, `INITIAL_LOAD` (catalog.ts); `filterEntries`, `sortEntries`, `languageFacet`, `tagFacet`, `categoryFacet`, `optionCount` (facets.ts); `parseOnepagerFilter`, `onepagerSearchParams`, `OnepagerFilter`, `OnepagerSort`, `Sequence`, `debounce` (browse-state.ts); `EntryListItem` (api.ts); `EntryBlock` (components); `getLocale`, `localizeHref` (paraglide); `page`, `goto`.
- Produces: die interaktive Wurzelseite; stellt `filtered`/`catalog`-Zustand für Task 6 (BasketTray-Einbindung) bereit.

- [ ] **Step 1: +page.ts anlegen (client-only)**

Create `frontend/src/routes/(public)/+page.ts`:

```ts
// Onepager: Katalog + Filter laufen zur Laufzeit im Client (kein Prerender).
export const prerender = false;
export const ssr = false;
```

- [ ] **Step 2: i18n-Keys für Facetten/Sortierung/Laden ergänzen (beide Dateien)**

In `frontend/messages/en.json`:

```json
	"onepager_title": "Gestura Index",
	"facet_languages": "Languages",
	"facet_tags": "Tags",
	"facet_categories": "Categories",
	"facet_more": "more",
	"facet_less": "less",
	"sort_best": "Best rated",
	"loading_more": "Loading more entries…",
	"results_of": "{shown} of {total}"
```

In `frontend/messages/de.json`:

```json
	"onepager_title": "Gestura Index",
	"facet_languages": "Sprachen",
	"facet_tags": "Tags",
	"facet_categories": "Kategorien",
	"facet_more": "mehr",
	"facet_less": "weniger",
	"sort_best": "Beste Bewertung",
	"loading_more": "Weitere Einträge werden geladen …",
	"results_of": "{shown} von {total}"
```

Hinweis: `sort_newest`, `sort_installs`, `sort_label`, `filter_type_all`, `filter_category_all`, `filter_site`, `search_placeholder`, `type_menu`, `type_engine`, `installs`, `results_count`, `state_empty_title` existieren bereits.

- [ ] **Step 3: Failing test für die Onepager-Seite schreiben**

Create `frontend/src/routes/(public)/onepager.test.ts`:

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import type { EntryListResponse, EntryQuery } from '$lib/api';

// $app/state: page mit stabiler, leer-parametrisierter URL
const url = new URL('http://localhost/en');
vi.mock('$app/state', () => ({ page: { get url() { return url; } } }));
const goto = vi.fn();
vi.mock('$app/navigation', () => ({ goto: (...a: unknown[]) => goto(...a) }));

// Katalog-Fake über die API-list
function item(id: string, over: Partial<Record<string, unknown>> = {}) {
	return {
		formatId: id, type: 'menu', name: id, description: null, categories: ['dev'],
		tags: ['git'], domains: ['example.com'], installCount: 1,
		rating: { average: null, count: 0 }, currentVersion: '1.0.0',
		deprecated: false, successorFormatId: null, screenshotUrl: null,
		updatedAt: '2026-01-01T00:00:00Z', ...over
	};
}
const listEntries = vi.fn(async (q: EntryQuery): Promise<EntryListResponse> => ({
	items: [item('a'), item('b', { categories: ['news'], tags: ['rss'] })],
	page: q.page ?? 1, perPage: 50, total: 2
}));
vi.mock('$lib/api', async (orig) => {
	const actual = await orig<typeof import('$lib/api')>();
	return { ...actual, listEntries: (...a: unknown[]) => listEntries(...a) };
});

import Page from './+page.svelte';

beforeEach(() => {
	goto.mockReset();
	localStorage.clear();
});

describe('Onepager', () => {
	it('lädt den Katalog und rendert Blöcke', async () => {
		render(Page);
		await waitFor(() => expect(screen.getByText('a')).toBeInTheDocument());
		expect(screen.getByText('b')).toBeInTheDocument();
	});
	it('filtert on-the-fly nach Kategorie', async () => {
		render(Page);
		await waitFor(() => expect(screen.getByText('a')).toBeInTheDocument());
		// news-Kategorie-Chip klicken -> nur b bleibt
		const chip = screen.getByRole('button', { name: /news/i });
		await fireEvent.click(chip);
		await waitFor(() => expect(screen.queryByText('a')).not.toBeInTheDocument());
		expect(screen.getByText('b')).toBeInTheDocument();
		expect(goto).toHaveBeenCalled(); // URL-State geschrieben
	});
});
```

Hinweis für die Umsetzung: Der Test verlässt sich auf `getLocale()` = `en` (baseLocale) in jsdom und darauf, dass Kategorie-Chips per sichtbarem Label als `button` erreichbar sind. Falls Chips als andere Rolle gerendert werden, das Test-Query anpassen (aber Chips sollen echte `button`s sein).

- [ ] **Step 4: Test ausführen, Fehlschlag bestätigen**

Run: `npm --prefix frontend run test -- --run src/routes/'(public)'/onepager.test.ts`
Expected: FAIL (Hero-Seite rendert keine Blöcke / kein Katalog-Laden).

- [ ] **Step 5: +page.svelte zum Onepager umbauen**

Ersetze den **gesamten** Inhalt von `frontend/src/routes/(public)/+page.svelte` durch:

```svelte
<script lang="ts">
	import { browser } from '$app/environment';
	import { page } from '$app/state';
	import { goto } from '$app/navigation';
	import { onDestroy } from 'svelte';
	import { localizeHref, getLocale } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import type { EntryListItem } from '$lib/api';
	import { loadCatalog, INITIAL_LOAD } from '$lib/catalog';
	import {
		filterEntries,
		sortEntries,
		languageFacet,
		tagFacet,
		categoryFacet,
		optionCount
	} from '$lib/facets';
	import {
		parseOnepagerFilter,
		onepagerSearchParams,
		debounce,
		type OnepagerFilter,
		type OnepagerSort
	} from '$lib/browse-state';
	import { categoryLabel } from '$lib/categories';
	import EntryBlock from '$lib/components/EntryBlock.svelte';
	import Spinner from '$lib/components/Spinner.svelte';
	import EmptyState from '$lib/components/EmptyState.svelte';
	import ErrorState from '$lib/components/ErrorState.svelte';

	// Sprach-Weiche: die nackte Wurzel / auf die lokalisierte URL lenken.
	if (browser && location.pathname === '/') {
		location.replace(localizeHref('/', { locale: getLocale() }));
	}

	// --- Katalog-Zustand ---
	let items = $state<EntryListItem[]>([]);
	let loaded = $state(0);
	let total = $state(0);
	let complete = $state(false);
	let loadError = $state<string | null>(null);
	let started = false;

	function startLoad() {
		if (started) return;
		started = true;
		items = [];
		loaded = 0;
		complete = false;
		loadError = null;
		const seen = new Set<string>();
		loadCatalog({
			onBatch: (batch, ld, tot) => {
				const fresh = batch.filter((e) => !seen.has(e.formatId));
				for (const e of fresh) seen.add(e.formatId);
				items = [...items, ...fresh];
				loaded = ld;
				total = tot;
			},
			onComplete: () => (complete = true),
			onError: (msg) => (loadError = msg)
		});
	}

	$effect(() => {
		if (browser) startLoad();
	});

	function retry() {
		started = false;
		startLoad();
	}

	// --- Filter aus der URL (Single Source of Truth) ---
	const urlLocale = $derived(getLocale());
	const filter = $derived<OnepagerFilter>(applyLangDefault(parseOnepagerFilter(page.url.searchParams), urlLocale));

	function applyLangDefault(f: OnepagerFilter, locale: string): OnepagerFilter {
		// Vorbelegung der Sprachfacette folgt der URL-Locale, solange der Nutzer
		// nichts gewählt hat; frei änderbar.
		return f.langs.length === 0 ? { ...f, langs: [locale] } : f;
	}

	// Freitextfeld mit eigenem State für flüssiges Tippen.
	let qField = $state(parseOnepagerFilter(page.url.searchParams).q ?? '');
	$effect(() => {
		qField = filter.q ?? '';
	});

	const locale = $derived(getLocale());
	const filtered = $derived(sortEntries(filterEntries(items, filter, locale), filter.sort));

	// Facetten aus dem geladenen Katalog.
	const cats = $derived(categoryFacet(items));
	const langs = $derived(languageFacet(items));
	const allTags = $derived(tagFacet(items));
	let showAllTags = $state(false);
	const TOP_TAGS = 12;
	const tags = $derived(showAllTags ? allTags : allTags.slice(0, TOP_TAGS));

	// --- URL schreiben (ohne Reload) ---
	function updateUrl(next: OnepagerFilter) {
		const qs = onepagerSearchParams(next).toString();
		goto(localizeHref(`/${qs ? `?${qs}` : ''}`), { replaceState: true, keepFocus: true, noScroll: true });
	}
	function setFilter(patch: Partial<OnepagerFilter>) {
		updateUrl({ ...filter, ...patch });
	}
	function toggleIn(list: string[], value: string): string[] {
		return list.includes(value) ? list.filter((v) => v !== value) : [...list, value];
	}

	const pushQ = debounce((value: string) => setFilter({ q: value || undefined }), 250);
	onDestroy(() => pushQ.cancel());
</script>

<svelte:head>
	<title>{m.onepager_title()}</title>
	<meta name="description" content={m.hero_tagline()} />
</svelte:head>

<header class="op-head">
	<h1>{m.onepager_title()}</h1>
	<input
		type="search"
		bind:value={qField}
		oninput={() => pushQ(qField)}
		placeholder={m.search_placeholder()}
		aria-label={m.search_placeholder()}
	/>
</header>

<div class="op-body">
	<aside class="op-facets">
		<div class="facet-group">
			<label for="op-type">{m.filter_type_all()}</label>
			<select id="op-type" value={filter.type ?? ''}
				onchange={(e) => setFilter({ type: (e.currentTarget.value || undefined) as OnepagerFilter['type'] })}>
				<option value="">{m.filter_type_all()}</option>
				<option value="menu">{m.type_menu()}</option>
				<option value="engine">{m.type_engine()}</option>
			</select>
		</div>

		<div class="facet-group">
			<label for="op-sort">{m.sort_label()}</label>
			<select id="op-sort" value={filter.sort}
				onchange={(e) => setFilter({ sort: e.currentTarget.value as OnepagerSort })}>
				<option value="newest">{m.sort_newest()}</option>
				<option value="installs">{m.sort_installs()}</option>
				<option value="best">{m.sort_best()}</option>
			</select>
		</div>

		{#if cats.length}
			<div class="facet-group">
				<span class="facet-title">{m.facet_categories()}</span>
				<div class="chips">
					{#each cats as opt (opt.value)}
						<button class="chip" class:on={filter.categories.includes(opt.value)}
							onclick={() => setFilter({ categories: toggleIn(filter.categories, opt.value) })}>
							{categoryLabel(opt.value)}
							<span class="chip-count">{optionCount(items, filter, 'categories', opt.value, locale)}</span>
						</button>
					{/each}
				</div>
			</div>
		{/if}

		{#if langs.length}
			<div class="facet-group">
				<span class="facet-title">{m.facet_languages()}</span>
				<div class="chips">
					{#each langs as opt (opt.value)}
						<button class="chip" class:on={filter.langs.includes(opt.value)}
							onclick={() => setFilter({ langs: toggleIn(filter.langs, opt.value) })}>
							{opt.value.toUpperCase()}
							<span class="chip-count">{optionCount(items, filter, 'langs', opt.value, locale)}</span>
						</button>
					{/each}
				</div>
			</div>
		{/if}

		{#if allTags.length}
			<div class="facet-group">
				<span class="facet-title">{m.facet_tags()}</span>
				<div class="chips">
					{#each tags as opt (opt.value)}
						<button class="chip" class:on={filter.tags.includes(opt.value)}
							onclick={() => setFilter({ tags: toggleIn(filter.tags, opt.value) })}>
							#{opt.value}
							<span class="chip-count">{optionCount(items, filter, 'tags', opt.value, locale)}</span>
						</button>
					{/each}
				</div>
				{#if allTags.length > TOP_TAGS}
					<button class="link-btn" onclick={() => (showAllTags = !showAllTags)}>
						{showAllTags ? m.facet_less() : m.facet_more()}
					</button>
				{/if}
			</div>
		{/if}

		<div class="facet-group">
			<label for="op-site">{m.filter_site()}</label>
			<input id="op-site" type="text" value={filter.site ?? ''}
				onchange={(e) => setFilter({ site: e.currentTarget.value || undefined })}
				placeholder={m.filter_site()} />
		</div>
	</aside>

	<section class="op-list">
		{#if loadError && items.length === 0}
			<ErrorState message={loadError} onRetry={retry} />
		{:else if items.length === 0 && !complete}
			<Spinner />
		{:else if filtered.length === 0}
			<EmptyState title={m.state_empty_title()} hint={m.browse_empty_hint()} />
		{:else}
			<p class="count">{m.results_of({ shown: filtered.length, total })}</p>
			<div class="blocks">
				{#each filtered as entry (entry.formatId)}
					<EntryBlock {entry} open={filter.highlight === entry.formatId} />
				{/each}
			</div>
		{/if}
		{#if !complete && items.length > 0 && loaded >= INITIAL_LOAD}
			<p class="loading-more"><Spinner /> {m.loading_more()}</p>
		{/if}
	</section>
</div>

<style>
	.op-head {
		display: flex;
		align-items: center;
		gap: 16px;
		flex-wrap: wrap;
		padding: 12px 0;
	}
	.op-head h1 {
		margin: 0;
	}
	.op-head input[type='search'] {
		flex: 1 1 280px;
		max-width: 480px;
	}
	.op-body {
		display: grid;
		grid-template-columns: 260px 1fr;
		gap: 24px;
		align-items: start;
	}
	.op-facets {
		display: flex;
		flex-direction: column;
		gap: 16px;
		position: sticky;
		top: 16px;
	}
	.facet-group {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.facet-title {
		font-weight: 600;
	}
	.chips {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}
	.chip {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		padding: 4px 10px;
		border-radius: 999px;
		border: 1px solid var(--border-color);
		background: transparent;
		color: inherit;
		cursor: pointer;
		font-size: 0.85em;
	}
	.chip.on {
		background: var(--accent-color, #5b9cf6);
		color: #fff;
		border-color: transparent;
	}
	.chip-count {
		color: var(--text-muted);
		font-size: 0.85em;
	}
	.chip.on .chip-count {
		color: rgba(255, 255, 255, 0.8);
	}
	.link-btn {
		align-self: flex-start;
		background: none;
		border: none;
		color: var(--accent-color, #5b9cf6);
		cursor: pointer;
		padding: 0;
	}
	.op-list {
		min-width: 0;
	}
	.count {
		color: var(--text-secondary);
	}
	.blocks {
		display: flex;
		flex-direction: column;
		gap: 12px;
	}
	.loading-more {
		display: flex;
		align-items: center;
		gap: 8px;
		color: var(--text-muted);
		margin-top: 12px;
	}
	@media (max-width: 720px) {
		.op-body {
			grid-template-columns: 1fr;
		}
		.op-facets {
			position: static;
		}
	}
</style>
```

- [ ] **Step 6: Onepager-Test grün + voller Check**

Run: `npm --prefix frontend run test -- --run src/routes/'(public)'/onepager.test.ts && npm --prefix frontend run check`
Expected: PASS; `svelte-check` 0 Fehler.
Falls der Test wegen `$app/state`-Mock-Feinheiten hakt: sicherstellen, dass der Mock `page.url` als Getter liefert (siehe Test) und die Seite `page.url.searchParams` liest.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/routes/'(public)'/+page.ts frontend/src/routes/'(public)'/+page.svelte frontend/src/routes/'(public)'/onepager.test.ts frontend/messages/en.json frontend/messages/de.json
git commit -m "$(cat <<'EOF'
Baue oeffentliche Wurzel zum Onepager um

Die lokalisierte Wurzel laedt den Katalog progressiv (client-only,
prerender/ssr=false) und filtert on-the-fly: Freitext, Typ, Kategorie-,
Sprach- und Tag-Chips (mit Optionszaehlern), Site und Sortierung
(neueste/Installs/beste Bewertung). Sprachvorbelegung folgt der
URL-Locale, bleibt aber frei waehlbar; der Filterzustand steht ohne
Reload in der URL. Ersetzt die Hero-/Kachel-Startseite.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Sammelkorb-Tray & Bundle-Download

**Files:**
- Create: `frontend/src/lib/components/BasketTray.svelte`, `frontend/src/lib/components/BasketTray.test.ts`
- Modify: `frontend/src/routes/(public)/+page.svelte` (Tray einbinden, Katalog-Map, reconcile)
- Modify: `frontend/messages/en.json`, `frontend/messages/de.json`

**Interfaces:**
- Consumes: `basket` (basket.svelte.ts); `EntryListItem`, `downloadVersion` (api.ts); `resolveLocalized` (localized.ts); `triggerJsonDownload` (download.ts); `getLocale` (paraglide); Lucide `ShoppingBasket`, `X`, `Download`, `Send`.
- Produces: `BasketTray.svelte` – Props `{ catalog: Map<string, EntryListItem> }`.

- [ ] **Step 1: i18n-Keys für den Sammelkorb ergänzen (beide Dateien)**

In `frontend/messages/en.json`:

```json
	"basket_open": "Selection ({count})",
	"basket_title": "Your selection",
	"basket_empty": "No entries selected",
	"basket_remove": "Remove",
	"basket_clear": "Clear all",
	"basket_download": "Download JSON",
	"basket_send": "Send to Gestura",
	"basket_send_soon": "Coming with the next update",
	"basket_download_error": "Some entries could not be added: {ids}"
```

In `frontend/messages/de.json`:

```json
	"basket_open": "Auswahl ({count})",
	"basket_title": "Deine Auswahl",
	"basket_empty": "Keine Einträge ausgewählt",
	"basket_remove": "Entfernen",
	"basket_clear": "Alle entfernen",
	"basket_download": "Als JSON herunterladen",
	"basket_send": "An Gestura senden",
	"basket_send_soon": "Kommt mit dem nächsten Update",
	"basket_download_error": "Einige Einträge konnten nicht hinzugefügt werden: {ids}"
```

- [ ] **Step 2: Failing test für BasketTray schreiben**

Create `frontend/src/lib/components/BasketTray.test.ts`:

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import BasketTray from './BasketTray.svelte';
import type { EntryListItem } from '$lib/api';
import { basket } from '$lib/basket.svelte';

const downloadVersion = vi.fn();
const triggerJsonDownload = vi.fn();
vi.mock('$lib/api', async (orig) => {
	const actual = await orig<typeof import('$lib/api')>();
	return { ...actual, downloadVersion: (...a: unknown[]) => downloadVersion(...a) };
});
vi.mock('$lib/download', () => ({
	triggerJsonDownload: (...a: unknown[]) => triggerJsonDownload(...a),
	buildDownloadFilename: () => 'x.json'
}));

function item(id: string): EntryListItem {
	return {
		formatId: id, type: 'menu', name: id, description: null, categories: [], tags: [],
		domains: [], installCount: 0, rating: { average: null, count: 0 }, currentVersion: '1.2.0',
		deprecated: false, successorFormatId: null, screenshotUrl: null, updatedAt: '2026-01-01T00:00:00Z'
	};
}
const catalog = new Map([['a', item('a')], ['b', item('b')]]);

beforeEach(() => {
	localStorage.clear();
	basket.clear();
	downloadVersion.mockReset();
	triggerJsonDownload.mockReset();
});

describe('BasketTray', () => {
	it('ist verborgen, solange leer', () => {
		render(BasketTray, { catalog });
		expect(screen.queryByRole('button', { name: /selection|auswahl/i })).not.toBeInTheDocument();
	});
	it('zeigt den Zähler und öffnet das Panel', async () => {
		basket.toggle('a');
		render(BasketTray, { catalog });
		const opener = screen.getByRole('button', { name: /selection \(1\)|auswahl \(1\)/i });
		await fireEvent.click(opener);
		expect(screen.getByText(/your selection|deine auswahl/i)).toBeInTheDocument();
	});
	it('entfernt einen Eintrag im Panel', async () => {
		basket.toggle('a');
		basket.toggle('b');
		render(BasketTray, { catalog });
		await fireEvent.click(screen.getByRole('button', { name: /selection \(2\)|auswahl \(2\)/i }));
		const removeButtons = screen.getAllByRole('button', { name: /remove|entfernen/i });
		await fireEvent.click(removeButtons[0]);
		expect(basket.count).toBe(1);
	});
	it('lädt ein zusammengesetztes Bundle herunter', async () => {
		downloadVersion.mockImplementation(async (id: string) => ({ gesturaMenu: 1, id }));
		basket.toggle('a');
		basket.toggle('b');
		render(BasketTray, { catalog });
		await fireEvent.click(screen.getByRole('button', { name: /selection \(2\)|auswahl \(2\)/i }));
		await fireEvent.click(screen.getByRole('button', { name: /download json|als json/i }));
		await waitFor(() => expect(triggerJsonDownload).toHaveBeenCalled());
		const [bundle, filename] = triggerJsonDownload.mock.calls[0];
		expect(bundle).toEqual({ gesturaBundle: 1, entries: [{ gesturaMenu: 1, id: 'a' }, { gesturaMenu: 1, id: 'b' }] });
		expect(filename).toBe('gestura-bundle.json');
	});
	it('"An Gestura senden" ist deaktiviert', async () => {
		basket.toggle('a');
		render(BasketTray, { catalog });
		await fireEvent.click(screen.getByRole('button', { name: /selection \(1\)|auswahl \(1\)/i }));
		const sendBtn = screen.getByRole('button', { name: /send to gestura|an gestura senden/i });
		expect(sendBtn).toBeDisabled();
	});
});
```

- [ ] **Step 3: Test ausführen, Fehlschlag bestätigen**

Run: `npm --prefix frontend run test -- --run src/lib/components/BasketTray.test.ts`
Expected: FAIL (`Cannot find module './BasketTray.svelte'`).

- [ ] **Step 4: BasketTray.svelte implementieren**

Create `frontend/src/lib/components/BasketTray.svelte`:

```svelte
<script lang="ts">
	import { basket } from '$lib/basket.svelte';
	import { downloadVersion, type EntryListItem } from '$lib/api';
	import { triggerJsonDownload } from '$lib/download';
	import { resolveLocalized } from '$lib/localized';
	import { getLocale } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import { ShoppingBasket, X, Download, Send } from '@lucide/svelte';

	let { catalog }: { catalog: Map<string, EntryListItem> } = $props();

	let open = $state(false);
	let downloading = $state(false);
	let downloadError = $state<string | null>(null);

	const locale = $derived(getLocale());
	const entries = $derived(
		basket.ids.map((id) => ({ id, item: catalog.get(id) ?? null }))
	);

	async function download() {
		downloading = true;
		downloadError = null;
		const payloads: unknown[] = [];
		const failed: string[] = [];
		for (const id of basket.ids) {
			const item = catalog.get(id);
			const semver = item?.currentVersion;
			if (!semver) {
				failed.push(id);
				continue;
			}
			try {
				payloads.push(await downloadVersion(id, semver));
			} catch {
				failed.push(id);
			}
		}
		downloading = false;
		if (payloads.length) {
			triggerJsonDownload({ gesturaBundle: 1, entries: payloads }, 'gestura-bundle.json');
		}
		if (failed.length) {
			downloadError = m.basket_download_error({ ids: failed.join(', ') });
		}
	}
</script>

{#if basket.count > 0}
	<div class="tray">
		<button class="tray-open btn btn-primary" onclick={() => (open = !open)}
			aria-expanded={open}>
			<ShoppingBasket size={18} /> {m.basket_open({ count: basket.count })}
		</button>

		{#if open}
			<div class="tray-panel card">
				<div class="tray-head">
					<strong>{m.basket_title()}</strong>
					<button class="icon-btn" onclick={() => (open = false)} aria-label="close"><X size={18} /></button>
				</div>

				{#if entries.length === 0}
					<p class="muted">{m.basket_empty()}</p>
				{:else}
					<ul class="tray-list">
						{#each entries as e (e.id)}
							<li>
								<span class="tray-name">
									{e.item ? resolveLocalized(e.item.name, locale) || e.id : e.id}
									{#if e.item}<span class="muted">· {e.item.type === 'menu' ? m.type_menu() : m.type_engine()}</span>{/if}
								</span>
								<button class="link-btn" onclick={() => basket.remove(e.id)}>{m.basket_remove()}</button>
							</li>
						{/each}
					</ul>

					<div class="tray-actions">
						<button class="btn btn-primary" onclick={download} disabled={downloading}>
							<Download size={16} /> {m.basket_download()}
						</button>
						<button class="btn" disabled title={m.basket_send_soon()}>
							<Send size={16} /> {m.basket_send()}
						</button>
						<button class="link-btn" onclick={() => basket.clear()}>{m.basket_clear()}</button>
					</div>
					{#if downloadError}<p class="err">{downloadError}</p>{/if}
				{/if}
			</div>
		{/if}
	</div>
{/if}

<style>
	.tray {
		position: fixed;
		right: 16px;
		bottom: 16px;
		z-index: 50;
		display: flex;
		flex-direction: column;
		align-items: flex-end;
		gap: 8px;
	}
	.tray-open {
		display: inline-flex;
		align-items: center;
		gap: 8px;
	}
	.tray-panel {
		width: min(360px, 90vw);
		max-height: 70vh;
		overflow: auto;
	}
	.tray-head {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 8px;
	}
	.icon-btn {
		background: none;
		border: none;
		color: inherit;
		cursor: pointer;
	}
	.tray-list {
		list-style: none;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.tray-list li {
		display: flex;
		justify-content: space-between;
		gap: 8px;
		align-items: center;
	}
	.tray-name {
		min-width: 0;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}
	.tray-actions {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		align-items: center;
		margin-top: 12px;
	}
	.link-btn {
		background: none;
		border: none;
		color: var(--accent-color, #5b9cf6);
		cursor: pointer;
		padding: 0;
	}
	.muted {
		color: var(--text-muted);
		font-size: 0.9em;
	}
	.err {
		color: var(--danger-color, #e5484d);
		margin-top: 8px;
	}
</style>
```

- [ ] **Step 5: BasketTray-Test grün**

Run: `npm --prefix frontend run test -- --run src/lib/components/BasketTray.test.ts`
Expected: PASS.

- [ ] **Step 6: Tray in die Onepager-Seite einbinden + reconcile**

In `frontend/src/routes/(public)/+page.svelte`:

- Import ergänzen (bei den Komponenten-Imports):

```svelte
	import BasketTray from '$lib/components/BasketTray.svelte';
	import { basket } from '$lib/basket.svelte';
```

- Nach der `filtered`-Ableitung eine Katalog-Map + reconcile ergänzen:

```svelte
	const catalogMap = $derived(new Map(items.map((e) => [e.formatId, e])));

	// Nicht mehr vorhandene Auswahl-IDs still abräumen, sobald vollständig geladen.
	$effect(() => {
		if (complete) basket.reconcile(new Set(items.map((e) => e.formatId)));
	});
```

- Vor dem schließenden Ende der Markup (nach `</div>` des `.op-body`) den Tray einhängen:

```svelte
<BasketTray catalog={catalogMap} />
```

- [ ] **Step 7: Voller Check + gesamte Suite grün**

Run: `npm --prefix frontend run check && npm --prefix frontend run test -- --run`
Expected: `svelte-check` 0 Fehler; alle Tests PASS.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/lib/components/BasketTray.svelte frontend/src/lib/components/BasketTray.test.ts frontend/src/routes/'(public)'/+page.svelte frontend/messages/en.json frontend/messages/de.json
git commit -m "$(cat <<'EOF'
Ergaenze Sammelkorb-Tray mit client-seitigem Bundle-Download

Der Tray zeigt die Auswahl-Anzahl (verborgen, solange leer), oeffnet ein
Panel zum Entfernen einzelner Eintraege und "alle entfernen". "Als JSON
herunterladen" holt je formatId den currentVersion-Payload und setzt
daraus { gesturaBundle: 1, entries: [...] } zusammen; Teil-Fehler werden
gemeldet. "An Gestura senden" ist sichtbar, aber deaktiviert (kommt mit
B). Die Auswahl wird nach vollstaendigem Laden gegen den Katalog
abgeglichen.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Deep-Link-Redirects (browse/entry) & Highlight-Verhalten

**Files:**
- Create: `frontend/src/routes/(public)/browse/+page.svelte`, `frontend/src/routes/(public)/browse/+page.ts`
- Create: `frontend/src/routes/(public)/entry/[formatId]/+page.svelte`, `frontend/src/routes/(public)/entry/[formatId]/+page.ts`
- Create: `frontend/src/routes/(public)/redirects.test.ts`

**Interfaces:**
- Consumes: `goto`, `page` ($app/state), `localizeHref`, `onepagerSearchParams`/`OnepagerFilter` (browse-state.ts).
- Produces: клиент-Redirect von `/browse?...` und `/entry/{formatId}` auf die Onepager-Wurzel mit vorbelegtem Filter/Highlight.

- [ ] **Step 1: Failing test für die Redirects schreiben**

Create `frontend/src/routes/(public)/redirects.test.ts`:

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render } from '@testing-library/svelte';

const goto = vi.fn();
vi.mock('$app/navigation', () => ({ goto: (...a: unknown[]) => goto(...a) }));

let currentUrl = new URL('http://localhost/en/browse?category=dev&q=abc');
let currentParams: Record<string, string> = {};
vi.mock('$app/state', () => ({
	page: {
		get url() { return currentUrl; },
		get params() { return currentParams; }
	}
}));

beforeEach(() => goto.mockReset());

describe('deep-link redirects', () => {
	it('/browse mappt Alt-Parameter auf den Onepager', async () => {
		currentUrl = new URL('http://localhost/en/browse?category=dev&q=abc&sort=installs');
		const Browse = (await import('./browse/+page.svelte')).default;
		render(Browse);
		expect(goto).toHaveBeenCalledTimes(1);
		const target = goto.mock.calls[0][0] as string;
		expect(target).toContain('category=dev');
		expect(target).toContain('q=abc');
		expect(target).toContain('sort=installs');
		expect(target).not.toContain('/browse');
	});

	it('/entry/{id} leitet mit highlight weiter', async () => {
		currentUrl = new URL('http://localhost/en/entry/com.example.menu');
		currentParams = { formatId: 'com.example.menu' };
		const Entry = (await import('./entry/[formatId]/+page.svelte')).default;
		render(Entry);
		expect(goto).toHaveBeenCalledTimes(1);
		const target = goto.mock.calls[0][0] as string;
		expect(target).toContain('highlight=com.example.menu');
		expect(target).not.toContain('/entry/');
	});
});
```

- [ ] **Step 2: Test ausführen, Fehlschlag bestätigen**

Run: `npm --prefix frontend run test -- --run src/routes/'(public)'/redirects.test.ts`
Expected: FAIL (Routen existieren nicht).

- [ ] **Step 3: browse-Redirect anlegen**

Create `frontend/src/routes/(public)/browse/+page.ts`:

```ts
// Redirect-Shell: keine eigene Seite, wird zur Laufzeit auf den Onepager gelenkt.
export const prerender = false;
export const ssr = false;
```

Create `frontend/src/routes/(public)/browse/+page.svelte`:

```svelte
<script lang="ts">
	import { page } from '$app/state';
	import { goto } from '$app/navigation';
	import { localizeHref } from '$lib/paraglide/runtime';
	import { onepagerSearchParams, type OnepagerFilter, type OnepagerSort } from '$lib/browse-state';

	// Alt-Parameter (Einzelwerte) auf das Onepager-Filterformat abbilden.
	const sp = page.url.searchParams;
	const single = (k: string) => {
		const v = sp.get(k);
		return v && v.trim() !== '' ? v : undefined;
	};
	const type = single('type');
	const sort = single('sort');
	const filter: OnepagerFilter = {
		q: single('q'),
		type: type === 'menu' || type === 'engine' ? type : undefined,
		categories: single('category') ? [single('category')!] : [],
		tags: single('tag') ? [single('tag')!] : [],
		langs: [],
		site: single('site'),
		sort: sort === 'installs' || sort === 'best' ? (sort as OnepagerSort) : 'newest'
	};
	const qs = onepagerSearchParams(filter).toString();
	goto(localizeHref(`/${qs ? `?${qs}` : ''}`), { replaceState: true });
</script>
```

- [ ] **Step 4: entry-Redirect anlegen**

Create `frontend/src/routes/(public)/entry/[formatId]/+page.ts`:

```ts
export const prerender = false;
export const ssr = false;
```

Create `frontend/src/routes/(public)/entry/[formatId]/+page.svelte`:

```svelte
<script lang="ts">
	import { page } from '$app/state';
	import { goto } from '$app/navigation';
	import { localizeHref } from '$lib/paraglide/runtime';

	// Deep-Link auf eine Detailseite -> Onepager mit hervorgehobenem/aufgeklapptem Block.
	const formatId = page.params.formatId ?? '';
	const qs = formatId ? `?highlight=${encodeURIComponent(formatId)}` : '';
	goto(localizeHref(`/${qs}`), { replaceState: true });
</script>
```

- [ ] **Step 5: Redirect-Test grün**

Run: `npm --prefix frontend run test -- --run src/routes/'(public)'/redirects.test.ts`
Expected: PASS.

- [ ] **Step 6: Highlight-Scroll ergänzen (Onepager)**

In `frontend/src/routes/(public)/+page.svelte`: das `open`-Prop reicht bereits fürs Auto-Aufklappen (Task 4/5). Für sanftes Scrollen zum hervorgehobenen Block eine ID + Effect ergänzen.

- Im `.blocks`-`{#each}` einen Anker-Wrapper mit ID setzen:

```svelte
			<div class="blocks">
				{#each filtered as entry (entry.formatId)}
					<div id={`e-${entry.formatId}`}>
						<EntryBlock {entry} open={filter.highlight === entry.formatId} />
					</div>
				{/each}
			</div>
```

- Nach dem `catalogMap`/reconcile-Block einen Scroll-Effect ergänzen:

```svelte
	// Zum hervorgehobenen Block scrollen, sobald er im gefilterten Satz auftaucht.
	let scrolledTo = $state<string | null>(null);
	$effect(() => {
		const target = filter.highlight;
		if (!browser || !target || scrolledTo === target) return;
		if (filtered.some((e) => e.formatId === target)) {
			const el = document.getElementById(`e-${target}`);
			if (el) {
				el.scrollIntoView({ behavior: 'smooth', block: 'start' });
				scrolledTo = target;
			}
		}
	});
```

- [ ] **Step 7: Gesamte Suite + Check grün**

Run: `npm --prefix frontend run check && npm --prefix frontend run test -- --run`
Expected: `svelte-check` 0 Fehler; alle Tests PASS.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/routes/'(public)'/browse frontend/src/routes/'(public)'/entry frontend/src/routes/'(public)'/redirects.test.ts frontend/src/routes/'(public)'/+page.svelte
git commit -m "$(cat <<'EOF'
Erhalte Deep-Links: browse/entry leiten auf den Onepager

/browse?... bildet die Alt-Filter (category, tag, type, q, site, sort)
auf den Onepager-Filter ab; /entry/{formatId} leitet mit ?highlight=...
weiter, wodurch der Block automatisch aufklappt und sanft angescrollt
wird. Beide Routen sind reine client-seitige Redirect-Shells.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Selbst-Review (writing-plans)

**Spec-Abdeckung (Sub-A-Spec §-für-§):**
- §3 Routen-Restrukturierung → T1 (Teardown), T5 (Wurzel = Onepager), T7 (Redirects, Deep-Link-Erhalt, Highlight). ✔
- §4 Datenladen (progressiv, INITIAL_LOAD, perPage=50, Ladezustände) → T2 (catalog.ts), T5 (Skeleton/„lädt weitere“/Error). ✔
- §5 Facetten (Set, dynamisch, ODER/UND, Anzahl, Sprach-Default, URL-State) → T2 (facets.ts, browse-state), T5 (UI). ✔
- §6 Blockliste & Aufklappen (+ Reviews 2. Ebene) → T4. ✔
- §7 Sammelkorb (Toggle, Indikator, Panel, Download-Bundle, Senden deaktiviert, localStorage, reconcile) → T3 (Store), T6 (Tray+Download+reconcile), T4 (Toggle im Block). ✔
- §8 Sterne (rating-Typ, 5-Sterne + Wert + Anzahl, count 0, Sortierung best) → T1 (Typ), T2 (Sort), T3 (StarRating). ✔
- §9 i18n (beide JSON, messages.js gitignored) → verteilt über T3/T4/T5/T6. ✔
- §10 Wiederverwendung/Ersatz → T1 (EntryCard/Routen weg), Reuse von download/categories/browse-state/Badge/… durchgängig. ✔
- §11 Tests (Facetten, Sortierung, Sterne, Sammelkorb, Bundle-Download, Aufklappen/Reviews, Redirects) → T2/T3/T4/T5/T6/T7. ✔
- §12 offene Feinheiten → Layout (Facetten-Sidebar + Mobile-Collapse in T5), Zählsemantik (optionCount T2), Teil-Fehler-Bundle (T6: Teil-Bundle + Fehlermeldung), Highlight-Form (T7: Auto-Expand + Scroll). ✔

**Typ-Konsistenz:** `OnepagerFilter`/`OnepagerSort` (browse-state) einheitlich in facets.ts + Seite; `FacetOption` einheitlich; `loadCatalog`-Signatur identisch in Test + Seite; `basket`-API identisch in Store/Block/Tray; `EntryBlock`-Props `{entry, open}` konsistent Seite↔Komponente; `BasketTray`-Prop `{catalog: Map}` konsistent.

**Platzhalter:** keine – jeder Code-Step enthält vollständigen Code; jeder Test-Step vollständige Tests.

**Reihenfolge/Grün:** Jede Task endet mit grünem `check`+`test`; Typwechsel (T1) ist mit dem Entfernen der Alt-Konsumenten gebündelt; Komponenten bottom-up vor der Seite; Tray nach der Seite; Redirects zuletzt (brauchen den Onepager-URL-State aus T2/T5).
