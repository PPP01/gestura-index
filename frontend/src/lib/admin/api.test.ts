import { describe, it, expect, vi, afterEach } from 'vitest';

// Mock $env/dynamic/public für Tests
vi.mock('$env/dynamic/public', () => ({
	env: {
		PUBLIC_API_BASE: undefined
	}
}));

import {
	adminFetch,
	AdminApiError,
	me,
	authLogout,
	listUsers,
	queue,
	audit,
	entryDetail,
	adminAbsoluteScreenshotUrl,
	registerUnauthorizedHandler
} from './api';

const BASE = 'https://api.test';

function jsonResponse(body: unknown, init: Partial<{ status: number; contentType: string }> = {}) {
	return new Response(JSON.stringify(body), {
		status: init.status ?? 200,
		headers: { 'content-type': init.contentType ?? 'application/json' }
	});
}

describe('admin api', () => {
	// Nach jedem Test den 401-Handler zurücksetzen, damit Tests sich nicht
	// gegenseitig beeinflussen (Handler ist Modul-Level-Singleton).
	afterEach(() => {
		registerUnauthorizedHandler(null);
	});

	it('sendet credentials + X-Requested-With und parst me()', async () => {
		const fetchMock = vi.fn().mockResolvedValue(
			jsonResponse({
				email: 'a@b.de',
				displayName: 'A',
				role: 'admin',
				credentialCount: 2,
				stepUpFresh: true
			})
		);
		const res = await me({ fetch: fetchMock, baseUrl: BASE });
		expect(res.role).toBe('admin');
		const init = fetchMock.mock.calls[0][1];
		expect(init.credentials).toBe('include');
		expect(init.headers['X-Requested-With']).toBe('XMLHttpRequest');
	});

	it('mappt 403 stepUpRequired auf AdminApiError-Flag', async () => {
		const fetchMock = vi.fn().mockResolvedValue(
			jsonResponse({ title: 'Step-up required', stepUpRequired: true }, { status: 403 })
		);
		await expect(
			adminFetch('/x', { method: 'POST' }, { fetch: fetchMock, baseUrl: BASE })
		).rejects.toMatchObject({ status: 403, stepUpRequired: true });
		await expect(
			adminFetch('/x', { method: 'POST' }, { fetch: fetchMock, baseUrl: BASE })
		).rejects.toBeInstanceOf(AdminApiError);
	});

	it('mappt 409 backupRequired und 429 retryAfter', async () => {
		const f409 = vi
			.fn()
			.mockResolvedValue(jsonResponse({ title: 'Backup', backupRequired: true }, { status: 409 }));
		await expect(
			adminFetch('/x', { method: 'POST' }, { fetch: f409, baseUrl: BASE })
		).rejects.toMatchObject({ backupRequired: true });
		const f429 = vi
			.fn()
			.mockResolvedValue(jsonResponse({ title: 'Too many', retryAfter: 42 }, { status: 429 }));
		await expect(
			adminFetch('/x', { method: 'POST' }, { fetch: f429, baseUrl: BASE })
		).rejects.toMatchObject({ retryAfter: 42 });
	});

	it('behandelt 204 als undefined', async () => {
		const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
		const res = await authLogout({ fetch: fetchMock, baseUrl: BASE });
		expect(res).toBeUndefined();
	});

	it('packt Netzwerkfehler in AdminApiError status 0', async () => {
		const fetchMock = vi.fn().mockRejectedValue(new Error('boom'));
		await expect(me({ fetch: fetchMock, baseUrl: BASE })).rejects.toMatchObject({
			status: 0,
			title: 'Network error'
		});
	});

	it('listUsers() packt die {users:[...]}-Hülle aus', async () => {
		const fetchMock = vi.fn().mockResolvedValue(
			jsonResponse({
				users: [
					{
						id: 1,
						displayName: 'A',
						email: 'a@b.de',
						role: 'moderator',
						status: 'active',
						createdAt: '2026-01-01T00:00:00+00:00',
						lastLoginAt: null
					}
				]
			})
		);
		const res = await listUsers({ fetch: fetchMock, baseUrl: BASE });
		expect(res).toHaveLength(1);
		expect(res[0].role).toBe('moderator');
	});

	it('queue() liefert entries und versions durch', async () => {
		const fetchMock = vi.fn().mockResolvedValue(
			jsonResponse({
				entries: [{ id: 1, formatId: 'a.b', type: 'menu', createdAt: '2026-01-01T00:00:00+00:00' }],
				versions: [
					{
						id: 2,
						entryId: 1,
						formatId: 'a.b',
						semver: '1.0.0',
						hasTransformCode: true,
						submittedAt: '2026-01-01T00:00:00+00:00'
					}
				]
			})
		);
		const res = await queue({ fetch: fetchMock, baseUrl: BASE });
		expect(res.entries[0].formatId).toBe('a.b');
		expect(res.versions[0].hasTransformCode).toBe(true);
		const [url] = fetchMock.mock.calls[0];
		expect(String(url)).toBe('https://api.test/api/admin/queue');
	});

	it('audit() baut die Query-Parameter aus page/perPage', async () => {
		const fetchMock = vi
			.fn()
			.mockResolvedValue(jsonResponse({ items: [], page: 2, perPage: 20 }));
		await audit(2, 20, { fetch: fetchMock, baseUrl: BASE });
		const [url] = fetchMock.mock.calls[0];
		expect(String(url)).toBe('https://api.test/api/admin/audit?page=2&perPage=20');
	});

	it('ruft onUnauthorized-Handler bei 401 auf und wirft anschließend', async () => {
		const handler = vi.fn();
		registerUnauthorizedHandler(handler);
		const fetchMock = vi
			.fn()
			.mockResolvedValue(jsonResponse({ title: 'Unauthorized' }, { status: 401 }));
		await expect(
			adminFetch('/x', { method: 'GET' }, { fetch: fetchMock, baseUrl: BASE })
		).rejects.toMatchObject({ status: 401 });
		expect(handler).toHaveBeenCalledOnce();
	});

	it('ruft onUnauthorized-Handler NICHT bei anderen Fehlern auf', async () => {
		const handler = vi.fn();
		registerUnauthorizedHandler(handler);
		const fetchMock = vi
			.fn()
			.mockResolvedValue(jsonResponse({ title: 'Forbidden' }, { status: 403 }));
		await expect(
			adminFetch('/x', { method: 'GET' }, { fetch: fetchMock, baseUrl: BASE })
		).rejects.toMatchObject({ status: 403 });
		expect(handler).not.toHaveBeenCalled();
	});

	it('adminAbsoluteScreenshotUrl absolutiert relative Pfade', () => {
		expect(adminAbsoluteScreenshotUrl(null, BASE)).toBeNull();
		expect(adminAbsoluteScreenshotUrl('/api/v1/entries/a.b/screenshot', BASE)).toBe(
			'https://api.test/api/v1/entries/a.b/screenshot'
		);
		// Bereits absolute URLs bleiben unverändert
		expect(adminAbsoluteScreenshotUrl('https://cdn.example.com/img.webp', BASE)).toBe(
			'https://cdn.example.com/img.webp'
		);
	});

	it('entryDetail() absolutiert screenshotUrl im Mapping', async () => {
		const rawEntry = {
			formatId: 'com.example.test',
			status: 'published',
			type: 'menu',
			name: 'Test',
			description: null,
			categories: [],
			tags: [],
			domains: [],
			installCount: 0,
			currentVersion: '1.0.0',
			deprecated: false,
			successorFormatId: null,
			screenshotUrl: '/api/v1/entries/com.example.test/screenshot',
			updatedAt: '2026-01-01T00:00:00+00:00',
			versions: [],
			submitterId: 1,
			submitterBanned: false,
			openReports: []
		};
		const fetchMock = vi.fn().mockResolvedValue(jsonResponse(rawEntry));
		const result = await entryDetail(42, { fetch: fetchMock, baseUrl: BASE });
		expect(result.screenshotUrl).toBe(
			'https://api.test/api/v1/entries/com.example.test/screenshot'
		);
	});

	it('entryDetail() lässt screenshotUrl null unberührt', async () => {
		const rawEntry = {
			formatId: 'com.example.test2',
			status: 'pending',
			type: 'engine',
			name: 'Engine',
			description: null,
			categories: [],
			tags: [],
			domains: [],
			installCount: 0,
			currentVersion: null,
			deprecated: false,
			successorFormatId: null,
			screenshotUrl: null,
			updatedAt: '2026-01-01T00:00:00+00:00',
			versions: [],
			submitterId: 2,
			submitterBanned: false,
			openReports: []
		};
		const fetchMock = vi.fn().mockResolvedValue(jsonResponse(rawEntry));
		const result = await entryDetail(7, { fetch: fetchMock, baseUrl: BASE });
		expect(result.screenshotUrl).toBeNull();
	});
});
