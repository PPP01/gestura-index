import { describe, it, expect, vi } from 'vitest';

// Mock $env/dynamic/public für Tests
vi.mock('$env/dynamic/public', () => ({
	env: {
		PUBLIC_API_BASE: undefined
	}
}));

import {
	listEntries,
	getEntry,
	reportEntry,
	pingInstall,
	downloadVersionUrl,
	absoluteScreenshotUrl,
	ApiError
} from './api';

const BASE = 'https://api.test';

function jsonResponse(body: unknown, init: Partial<{ status: number; contentType: string }> = {}) {
	return new Response(JSON.stringify(body), {
		status: init.status ?? 200,
		headers: { 'content-type': init.contentType ?? 'application/json' }
	});
}

describe('absoluteScreenshotUrl', () => {
	it('setzt die Basis vor relative URLs', () => {
		expect(absoluteScreenshotUrl('/media/a.webp', BASE)).toBe('https://api.test/media/a.webp');
	});
	it('gibt null für null zurück', () => {
		expect(absoluteScreenshotUrl(null, BASE)).toBeNull();
	});
});

describe('listEntries', () => {
	it('serialisiert nur gesetzte Query-Parameter und mappt Screenshots', async () => {
		const fetchMock = vi.fn().mockResolvedValue(
			jsonResponse({
				items: [{ formatId: 'a', screenshotUrl: '/media/a.webp' }],
				page: 1,
				perPage: 20,
				total: 1
			})
		);
		const res = await listEntries(
			{ q: 'wiki', type: 'menu', page: 2 },
			{ fetch: fetchMock, baseUrl: BASE }
		);
		const calledUrl = new URL(fetchMock.mock.calls[0][0]);
		expect(calledUrl.pathname).toBe('/api/v1/entries');
		expect(calledUrl.searchParams.get('q')).toBe('wiki');
		expect(calledUrl.searchParams.get('type')).toBe('menu');
		expect(calledUrl.searchParams.get('page')).toBe('2');
		expect(calledUrl.searchParams.has('tag')).toBe(false);
		expect(res.items[0].screenshotUrl).toBe('https://api.test/media/a.webp');
	});
});

describe('getEntry', () => {
	it('wirft ApiError mit Detail aus problem+json bei 404', async () => {
		const fetchMock = vi.fn().mockResolvedValue(
			jsonResponse(
				{ title: 'Not Found', detail: 'Entry not found' },
				{ status: 404, contentType: 'application/problem+json' }
			)
		);
		await expect(getEntry('nope', { fetch: fetchMock, baseUrl: BASE })).rejects.toMatchObject({
			status: 404,
			detail: 'Entry not found'
		});
		await expect(getEntry('nope', { fetch: fetchMock, baseUrl: BASE })).rejects.toBeInstanceOf(
			ApiError
		);
	});
});

describe('reportEntry', () => {
	it('sendet reason und comment als JSON-POST', async () => {
		const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
		await reportEntry('a', { reason: 'spam', comment: 'x' }, { fetch: fetchMock, baseUrl: BASE });
		const [url, init] = fetchMock.mock.calls[0];
		expect(String(url)).toBe('https://api.test/api/v1/entries/a/report');
		expect(init.method).toBe('POST');
		expect(JSON.parse(init.body)).toEqual({ reason: 'spam', comment: 'x' });
	});
});

describe('pingInstall', () => {
	it('schluckt Fehler nicht – wirft bei non-2xx', async () => {
		const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 500 }));
		await expect(pingInstall('a', { fetch: fetchMock, baseUrl: BASE })).rejects.toBeInstanceOf(
			ApiError
		);
	});
});

describe('downloadVersionUrl', () => {
	it('baut die Versions-URL', () => {
		expect(downloadVersionUrl('a', '1.2.3', BASE)).toBe(
			'https://api.test/api/v1/entries/a/versions/1.2.3'
		);
	});
});

describe('network errors', () => {
	it('wickelt einen fetch-Ausfall in einen ApiError mit status 0', async () => {
		const fetchMock = vi.fn().mockRejectedValue(new Error('boom'));
		await expect(getEntry('a', { fetch: fetchMock, baseUrl: 'https://api.test' })).rejects.toMatchObject({
			status: 0,
			title: 'Network error',
			detail: 'boom'
		});
	});
});
