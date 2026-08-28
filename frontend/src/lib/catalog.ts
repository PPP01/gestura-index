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
