import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('$env/dynamic/public', () => ({ env: { PUBLIC_API_BASE: undefined } }));

const { performRegistration } = vi.hoisted(() => ({ performRegistration: vi.fn() }));
vi.mock('$lib/admin/webauthn', () => ({ performRegistration }));

const { withStepUp } = vi.hoisted(() => ({
	// Für den Happy-Path reicht ein Passthrough auf die gewrappte Aktion — die
	// Step-up-Ceremony selbst ist bereits in stepup.test.ts abgedeckt.
	withStepUp: vi.fn((action: () => Promise<unknown>) => action())
}));
vi.mock('$lib/admin/stepup', () => ({ withStepUp }));

const { sessionLoad } = vi.hoisted(() => ({ sessionLoad: vi.fn() }));
vi.mock('$lib/admin/session.svelte', () => ({ session: { load: sessionLoad } }));

const { listCredentials, addCredentialOptions, addCredential, renameCredential, removeCredential } =
	vi.hoisted(() => ({
		listCredentials: vi.fn(),
		addCredentialOptions: vi.fn(),
		addCredential: vi.fn(),
		renameCredential: vi.fn(),
		removeCredential: vi.fn()
	}));
vi.mock('$lib/admin/api', async (orig) => ({
	...(await orig<typeof import('$lib/admin/api')>()),
	listCredentials,
	addCredentialOptions,
	addCredential,
	renameCredential,
	removeCredential
}));

import AccountPage from './+page.svelte';
import { AdminApiError } from '$lib/admin/api';

function makeCredentials(count: number) {
	return Array.from({ length: count }, (_, i) => ({
		id: i + 1,
		label: `Passkey ${i + 1}`,
		createdAt: '2026-01-0' + (i + 1) + 'T10:00:00Z',
		lastUsedAt: i === 0 ? null : '2026-02-0' + (i + 1) + 'T10:00:00Z'
	}));
}

describe('Mein Konto — Passkey-Verwaltung', () => {
	beforeEach(() => {
		vi.clearAllMocks();
	});

	it('rendert die Liste der vorhandenen Passkeys', async () => {
		listCredentials.mockResolvedValue(makeCredentials(2));

		render(AccountPage);

		await waitFor(() => expect(screen.getByText('Passkey 1')).toBeInTheDocument());
		expect(screen.getByText('Passkey 2')).toBeInTheDocument();
		expect(listCredentials).toHaveBeenCalledTimes(1);
	});

	it('legt per Ceremony einen neuen Passkey an und lädt Liste + Session neu', async () => {
		listCredentials.mockResolvedValue(makeCredentials(2));
		const options = { challenge: 'c' };
		const attestation = { id: 'new-cred' };
		addCredentialOptions.mockResolvedValue(options);
		performRegistration.mockResolvedValue(attestation);
		addCredential.mockResolvedValue({ id: 3, label: 'Neuer Passkey' });
		sessionLoad.mockResolvedValue(undefined);

		render(AccountPage);
		await waitFor(() => expect(screen.getByText('Passkey 1')).toBeInTheDocument());

		await fireEvent.click(screen.getByRole('button', { name: /hinzufügen|add/i }));

		await waitFor(() => expect(addCredential).toHaveBeenCalled());

		expect(addCredentialOptions).toHaveBeenCalled();
		expect(performRegistration).toHaveBeenCalledWith(options);
		expect(addCredential).toHaveBeenCalledWith(attestation, expect.any(String));
		await waitFor(() => expect(listCredentials).toHaveBeenCalledTimes(2));
		await waitFor(() => expect(sessionLoad).toHaveBeenCalled());
	});

	it('zeigt bei 409 backupRequired einen lokalisierten Hinweis; Liste bleibt unverändert', async () => {
		listCredentials.mockResolvedValue(makeCredentials(2));
		removeCredential.mockRejectedValue(new AdminApiError(409, 'conflict', null, false, true));

		render(AccountPage);
		await waitFor(() => expect(screen.getByText('Passkey 1')).toBeInTheDocument());

		const removeButtons = screen.getAllByRole('button', { name: /entfernen|remove/i });
		await fireEvent.click(removeButtons[0]);

		await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
		expect(screen.getByRole('alert').textContent).toMatch(/2\s*passkeys/i);

		expect(withStepUp).toHaveBeenCalled();
		expect(removeCredential).toHaveBeenCalledWith(1);
		expect(listCredentials).toHaveBeenCalledTimes(1);
		expect(screen.getByText('Passkey 1')).toBeInTheDocument();
		expect(screen.getByText('Passkey 2')).toBeInTheDocument();
	});

	it('entfernt einen Passkey über withStepUp, wenn genug Backups vorhanden sind', async () => {
		listCredentials.mockResolvedValue(makeCredentials(3));
		removeCredential.mockResolvedValue(undefined);

		render(AccountPage);
		await waitFor(() => expect(screen.getByText('Passkey 1')).toBeInTheDocument());

		const removeButtons = screen.getAllByRole('button', { name: /entfernen|remove/i });
		await fireEvent.click(removeButtons[0]);

		await waitFor(() => expect(removeCredential).toHaveBeenCalledWith(1));
		expect(withStepUp).toHaveBeenCalledWith(expect.any(Function));
		await waitFor(() => expect(listCredentials).toHaveBeenCalledTimes(2));
		expect(screen.queryByRole('alert')).not.toBeInTheDocument();
	});

	it('benennt einen Passkey um', async () => {
		listCredentials.mockResolvedValue(makeCredentials(2));
		renameCredential.mockResolvedValue({ id: 1, label: 'Laptop' });

		render(AccountPage);
		await waitFor(() => expect(screen.getByText('Passkey 1')).toBeInTheDocument());

		const renameButtons = screen.getAllByRole('button', { name: /umbenennen|rename/i });
		await fireEvent.click(renameButtons[0]);

		// Sowohl das »Passkey hinzufügen«- als auch das Umbenennen-Feld tragen
		// dasselbe Label; das Umbenennen-Feld über seinen vorausgefüllten Wert
		// eindeutig auswählen.
		const input = screen
			.getAllByRole('textbox')
			.find((el) => (el as HTMLInputElement).value === 'Passkey 1') as HTMLInputElement;
		expect(input).toBeTruthy();
		await fireEvent.input(input, { target: { value: 'Laptop' } });

		const saveButton = screen.getByRole('button', { name: /speichern|save/i });
		await fireEvent.click(saveButton);

		await waitFor(() => expect(renameCredential).toHaveBeenCalledWith(1, 'Laptop'));
		await waitFor(() => expect(listCredentials).toHaveBeenCalledTimes(2));
	});
});
