import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render } from '@testing-library/svelte';
import { localizeHref } from '$lib/paraglide/runtime';

const goto = vi.fn();
vi.mock('$app/navigation', () => ({ goto: (...a: unknown[]) => goto(...a) }));

let currentUrl = new URL('http://localhost/en/browse?category=dev&q=abc');
let currentParams: Record<string, string> = {};
vi.mock('$app/state', () => ({
	page: {
		get url() {
			return currentUrl;
		},
		get params() {
			return currentParams;
		}
	}
}));

beforeEach(() => goto.mockReset());

describe('deep-link redirects', () => {
	it('/browse mappt Alt-Parameter auf den Onepager unter /index', async () => {
		currentUrl = new URL('http://localhost/en/browse?category=dev&q=abc&sort=installs');
		const Browse = (await import('./browse/+page.svelte')).default;
		render(Browse);
		expect(goto).toHaveBeenCalledTimes(1);
		const target = goto.mock.calls[0][0] as string;
		expect(target).toContain('/index');
		expect(target).toContain('category=dev');
		expect(target).toContain('q=abc');
		expect(target).toContain('sort=installs');
		expect(target).not.toContain('/browse');
	});

	it('/entry/{id} leitet mit highlight auf /index weiter', async () => {
		currentUrl = new URL('http://localhost/en/entry/com.example.menu');
		currentParams = { formatId: 'com.example.menu' };
		const Entry = (await import('./entry/[formatId]/+page.svelte')).default;
		render(Entry);
		expect(goto).toHaveBeenCalledTimes(1);
		const target = goto.mock.calls[0][0] as string;
		expect(target).toContain('/index');
		expect(target).toContain('highlight=com.example.menu');
		expect(target).not.toContain('/entry/');
	});

	it('/gestura leitet auf die (kuenftige) Startseite / weiter', async () => {
		currentUrl = new URL('http://localhost/en/gestura');
		const Gestura = (await import('./gestura/+page.svelte')).default;
		render(Gestura);
		expect(goto).toHaveBeenCalledTimes(1);
		const target = goto.mock.calls[0][0] as string;
		expect(target).toBe(localizeHref('/'));
		expect(target).not.toContain('/gestura');
	});
});
