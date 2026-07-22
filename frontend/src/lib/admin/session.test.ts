import { describe, it, expect, vi } from 'vitest';

// Mock $env/dynamic/public für Tests
vi.mock('$env/dynamic/public', () => ({
	env: {
		PUBLIC_API_BASE: undefined
	}
}));

import { session } from './session.svelte';

const meBody = {
	email: 'a@b.de',
	displayName: 'A',
	role: 'admin',
	credentialCount: 1,
	stepUpFresh: true
};

function json(body: unknown, init: ResponseInit = {}) {
	return new Response(JSON.stringify(body), {
		headers: { 'content-type': 'application/json' },
		...init
	});
}

describe('session store', () => {
	it('load() setzt user und leitet isAdmin/needsBackup ab', async () => {
		const fetchMock = vi.fn().mockResolvedValue(json(meBody));
		const u = await session.load({ fetch: fetchMock, baseUrl: 'https://api.test' });
		expect(u?.email).toBe('a@b.de');
		expect(session.isAdmin).toBe(true);
		expect(session.needsBackup).toBe(true); // credentialCount 1 < 2
	});

	it('load() gibt bei 401 null zurück und leert den Store', async () => {
		const fetchMock = vi.fn().mockResolvedValue(json({ title: 'unauth' }, { status: 401 }));
		const u = await session.load({ fetch: fetchMock, baseUrl: 'https://api.test' });
		expect(u).toBeNull();
		expect(session.user).toBeNull();
	});
});
