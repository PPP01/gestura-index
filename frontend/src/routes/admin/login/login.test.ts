import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('$env/dynamic/public', () => ({ env: { PUBLIC_API_BASE: undefined } }));

const { goto } = vi.hoisted(() => ({ goto: vi.fn() }));
vi.mock('$app/navigation', () => ({ goto }));

const { performAssertion } = vi.hoisted(() => ({ performAssertion: vi.fn() }));
vi.mock('$lib/admin/webauthn', () => ({ performAssertion }));

const { authOptions, authLogin, me } = vi.hoisted(() => ({
	authOptions: vi.fn(),
	authLogin: vi.fn(),
	me: vi.fn()
}));
vi.mock('$lib/admin/api', async (orig) => ({
	...(await orig<typeof import('$lib/admin/api')>()),
	authOptions,
	authLogin,
	me
}));

import LoginPage from './+page.svelte';
import { session } from '$lib/admin/session.svelte';

describe('Admin-Login', () => {
	beforeEach(() => {
		vi.clearAllMocks();
		session.clear();
	});

	it('meldet per Passkey an, lädt die Session und leitet zur Queue weiter', async () => {
		const options = { challenge: 'c' };
		const assertion = { id: 'cred' };
		authOptions.mockResolvedValue(options);
		performAssertion.mockResolvedValue(assertion);
		authLogin.mockResolvedValue(undefined);
		me.mockResolvedValue({
			email: 'admin@example.com',
			displayName: 'Admin',
			role: 'admin',
			credentialCount: 2,
			stepUpFresh: true
		});

		render(LoginPage);
		await fireEvent.click(screen.getByRole('button', { name: /anmelden|sign in|log in/i }));

		await waitFor(() => expect(goto).toHaveBeenCalled());

		expect(authOptions).toHaveBeenCalled();
		expect(performAssertion).toHaveBeenCalledWith(options);
		expect(authLogin).toHaveBeenCalledWith(assertion);
		expect(session.user?.displayName).toBe('Admin');
		expect(goto).toHaveBeenCalledWith(expect.stringContaining('/admin/queue'));
	});

	it('zeigt eine lokalisierte Fehlermeldung, wenn die Ceremony fehlschlägt', async () => {
		authOptions.mockResolvedValue({ challenge: 'c' });
		performAssertion.mockRejectedValue(new Error('NotAllowedError'));

		render(LoginPage);
		await fireEvent.click(screen.getByRole('button', { name: /anmelden|sign in|log in/i }));

		await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());

		expect(authLogin).not.toHaveBeenCalled();
		expect(goto).not.toHaveBeenCalled();
	});
});
