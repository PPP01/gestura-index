import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('$env/dynamic/public', () => ({ env: { PUBLIC_API_BASE: undefined } }));

const { withStepUp } = vi.hoisted(() => ({
	// Für den Happy-Path reicht ein Passthrough auf die gewrappte Aktion — die
	// Step-up-Ceremony selbst ist bereits in stepup.test.ts abgedeckt.
	withStepUp: vi.fn((action: () => Promise<unknown>) => action())
}));
vi.mock('$lib/admin/stepup', () => ({ withStepUp }));

const { queue, approveEntry, rejectEntry, approveVersion, rejectVersion } = vi.hoisted(() => ({
	queue: vi.fn(),
	approveEntry: vi.fn(),
	rejectEntry: vi.fn(),
	approveVersion: vi.fn(),
	rejectVersion: vi.fn()
}));
vi.mock('$lib/admin/api', async (orig) => ({
	...(await orig<typeof import('$lib/admin/api')>()),
	queue,
	approveEntry,
	rejectEntry,
	approveVersion,
	rejectVersion
}));

import QueuePage from './+page.svelte';
import { AdminApiError } from '$lib/admin/api';

function makeQueue() {
	return {
		entries: [
			{ id: 1, formatId: 'com.example.menu', type: 'menu' as const, createdAt: '2026-01-01T10:00:00Z' }
		],
		versions: [
			{
				id: 2,
				entryId: 1,
				formatId: 'com.example.engine',
				semver: '1.1.0',
				hasTransformCode: true,
				submittedAt: '2026-01-02T10:00:00Z'
			}
		]
	};
}

describe('Moderations-Warteschlange', () => {
	beforeEach(() => {
		vi.clearAllMocks();
	});

	it('rendert Einträge und Versionen aus queue()', async () => {
		queue.mockResolvedValue(makeQueue());

		render(QueuePage);

		await waitFor(() => expect(screen.getByText('com.example.menu')).toBeInTheDocument());
		expect(screen.getByText('com.example.engine')).toBeInTheDocument();
		expect(screen.getByText('1.1.0')).toBeInTheDocument();
		expect(queue).toHaveBeenCalledTimes(1);
	});

	it('zeigt bei einer Version mit hasTransformCode:true die Warnung-Badge', async () => {
		queue.mockResolvedValue(makeQueue());

		render(QueuePage);

		await waitFor(() => expect(screen.getByText('com.example.engine')).toBeInTheDocument());
		expect(screen.getByText('transformCode')).toBeInTheDocument();
	});

	it('gibt einen Eintrag frei (ohne Step-up) und lädt die Liste neu', async () => {
		queue.mockResolvedValue(makeQueue());
		approveEntry.mockResolvedValue(undefined);

		render(QueuePage);
		await waitFor(() => expect(screen.getByText('com.example.menu')).toBeInTheDocument());

		const approveButtons = screen.getAllByRole('button', { name: /freigeben|approve/i });
		await fireEvent.click(approveButtons[0]);

		await waitFor(() => expect(approveEntry).toHaveBeenCalledWith(1));
		expect(withStepUp).not.toHaveBeenCalled();
		await waitFor(() => expect(queue).toHaveBeenCalledTimes(2));
	});

	it('lehnt einen Eintrag über withStepUp ab', async () => {
		queue.mockResolvedValue(makeQueue());
		rejectEntry.mockResolvedValue(undefined);

		render(QueuePage);
		await waitFor(() => expect(screen.getByText('com.example.menu')).toBeInTheDocument());

		const rejectButtons = screen.getAllByRole('button', { name: /ablehnen|reject/i });
		await fireEvent.click(rejectButtons[0]);

		await waitFor(() => expect(rejectEntry).toHaveBeenCalledWith(1));
		expect(withStepUp).toHaveBeenCalledWith(expect.any(Function));
		await waitFor(() => expect(queue).toHaveBeenCalledTimes(2));
	});

	it('zeigt bei 409 backupRequired einen lokalisierten Hinweis', async () => {
		queue.mockResolvedValue(makeQueue());
		rejectEntry.mockRejectedValue(new AdminApiError(409, 'conflict', null, false, true));

		render(QueuePage);
		await waitFor(() => expect(screen.getByText('com.example.menu')).toBeInTheDocument());

		const rejectButtons = screen.getAllByRole('button', { name: /ablehnen|reject/i });
		await fireEvent.click(rejectButtons[0]);

		await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
		expect(screen.getByRole('alert').textContent).toMatch(/2\s*passkeys/i);
		expect(queue).toHaveBeenCalledTimes(1);
	});

	it('zeigt EmptyState bei leerer Warteschlange', async () => {
		queue.mockResolvedValue({ entries: [], versions: [] });

		render(QueuePage);

		await waitFor(() =>
			expect(screen.getByText(/warteschlange ist leer|queue is empty/i)).toBeInTheDocument()
		);
		expect(screen.queryByRole('button', { name: /freigeben|approve/i })).not.toBeInTheDocument();
	});
});
