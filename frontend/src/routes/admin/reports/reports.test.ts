import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('$env/dynamic/public', () => ({ env: { PUBLIC_API_BASE: undefined } }));

const { reports, resolveReport } = vi.hoisted(() => ({
	reports: vi.fn(),
	resolveReport: vi.fn()
}));
vi.mock('$lib/admin/api', async (orig) => ({
	...(await orig<typeof import('$lib/admin/api')>()),
	reports,
	resolveReport
}));

import ReportsPage from './+page.svelte';

function makeReports() {
	return [
		{
			id: 1,
			entryId: 2,
			formatId: 'com.example.menu',
			submitterId: 7,
			submitterBanned: false,
			reason: 'spam' as const,
			comment: 'Sieht nach Spam aus',
			createdAt: '2026-01-03T10:00:00Z'
		}
	];
}

describe('Meldungen', () => {
	beforeEach(() => {
		vi.clearAllMocks();
	});

	it('rendert offene Meldungen mit Grund, Kommentar und Eintrag', async () => {
		reports.mockResolvedValue(makeReports());

		render(ReportsPage);

		await waitFor(() => expect(screen.getByText('com.example.menu')).toBeInTheDocument());
		expect(screen.getByText('Sieht nach Spam aus')).toBeInTheDocument();
		expect(reports).toHaveBeenCalledTimes(1);
	});

	it('»Freigeben/Behalten« ruft resolveReport(id, true) auf und lädt die Liste neu', async () => {
		reports.mockResolvedValue(makeReports());
		resolveReport.mockResolvedValue(undefined);

		render(ReportsPage);
		await waitFor(() => expect(screen.getByText('com.example.menu')).toBeInTheDocument());

		const keepButtons = screen.getAllByRole('button', { name: /behalten|keep/i });
		await fireEvent.click(keepButtons[0]);

		await waitFor(() => expect(resolveReport).toHaveBeenCalledWith(1, true));
		await waitFor(() => expect(reports).toHaveBeenCalledTimes(2));
	});

	it('»Löschen/Ablehnen« ruft resolveReport(id, false) auf und lädt die Liste neu', async () => {
		reports.mockResolvedValue(makeReports());
		resolveReport.mockResolvedValue(undefined);

		render(ReportsPage);
		await waitFor(() => expect(screen.getByText('com.example.menu')).toBeInTheDocument());

		const rejectButtons = screen.getAllByRole('button', { name: /löschen|delete|ablehnen|reject/i });
		await fireEvent.click(rejectButtons[0]);

		await waitFor(() => expect(resolveReport).toHaveBeenCalledWith(1, false));
		await waitFor(() => expect(reports).toHaveBeenCalledTimes(2));
	});

	it('zeigt EmptyState, wenn keine offenen Meldungen vorliegen', async () => {
		reports.mockResolvedValue([]);

		render(ReportsPage);

		await waitFor(() =>
			expect(
				screen.getByText(/keine offenen meldungen|no open reports/i)
			).toBeInTheDocument()
		);
		expect(screen.queryByRole('button')).not.toBeInTheDocument();
	});
});
