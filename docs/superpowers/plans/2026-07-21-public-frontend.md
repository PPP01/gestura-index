# Öffentliches Frontend (SP3) – Implementierungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein rein lesendes, zweisprachiges (en/de) SvelteKit-Frontend im Gestura-Design, das den Index browst, Einträge anzeigt und herunterlädt – gegen die Live-API `https://api.gestura.eu`.

**Architecture:** SvelteKit + adapter-static. Start/Docs/Recht werden prerendered (SEO), Stöbern/Detail holen Daten client-seitig. Ein zentraler, getesteter API-Client kapselt alle HTTP-Zugriffe. i18n über Paraglide JS mit URL-Präfix /en /de.

**Tech Stack:** Svelte 5 (Runes), TypeScript, `@sveltejs/adapter-static`, `@inlang/paraglide-js`, `@lucide/svelte`, Vitest + `@testing-library/svelte`.

**Spec:** `docs/superpowers/specs/2026-07-21-public-frontend-design.md`

## Global Constraints

- **Nur lesend:** kein Login, kein Einreichen/Verwalten-Formular. Einreichen läuft über die Extension bzw. Phase 3.
- **Design strikt nach** `docs/design-system.md`: Tokens aus `gestura-common.css` (`--accent-color`, `--bg-primary/secondary/tertiary`, `--text-primary/secondary/muted`, `--danger/success/warning-color`, `--section-border`, `--section-shadow`, `--border-color`), Karten `.card`, Hell/Dunkel (`data-theme`), Logo, Lucide-Icons (stroke 2, `currentColor`). Keine hartkodierten Farben, keine gestalterischen Alleingänge.
- **Max-Width-Shell** liegt bereits im Root-Layout (`.page-shell` 1200px, `.container` 900px).
- **Sprachen:** en (Fallback) + de. Alle sichtbaren Strings über Paraglide-Messages (`messages/en.json`, `messages/de.json`), Zugriff via `import { m } from '$lib/paraglide/messages.js'`.
- **API-Basis:** `https://api.gestura.eu`, überschreibbar über `PUBLIC_API_BASE`.
- **Verifikation:** `npm --prefix frontend run check` (svelte-check, 0 Fehler) **und** `npm --prefix frontend run test -- --run` (Vitest) müssen grün sein. `npm --prefix frontend run build` muss durchlaufen. Alle Befehle relativ zum Repo-Root des Worktrees.
- **Deutsche Texte** in Commits/Kommentaren mit Guillemets »…« und Halbgeviertstrich –.
- **Arbeitsverzeichnis:** Worktree `.claude/worktrees/sp3-public-frontend`. Branch `worktree-sp3-public-frontend`.

## Verifizierte API-Verträge (Stand SP1/SP2, live)

- `GET /api/v1/entries?q&type&category&tag&site&sort&page&perPage` → `{ items: EntryListItem[], page, perPage, total }`. `type ∈ {menu,engine}`, `sort` Default `newest`, `perPage` 1…50 (Default 20), `page` ≥1.
- `GET /api/v1/entries/{formatId}` → `EntryListItem` + `versions: VersionInfo[]`.
- `GET /api/v1/entries/{formatId}/versions/{semver}` → Austausch-JSON der Version (ETag, max-age=300). `semver`-Muster `\d{1,5}\.\d{1,5}\.\d{1,5}`.
- `POST /api/v1/entries/{formatId}/install` → 204.
- `POST /api/v1/entries/{formatId}/report` mit `{ reason, comment? }`, `reason ∈ {spam,broken_links,misleading,legal}`, `comment` ≤2000 Zeichen.
- **`screenshotUrl` kommt relativ** (z. B. `/media/…`) → API-Basis davorsetzen.
- Fehler kommen als `application/problem+json` mit `title`/`detail`.
- Feste Kategorien (Backend-Enum): `dev, shopping, video, news, social, productivity, search, reference, entertainment, other`.

---

## Task 1: i18n- und Static-Fundament ✅ (bereits umgesetzt, Commit 43d4b83)

**Status:** Erledigt vor Planbeginn (Config war zu heikel zum Raten und wurde real verifiziert). Dieser Task dient als Referenz für die Interfaces, auf denen die folgenden Tasks aufbauen. **Nicht erneut umsetzen.**

**Dateien (bereits vorhanden):**
- `frontend/vite.config.ts` – `sveltekit()` (Runes-Zwang) + `adapter({ fallback: '200.html' })` + `paraglideVitePlugin({ project:'./project.inlang', outdir:'./src/lib/paraglide', strategy:['url','cookie','preferredLanguage','baseLocale'], urlPatterns:[…] })` (symmetrisches /en /de).
- `frontend/src/hooks.ts` – `reroute` via `deLocalizeUrl`.
- `frontend/src/hooks.server.ts` – `paraglideMiddleware`, ersetzt `%paraglide.lang%`.
- `frontend/src/app.html` – `<html lang="%paraglide.lang%">` + bestehender No-Flash-Theme-Init.
- `frontend/project.inlang/settings.json`, `frontend/messages/{en,de}.json` (Start-Keys `hero_title`, `nav_browse`).
- `frontend/package.json` – Scripts `paraglide`, `prepare`, `check` rufen `paraglide-js compile` auf. `@types/node` als devDependency.
- `frontend/.gitignore` – ignoriert `src/lib/paraglide/` (generiert).

**Interfaces, die folgende Tasks konsumieren:**
- Messages: `import { m } from '$lib/paraglide/messages.js'` → `m.<key>()`.
- Runtime: `import { locales, getLocale, setLocale, localizeHref, deLocalizeUrl } from '$lib/paraglide/runtime'`.
- Routen liegen **ohne** Sprachpräfix (`src/routes/browse/…`, `src/routes/entry/[formatId]/…`); die Präfixe /en /de macht Paraglide über `reroute` + `localizeHref`.
- `prerender = true` gilt global (`src/routes/+layout.ts`). Dynamische Routen setzen lokal `export const prerender = false`.

**Verifikation (bereits grün):** `npm --prefix frontend run build` erzeugt `build/en.html` (lang="en", »Browse«) und `build/de.html` (lang="de", »Stöbern«) sowie `build/200.html`; `npm --prefix frontend run check` = 0 Fehler.

---

## Task 2: Vitest-Testumgebung

**Files:**
- Modify: `frontend/package.json` (Script `test`, devDependencies)
- Create: `frontend/vitest-setup.ts`
- Modify: `frontend/vite.config.ts` (test-Block)
- Create: `frontend/src/lib/sanity.test.ts` (Wegwerf-Sanity-Test, am Ende des Tasks wieder entfernt)

**Interfaces:**
- Produces: lauffähiges `npm --prefix frontend run test -- --run`; `@testing-library/svelte` render/screen verfügbar; `$env`, `$lib`, `$app` in Tests auflösbar (durch `sveltekit()`-Plugin im Vitest-Kontext).

- [ ] **Step 1: Testabhängigkeiten installieren**

```bash
npm --prefix frontend install -D vitest@latest @testing-library/svelte@latest @testing-library/jest-dom@latest jsdom@latest
```

- [ ] **Step 2: Vitest-Setup-Datei anlegen**

`frontend/vitest-setup.ts`:
```typescript
import '@testing-library/jest-dom/vitest';
```

- [ ] **Step 3: `test`-Block in `vite.config.ts` ergänzen**

Innerhalb des `defineConfig({ … })`-Objekts (nach `plugins`) ergänzen:
```typescript
	test: {
		environment: 'jsdom',
		setupFiles: ['./vitest-setup.ts'],
		globals: true
	}
```

- [ ] **Step 4: `test`-Script in `package.json` ergänzen**

In `scripts` einfügen:
```json
		"test": "npm run paraglide && vitest",
```

- [ ] **Step 5: Sanity-Test schreiben**

`frontend/src/lib/sanity.test.ts`:
```typescript
import { describe, it, expect } from 'vitest';

describe('sanity', () => {
	it('rechnet', () => {
		expect(1 + 1).toBe(2);
	});
});
```

- [ ] **Step 6: Test laufen lassen**

Run: `npm --prefix frontend run test -- --run`
Expected: 1 passed.

- [ ] **Step 7: Sanity-Test entfernen und committen**

```bash
rm frontend/src/lib/sanity.test.ts
git -C .claude/worktrees/sp3-public-frontend add -A
git -C .claude/worktrees/sp3-public-frontend commit -m "Richte Vitest-Testumgebung ein"
```

---

## Task 3: API-Client + Typen

Der zentrale, einzige Ort für HTTP-Zugriffe. Höchster Testfokus.

**Files:**
- Create: `frontend/src/lib/api.ts`
- Create: `frontend/src/lib/api.test.ts`

**Interfaces:**
- Produces:
  - Typen `EntryType`, `EntryListItem`, `VersionInfo`, `EntryDetail`, `EntryListResponse`, `EntryQuery`, `ReportReason`, `ReportInput`.
  - `class ApiError extends Error { status: number; title: string; detail: string | null }`
  - `const API_BASE: string`
  - `type Fetch = typeof fetch`
  - `interface ClientOpts { fetch?: Fetch; baseUrl?: string }`
  - `listEntries(query: EntryQuery, opts?: ClientOpts): Promise<EntryListResponse>`
  - `getEntry(formatId: string, opts?: ClientOpts): Promise<EntryDetail>`
  - `downloadVersionUrl(formatId: string, semver: string, baseUrl?: string): string`
  - `downloadVersion(formatId: string, semver: string, opts?: ClientOpts): Promise<unknown>`
  - `pingInstall(formatId: string, opts?: ClientOpts): Promise<void>`
  - `reportEntry(formatId: string, input: ReportInput, opts?: ClientOpts): Promise<void>`
  - `absoluteScreenshotUrl(url: string | null, baseUrl?: string): string | null`

- [ ] **Step 1: Failing tests schreiben**

`frontend/src/lib/api.test.ts`:
```typescript
import { describe, it, expect, vi } from 'vitest';
import {
	listEntries,
	getEntry,
	reportEntry,
	pingInstall,
	downloadVersionUrl,
	absoluteScreenshotUrl,
	ApiError
} from './api';

const BASE = 'https://api.test';

function jsonResponse(body: unknown, init: Partial<{ status: number; contentType: string }> = {}) {
	return new Response(JSON.stringify(body), {
		status: init.status ?? 200,
		headers: { 'content-type': init.contentType ?? 'application/json' }
	});
}

describe('absoluteScreenshotUrl', () => {
	it('setzt die Basis vor relative URLs', () => {
		expect(absoluteScreenshotUrl('/media/a.webp', BASE)).toBe('https://api.test/media/a.webp');
	});
	it('gibt null für null zurück', () => {
		expect(absoluteScreenshotUrl(null, BASE)).toBeNull();
	});
});

describe('listEntries', () => {
	it('serialisiert nur gesetzte Query-Parameter und mappt Screenshots', async () => {
		const fetchMock = vi.fn().mockResolvedValue(
			jsonResponse({
				items: [{ formatId: 'a', screenshotUrl: '/media/a.webp' }],
				page: 1,
				perPage: 20,
				total: 1
			})
		);
		const res = await listEntries(
			{ q: 'wiki', type: 'menu', page: 2 },
			{ fetch: fetchMock, baseUrl: BASE }
		);
		const calledUrl = new URL(fetchMock.mock.calls[0][0]);
		expect(calledUrl.pathname).toBe('/api/v1/entries');
		expect(calledUrl.searchParams.get('q')).toBe('wiki');
		expect(calledUrl.searchParams.get('type')).toBe('menu');
		expect(calledUrl.searchParams.get('page')).toBe('2');
		expect(calledUrl.searchParams.has('tag')).toBe(false);
		expect(res.items[0].screenshotUrl).toBe('https://api.test/media/a.webp');
	});
});

describe('getEntry', () => {
	it('wirft ApiError mit Detail aus problem+json bei 404', async () => {
		const fetchMock = vi.fn().mockResolvedValue(
			jsonResponse(
				{ title: 'Not Found', detail: 'Entry not found' },
				{ status: 404, contentType: 'application/problem+json' }
			)
		);
		await expect(getEntry('nope', { fetch: fetchMock, baseUrl: BASE })).rejects.toMatchObject({
			status: 404,
			detail: 'Entry not found'
		});
		await expect(getEntry('nope', { fetch: fetchMock, baseUrl: BASE })).rejects.toBeInstanceOf(
			ApiError
		);
	});
});

describe('reportEntry', () => {
	it('sendet reason und comment als JSON-POST', async () => {
		const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
		await reportEntry('a', { reason: 'spam', comment: 'x' }, { fetch: fetchMock, baseUrl: BASE });
		const [url, init] = fetchMock.mock.calls[0];
		expect(String(url)).toBe('https://api.test/api/v1/entries/a/report');
		expect(init.method).toBe('POST');
		expect(JSON.parse(init.body)).toEqual({ reason: 'spam', comment: 'x' });
	});
});

describe('pingInstall', () => {
	it('schluckt Fehler nicht – wirft bei non-2xx', async () => {
		const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 500 }));
		await expect(pingInstall('a', { fetch: fetchMock, baseUrl: BASE })).rejects.toBeInstanceOf(
			ApiError
		);
	});
});

describe('downloadVersionUrl', () => {
	it('baut die Versions-URL', () => {
		expect(downloadVersionUrl('a', '1.2.3', BASE)).toBe(
			'https://api.test/api/v1/entries/a/versions/1.2.3'
		);
	});
});
```

- [ ] **Step 2: Tests laufen lassen (müssen scheitern)**

Run: `npm --prefix frontend run test -- --run src/lib/api.test.ts`
Expected: FAIL (Modul `./api` fehlt).

- [ ] **Step 3: `api.ts` implementieren**

`frontend/src/lib/api.ts`:
```typescript
import { PUBLIC_API_BASE } from '$env/static/public';

/** Basis-URL der Index-API; über PUBLIC_API_BASE überschreibbar. */
export const API_BASE = PUBLIC_API_BASE || 'https://api.gestura.eu';

export type EntryType = 'menu' | 'engine';
export type ReportReason = 'spam' | 'broken_links' | 'misleading' | 'legal';

/** Ein Eintrag in der Listen-/Kartenansicht (Serializer::toListItem). */
export interface EntryListItem {
	formatId: string;
	type: EntryType;
	name: string;
	description: string | null;
	categories: string[];
	tags: string[];
	domains: string[];
	installCount: number;
	currentVersion: string | null;
	deprecated: boolean;
	successorFormatId: string | null;
	screenshotUrl: string | null;
	updatedAt: string;
}

/** Eine Version eines Eintrags (Serializer::toDetail.versions[]). */
export interface VersionInfo {
	semver: string;
	changelog: string | null;
	hasTransformCode: boolean;
	submittedAt: string;
}

/** Detailansicht: Listenfelder plus Versionsliste. */
export interface EntryDetail extends EntryListItem {
	versions: VersionInfo[];
}

export interface EntryListResponse {
	items: EntryListItem[];
	page: number;
	perPage: number;
	total: number;
}

/** Query-Parameter für listEntries; nur gesetzte Felder landen in der URL. */
export interface EntryQuery {
	q?: string;
	type?: EntryType;
	category?: string;
	tag?: string;
	site?: string;
	sort?: string;
	page?: number;
	perPage?: number;
}

export interface ReportInput {
	reason: ReportReason;
	comment?: string;
}

type Fetch = typeof fetch;

export interface ClientOpts {
	fetch?: Fetch;
	baseUrl?: string;
}

/** Normalisierter API-Fehler (aus application/problem+json bzw. Netzwerkfehler). */
export class ApiError extends Error {
	constructor(
		public status: number,
		public title: string,
		public detail: string | null
	) {
		super(title);
		this.name = 'ApiError';
	}
}

/** Setzt die API-Basis vor eine relativ gelieferte Screenshot-URL. */
export function absoluteScreenshotUrl(url: string | null, baseUrl: string = API_BASE): string | null {
	return url ? baseUrl + url : null;
}

/** Baut die Download-URL einer konkreten Version (auch als Import-URL nutzbar). */
export function downloadVersionUrl(formatId: string, semver: string, baseUrl: string = API_BASE): string {
	return `${baseUrl}/api/v1/entries/${encodeURIComponent(formatId)}/versions/${encodeURIComponent(semver)}`;
}

async function toApiError(res: Response): Promise<ApiError> {
	let title = res.statusText || 'Request failed';
	let detail: string | null = null;
	try {
		const body = await res.json();
		if (typeof body?.title === 'string') title = body.title;
		if (typeof body?.detail === 'string') detail = body.detail;
	} catch {
		/* kein JSON-Body – Fallback auf statusText */
	}
	return new ApiError(res.status, title, detail);
}

async function request(path: string, init: RequestInit, opts: ClientOpts): Promise<Response> {
	const f = opts.fetch ?? fetch;
	const base = opts.baseUrl ?? API_BASE;
	let res: Response;
	try {
		res = await f(base + path, init);
	} catch (e) {
		throw new ApiError(0, 'Network error', e instanceof Error ? e.message : null);
	}
	if (!res.ok) throw await toApiError(res);
	return res;
}

function mapItem(item: EntryListItem, base: string): EntryListItem {
	return { ...item, screenshotUrl: absoluteScreenshotUrl(item.screenshotUrl, base) };
}

export async function listEntries(query: EntryQuery, opts: ClientOpts = {}): Promise<EntryListResponse> {
	const params = new URLSearchParams();
	for (const [key, value] of Object.entries(query)) {
		if (value !== undefined && value !== null && value !== '') params.set(key, String(value));
	}
	const qs = params.toString();
	const res = await request(`/api/v1/entries${qs ? `?${qs}` : ''}`, { method: 'GET' }, opts);
	const data = (await res.json()) as EntryListResponse;
	const base = opts.baseUrl ?? API_BASE;
	return { ...data, items: data.items.map((i) => mapItem(i, base)) };
}

export async function getEntry(formatId: string, opts: ClientOpts = {}): Promise<EntryDetail> {
	const res = await request(
		`/api/v1/entries/${encodeURIComponent(formatId)}`,
		{ method: 'GET' },
		opts
	);
	const data = (await res.json()) as EntryDetail;
	const base = opts.baseUrl ?? API_BASE;
	return { ...mapItem(data, base), versions: data.versions };
}

export async function downloadVersion(
	formatId: string,
	semver: string,
	opts: ClientOpts = {}
): Promise<unknown> {
	const res = await request(
		`/api/v1/entries/${encodeURIComponent(formatId)}/versions/${encodeURIComponent(semver)}`,
		{ method: 'GET' },
		opts
	);
	return res.json();
}

export async function pingInstall(formatId: string, opts: ClientOpts = {}): Promise<void> {
	await request(`/api/v1/entries/${encodeURIComponent(formatId)}/install`, { method: 'POST' }, opts);
}

export async function reportEntry(
	formatId: string,
	input: ReportInput,
	opts: ClientOpts = {}
): Promise<void> {
	await request(
		`/api/v1/entries/${encodeURIComponent(formatId)}/report`,
		{
			method: 'POST',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify(input)
		},
		opts
	);
}
```

- [ ] **Step 4: Tests laufen lassen (müssen bestehen)**

Run: `npm --prefix frontend run test -- --run src/lib/api.test.ts`
Expected: alle grün.

- [ ] **Step 5: svelte-check + committen**

```bash
npm --prefix frontend run check
git -C .claude/worktrees/sp3-public-frontend add frontend/src/lib/api.ts frontend/src/lib/api.test.ts
git -C .claude/worktrees/sp3-public-frontend commit -m "Ergänze getesteten API-Client für das Frontend"
```

---

## Task 4: Filter-Zustand (URL-Serialisierung + Debounce + Sequenz-Guard)

Reine, testbare Logik für die Stöbern-Seite – getrennt von der Komponente.

**Files:**
- Create: `frontend/src/lib/browse-state.ts`
- Create: `frontend/src/lib/browse-state.test.ts`

**Interfaces:**
- Consumes: `EntryQuery` aus `./api`.
- Produces:
  - `parseQuery(searchParams: URLSearchParams): EntryQuery` – liest Filter aus der URL (page als Zahl, Default 1; perPage ignoriert = Server-Default; nur bekannte Keys).
  - `toSearchParams(query: EntryQuery): URLSearchParams` – nur gesetzte, nicht-leere Werte; `page=1` wird weggelassen (kanonische URL).
  - `class Sequence { next(): number; isCurrent(n: number): boolean }` – monoton steigende Nummer; `isCurrent` true nur für die zuletzt vergebene.
  - `debounce<T extends (...a: any[]) => void>(fn: T, ms: number): T & { cancel(): void }`.

- [ ] **Step 1: Failing tests schreiben**

`frontend/src/lib/browse-state.test.ts`:
```typescript
import { describe, it, expect, vi } from 'vitest';
import { parseQuery, toSearchParams, Sequence, debounce } from './browse-state';

describe('parseQuery', () => {
	it('liest bekannte Filter und page als Zahl', () => {
		const q = parseQuery(new URLSearchParams('q=wiki&type=menu&category=dev&page=3&unknown=x'));
		expect(q).toEqual({ q: 'wiki', type: 'menu', category: 'dev', page: 3 });
	});
	it('ignoriert ungültigen type und leere Werte', () => {
		const q = parseQuery(new URLSearchParams('type=bogus&q='));
		expect(q.type).toBeUndefined();
		expect(q.q).toBeUndefined();
	});
});

describe('toSearchParams', () => {
	it('lässt page=1 und leere Werte weg', () => {
		expect(toSearchParams({ q: 'a', page: 1, tag: '' }).toString()).toBe('q=a');
	});
	it('behält page>1', () => {
		expect(toSearchParams({ page: 2 }).get('page')).toBe('2');
	});
});

describe('Sequence', () => {
	it('markiert nur die zuletzt vergebene Nummer als aktuell', () => {
		const s = new Sequence();
		const a = s.next();
		const b = s.next();
		expect(s.isCurrent(a)).toBe(false);
		expect(s.isCurrent(b)).toBe(true);
	});
});

describe('debounce', () => {
	it('ruft fn erst nach Ablauf und nur einmal', () => {
		vi.useFakeTimers();
		const fn = vi.fn();
		const d = debounce(fn, 250);
		d();
		d();
		expect(fn).not.toHaveBeenCalled();
		vi.advanceTimersByTime(250);
		expect(fn).toHaveBeenCalledTimes(1);
		vi.useRealTimers();
	});
});
```

- [ ] **Step 2: Tests laufen lassen (müssen scheitern)**

Run: `npm --prefix frontend run test -- --run src/lib/browse-state.test.ts`
Expected: FAIL (Modul fehlt).

- [ ] **Step 3: `browse-state.ts` implementieren**

`frontend/src/lib/browse-state.ts`:
```typescript
import type { EntryQuery, EntryType } from './api';

const TYPES: EntryType[] = ['menu', 'engine'];

/** Liest den Filterzustand aus den URL-Query-Parametern. */
export function parseQuery(searchParams: URLSearchParams): EntryQuery {
	const q: EntryQuery = {};
	const str = (k: string) => {
		const v = searchParams.get(k);
		return v && v.trim() !== '' ? v : undefined;
	};
	if (str('q')) q.q = str('q');
	const type = str('type');
	if (type && (TYPES as string[]).includes(type)) q.type = type as EntryType;
	if (str('category')) q.category = str('category');
	if (str('tag')) q.tag = str('tag');
	if (str('site')) q.site = str('site');
	if (str('sort')) q.sort = str('sort');
	const page = Number(searchParams.get('page'));
	if (Number.isInteger(page) && page > 1) q.page = page;
	return q;
}

/** Serialisiert den Filterzustand in kanonische Query-Parameter (page=1 entfällt). */
export function toSearchParams(query: EntryQuery): URLSearchParams {
	const params = new URLSearchParams();
	for (const [key, value] of Object.entries(query)) {
		if (value === undefined || value === null || value === '') continue;
		if (key === 'page' && Number(value) <= 1) continue;
		params.set(key, String(value));
	}
	return params;
}

/** Monoton steigende Sequenznummer, um veraltete Antworten zu verwerfen. */
export class Sequence {
	#current = 0;
	next(): number {
		this.#current += 1;
		return this.#current;
	}
	isCurrent(n: number): boolean {
		return n === this.#current;
	}
}

/** Verzögert Aufrufe; nur der letzte innerhalb des Fensters wird ausgeführt. */
export function debounce<T extends (...args: never[]) => void>(
	fn: T,
	ms: number
): T & { cancel(): void } {
	let timer: ReturnType<typeof setTimeout> | undefined;
	const wrapped = ((...args: never[]) => {
		if (timer) clearTimeout(timer);
		timer = setTimeout(() => fn(...args), ms);
	}) as T & { cancel(): void };
	wrapped.cancel = () => {
		if (timer) clearTimeout(timer);
	};
	return wrapped;
}
```

- [ ] **Step 4: Tests laufen lassen (müssen bestehen)**

Run: `npm --prefix frontend run test -- --run src/lib/browse-state.test.ts`
Expected: alle grün.

- [ ] **Step 5: svelte-check + committen**

```bash
npm --prefix frontend run check
git -C .claude/worktrees/sp3-public-frontend add frontend/src/lib/browse-state.ts frontend/src/lib/browse-state.test.ts
git -C .claude/worktrees/sp3-public-frontend commit -m "Ergänze getestete Filter-URL-Logik für das Stöbern"
```

---

## Task 5: Kategorien-Metadaten + Design-Utilities

**Files:**
- Create: `frontend/src/lib/categories.ts`
- Create: `frontend/src/lib/categories.test.ts`
- Modify: `frontend/src/lib/styles/site.css`
- Modify: `frontend/messages/en.json`, `frontend/messages/de.json`

**Interfaces:**
- Produces:
  - `const CATEGORIES: readonly string[]` – die 10 festen Keys in fester Reihenfolge.
  - `categoryIcon(key: string): Component` – Lucide-Icon-Komponente je Kategorie (Fallback für unbekannt).
  - `categoryLabel(key: string): string` – lokalisiertes Label via Paraglide (`m.cat_<key>()`), Fallback = key.
  - CSS-Klassen `.icon-tile` (40×40, `border-radius:12px`, Tönung via `oklch(from var(--icon-color) l c h / 12%)`), `.badge`, `.filter-bar`, `.grid-cards`.

- [ ] **Step 1: Kategorie-Messages ergänzen**

In `frontend/messages/en.json` ergänzen (Werte englisch), in `de.json` analog deutsch:
```json
	"cat_dev": "Development",
	"cat_shopping": "Shopping",
	"cat_video": "Video",
	"cat_news": "News",
	"cat_social": "Social",
	"cat_productivity": "Productivity",
	"cat_search": "Search",
	"cat_reference": "Reference",
	"cat_entertainment": "Entertainment",
	"cat_other": "Other"
```
Deutsche Werte: Development→»Entwicklung«, Shopping→»Shopping«, Video→»Video«, News→»Nachrichten«, Social→»Soziale Netzwerke«, Productivity→»Produktivität«, Search→»Suche«, Reference→»Nachschlagewerke«, Entertainment→»Unterhaltung«, Other→»Sonstiges«.

- [ ] **Step 2: Failing test schreiben**

`frontend/src/lib/categories.test.ts`:
```typescript
import { describe, it, expect } from 'vitest';
import { CATEGORIES, categoryIcon } from './categories';

describe('categories', () => {
	it('enthält genau die 10 Backend-Keys', () => {
		expect(CATEGORIES).toEqual([
			'dev',
			'shopping',
			'video',
			'news',
			'social',
			'productivity',
			'search',
			'reference',
			'entertainment',
			'other'
		]);
	});
	it('liefert für jede Kategorie ein Icon', () => {
		for (const c of CATEGORIES) expect(categoryIcon(c)).toBeTruthy();
		expect(categoryIcon('unknown')).toBeTruthy();
	});
});
```

- [ ] **Step 3: Test laufen lassen (muss scheitern)**

Run: `npm --prefix frontend run test -- --run src/lib/categories.test.ts`
Expected: FAIL.

- [ ] **Step 4: `categories.ts` implementieren**

`frontend/src/lib/categories.ts`:
```typescript
import type { Component } from 'svelte';
import {
	Code,
	ShoppingCart,
	Video,
	Newspaper,
	Users,
	CheckSquare,
	Search,
	BookOpen,
	Clapperboard,
	Tag
} from '@lucide/svelte';
import { m } from '$lib/paraglide/messages.js';

/** Die festen Kategorie-Keys – identisch zum Backend-Enum, feste Reihenfolge. */
export const CATEGORIES = [
	'dev',
	'shopping',
	'video',
	'news',
	'social',
	'productivity',
	'search',
	'reference',
	'entertainment',
	'other'
] as const;

const ICONS: Record<string, Component> = {
	dev: Code,
	shopping: ShoppingCart,
	video: Video,
	news: Newspaper,
	social: Users,
	productivity: CheckSquare,
	search: Search,
	reference: BookOpen,
	entertainment: Clapperboard,
	other: Tag
};

/** Lucide-Icon-Komponente für eine Kategorie (Fallback: Tag). */
export function categoryIcon(key: string): Component {
	return ICONS[key] ?? Tag;
}

/** Lokalisiertes Label einer Kategorie (Fallback: der Key selbst). */
export function categoryLabel(key: string): string {
	const fn = (m as Record<string, () => string>)[`cat_${key}`];
	return typeof fn === 'function' ? fn() : key;
}
```

- [ ] **Step 5: Test laufen lassen (muss bestehen)**

Run: `npm --prefix frontend run test -- --run src/lib/categories.test.ts`
Expected: grün.

- [ ] **Step 6: Design-Utilities in `site.css` ergänzen**

An `frontend/src/lib/styles/site.css` anhängen:
```css
/* Farbige Icon-Kachel nach dem Muster der Extension (option.css). */
.icon-tile {
	width: 40px;
	height: 40px;
	border-radius: 12px;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	color: var(--icon-color, var(--accent-color));
	background: oklch(from var(--icon-color, var(--accent-color)) l c h / 12%);
	flex-shrink: 0;
}

/* Badge (Typ, Kategorie, Warnung). */
.badge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 8px;
	border-radius: 999px;
	font-size: 0.8em;
	background: var(--bg-tertiary);
	color: var(--text-secondary);
	border: 1px solid var(--border-color);
}
.badge.badge-warning {
	color: var(--warning-color);
	border-color: var(--warning-color);
}

/* Kartengitter und Filterleiste. */
.grid-cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: 16px;
}
.filter-bar {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: center;
}
```

- [ ] **Step 7: check + committen**

```bash
npm --prefix frontend run check
git -C .claude/worktrees/sp3-public-frontend add frontend/src/lib/categories.ts frontend/src/lib/categories.test.ts frontend/src/lib/styles/site.css frontend/messages/en.json frontend/messages/de.json
git -C .claude/worktrees/sp3-public-frontend commit -m "Ergänze Kategorie-Metadaten und Design-Utilities"
```

---

## Task 6: Präsentationskomponenten

Reine, zustandslose Komponenten. Props rein, Events raus.

**Files:**
- Create: `frontend/src/lib/components/Badge.svelte`
- Create: `frontend/src/lib/components/Spinner.svelte`
- Create: `frontend/src/lib/components/EmptyState.svelte`
- Create: `frontend/src/lib/components/ErrorState.svelte`
- Create: `frontend/src/lib/components/Pagination.svelte`
- Create: `frontend/src/lib/components/EntryCard.svelte`
- Create: `frontend/src/lib/components/EntryCard.test.ts`
- Create: `frontend/src/lib/components/Pagination.test.ts`

**Interfaces:**
- Consumes: `EntryListItem` aus `$lib/api`; `categoryLabel`, `categoryIcon` aus `$lib/categories`; `localizeHref` aus Paraglide.
- Produces (Props):
  - `Badge`: `{ text: string; variant?: 'default' | 'warning'; icon?: Component }`.
  - `Spinner`: `{ label?: string }`.
  - `EmptyState`: `{ title: string; hint?: string }`.
  - `ErrorState`: `{ message: string; onRetry?: () => void }`.
  - `Pagination`: `{ page: number; perPage: number; total: number; onPage: (p: number) => void }`.
  - `EntryCard`: `{ entry: EntryListItem }` – verlinkt via `localizeHref('/entry/'+formatId)` auf die Detailseite; zeigt Name, Typ-Badge, Kurzbeschreibung, bis zu 3 Kategorie-Badges, Install-Zähler; `deprecated` als Warnbadge.

- [ ] **Step 1: EntryCard-Test schreiben**

`frontend/src/lib/components/EntryCard.test.ts`:
```typescript
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/svelte';
import EntryCard from './EntryCard.svelte';
import type { EntryListItem } from '$lib/api';

const entry: EntryListItem = {
	formatId: 'com.example.menu',
	type: 'menu',
	name: 'Example Menu',
	description: 'Ein Beispiel',
	categories: ['dev'],
	tags: ['x'],
	domains: ['example.com'],
	installCount: 42,
	currentVersion: '1.0.0',
	deprecated: false,
	successorFormatId: null,
	screenshotUrl: null,
	updatedAt: '2026-07-01T00:00:00Z'
};

describe('EntryCard', () => {
	it('zeigt Name, Install-Zähler und einen Detail-Link', () => {
		render(EntryCard, { entry });
		expect(screen.getByText('Example Menu')).toBeInTheDocument();
		expect(screen.getByText('42')).toBeInTheDocument();
		const link = screen.getByRole('link');
		expect(link.getAttribute('href')).toContain('com.example.menu');
	});
	it('markiert veraltete Einträge', () => {
		render(EntryCard, { entry: { ...entry, deprecated: true } });
		expect(screen.getByText(/deprecated|veraltet/i)).toBeInTheDocument();
	});
});
```

- [ ] **Step 2: Pagination-Test schreiben**

`frontend/src/lib/components/Pagination.test.ts`:
```typescript
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/svelte';
import Pagination from './Pagination.svelte';

describe('Pagination', () => {
	it('deaktiviert Zurück auf Seite 1 und ruft onPage bei Weiter', async () => {
		const onPage = vi.fn();
		render(Pagination, { page: 1, perPage: 20, total: 60, onPage });
		const prev = screen.getByRole('button', { name: /prev|zurück/i });
		expect(prev).toBeDisabled();
		const next = screen.getByRole('button', { name: /next|weiter/i });
		next.click();
		expect(onPage).toHaveBeenCalledWith(2);
	});
});
```

- [ ] **Step 3: Tests laufen lassen (müssen scheitern)**

Run: `npm --prefix frontend run test -- --run src/lib/components`
Expected: FAIL (Komponenten fehlen).

- [ ] **Step 4: Nötige Messages ergänzen**

In `frontend/messages/en.json` + `de.json` ergänzen (en / de):
```
badge_deprecated: "Deprecated" / "Veraltet"
badge_transform: "Contains code" / "Enthält Code"
type_menu: "Menu" / "Menü"
type_engine: "Engine" / "Suchmaschine"
installs: "installs" / "Installationen"
pager_prev: "Previous" / "Zurück"
pager_next: "Next" / "Weiter"
pager_info: "Page {page} of {total}" / "Seite {page} von {total}"
state_empty_title: "Nothing found" / "Nichts gefunden"
state_error_retry: "Retry" / "Erneut versuchen"
```

- [ ] **Step 5: Komponenten implementieren**

`frontend/src/lib/components/Badge.svelte`:
```svelte
<script lang="ts">
	import type { Component } from 'svelte';
	let { text, variant = 'default', icon }: { text: string; variant?: 'default' | 'warning'; icon?: Component } = $props();
	const Icon = icon;
</script>

<span class="badge" class:badge-warning={variant === 'warning'}>
	{#if Icon}<Icon size={14} />{/if}
	{text}
</span>
```

`frontend/src/lib/components/Spinner.svelte`:
```svelte
<script lang="ts">
	import { LoaderCircle } from '@lucide/svelte';
	let { label }: { label?: string } = $props();
</script>

<span class="spinner" role="status" aria-live="polite">
	<LoaderCircle size={20} class="spin" />
	{#if label}<span>{label}</span>{/if}
</span>

<style>
	.spinner {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		color: var(--text-secondary);
	}
	:global(.spin) {
		animation: spin 1s linear infinite;
	}
	@keyframes spin {
		to {
			transform: rotate(360deg);
		}
	}
</style>
```

`frontend/src/lib/components/EmptyState.svelte`:
```svelte
<script lang="ts">
	let { title, hint }: { title: string; hint?: string } = $props();
</script>

<div class="card" style="text-align:center; padding:32px;">
	<p style="font-weight:600;">{title}</p>
	{#if hint}<p style="color:var(--text-secondary);">{hint}</p>{/if}
</div>
```

`frontend/src/lib/components/ErrorState.svelte`:
```svelte
<script lang="ts">
	import { m } from '$lib/paraglide/messages.js';
	let { message, onRetry }: { message: string; onRetry?: () => void } = $props();
</script>

<div class="card" style="text-align:center; padding:32px;">
	<p style="color:var(--danger-color); font-weight:600;">{message}</p>
	{#if onRetry}<button class="btn" onclick={onRetry}>{m.state_error_retry()}</button>{/if}
</div>
```

`frontend/src/lib/components/Pagination.svelte`:
```svelte
<script lang="ts">
	import { m } from '$lib/paraglide/messages.js';
	let { page, perPage, total, onPage }: { page: number; perPage: number; total: number; onPage: (p: number) => void } = $props();
	const lastPage = $derived(Math.max(1, Math.ceil(total / perPage)));
</script>

<nav class="filter-bar" aria-label="Pagination">
	<button class="btn" disabled={page <= 1} onclick={() => onPage(page - 1)}>{m.pager_prev()}</button>
	<span>{m.pager_info({ page, total: lastPage })}</span>
	<button class="btn" disabled={page >= lastPage} onclick={() => onPage(page + 1)}>{m.pager_next()}</button>
</nav>
```

`frontend/src/lib/components/EntryCard.svelte`:
```svelte
<script lang="ts">
	import type { EntryListItem } from '$lib/api';
	import { localizeHref } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import { categoryLabel } from '$lib/categories';
	import Badge from './Badge.svelte';

	let { entry }: { entry: EntryListItem } = $props();
	const href = $derived(localizeHref(`/entry/${entry.formatId}`));
	const typeLabel = $derived(entry.type === 'menu' ? m.type_menu() : m.type_engine());
</script>

<a class="card entry-card" {href}>
	<div class="entry-head">
		<strong>{entry.name}</strong>
		<Badge text={typeLabel} />
	</div>
	{#if entry.description}<p class="entry-desc">{entry.description}</p>{/if}
	<div class="entry-cats">
		{#each entry.categories.slice(0, 3) as cat}
			<Badge text={categoryLabel(cat)} />
		{/each}
		{#if entry.deprecated}<Badge text={m.badge_deprecated()} variant="warning" />{/if}
	</div>
	<div class="entry-foot">
		<span>{entry.installCount}</span>
		<span>{m.installs()}</span>
	</div>
</a>

<style>
	.entry-card {
		display: flex;
		flex-direction: column;
		gap: 8px;
		text-decoration: none;
		color: inherit;
	}
	.entry-head {
		display: flex;
		justify-content: space-between;
		align-items: center;
		gap: 8px;
	}
	.entry-desc {
		color: var(--text-secondary);
		display: -webkit-box;
		-webkit-line-clamp: 2;
		-webkit-box-orient: vertical;
		overflow: hidden;
	}
	.entry-cats {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}
	.entry-foot {
		display: flex;
		gap: 4px;
		color: var(--text-muted);
		font-size: 0.85em;
	}
</style>
```

- [ ] **Step 6: Tests laufen lassen (müssen bestehen)**

Run: `npm --prefix frontend run test -- --run src/lib/components`
Expected: grün. Falls `.btn`-Klasse fehlt: sie kommt aus `gestura-common.css`; wenn nicht vorhanden, in `site.css` eine schlichte `.btn`-Regel nach dem Extension-Muster ergänzen (Padding, `border-radius`, `--accent-color`).

- [ ] **Step 7: svelte-check + committen**

```bash
npm --prefix frontend run check
git -C .claude/worktrees/sp3-public-frontend add frontend/src/lib/components frontend/messages
git -C .claude/worktrees/sp3-public-frontend commit -m "Ergänze Präsentationskomponenten mit Tests"
```

---

## Task 7: Layout (Header, Footer, Theme- und Sprach-Umschalter)

**Files:**
- Create: `frontend/src/lib/components/ThemeToggle.svelte`
- Create: `frontend/src/lib/components/LangToggle.svelte`
- Create: `frontend/src/lib/components/Header.svelte`
- Create: `frontend/src/lib/components/Footer.svelte`
- Modify: `frontend/src/routes/+layout.svelte`
- Modify: `frontend/messages/en.json`, `de.json`

**Interfaces:**
- Consumes: `locales`, `getLocale`, `localizeHref` aus Paraglide; Logo-Assets aus `$lib/assets/logo/`.
- Produces: globales Layout mit Header/Footer um `{@render children()}`.

- [ ] **Step 1: Messages ergänzen**

`en.json` / `de.json`:
```
nav_home: "Home" / "Start"
nav_docs: "Format & Schema" / "Format & Schema"
footer_about: "About" / "Über"
footer_privacy: "Privacy" / "Datenschutz"
footer_imprint: "Imprint" / "Impressum"
footer_works_with: "Works with the Gestura extension" / "Funktioniert mit der Gestura-Erweiterung"
theme_label: "Theme" / "Design"
```

- [ ] **Step 2: ThemeToggle implementieren**

`frontend/src/lib/components/ThemeToggle.svelte` – drei Modi auto/light/dark, schreibt `gestura_index_theme` in localStorage und setzt `data-theme` auf `<html>` (dieselbe Logik wie der No-Flash-Init in `app.html`):
```svelte
<script lang="ts">
	import { onMount } from 'svelte';
	import { Sun, Moon, MonitorSmartphone } from '@lucide/svelte';

	type Mode = 'auto' | 'light' | 'dark';
	let mode = $state<Mode>('auto');

	function apply(m: Mode) {
		const actual = m === 'auto' ? (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark') : m;
		document.documentElement.setAttribute('data-theme', actual);
	}
	function cycle() {
		mode = mode === 'auto' ? 'light' : mode === 'light' ? 'dark' : 'auto';
		try {
			localStorage.setItem('gestura_index_theme', mode);
		} catch {
			/* ignore */
		}
		apply(mode);
	}
	onMount(() => {
		try {
			mode = (localStorage.getItem('gestura_index_theme') as Mode) || 'auto';
		} catch {
			/* ignore */
		}
	});
</script>

<button class="btn icon-btn" onclick={cycle} title="Theme" aria-label="Theme">
	{#if mode === 'light'}<Sun size={18} />{:else if mode === 'dark'}<Moon size={18} />{:else}<MonitorSmartphone size={18} />{/if}
</button>
```

- [ ] **Step 3: LangToggle implementieren**

`frontend/src/lib/components/LangToggle.svelte`:
```svelte
<script lang="ts">
	import { page } from '$app/state';
	import { locales, getLocale, localizeHref } from '$lib/paraglide/runtime';
</script>

<div class="lang-toggle">
	{#each locales as locale}
		<a
			href={localizeHref(page.url.pathname, { locale })}
			data-sveltekit-reload
			class:active={getLocale() === locale}>{locale.toUpperCase()}</a
		>
	{/each}
</div>

<style>
	.lang-toggle {
		display: inline-flex;
		gap: 4px;
	}
	.lang-toggle a {
		padding: 2px 8px;
		border-radius: 8px;
		text-decoration: none;
		color: var(--text-secondary);
	}
	.lang-toggle a.active {
		background: var(--bg-tertiary);
		color: var(--text-primary);
	}
</style>
```

- [ ] **Step 4: Header implementieren**

`frontend/src/lib/components/Header.svelte` – Logo-Kachel (hell/dunkel), Schriftzug »Gestura« + Badge »Index«, Navigation (Start, Stöbern, Format & Schema) via `localizeHref`, ThemeToggle, LangToggle. Logo-Import:
```svelte
<script lang="ts">
	import logoLight from '$lib/assets/logo/icon128.png';
	import logoDark from '$lib/assets/logo/icon128-dark.png';
	import { localizeHref } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import ThemeToggle from './ThemeToggle.svelte';
	import LangToggle from './LangToggle.svelte';
</script>

<header class="site-header">
	<a class="brand" href={localizeHref('/')}>
		<span class="logo-img">
			<img src={logoLight} alt="" class="logo-light" width="36" height="36" />
			<img src={logoDark} alt="" class="logo-dark" width="36" height="36" />
		</span>
		<span class="brand-name">Gestura</span>
		<span class="version">Index</span>
	</a>
	<nav class="site-nav">
		<a href={localizeHref('/')}>{m.nav_home()}</a>
		<a href={localizeHref('/browse')}>{m.nav_browse()}</a>
		<a href={localizeHref('/docs')}>{m.nav_docs()}</a>
	</nav>
	<div class="header-actions">
		<ThemeToggle />
		<LangToggle />
	</div>
</header>

<style>
	.site-header {
		display: flex;
		align-items: center;
		gap: 16px;
		flex-wrap: wrap;
		padding: 12px 0;
	}
	.brand {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		text-decoration: none;
		color: inherit;
	}
	.logo-img {
		width: 36px;
		height: 36px;
		border-radius: 10px;
		overflow: hidden;
		display: inline-flex;
	}
	.logo-dark {
		display: none;
	}
	:global([data-theme='dark']) .logo-dark {
		display: inline;
	}
	:global([data-theme='dark']) .logo-light {
		display: none;
	}
	.brand-name {
		font-weight: 700;
		font-size: 1.2em;
	}
	.site-nav {
		display: flex;
		gap: 16px;
		margin-inline-start: auto;
	}
	.site-nav a {
		text-decoration: none;
		color: var(--text-secondary);
	}
	.header-actions {
		display: inline-flex;
		gap: 8px;
		align-items: center;
	}
</style>
```
Falls die `.version`-Badge-Klasse aus `gestura-common.css` nicht existiert, im `<style>` eine schlichte Badge nach dem Extension-Muster ergänzen (kleiner, `--accent-color`-getönt).

- [ ] **Step 5: Footer implementieren**

`frontend/src/lib/components/Footer.svelte` – Links zu About/Privacy/Imprint via `localizeHref`, Repo-Link (`https://github.com/PPP01/gestura-index`), Hinweis `m.footer_works_with()`.

- [ ] **Step 6: Layout einbinden**

`frontend/src/routes/+layout.svelte` anpassen (Header/Footer um den Inhalt, bestehende CSS-Imports behalten):
```svelte
<script lang="ts">
	import '$lib/styles/gestura-common.css';
	import '$lib/styles/site.css';
	import favicon from '$lib/assets/logo/icon32.png';
	import Header from '$lib/components/Header.svelte';
	import Footer from '$lib/components/Footer.svelte';

	let { children } = $props();
</script>

<svelte:head>
	<link rel="icon" href={favicon} />
</svelte:head>

<div class="page-shell">
	<Header />
	<main class="container">
		{@render children()}
	</main>
	<Footer />
</div>
```

- [ ] **Step 7: Build + check + committen**

```bash
npm --prefix frontend run build
npm --prefix frontend run check
git -C .claude/worktrees/sp3-public-frontend add frontend/src frontend/messages
git -C .claude/worktrees/sp3-public-frontend commit -m "Ergänze Layout mit Header, Footer, Theme- und Sprach-Umschalter"
```

---

## Task 8: Startseite + Sprach-Weiche

**Files:**
- Modify: `frontend/src/routes/+page.svelte`
- Modify: `frontend/messages/en.json`, `de.json`

**Interfaces:**
- Consumes: `CATEGORIES`, `categoryLabel`, `categoryIcon`; `localizeHref`, `getLocale`; `browser` aus `$app/environment`; `goto` aus `$app/navigation`.

- [ ] **Step 1: Messages ergänzen**

```
hero_tagline (en): "Discover and share Gestura menus and search engines." / (de) "Gestura-Menüs und Suchmaschinen entdecken und teilen."
hero_sub (en): "The extension works entirely without this index — it is an optional, free add-on." / (de) "Die Erweiterung funktioniert vollständig ohne diesen Index — er ist ein optionales, kostenloses Zusatzangebot."
search_placeholder (en): "Search menus and engines…" / (de) "Menüs und Suchmaschinen suchen …"
home_categories (en): "Categories" / (de) "Kategorien"
home_docs_cta (en): "How the exchange format works" / (de) "Wie das Austauschformat funktioniert"
```

- [ ] **Step 2: Startseite implementieren (mit Sprach-Weiche)**

`frontend/src/routes/+page.svelte`:
```svelte
<script lang="ts">
	import { browser } from '$app/environment';
	import { goto } from '$app/navigation';
	import { localizeHref, getLocale } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import { CATEGORIES, categoryLabel, categoryIcon } from '$lib/categories';

	// Sprach-Weiche: die nackte Wurzel / (ohne Präfix) auf die lokalisierte URL lenken.
	if (browser && location.pathname === '/') {
		location.replace(localizeHref('/', { locale: getLocale() }));
	}

	let query = $state('');
	function submitSearch(e: Event) {
		e.preventDefault();
		const q = query.trim();
		goto(localizeHref(`/browse${q ? `?q=${encodeURIComponent(q)}` : ''}`));
	}
</script>

<svelte:head>
	<title>Gestura Index</title>
	<meta name="description" content={m.hero_tagline()} />
</svelte:head>

<section class="hero">
	<h1>Gestura Index</h1>
	<p class="hero-tagline">{m.hero_tagline()}</p>
	<p class="hero-sub">{m.hero_sub()}</p>
	<form onsubmit={submitSearch} class="hero-search">
		<input type="search" bind:value={query} placeholder={m.search_placeholder()} aria-label={m.search_placeholder()} />
	</form>
</section>

<section>
	<h2>{m.home_categories()}</h2>
	<div class="grid-cards">
		{#each CATEGORIES as cat}
			{@const Icon = categoryIcon(cat)}
			<a class="card cat-tile" href={localizeHref(`/browse?category=${cat}`)}>
				<span class="icon-tile"><Icon size={20} /></span>
				<span>{categoryLabel(cat)}</span>
			</a>
		{/each}
	</div>
</section>

<section>
	<a class="btn" href={localizeHref('/docs')}>{m.home_docs_cta()}</a>
</section>

<style>
	.hero {
		padding: 24px 0;
	}
	.hero-tagline {
		font-size: 1.2em;
		color: var(--text-secondary);
	}
	.hero-search input {
		width: 100%;
		max-width: 480px;
	}
	.cat-tile {
		display: flex;
		align-items: center;
		gap: 12px;
		text-decoration: none;
		color: inherit;
	}
</style>
```

- [ ] **Step 3: Build + check**

Run: `npm --prefix frontend run build && npm --prefix frontend run check`
Expected: build erzeugt weiterhin `build/en.html` + `build/de.html`; check 0 Fehler.

- [ ] **Step 4: Manuelle Sicht (dev-Server)**

```bash
npm --prefix frontend run dev -- --port 5199
```
Prüfen: `http://localhost:5199/` leitet auf `/en` bzw. `/de`; Kategorie-Kacheln verlinken auf `/…/browse?category=…`; Sprach-Umschalter wechselt. Danach dev-Server stoppen.

- [ ] **Step 5: Committen**

```bash
git -C .claude/worktrees/sp3-public-frontend add frontend/src/routes/+page.svelte frontend/messages
git -C .claude/worktrees/sp3-public-frontend commit -m "Ergänze Startseite mit Suche, Kategorien und Sprach-Weiche"
```

---

## Task 9: Stöbern-Seite (Live-Suche)

**Files:**
- Create: `frontend/src/routes/browse/+page.ts`
- Create: `frontend/src/routes/browse/+page.svelte`
- Modify: `frontend/messages/en.json`, `de.json`

**Interfaces:**
- Consumes: `listEntries`, `EntryListResponse`, `EntryQuery` aus `$lib/api`; `parseQuery`, `toSearchParams`, `Sequence`, `debounce` aus `$lib/browse-state`; `CATEGORIES`, `categoryLabel`; `EntryCard`, `Spinner`, `EmptyState`, `ErrorState`, `Pagination`; `goto` aus `$app/navigation`, `page` aus `$app/state`.

- [ ] **Step 1: Prerender für diese Route deaktivieren**

`frontend/src/routes/browse/+page.ts`:
```typescript
// Client-seitig gerendert: Daten kommen zur Laufzeit von der API.
export const prerender = false;
export const ssr = false;
```

- [ ] **Step 2: Messages ergänzen**

```
browse_title (en): "Browse" / (de) "Stöbern"
filter_type_all (en): "All types" / (de) "Alle Typen"
filter_category_all (en): "All categories" / (de) "Alle Kategorien"
filter_site (en): "Domain" / (de) "Domain"
filter_tag (en): "Tag" / (de) "Tag"
sort_newest (en): "Newest" / (de) "Neueste"
browse_empty_hint (en): "Try a different search or filter." / (de) "Andere Suche oder anderen Filter versuchen."
results_count (en): "{total} results" / (de) "{total} Ergebnisse"
```

- [ ] **Step 3: Stöbern-Seite implementieren**

`frontend/src/routes/browse/+page.svelte`:
```svelte
<script lang="ts">
	import { page } from '$app/state';
	import { goto } from '$app/navigation';
	import { listEntries, type EntryListResponse, type EntryQuery } from '$lib/api';
	import { parseQuery, toSearchParams, Sequence, debounce } from '$lib/browse-state';
	import { localizeHref } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import { CATEGORIES, categoryLabel } from '$lib/categories';
	import EntryCard from '$lib/components/EntryCard.svelte';
	import Spinner from '$lib/components/Spinner.svelte';
	import EmptyState from '$lib/components/EmptyState.svelte';
	import ErrorState from '$lib/components/ErrorState.svelte';
	import Pagination from '$lib/components/Pagination.svelte';

	const seq = new Sequence();
	let result = $state<EntryListResponse | null>(null);
	let loading = $state(false);
	let error = $state<string | null>(null);

	// Freitext-Feld: eigener State, damit Tippen flüssig bleibt; Debounce schreibt in die URL.
	let qField = $state(parseQuery(page.url.searchParams).q ?? '');

	// Aktueller Filter direkt aus der URL abgeleitet (Single Source of Truth).
	const query = $derived<EntryQuery>(parseQuery(page.url.searchParams));

	async function load(q: EntryQuery) {
		const ticket = seq.next();
		loading = true;
		error = null;
		try {
			const res = await listEntries(q);
			if (seq.isCurrent(ticket)) result = res;
		} catch (e) {
			if (seq.isCurrent(ticket)) error = e instanceof Error ? e.message : String(e);
		} finally {
			if (seq.isCurrent(ticket)) loading = false;
		}
	}

	// Bei jeder URL-Änderung neu laden.
	$effect(() => {
		load(query);
	});

	function updateUrl(next: EntryQuery) {
		const qs = toSearchParams(next).toString();
		goto(localizeHref(`/browse${qs ? `?${qs}` : ''}`), { replaceState: true, keepFocus: true, noScroll: true });
	}

	const pushQ = debounce((value: string) => {
		updateUrl({ ...query, q: value || undefined, page: undefined });
	}, 250);

	function onQInput() {
		pushQ(qField);
	}
	function setFilter(patch: Partial<EntryQuery>) {
		updateUrl({ ...query, ...patch, page: undefined });
	}
	function setPage(p: number) {
		updateUrl({ ...query, page: p });
	}
</script>

<svelte:head>
	<title>{m.browse_title()} · Gestura Index</title>
</svelte:head>

<h1>{m.browse_title()}</h1>

<div class="filter-bar">
	<input
		type="search"
		bind:value={qField}
		oninput={onQInput}
		placeholder={m.search_placeholder()}
		aria-label={m.search_placeholder()}
	/>
	<select value={query.type ?? ''} onchange={(e) => setFilter({ type: (e.currentTarget.value || undefined) as EntryQuery['type'] })}>
		<option value="">{m.filter_type_all()}</option>
		<option value="menu">{m.type_menu()}</option>
		<option value="engine">{m.type_engine()}</option>
	</select>
	<select value={query.category ?? ''} onchange={(e) => setFilter({ category: e.currentTarget.value || undefined })}>
		<option value="">{m.filter_category_all()}</option>
		{#each CATEGORIES as cat}
			<option value={cat}>{categoryLabel(cat)}</option>
		{/each}
	</select>
	<input
		type="text"
		value={query.tag ?? ''}
		onchange={(e) => setFilter({ tag: e.currentTarget.value || undefined })}
		placeholder={m.filter_tag()}
		aria-label={m.filter_tag()}
	/>
	<input
		type="text"
		value={query.site ?? ''}
		onchange={(e) => setFilter({ site: e.currentTarget.value || undefined })}
		placeholder={m.filter_site()}
		aria-label={m.filter_site()}
	/>
	{#if loading}<Spinner />{/if}
</div>

{#if error}
	<ErrorState message={error} onRetry={() => load(query)} />
{:else if result && result.items.length === 0}
	<EmptyState title={m.state_empty_title()} hint={m.browse_empty_hint()} />
{:else if result}
	<p style="color:var(--text-secondary);">{m.results_count({ total: result.total })}</p>
	<div class="grid-cards">
		{#each result.items as entry (entry.formatId)}
			<EntryCard {entry} />
		{/each}
	</div>
	<Pagination page={result.page} perPage={result.perPage} total={result.total} onPage={setPage} />
{:else}
	<Spinner />
{/if}
```

- [ ] **Step 4: Build + check**

Run: `npm --prefix frontend run build && npm --prefix frontend run check`
Expected: grün; `build/200.html` existiert weiterhin (Fallback für diese client-gerenderte Route).

- [ ] **Step 5: Manuelle Sicht gegen die Live-API**

```bash
npm --prefix frontend run dev -- --port 5199
```
`http://localhost:5199/en/browse` öffnen: Tippen filtert live (mit ~250 ms Verzögerung), die URL aktualisiert sich (`?q=`), Filter wirken sofort, Zurück-Button funktioniert. (Die Live-API liefert derzeit evtl. 0 Einträge → EmptyState ist dann korrekt.) dev-Server stoppen.

- [ ] **Step 6: Committen**

```bash
git -C .claude/worktrees/sp3-public-frontend add frontend/src/routes/browse frontend/messages
git -C .claude/worktrees/sp3-public-frontend commit -m "Ergänze Stöbern-Seite mit Live-Suche und URL-Filtern"
```

---

## Task 10: Detailseite (Versionen, Download, Melden)

**Files:**
- Create: `frontend/src/routes/entry/[formatId]/+page.ts`
- Create: `frontend/src/routes/entry/[formatId]/+page.svelte`
- Create: `frontend/src/lib/download.ts`
- Create: `frontend/src/lib/download.test.ts`
- Modify: `frontend/messages/en.json`, `de.json`

**Interfaces:**
- Consumes: `getEntry`, `EntryDetail`, `downloadVersion`, `downloadVersionUrl`, `pingInstall`, `reportEntry`, `absoluteScreenshotUrl` aus `$lib/api`; `page` aus `$app/state`.
- Produces: `triggerJsonDownload(data: unknown, filename: string): void` (in `download.ts`, testbar über eine injizierbare DOM-Abstraktion).

- [ ] **Step 1: Prerender deaktivieren**

`frontend/src/routes/entry/[formatId]/+page.ts`:
```typescript
// Dynamische Route mit Laufzeit-Parameter: nicht prerendern, Fallback-SPA nutzen.
export const prerender = false;
export const ssr = false;
```

- [ ] **Step 2: Download-Helfer testen**

`frontend/src/lib/download.test.ts`:
```typescript
import { describe, it, expect, vi } from 'vitest';
import { buildDownloadFilename } from './download';

describe('buildDownloadFilename', () => {
	it('kombiniert formatId und semver zu einem .json-Namen', () => {
		expect(buildDownloadFilename('com.example.menu', '1.2.3')).toBe('com.example.menu-1.2.3.json');
	});
	it('ersetzt unzulässige Zeichen', () => {
		expect(buildDownloadFilename('a/b:c', '1.0.0')).toBe('a-b-c-1.0.0.json');
	});
});
```

- [ ] **Step 3: Test laufen lassen (muss scheitern), dann `download.ts` implementieren**

Run: `npm --prefix frontend run test -- --run src/lib/download.test.ts` → FAIL.

`frontend/src/lib/download.ts`:
```typescript
/** Baut einen sicheren Dateinamen aus formatId und Version. */
export function buildDownloadFilename(formatId: string, semver: string): string {
	const safe = `${formatId}-${semver}`.replace(/[^a-zA-Z0-9._-]/g, '-');
	return `${safe}.json`;
}

/** Bietet ein JSON-Objekt als Datei-Download an (nur im Browser). */
export function triggerJsonDownload(data: unknown, filename: string): void {
	const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
	const url = URL.createObjectURL(blob);
	const a = document.createElement('a');
	a.href = url;
	a.download = filename;
	document.body.appendChild(a);
	a.click();
	a.remove();
	URL.revokeObjectURL(url);
}
```

Run erneut: Expected grün.

- [ ] **Step 4: Messages ergänzen**

```
detail_download (en): "Download" / (de) "Herunterladen"
detail_copy_url (en): "Copy import URL" / (de) "Import-URL kopieren"
detail_copied (en): "Copied" / (de) "Kopiert"
detail_versions (en): "Versions" / (de) "Versionen"
detail_domains (en): "Domains" / (de) "Domains"
detail_transform_warning (en): "This version contains executable code (transformCode). Only install it if you trust the author." / (de) "Diese Version enthält ausführbaren Code (transformCode). Nur installieren, wenn du dem Autor vertraust."
detail_deprecated (en): "This entry is deprecated." / (de) "Dieser Eintrag ist veraltet."
detail_successor (en): "Successor" / (de) "Nachfolger"
detail_not_found (en): "Entry not found" / (de) "Eintrag nicht gefunden"
detail_back (en): "Back to browse" / (de) "Zurück zum Stöbern"
report_title (en): "Report this entry" / (de) "Diesen Eintrag melden"
report_reason (en): "Reason" / (de) "Grund"
report_spam: "Spam" / "Spam"
report_broken_links (en): "Broken links" / (de) "Defekte Links"
report_misleading (en): "Misleading" / (de) "Irreführend"
report_legal (en): "Legal issue" / (de) "Rechtsverstoß"
report_comment (en): "Comment (optional)" / (de) "Kommentar (optional)"
report_submit (en): "Send report" / (de) "Meldung senden"
report_thanks (en): "Thank you for your report." / (de) "Danke für deine Meldung."
```

- [ ] **Step 5: Detailseite implementieren**

`frontend/src/routes/entry/[formatId]/+page.svelte`:
```svelte
<script lang="ts">
	import { page } from '$app/state';
	import {
		getEntry,
		downloadVersion,
		downloadVersionUrl,
		pingInstall,
		reportEntry,
		type EntryDetail,
		type ReportReason
	} from '$lib/api';
	import { triggerJsonDownload, buildDownloadFilename } from '$lib/download';
	import { localizeHref } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import { categoryLabel } from '$lib/categories';
	import Badge from '$lib/components/Badge.svelte';
	import Spinner from '$lib/components/Spinner.svelte';
	import ErrorState from '$lib/components/ErrorState.svelte';
	import { TriangleAlert } from '@lucide/svelte';

	const formatId = $derived(page.params.formatId);
	let entry = $state<EntryDetail | null>(null);
	let loading = $state(true);
	let notFound = $state(false);
	let error = $state<string | null>(null);

	let copied = $state(false);
	let reportReason = $state<ReportReason>('spam');
	let reportComment = $state('');
	let reportSent = $state(false);
	let reportError = $state<string | null>(null);

	async function load() {
		loading = true;
		error = null;
		notFound = false;
		try {
			entry = await getEntry(formatId);
		} catch (e) {
			if (e && typeof e === 'object' && 'status' in e && (e as { status: number }).status === 404) {
				notFound = true;
			} else {
				error = e instanceof Error ? e.message : String(e);
			}
		} finally {
			loading = false;
		}
	}
	$effect(() => {
		load();
	});

	const currentVersion = $derived(entry?.currentVersion ?? null);
	const currentHasTransform = $derived(
		entry?.versions.find((v) => v.semver === currentVersion)?.hasTransformCode ?? false
	);

	async function download() {
		if (!entry || !currentVersion) return;
		const data = await downloadVersion(entry.formatId, currentVersion);
		triggerJsonDownload(data, buildDownloadFilename(entry.formatId, currentVersion));
		try {
			await pingInstall(entry.formatId);
		} catch {
			/* Install-Ping ist Best-Effort – Fehler nicht anzeigen. */
		}
	}
	async function copyUrl() {
		if (!entry || !currentVersion) return;
		await navigator.clipboard.writeText(downloadVersionUrl(entry.formatId, currentVersion));
		copied = true;
		setTimeout(() => (copied = false), 1500);
	}
	async function submitReport(e: Event) {
		e.preventDefault();
		if (!entry) return;
		reportError = null;
		try {
			await reportEntry(entry.formatId, {
				reason: reportReason,
				comment: reportComment.trim() || undefined
			});
			reportSent = true;
		} catch (err) {
			reportError = err instanceof Error ? err.message : String(err);
		}
	}
</script>

<svelte:head>
	<title>{entry?.name ?? formatId} · Gestura Index</title>
</svelte:head>

{#if loading}
	<Spinner />
{:else if notFound}
	<div class="card" style="text-align:center; padding:32px;">
		<h1>{m.detail_not_found()}</h1>
		<a class="btn" href={localizeHref('/browse')}>{m.detail_back()}</a>
	</div>
{:else if error}
	<ErrorState message={error} onRetry={load} />
{:else if entry}
	<article>
		<header class="detail-head">
			<h1>{entry.name}</h1>
			<Badge text={entry.type === 'menu' ? m.type_menu() : m.type_engine()} />
			{#if entry.deprecated}<Badge text={m.badge_deprecated()} variant="warning" />{/if}
		</header>

		{#if entry.deprecated}
			<p class="notice">{m.detail_deprecated()}
				{#if entry.successorFormatId}
					· {m.detail_successor()}: <a href={localizeHref(`/entry/${entry.successorFormatId}`)}>{entry.successorFormatId}</a>
				{/if}
			</p>
		{/if}

		<div class="detail-cats">
			{#each entry.categories as cat}<Badge text={categoryLabel(cat)} />{/each}
			{#each entry.tags as tag}<Badge text={`#${tag}`} />{/each}
		</div>

		{#if entry.screenshotUrl}
			<img class="screenshot" src={entry.screenshotUrl} alt={entry.name} loading="lazy" />
		{/if}

		{#if entry.description}<p>{entry.description}</p>{/if}

		{#if entry.domains.length}
			<p><strong>{m.detail_domains()}:</strong> {entry.domains.join(', ')}</p>
		{/if}

		<section class="card download-box">
			{#if currentHasTransform}
				<p class="warn"><TriangleAlert size={16} /> {m.detail_transform_warning()}</p>
			{/if}
			<div class="filter-bar">
				<button class="btn btn-primary" onclick={download} disabled={!currentVersion}>
					{m.detail_download()} {#if currentVersion}({currentVersion}){/if}
				</button>
				<button class="btn" onclick={copyUrl} disabled={!currentVersion}>
					{copied ? m.detail_copied() : m.detail_copy_url()}
				</button>
				<span>{entry.installCount} {m.installs()}</span>
			</div>
		</section>

		<section>
			<h2>{m.detail_versions()}</h2>
			<ul class="versions">
				{#each entry.versions as v (v.semver)}
					<li class="card">
						<div class="filter-bar">
							<strong>{v.semver}</strong>
							{#if v.hasTransformCode}<Badge text={m.badge_transform()} variant="warning" icon={TriangleAlert} />{/if}
							<span style="color:var(--text-muted);">{new Date(v.submittedAt).toLocaleDateString()}</span>
						</div>
						{#if v.changelog}<p>{v.changelog}</p>{/if}
					</li>
				{/each}
			</ul>
		</section>

		<section class="card">
			<h2>{m.report_title()}</h2>
			{#if reportSent}
				<p style="color:var(--success-color);">{m.report_thanks()}</p>
			{:else}
				<form onsubmit={submitReport}>
					<label>
						{m.report_reason()}
						<select bind:value={reportReason}>
							<option value="spam">{m.report_spam()}</option>
							<option value="broken_links">{m.report_broken_links()}</option>
							<option value="misleading">{m.report_misleading()}</option>
							<option value="legal">{m.report_legal()}</option>
						</select>
					</label>
					<label>
						{m.report_comment()}
						<textarea bind:value={reportComment} maxlength="2000" rows="3"></textarea>
					</label>
					{#if reportError}<p style="color:var(--danger-color);">{reportError}</p>{/if}
					<button class="btn" type="submit">{m.report_submit()}</button>
				</form>
			{/if}
		</section>
	</article>
{/if}

<style>
	.detail-head {
		display: flex;
		align-items: center;
		gap: 12px;
		flex-wrap: wrap;
	}
	.detail-cats {
		display: flex;
		gap: 6px;
		flex-wrap: wrap;
		margin: 8px 0;
	}
	.screenshot {
		max-width: 100%;
		border-radius: 12px;
		border: 1px solid var(--border-color);
	}
	.download-box {
		margin: 16px 0;
	}
	.warn {
		color: var(--warning-color);
		display: flex;
		align-items: center;
		gap: 6px;
	}
	.notice {
		color: var(--warning-color);
	}
	.versions {
		list-style: none;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	form label {
		display: block;
		margin-bottom: 8px;
	}
	textarea {
		width: 100%;
	}
</style>
```
Falls `.btn-primary` in `gestura-common.css` fehlt, in `site.css` eine Variante mit `background: var(--accent-color); color: #fff;` ergänzen.

- [ ] **Step 6: Build + check + Tests**

```bash
npm --prefix frontend run test -- --run
npm --prefix frontend run build
npm --prefix frontend run check
```
Expected: alle grün.

- [ ] **Step 7: Committen**

```bash
git -C .claude/worktrees/sp3-public-frontend add frontend/src frontend/messages
git -C .claude/worktrees/sp3-public-frontend commit -m "Ergänze Detailseite mit Versionen, Download und Melden"
```

---

## Task 11: Format-&-Schema-Seite

**Files:**
- Create: `frontend/src/routes/docs/+page.svelte`
- Modify: `frontend/messages/en.json`, `de.json`

**Interfaces:** Prerendered (erbt `prerender = true`), rein statischer, lokalisierter Inhaltstext.

- [ ] **Step 1: Referenz lesen**

Lies `schema/exchange-schema.json` (im Repo) für die tatsächlichen Feldnamen und Regeln, damit der erklärende Text stimmt (Typen `gesturaMenu`/`gesturaEngine`, `id`, `version`, `name`/`description`, `items`/`url`/`patterns`, `transformCode`, `https:`-only, SemVer, Aktions-Whitelist, Limits).

- [ ] **Step 2: Messages für die Doku-Inhalte ergänzen**

Lege sprechende Keys an (`docs_title`, `docs_intro`, `docs_menu_heading`, `docs_engine_heading`, `docs_fields_heading`, `docs_rules_heading`, je ein Absatz-Key en/de). Halte die Texte in Prosa, deutsche Umlaute korrekt, Guillemets im de-Text.

- [ ] **Step 3: Seite implementieren**

`frontend/src/routes/docs/+page.svelte` – `<svelte:head>` mit Titel/Description, Abschnitte mit `.card`, Inhalte aus den Messages, Codebeispiel eines minimalen `gesturaMenu`/`gesturaEngine`-JSON als `<pre>`. Keine Live-Daten.

- [ ] **Step 4: Build + check**

Run: `npm --prefix frontend run build && npm --prefix frontend run check`
Expected: `build/en/docs.html` und `build/de/docs.html` (bzw. entsprechende Pfade) werden erzeugt; check grün.

- [ ] **Step 5: Committen**

```bash
git -C .claude/worktrees/sp3-public-frontend add frontend/src/routes/docs frontend/messages
git -C .claude/worktrees/sp3-public-frontend commit -m "Ergänze Format-&-Schema-Doku-Seite"
```

---

## Task 12: Über / Datenschutz / Impressum

**Files:**
- Create: `frontend/src/routes/about/+page.svelte`
- Create: `frontend/src/routes/privacy/+page.svelte`
- Create: `frontend/src/routes/imprint/+page.svelte`
- Modify: `frontend/messages/en.json`, `de.json`

**Interfaces:** Alle prerendered, rein statischer lokalisierter Text.

- [ ] **Step 1: Messages ergänzen**

- **Über:** was der Index ist, Verhältnis zur Extension (läuft vollständig ohne), optional/kostenlos, Repo-Link, Lizenz AGPL-3.0-or-later.
- **Datenschutz:** Datensparsamkeit – keine IP-Speicherung, anonyme Install-Zähler, keine Konten in dieser Phase, welche Daten die API sieht (eingereichte Inhalte sind öffentlich).
- **Impressum:** Struktur mit klar markierten Platzhaltern (`[Name]`, `[Anschrift]`, `[E-Mail]`) – **keine erfundenen Angaben**.

- [ ] **Step 2: Drei Seiten implementieren**

Je `+page.svelte` mit `<svelte:head>`-Titel, `.card`-Abschnitten und den Messages.

- [ ] **Step 3: Build + check**

Run: `npm --prefix frontend run build && npm --prefix frontend run check`
Expected: grün, Seiten je Sprache erzeugt.

- [ ] **Step 4: Committen**

```bash
git -C .claude/worktrees/sp3-public-frontend add frontend/src/routes frontend/messages
git -C .claude/worktrees/sp3-public-frontend commit -m "Ergänze Über-, Datenschutz- und Impressum-Seite"
```

---

## Task 13: SPA-Fallback-Routing + Abschluss-Integration

**Files:**
- Create: `frontend/static/.htaccess`
- Modify: `docs/design-system.md` (kurzer Hinweis, falls neue `.btn-primary`/`.version`-Regeln in `site.css` ergänzt wurden)

**Interfaces:** `static/.htaccess` wird von SvelteKit unverändert nach `build/` kopiert und sorgt auf dem Apache-Hosting dafür, dass unbekannte Pfade (client-gerenderte Routen wie `/en/entry/…`, `/de/browse`) die `200.html`-Fallback-Hülle erhalten.

- [ ] **Step 1: `.htaccess` für den statischen Build anlegen**

`frontend/static/.htaccess`:
```apache
# Statisches SvelteKit-Frontend (adapter-static mit SPA-Fallback).
Options -MultiViews
DirectoryIndex index.html

<IfModule mod_rewrite.c>
	RewriteEngine On

	# Vorhandene Dateien und Verzeichnisse direkt ausliefern.
	RewriteCond %{REQUEST_FILENAME} -f [OR]
	RewriteCond %{REQUEST_FILENAME} -d
	RewriteRule ^ - [L]

	# Alles andere (client-gerenderte Routen) auf die SPA-Fallback-Hülle.
	RewriteRule ^ /200.html [L]
</IfModule>
```

- [ ] **Step 2: Build und Fallback verifizieren**

```bash
npm --prefix frontend run build
test -f frontend/build/.htaccess && echo ".htaccess kopiert"
test -f frontend/build/200.html && echo "Fallback vorhanden"
ls frontend/build
```
Expected: `.htaccess` und `200.html` liegen in `build/`; `en.html`/`de.html` sowie die Unterseiten je Sprache existieren.

- [ ] **Step 3: Gesamter Grün-Durchlauf**

```bash
npm --prefix frontend run test -- --run
npm --prefix frontend run check
npm --prefix frontend run build
```
Expected: Tests grün, check 0 Fehler, Build erfolgreich.

- [ ] **Step 4: Manuelle End-to-End-Sicht (Preview des Builds)**

```bash
npm --prefix frontend run preview -- --port 5199
```
Prüfen: `/` leitet auf Sprache; Header-Navigation, Theme- und Sprach-Umschalter funktionieren; `/en/browse` und `/de/browse` laden (Live-API); eine Detailseite über einen Eintrag (falls die API welche liefert) oder der 404-Zweig; Footer-Links. Danach Preview stoppen.

- [ ] **Step 5: design-system.md nachziehen (falls nötig)**

Falls in `site.css` neue, aus der Extension abgeleitete Regeln ergänzt wurden (z. B. `.btn-primary`, `.version`), in `docs/design-system.md` unter »Übernommen« kurz vermerken.

- [ ] **Step 6: Abschluss-Commit**

```bash
git -C .claude/worktrees/sp3-public-frontend add frontend/static/.htaccess docs/design-system.md
git -C .claude/worktrees/sp3-public-frontend commit -m "Ergänze SPA-Fallback-Routing und schließe SP3 ab"
```

- [ ] **Step 7: Entwicklungszweig abschließen**

**REQUIRED SUB-SKILL:** superpowers:finishing-a-development-branch – Tests final prüfen, Optionen (Merge nach `main`) präsentieren, gewählte Option ausführen.

---

## Self-Review (gegen die Spec)

- **Spec-Abdeckung:** Rendering-Hybrid (T1, T9, T10) · i18n /en /de + Umschalter (T1, T7) · API-Client mit Screenshot-Präfix + problem+json (T3) · Start mit Suche + Kategorien + Sprach-Weiche (T8) · Stöbern mit Live-Suche/Debounce/Sequenz-Guard/URL-Filter/Pagination (T4, T9) · Detail mit Versionen/Download+Install-Ping/Import-URL/transformCode-Warnung/Melden/404 (T10) · Docs (T11) · Über/Datenschutz/Impressum (T12) · Zustände Laden/Fehler/Leer (T6, T9, T10) · Tests Vitest + api/browse-state/categories/Komponenten (T2–T6) · Design nach design-system.md (T5–T12) · Build-Rauchtest beide Sprachen (T13). **Deployment des Frontend-Uploads** bleibt laut Spec §10 außerhalb SP3; `static/.htaccess` (T13) macht den Build lauffähig, sobald er hochgeladen wird.
- **Platzhalter:** keine offenen TODOs; Impressum-Platzhalter sind bewusst und markiert.
- **Typkonsistenz:** `EntryQuery`, `EntryListItem`, `EntryDetail`, `ReportReason` einheitlich aus `$lib/api`; `parseQuery`/`toSearchParams`/`Sequence`/`debounce` einheitlich aus `$lib/browse-state`; `localizeHref`/`getLocale`/`locales` aus Paraglide-Runtime.
