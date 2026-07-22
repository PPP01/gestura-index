import { describe, it, expect, vi } from 'vitest';

const { startRegistration, startAuthentication } = vi.hoisted(() => ({
	startRegistration: vi.fn(),
	startAuthentication: vi.fn()
}));
vi.mock('@simplewebauthn/browser', () => ({ startRegistration, startAuthentication }));

import { performRegistration, performAssertion } from './webauthn';

describe('webauthn wrapper', () => {
	it('reicht Options an startRegistration und liefert die Attestation', async () => {
		startRegistration.mockResolvedValue({ id: 'cred', response: {} });
		const out = await performRegistration({ challenge: 'abc' });
		expect(startRegistration).toHaveBeenCalledWith({ optionsJSON: { challenge: 'abc' } });
		expect(out).toEqual({ id: 'cred', response: {} });
	});

	it('reicht Options an startAuthentication und liefert die Assertion', async () => {
		startAuthentication.mockResolvedValue({ id: 'cred' });
		const out = await performAssertion({ challenge: 'xyz' });
		expect(startAuthentication).toHaveBeenCalledWith({ optionsJSON: { challenge: 'xyz' } });
		expect(out).toEqual({ id: 'cred' });
	});
});
