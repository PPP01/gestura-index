import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/svelte';
import type { ClientOpts, EntryListItem, EntryListResponse, EntryQuery } from '$lib/api';
import { m } from '$lib/paraglide/messages.js';

// Wie bei allen Tests, die (transitiv) api.ts importieren: $env/dynamic/public
// muss gemockt werden (catalog.ts/api.ts lesen PUBLIC_*-Variablen beim
// Modul-Import) – siehe onepager.test.ts, EntryBlock.test.ts.
vi.mock('$env/dynamic/public', () => ({ env: { PUBLIC_API_BASE: undefined } }));

function item(id: string, over: Partial<EntryListItem> = {}): EntryListItem {
	return {
		formatId: id,
		type: 'menu',
		name: id,
		description: null,
		categories: ['dev'],
		tags: ['git'],
		domains: ['example.com'],
		itemCount: 2,
		installCount: 1,
		rating: { average: null, count: 0 },
		currentVersion: '1.0.0',
		deprecated: false,
		successorFormatId: null,
		screenshotUrl: null,
		updatedAt: '2026-01-01T00:00:00Z',
		...over
	};
}

const teaserItems: EntryListItem[] = [item('teaser-a'), item('teaser-b'), item('teaser-c')];

const listEntries = vi.fn(
	async (_q: EntryQuery, _opts?: ClientOpts): Promise<EntryListResponse> => ({
		items: teaserItems,
		page: 1,
		perPage: 3,
		total: 42
	})
);
vi.mock('$lib/api', async (orig) => {
	const actual = await orig<typeof import('$lib/api')>();
	return { ...actual, listEntries: (q: EntryQuery, opts?: ClientOpts) => listEntries(q, opts) };
});

import Page from './+page.svelte';

beforeEach(() => {
	listEntries.mockClear();
});

describe('C1 Marketing-Startseite', () => {
	it('rendert die H1-Teilsätze und die Status-Pill', () => {
		render(Page);
		expect(screen.getByText(m.c1_hero_h1_a())).toBeInTheDocument();
		expect(screen.getByText(m.c1_hero_h1_b())).toBeInTheDocument();
		expect(screen.getByText(m.c1_status_pill())).toBeInTheDocument();
	});

	it('#install enthält drei Store-Links', () => {
		const { container } = render(Page);
		const install = container.querySelector('#install');
		expect(install).not.toBeNull();
		const hrefs = [...within(install as HTMLElement).getAllByRole('link')].map((a) =>
			a.getAttribute('href')
		);
		expect(hrefs.some((h) => h?.includes('chromewebstore.google.com'))).toBe(true);
		expect(hrefs.some((h) => h?.includes('microsoftedge.microsoft.com'))).toBe(true);
		expect(hrefs.some((h) => h?.includes('addons.mozilla.org'))).toBe(true);
	});

	it('Discover-Link zeigt auf /index', () => {
		render(Page);
		const link = screen.getByRole('link', { name: m.c1_hero_discover() });
		expect(link.getAttribute('href')).toContain('/index');
	});

	it('Bento-Grid zeigt 6 Karten, davon eine breite Hero-Karte', () => {
		const { container } = render(Page);
		expect(container.querySelectorAll('.feature-tile')).toHaveLength(6);
		expect(container.querySelectorAll('.bento-hero')).toHaveLength(1);
	});

	it('Browser-Mock zeigt die vier Gesten-Pills und ist für Screenreader stumm', () => {
		const { container } = render(Page);
		expect(container.querySelectorAll('.mock-canvas .pill')).toHaveLength(4);
		expect(container.querySelector('.mock-wrap')).toHaveAttribute('aria-hidden', 'true');
	});

	it('lädt den Teaser (3 Einträge) und zeigt die echte Gesamtzahl', async () => {
		const { container } = render(Page);
		await waitFor(() => expect(listEntries).toHaveBeenCalled());
		expect(listEntries.mock.calls[0][0]).toEqual({ page: 1, perPage: 3, sort: 'newest' });
		await waitFor(() => expect(screen.getByText('teaser-a')).toBeInTheDocument());
		expect(screen.getByText('teaser-b')).toBeInTheDocument();
		expect(screen.getByText('teaser-c')).toBeInTheDocument();
		// Die Zahl im Teaser-Panel UND im Zahlen-Band kommt aus derselben
		// API-Antwort – nirgends hart verdrahtet.
		expect(screen.getByText(m.c1_teaser_heading_count({ total: '42' }))).toBeInTheDocument();
		const entriesStat = screen.getByText(m.c1_stat_entries_label()).closest('.stat');
		expect(entriesStat?.querySelector('.stat-value')?.textContent).toBe('42');
		expect(container.querySelectorAll('.stats .stat')).toHaveLength(4);
	});

	it('Teaser-CTA zeigt auf /index', async () => {
		render(Page);
		const cta = screen.getByRole('link', { name: m.c1_teaser_cta() });
		expect(cta.getAttribute('href')).toContain('/index');
	});

	it('blendet Teaser und Index-Zahl bei einem API-Fehler still aus', async () => {
		listEntries.mockRejectedValueOnce(new Error('network'));
		const { container } = render(Page);
		await waitFor(() => expect(listEntries).toHaveBeenCalled());
		await waitFor(() => expect(container.querySelector('.teaser')).toBeNull());
		// Keine erfundene Zahl: die Kachel verschwindet mit, das Band bleibt stehen.
		expect(screen.queryByText(m.c1_stat_entries_label())).toBeNull();
		expect(container.querySelectorAll('.stats .stat')).toHaveLength(3);
	});
});
