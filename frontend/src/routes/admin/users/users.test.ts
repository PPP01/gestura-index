import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('$env/dynamic/public', () => ({ env: { PUBLIC_API_BASE: undefined } }));

const { withStepUp } = vi.hoisted(() => ({
	// Für den Happy-Path reicht ein Passthrough auf die gewrappte Aktion — die
	// Step-up-Ceremony selbst ist bereits in stepup.test.ts abgedeckt.
	withStepUp: vi.fn((action: () => Promise<unknown>) => action())
}));
vi.mock('$lib/admin/stepup', () => ({ withStepUp }));

const { listUsers, inviteUser, disableUser, enableUser, reinviteUser } = vi.hoisted(() => ({
	listUsers: vi.fn(),
	inviteUser: vi.fn(),
	disableUser: vi.fn(),
	enableUser: vi.fn(),
	reinviteUser: vi.fn()
}));
vi.mock('$lib/admin/api', async (orig) => ({
	...(await orig<typeof import('$lib/admin/api')>()),
	listUsers,
	inviteUser,
	disableUser,
	enableUser,
	reinviteUser
}));

import UsersPage from './+page.svelte';
import { AdminApiError } from '$lib/admin/api';

function makeUsers() {
	return [
		{
			id: 1,
			displayName: 'Alice Admin',
			email: 'alice@example.com',
			role: 'admin' as const,
			status: 'active' as const,
			createdAt: '2026-01-01T10:00:00Z',
			lastLoginAt: '2026-02-01T10:00:00Z'
		},
		{
			id: 2,
			displayName: 'Mona Mod',
			email: 'mona@example.com',
			role: 'moderator' as const,
			status: 'invited' as const,
			createdAt: '2026-01-05T10:00:00Z',
			lastLoginAt: null
		},
		{
			id: 3,
			displayName: 'Dana Dormant',
			email: 'dana@example.com',
			role: 'moderator' as const,
			status: 'disabled' as const,
			createdAt: '2026-01-06T10:00:00Z',
			lastLoginAt: null
		}
	];
}

describe('Nutzerverwaltung', () => {
	beforeEach(() => {
		vi.clearAllMocks();
	});

	it('rendert Nutzer mit Status-Badges', async () => {
		listUsers.mockResolvedValue(makeUsers());

		render(UsersPage);

		await waitFor(() => expect(screen.getByText('Alice Admin')).toBeInTheDocument());
		expect(screen.getByText('alice@example.com')).toBeInTheDocument();
		expect(screen.getByText('Mona Mod')).toBeInTheDocument();
		expect(screen.getByText('Dana Dormant')).toBeInTheDocument();

		expect(screen.getByText(/aktiv|active/i)).toBeInTheDocument();
		expect(screen.getByText(/eingeladen|invited/i)).toBeInTheDocument();
		expect(screen.getByText(/deaktiviert|disabled/i)).toBeInTheDocument();
	});

	it('lädt per Einladen-Formular einen Nutzer über withStepUp ein, lädt die Liste neu und leert das Formular', async () => {
		listUsers.mockResolvedValue(makeUsers());
		inviteUser.mockResolvedValue({
			id: 4,
			displayName: 'Neu Nutzer',
			email: 'neu@example.com',
			role: 'moderator',
			status: 'invited',
			createdAt: '',
			lastLoginAt: null
		});

		render(UsersPage);
		await waitFor(() => expect(screen.getByText('Alice Admin')).toBeInTheDocument());

		const nameInput = screen.getByLabelText(/^name$/i) as HTMLInputElement;
		const emailInput = screen.getByLabelText(/email|e-mail-adresse/i) as HTMLInputElement;
		await fireEvent.input(nameInput, { target: { value: 'Neu Nutzer' } });
		await fireEvent.input(emailInput, { target: { value: 'neu@example.com' } });

		const inviteButton = screen.getByRole('button', { name: /^einladen$|^invite$/i });
		await fireEvent.click(inviteButton);

		await waitFor(() =>
			expect(inviteUser).toHaveBeenCalledWith({
				displayName: 'Neu Nutzer',
				email: 'neu@example.com',
				role: 'moderator'
			})
		);
		expect(withStepUp).toHaveBeenCalledWith(expect.any(Function));
		await waitFor(() => expect(listUsers).toHaveBeenCalledTimes(2));
		expect(nameInput.value).toBe('');
		expect(emailInput.value).toBe('');
	});

	it('deaktiviert einen aktiven Nutzer über withStepUp und lädt die Liste neu', async () => {
		listUsers.mockResolvedValue(makeUsers());
		disableUser.mockResolvedValue(undefined);

		render(UsersPage);
		await waitFor(() => expect(screen.getByText('Alice Admin')).toBeInTheDocument());

		const disableButtons = screen.getAllByRole('button', { name: /deaktivieren|disable/i });
		await fireEvent.click(disableButtons[0]);

		await waitFor(() => expect(disableUser).toHaveBeenCalledWith(1));
		expect(withStepUp).toHaveBeenCalledWith(expect.any(Function));
		await waitFor(() => expect(listUsers).toHaveBeenCalledTimes(2));
	});

	it('reaktiviert einen deaktivierten Nutzer über withStepUp', async () => {
		listUsers.mockResolvedValue(makeUsers());
		enableUser.mockResolvedValue(undefined);

		render(UsersPage);
		await waitFor(() => expect(screen.getByText('Dana Dormant')).toBeInTheDocument());

		const enableButton = screen.getByRole('button', { name: /reaktivieren|reactivate/i });
		await fireEvent.click(enableButton);

		await waitFor(() => expect(enableUser).toHaveBeenCalledWith(3));
		expect(withStepUp).toHaveBeenCalledWith(expect.any(Function));
		await waitFor(() => expect(listUsers).toHaveBeenCalledTimes(2));
	});

	it('zeigt bei 409 backupRequired beim Einladen einen lokalisierten Hinweis', async () => {
		listUsers.mockResolvedValue(makeUsers());
		inviteUser.mockRejectedValue(new AdminApiError(409, 'conflict', null, false, true));

		render(UsersPage);
		await waitFor(() => expect(screen.getByText('Alice Admin')).toBeInTheDocument());

		const nameInput = screen.getByLabelText(/^name$/i) as HTMLInputElement;
		const emailInput = screen.getByLabelText(/email|e-mail-adresse/i) as HTMLInputElement;
		await fireEvent.input(nameInput, { target: { value: 'Neu Nutzer' } });
		await fireEvent.input(emailInput, { target: { value: 'neu@example.com' } });

		const inviteButton = screen.getByRole('button', { name: /^einladen$|^invite$/i });
		await fireEvent.click(inviteButton);

		await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
		expect(screen.getByRole('alert').textContent).toMatch(/2\s*passkeys/i);
		await waitFor(() => expect(listUsers).toHaveBeenCalledTimes(1));
	});
});
