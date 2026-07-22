import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('$env/dynamic/public', () => ({ env: { PUBLIC_API_BASE: undefined } }));

const { goto } = vi.hoisted(() => ({ goto: vi.fn() }));
vi.mock('$app/navigation', () => ({ goto }));

const { pageState } = vi.hoisted(() => ({
	pageState: { url: new URL('http://localhost/admin/register?token=valid-token') }
}));
vi.mock('$app/state', () => ({ page: pageState }));

const { performRegistration } = vi.hoisted(() => ({ performRegistration: vi.fn() }));
vi.mock('$lib/admin/webauthn', () => ({ performRegistration }));

const { registerOptions, register } = vi.hoisted(() => ({
	registerOptions: vi.fn(),
	register: vi.fn()
}));
vi.mock('$lib/admin/api', async (orig) => ({
	...(await orig<typeof import('$lib/admin/api')>()),
	registerOptions,
	register
}));

import RegisterPage from './+page.svelte';
import { AdminApiError } from '$lib/admin/api';

describe('Admin-Registrierung', () => {
	beforeEach(() => {
		vi.clearAllMocks();
		pageState.url = new URL('http://localhost/admin/register?token=valid-token');
	});

	it('legt per Passkey einen Account an und bietet danach die Anmeldung an', async () => {
		const options = { challenge: 'c' };
		const attestation = { id: 'cred' };
		registerOptions.mockResolvedValue(options);
		performRegistration.mockResolvedValue(attestation);
		register.mockResolvedValue(undefined);

		render(RegisterPage);
		await fireEvent.click(screen.getByRole('button', { name: /passkey/i }));

		await waitFor(() =>
			expect(screen.getByRole('button', { name: /anmeldung|sign in|log in/i })).toBeInTheDocument()
		);

		expect(registerOptions).toHaveBeenCalledWith('valid-token');
		expect(performRegistration).toHaveBeenCalledWith(options);
		expect(register).toHaveBeenCalledWith('valid-token', attestation);
		expect(goto).not.toHaveBeenCalled();

		await fireEvent.click(screen.getByRole('button', { name: /anmeldung|sign in|log in/i }));
		expect(goto).toHaveBeenCalledWith(expect.stringContaining('/admin/login'));
	});

	it('zeigt einen lokalisierten Fehler und keinen Button, wenn der Token fehlt', () => {
		pageState.url = new URL('http://localhost/admin/register');

		render(RegisterPage);

		expect(screen.getByRole('alert')).toBeInTheDocument();
		expect(screen.queryByRole('button')).not.toBeInTheDocument();
		expect(registerOptions).not.toHaveBeenCalled();
	});

	it('zeigt eine lokalisierte Fehlermeldung, wenn der Token ungültig/abgelaufen ist', async () => {
		registerOptions.mockResolvedValue({ challenge: 'c' });
		performRegistration.mockResolvedValue({ id: 'cred' });
		register.mockRejectedValue(new AdminApiError(400, 'invalid_token', null));

		render(RegisterPage);
		await fireEvent.click(screen.getByRole('button', { name: /passkey/i }));

		await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());

		expect(goto).not.toHaveBeenCalled();
		expect(
			screen.queryByRole('button', { name: /anmeldung|sign in|log in/i })
		).not.toBeInTheDocument();
	});
});
