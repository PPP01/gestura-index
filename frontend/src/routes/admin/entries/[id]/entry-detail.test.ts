import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('$env/dynamic/public', () => ({ env: { PUBLIC_API_BASE: undefined } }));

vi.mock('$app/state', () => ({ page: { params: { id: '42' } } }));

const { withStepUp } = vi.hoisted(() => ({
	// Für den Happy-Path reicht ein Passthrough auf die gewrappte Aktion — die
	// Step-up-Ceremony selbst ist bereits in stepup.test.ts abgedeckt.
	withStepUp: vi.fn((action: () => Promise<unknown>) => action())
}));
vi.mock('$lib/admin/stepup', () => ({ withStepUp }));

const { entryDetail, approveEntry, rejectEntry, banSubmitter, unbanSubmitter } = vi.hoisted(() => ({
	entryDetail: vi.fn(),
	approveEntry: vi.fn(),
	rejectEntry: vi.fn(),
	banSubmitter: vi.fn(),
	unbanSubmitter: vi.fn()
}));
vi.mock('$lib/admin/api', async (orig) => ({
	...(await orig<typeof import('$lib/admin/api')>()),
	entryDetail,
	approveEntry,
	rejectEntry,
	banSubmitter,
	unbanSubmitter
}));

import EntryDetailPage from './+page.svelte';
import { AdminApiError } from '$lib/admin/api';

function makeDetail(overrides: Partial<Record<string, unknown>> = {}) {
	return {
		formatId: 'com.example.menu',
		status: 'pending' as const,
		type: 'menu' as const,
		name: 'Example Menu',
		description: 'Ein Beispiel-Menü',
		categories: ['dev'],
		tags: ['example'],
		domains: ['example.com'],
		installCount: 12,
		currentVersion: '1.0.0',
		deprecated: false,
		successorFormatId: null,
		screenshotUrl: null,
		updatedAt: '2026-01-05T10:00:00Z',
		versions: [
			{
				semver: '1.0.0',
				changelog: 'Initiale Version',
				hasTransformCode: false,
				submittedAt: '2026-01-01T10:00:00Z'
			}
		],
		submitterId: 7,
		submitterBanned: false,
		openReports: [
			{ id: 1, reason: 'spam' as const, comment: 'Sieht nach Spam aus', createdAt: '2026-01-03T10:00:00Z' }
		],
		...overrides
	};
}

describe('Eintrag-Detail', () => {
	beforeEach(() => {
		vi.clearAllMocks();
	});

	it('rendert Metadaten, Versionsliste und offene Meldungen', async () => {
		entryDetail.mockResolvedValue(makeDetail());

		render(EntryDetailPage);

		await waitFor(() => expect(screen.getByText('Example Menu')).toBeInTheDocument());
		// '1.0.0' erscheint sowohl in der Metadaten-Zeile als auch im Versions-Eintrag —
		// `getAllByText` statt `getByText`, um keine Mehrdeutigkeit vorauszusetzen.
		expect(screen.getAllByText('1.0.0').length).toBeGreaterThan(0);
		expect(screen.getByText('Initiale Version')).toBeInTheDocument();
		expect(screen.getByText('Sieht nach Spam aus')).toBeInTheDocument();
		expect(entryDetail).toHaveBeenCalledWith(42);
	});

	it('gibt einen Eintrag frei (ohne Step-up) und lädt Detail neu', async () => {
		entryDetail.mockResolvedValue(makeDetail());
		approveEntry.mockResolvedValue(undefined);

		render(EntryDetailPage);
		await waitFor(() => expect(screen.getByText('Example Menu')).toBeInTheDocument());

		await fireEvent.click(screen.getByRole('button', { name: /freigeben|approve/i }));

		await waitFor(() => expect(approveEntry).toHaveBeenCalledWith(42));
		expect(withStepUp).not.toHaveBeenCalled();
		await waitFor(() => expect(entryDetail).toHaveBeenCalledTimes(2));
	});

	it('lehnt einen Eintrag über withStepUp ab', async () => {
		entryDetail.mockResolvedValue(makeDetail());
		rejectEntry.mockResolvedValue(undefined);

		render(EntryDetailPage);
		await waitFor(() => expect(screen.getByText('Example Menu')).toBeInTheDocument());

		await fireEvent.click(screen.getByRole('button', { name: /ablehnen|reject/i }));

		await waitFor(() => expect(rejectEntry).toHaveBeenCalledWith(42));
		expect(withStepUp).toHaveBeenCalledWith(expect.any(Function));
		await waitFor(() => expect(entryDetail).toHaveBeenCalledTimes(2));
	});

	it('sperrt einen nicht gesperrten Einreicher über withStepUp und lädt neu', async () => {
		entryDetail.mockResolvedValue(makeDetail({ submitterBanned: false }));
		banSubmitter.mockResolvedValue(undefined);

		render(EntryDetailPage);
		await waitFor(() => expect(screen.getByText('Example Menu')).toBeInTheDocument());

		expect(screen.queryByRole('button', { name: /entsperren|unban/i })).not.toBeInTheDocument();
		await fireEvent.click(screen.getByRole('button', { name: /sperren|^ban$/i }));

		await waitFor(() => expect(banSubmitter).toHaveBeenCalledWith(7));
		expect(withStepUp).toHaveBeenCalledWith(expect.any(Function));
		await waitFor(() => expect(entryDetail).toHaveBeenCalledTimes(2));
	});

	it('entsperrt einen gesperrten Einreicher (ohne Step-up)', async () => {
		entryDetail.mockResolvedValue(makeDetail({ submitterBanned: true }));
		unbanSubmitter.mockResolvedValue(undefined);

		render(EntryDetailPage);
		await waitFor(() => expect(screen.getByText('Example Menu')).toBeInTheDocument());

		expect(screen.queryByRole('button', { name: /^sperren$|^ban$/i })).not.toBeInTheDocument();
		await fireEvent.click(screen.getByRole('button', { name: /entsperren|unban/i }));

		await waitFor(() => expect(unbanSubmitter).toHaveBeenCalledWith(7));
		expect(withStepUp).not.toHaveBeenCalled();
		await waitFor(() => expect(entryDetail).toHaveBeenCalledTimes(2));
	});

	it('zeigt Freigeben/Ablehnen bei einem wartenden Eintrag', async () => {
		entryDetail.mockResolvedValue(makeDetail({ status: 'pending' }));

		render(EntryDetailPage);

		await waitFor(() => expect(screen.getByText('Example Menu')).toBeInTheDocument());
		expect(screen.getByRole('button', { name: /freigeben|approve/i })).toBeInTheDocument();
		expect(screen.getByRole('button', { name: /ablehnen|reject/i })).toBeInTheDocument();
	});

	// Reports verlinken auch auf published Entries — ohne diese Gating hätte
	// die Reject-Aktion einen bereits veröffentlichten Eintrag hart gelöscht.
	it('versteckt Freigeben/Ablehnen bei einem veröffentlichten Eintrag, zeigt aber weiter Sperren', async () => {
		entryDetail.mockResolvedValue(makeDetail({ status: 'published' }));

		render(EntryDetailPage);

		await waitFor(() => expect(screen.getByText('Example Menu')).toBeInTheDocument());
		expect(screen.queryByRole('button', { name: /freigeben|approve/i })).not.toBeInTheDocument();
		expect(screen.queryByRole('button', { name: /ablehnen|reject/i })).not.toBeInTheDocument();
		expect(screen.getByRole('button', { name: /sperren|^ban$/i })).toBeInTheDocument();
	});

	it('zeigt bei 404 einen ErrorState', async () => {
		entryDetail.mockRejectedValue(new AdminApiError(404, 'Not Found', null));

		render(EntryDetailPage);

		await waitFor(() =>
			expect(screen.getByText(/nicht gefunden|not found/i)).toBeInTheDocument()
		);
		expect(screen.queryByRole('button', { name: /freigeben|approve/i })).not.toBeInTheDocument();
	});
});
