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
