import { describe, it, expect, vi } from 'vitest';

// Mock $env/dynamic/public für Tests (wie api.test.ts – catalog.ts liest
// PUBLIC_INDEX_INITIAL_LOAD beim Modul-Import).
vi.mock('$env/dynamic/public', () => ({
	env: {
		PUBLIC_INDEX_INITIAL_LOAD: undefined
	}
}));

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
		itemCount: 2,
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
