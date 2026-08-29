import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('$env/dynamic/public', () => ({ env: { PUBLIC_API_BASE: undefined } }));

const { pages, setPageEnabled } = vi.hoisted(() => ({
	pages: vi.fn(),
	setPageEnabled: vi.fn()
}));
vi.mock('$lib/admin/api', async (orig) => ({
	...(await orig<typeof import('$lib/admin/api')>()),
	pages,
	setPageEnabled
}));

import PagesPage from './+page.svelte';

function makePages() {
	return [
		{ pageKey: 'was-ist-gestura', enabled: true, updatedAt: null, updatedBy: null },
		{ pageKey: 'maus-gesten', enabled: true, updatedAt: null, updatedBy: null },
		{ pageKey: 'vergleich', enabled: false, updatedAt: null, updatedBy: null },
		{ pageKey: 'beispiele', enabled: true, updatedAt: null, updatedBy: null }
	];
}

describe('Seiten-Sichtbarkeit', () => {
	beforeEach(() => {
		vi.clearAllMocks();
	});

	it('rendert alle vier Seiten mit ihrem Aktiv/Deaktiviert-Status', async () => {
		pages.mockResolvedValue(makePages());

		render(PagesPage);

		await waitFor(() =>
			expect(screen.getByText(/vergleich|comparison/i)).toBeInTheDocument()
		);
		expect(screen.getByText(/was ist gestura|what is gestura/i)).toBeInTheDocument();
		expect(screen.getByText(/maus-gesten|mouse gestures/i)).toBeInTheDocument();
		expect(screen.getByText(/beispiele|examples/i)).toBeInTheDocument();
		expect(pages).toHaveBeenCalledTimes(1);

		const buttons = screen.getAllByRole('button');
		expect(buttons).toHaveLength(4);
		expect(screen.getAllByRole('button', { name: /^aktiv$|^active$/i }).length).toBeGreaterThan(0);
		expect(screen.getByRole('button', { name: /^deaktiviert$|^disabled$/i })).toBeInTheDocument();
	});

	it("Klick auf den deaktivierten »Vergleich«-Eintrag ruft setPageEnabled('vergleich', true) auf und lädt neu", async () => {
		pages.mockResolvedValue(makePages());
		setPageEnabled.mockResolvedValue(undefined);

		render(PagesPage);
		await waitFor(() =>
			expect(screen.getByText(/vergleich|comparison/i)).toBeInTheDocument()
		);

		const vergleichRow = screen.getByText(/vergleich|comparison/i).closest('li');
		const toggleButton = vergleichRow?.querySelector('button');
		expect(toggleButton).toBeTruthy();

		await fireEvent.click(toggleButton!);

		await waitFor(() => expect(setPageEnabled).toHaveBeenCalledWith('vergleich', true));
		await waitFor(() => expect(pages).toHaveBeenCalledTimes(2));
	});
});
