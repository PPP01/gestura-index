import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import type { ClientOpts, EntryListItem, EntryListResponse, EntryQuery } from '$lib/api';

// $env/dynamic/public: wie bei allen Tests, die (transitiv) catalog.ts/api.ts
// importieren – catalog.ts liest PUBLIC_INDEX_INITIAL_LOAD beim Modul-Import.
vi.mock('$env/dynamic/public', () => ({ env: { PUBLIC_API_BASE: undefined } }));

// $app/state: page mit stabiler, leer-parametrisierter URL
const url = new URL('http://localhost/en/index');
vi.mock('$app/state', () => ({ page: { get url() { return url; } } }));
const goto = vi.fn();
vi.mock('$app/navigation', () => ({ goto: (...a: unknown[]) => goto(...a) }));

// Katalog-Fake über die API-list
function item(id: string, over: Partial<EntryListItem> = {}): EntryListItem {
	return {
		formatId: id, type: 'menu', name: id, description: null, categories: ['dev'],
		tags: ['git'], domains: ['example.com'], installCount: 1,
		rating: { average: null, count: 0 }, currentVersion: '1.0.0',
		deprecated: false, successorFormatId: null, screenshotUrl: null,
		updatedAt: '2026-01-01T00:00:00Z', ...over
	};
}
const listEntries = vi.fn(async (q: EntryQuery, _opts?: ClientOpts): Promise<EntryListResponse> => ({
	items: [item('a'), item('b', { categories: ['news'], tags: ['rss'] })],
	page: q.page ?? 1, perPage: 50, total: 2
}));
vi.mock('$lib/api', async (orig) => {
	const actual = await orig<typeof import('$lib/api')>();
	return { ...actual, listEntries: (q: EntryQuery, opts?: ClientOpts) => listEntries(q, opts) };
});

import Page from './index/+page.svelte';

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
	it('filtert on-the-fly nach Kategorie (Sidebar-Zeile)', async () => {
		render(Page);
		await waitFor(() => expect(screen.getByText('a')).toBeInTheDocument());
		// News-Kategorie-Zeile in der Sidebar klicken -> nur b bleibt
		const row = screen.getByRole('button', { name: /news/i });
		await fireEvent.click(row);
		await waitFor(() => expect(screen.queryByText('a')).not.toBeInTheDocument());
		expect(screen.getByText('b')).toBeInTheDocument();
		expect(goto).toHaveBeenCalled(); // URL-State geschrieben
	});
});
