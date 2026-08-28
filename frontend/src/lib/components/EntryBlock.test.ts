import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import EntryBlock from './EntryBlock.svelte';
import type { EntryListItem, EntryDetail, ReviewListResponse } from '$lib/api';
import { basket } from '$lib/basket.svelte';

// Wie bei allen anderen Tests, die (transitiv) api.ts importieren: $env/dynamic/public
// muss gemockt werden, sonst schlägt das virtuelle SvelteKit-Modul außerhalb eines
// echten Requests fehl (siehe api.test.ts, admin/api.test.ts, catalog.test.ts).
vi.mock('$env/dynamic/public', () => ({ env: { PUBLIC_API_BASE: undefined } }));

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
