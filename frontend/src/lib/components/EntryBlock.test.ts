import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/svelte';
import EntryBlock from './EntryBlock.svelte';
import type { EntryListItem, EntryDetail, ReviewListResponse } from '$lib/api';
import { basket } from '$lib/basket.svelte';

// Wie bei allen anderen Tests, die (transitiv) api.ts importieren: $env/dynamic/public
// muss gemockt werden, sonst schlägt das virtuelle SvelteKit-Modul außerhalb eines
// echten Requests fehl (siehe api.test.ts, admin/api.test.ts, catalog.test.ts).
vi.mock('$env/dynamic/public', () => ({ env: { PUBLIC_API_BASE: undefined } }));

const getEntry = vi.fn();
const listReviews = vi.fn();
const downloadVersion = vi.fn();
vi.mock('$lib/api', async (orig) => {
	const actual = await orig<typeof import('$lib/api')>();
	return {
		...actual,
		getEntry: (...a: unknown[]) => getEntry(...a),
		listReviews: (...a: unknown[]) => listReviews(...a),
		downloadVersion: (...a: unknown[]) => downloadVersion(...a)
	};
});

/** Roh-Payload im Austauschformat – Datenquelle der Live-Vorschau. */
const payload = {
	gesturaMenu: 1,
	id: 'com.example.menu',
	version: '1.0.0',
	name: 'Example',
	items: [
		{ id: 'i1', action: 'openCustomUrl', label: { en: 'Home', de: 'Start' }, icon: 'house', customUrl: 'https://example.com/' },
		{ id: 'i2', type: 'separator' },
		{ id: 'i3', action: 'back' }
	]
};

const entry: EntryListItem = {
	formatId: 'com.example.menu',
	type: 'menu',
	name: { en: 'Example', de: 'Beispiel' },
	description: { en: 'A sample', de: 'Ein Beispiel' },
	categories: ['dev'],
	tags: ['git'],
	domains: ['example.com'],
	itemCount: 3,
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
	downloadVersion.mockReset();
	downloadVersion.mockRejectedValue(new Error('nicht gemockt'));
});

describe('EntryBlock', () => {
	it('zeigt aufgelösten Namen, Sterne und Install-Zähler', () => {
		render(EntryBlock, { entry });
		expect(screen.getByText('Example')).toBeInTheDocument(); // Test-Locale = en (baseLocale)
		expect(screen.getByText(/4[.,]5/)).toBeInTheDocument();
		expect(screen.getByText(/42/)).toBeInTheDocument();
	});

	// Ohne Aufklappen erkennbar, wie umfangreich ein Menü ist – die Zahl kommt
	// aus der LISTEN-Antwort, kostet also keinen zusätzlichen Request.
	it('zeigt die Item-Zahl schon an der eingeklappten Karte', () => {
		render(EntryBlock, { entry });
		expect(screen.getByTitle(/entries|einträge/i)).toHaveTextContent('3');
	});

	it('zeigt für Suchmaschinen keine Item-Zahl', () => {
		render(EntryBlock, { entry: { ...entry, type: 'engine', itemCount: null } });
		expect(screen.queryByTitle(/entries|einträge/i)).not.toBeInTheDocument();
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

	it('open=true lädt Details bereits beim Mount (T4-Review-Fix: Auto-Expand-Bug)', async () => {
		getEntry.mockResolvedValue(detail);
		render(EntryBlock, { entry, open: true });
		await waitFor(() => expect(getEntry).toHaveBeenCalledWith('com.example.menu'));
		await waitFor(() => expect(screen.getByText('1.0.0')).toBeInTheDocument());
	});

	it('rendert die Live-Vorschau des Menüs statt des Screenshot-Platzhalters', async () => {
		getEntry.mockResolvedValue(detail);
		downloadVersion.mockResolvedValue(payload);
		render(EntryBlock, { entry, open: true });
		await waitFor(() => expect(downloadVersion).toHaveBeenCalledWith('com.example.menu', '1.0.0'));
		// Gezielt IM Vorschau-Menü suchen: die Labels stehen zusätzlich im
		// Inhalt-Kasten, dort aber mit Ziel-URL statt in Menü-Optik.
		const menu = await screen.findByRole('list', { name: /menu preview|menü-vorschau/i });
		expect(within(menu).getByText('Home')).toBeInTheDocument();
		// Aktions-Item ohne Label fällt auf den Aktionsnamen zurück (Test-Locale = en).
		expect(within(menu).getByText('Go Back')).toBeInTheDocument();
		expect(within(menu).getByRole('separator')).toBeInTheDocument();
		expect(screen.queryByText(/screenshot provided|screenshot des menüs/i)).not.toBeInTheDocument();
	});

	it('zeigt den Platzhalter, wenn der Vorschau-Payload nicht ladbar ist', async () => {
		getEntry.mockResolvedValue(detail); // downloadVersion lehnt per beforeEach ab
		render(EntryBlock, { entry, open: true });
		await waitFor(() => expect(downloadVersion).toHaveBeenCalled());
		await waitFor(() =>
			expect(screen.getByText(/screenshot provided|screenshot des menüs/i)).toBeInTheDocument()
		);
		expect(screen.queryByRole('list', { name: /menu preview|menü-vorschau/i })).not.toBeInTheDocument();
	});

	it('zeigt für Suchmaschinen die URL-Vorlage statt einer Menü-Vorschau', async () => {
		const engine = { ...entry, type: 'engine' as const, formatId: 'com.example.engine' };
		getEntry.mockResolvedValue({ ...detail, ...engine });
		downloadVersion.mockResolvedValue({
			gesturaEngine: 1,
			id: 'com.example.engine',
			version: '1.0.0',
			name: 'Example',
			url: 'https://e.test/?q=',
			plus: true
		});
		render(EntryBlock, { entry: engine, open: true });
		await waitFor(() => expect(screen.getByText('https://e.test/?q=')).toBeInTheDocument());
		expect(screen.getByText(/spaces as \+|leerzeichen als \+/i)).toBeInTheDocument();
		// Eine Engine hat kein In-Page-Menü – also auch keine Menü-Vorschau.
		expect(screen.queryByRole('list', { name: /menu preview|menü-vorschau/i })).not.toBeInTheDocument();
	});

	it('listet die Ziel-URLs des Menüs im Inhalt-Kasten', async () => {
		getEntry.mockResolvedValue(detail);
		downloadVersion.mockResolvedValue(payload);
		render(EntryBlock, { entry, open: true });
		// Niemand soll importieren müssen, ohne die Ziele gesehen zu haben.
		await waitFor(() => expect(screen.getByText('https://example.com/')).toBeInTheDocument());
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
