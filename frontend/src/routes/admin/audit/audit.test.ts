import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('$env/dynamic/public', () => ({ env: { PUBLIC_API_BASE: undefined } }));

const { audit } = vi.hoisted(() => ({
	audit: vi.fn()
}));
vi.mock('$lib/admin/api', async (orig) => ({
	...(await orig<typeof import('$lib/admin/api')>()),
	audit
}));

import AuditPage from './+page.svelte';

function makeItems(count: number) {
	return Array.from({ length: count }, (_, i) => ({
		id: i + 1,
		actor: i === 0 ? 'alice@example.com' : null,
		action: i === 0 ? 'entry.approve' : 'entry.reject',
		targetType: 'entry',
		targetId: String(100 + i),
		detail: i === 0 ? { note: 'ok' } : null,
		createdAt: '2026-01-03T10:00:00Z'
	}));
}

describe('Audit-Log', () => {
	beforeEach(() => {
		vi.clearAllMocks();
	});

	it('rendert Audit-Einträge mit Akteur, Aktion und Datum', async () => {
		audit.mockResolvedValue({ items: makeItems(2), page: 1, perPage: 50 });

		render(AuditPage);

		await waitFor(() => expect(screen.getByText('alice@example.com')).toBeInTheDocument());
		expect(screen.getByText('entry.approve')).toBeInTheDocument();
		expect(screen.getByText(/entry #100/)).toBeInTheDocument();
		expect(audit).toHaveBeenCalledWith(1, 50);
	});

	it('»Weiter« lädt die nächste Seite über audit(2, perPage)', async () => {
		audit.mockResolvedValue({ items: makeItems(50), page: 1, perPage: 50 });

		render(AuditPage);
		await waitFor(() => expect(screen.getByText('alice@example.com')).toBeInTheDocument());

		const nextButton = screen.getByRole('button', { name: /weiter|next/i });
		expect(nextButton).not.toBeDisabled();
		await fireEvent.click(nextButton);

		await waitFor(() => expect(audit).toHaveBeenCalledWith(2, 50));
	});

	it('deaktiviert »Weiter«, wenn eine unvollständige Seite zurückkommt', async () => {
		audit.mockResolvedValue({ items: makeItems(3), page: 1, perPage: 50 });

		render(AuditPage);
		await waitFor(() => expect(screen.getByText('alice@example.com')).toBeInTheDocument());

		const nextButton = screen.getByRole('button', { name: /weiter|next/i });
		expect(nextButton).toBeDisabled();
	});

	it('rendert einen null-Akteur als System/—', async () => {
		audit.mockResolvedValue({ items: makeItems(2), page: 1, perPage: 50 });

		render(AuditPage);

		await waitFor(() => expect(screen.getByText('entry.reject')).toBeInTheDocument());
		// Sprachneutral: entweder "System" oder "—" ist als eigenständige Zelle
		// zulässig (Implementierungsdetail), aber genau eine davon muss auftauchen.
		const matches = screen.getAllByText(/^system$|^—$/i);
		expect(matches.length).toBeGreaterThan(0);
	});

	it('zeigt EmptyState, wenn keine Einträge vorliegen', async () => {
		audit.mockResolvedValue({ items: [], page: 1, perPage: 50 });

		render(AuditPage);

		await waitFor(() =>
			expect(screen.getByText(/keine.*audit|no audit/i)).toBeInTheDocument()
		);
	});
});
