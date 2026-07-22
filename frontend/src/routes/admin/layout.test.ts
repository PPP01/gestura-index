import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('$env/dynamic/public', () => ({ env: { PUBLIC_API_BASE: undefined } }));

const { me } = vi.hoisted(() => ({ me: vi.fn() }));
vi.mock('$lib/admin/api', async (orig) => ({ ...(await orig<typeof import('$lib/admin/api')>()), me }));

import { load } from './+layout';
import { session } from '$lib/admin/session.svelte';
import { AdminApiError } from '$lib/admin/api';

// Kalter Lade-Fall (Reload/Deep-Link): `session.user` ist `null`, bis das
// Layout-`load` die Session bootstrapped. Das ist genau die Lücke, die die
// Screen-Tests nicht abdecken, weil sie `session.user` dort direkt setzen.
describe('admin/+layout.ts load (Guard-Verdrahtung)', () => {
	beforeEach(() => {
		vi.clearAllMocks();
		session.clear();
	});

	it('lädt die Session und leitet NICHT um, wenn `me()` einen Nutzer liefert', async () => {
		const user = {
			email: 'a@b.de',
			displayName: 'Admin',
			role: 'admin' as const,
			credentialCount: 2,
			stepUpFresh: true
		};
		me.mockResolvedValue(user);

		const result = await load({ url: new URL('http://localhost/admin/queue') } as never);

		expect(result).toEqual({});
		expect(session.user).toEqual(user);
	});

	it('wirft einen Redirect nach /admin/login, wenn `me()` mit 401 scheitert', async () => {
		me.mockRejectedValue(new AdminApiError(401, 'unauth', null));

		await expect(load({ url: new URL('http://localhost/admin/reports') } as never)).rejects.toMatchObject(
			{ status: 302 }
		);
		expect(session.user).toBeNull();
	});

	it('leitet auf der Login-Route ohne Session NICHT um (kein Redirect-Loop)', async () => {
		me.mockRejectedValue(new AdminApiError(401, 'unauth', null));

		const result = await load({ url: new URL('http://localhost/admin/login') } as never);

		expect(result).toEqual({});
		expect(session.user).toBeNull();
	});

	it('leitet auf der Registrierungs-Route (mit Query-String) ohne Session NICHT um', async () => {
		me.mockRejectedValue(new AdminApiError(401, 'unauth', null));

		const result = await load({
			url: new URL('http://localhost/admin/register?token=t')
		} as never);

		expect(result).toEqual({});
	});
});
