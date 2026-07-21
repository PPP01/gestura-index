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
