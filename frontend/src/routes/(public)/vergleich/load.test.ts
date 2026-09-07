import { describe, it, expect, vi, beforeEach } from 'vitest';

// Wie bei allen Tests, die (transitiv) api.ts importieren: $env/dynamic/public
// muss gemockt werden, sonst schlägt das virtuelle SvelteKit-Modul außerhalb
// eines echten Requests fehl (siehe api.test.ts, Header.test.ts).
vi.mock('$env/dynamic/public', () => ({ env: { PUBLIC_API_BASE: undefined } }));

// `dev` aus $app/environment lässt sich im Vitest-Kontext direkt mocken – im
// echten Testlauf ist `dev` ohnehin bereits `true` (Vite läuft im Serve-Modus),
// der explizite Mock macht die Annahme aber unabhängig davon dokumentiert und
// robust.
vi.mock('$app/environment', () => ({ dev: true }));

// getPageVisibility gezielt mocken statt echten Netzwerk-Fetch laufen zu
// lassen – deterministisch statt von echter Netzwerkerreichbarkeit abhängig.
const getPageVisibility = vi.fn();
vi.mock('$lib/api', async (orig) => {
	const actual = await orig<typeof import('$lib/api')>();
	return { ...actual, getPageVisibility: (...a: unknown[]) => getPageVisibility(...a) };
});

// SvelteKit ruft `load` stets MIT Event auf; die Seite holt die Sichtbarkeit
// über dessen `fetch` (Vertrag geprüft in ../marketing-load-fetch.test.ts).
const EVENT = { fetch: vi.fn() } as never;

describe('C4 »Gestura im Vergleich« – Dev-404-Gate', () => {
	beforeEach(() => {
		getPageVisibility.mockReset();
	});

	it('wirft ein 404, wenn die Seite per Flag deaktiviert ist', async () => {
		getPageVisibility.mockResolvedValue({ vergleich: false });
		const { load } = await import('./+page.ts');
		await expect(load(EVENT)).rejects.toMatchObject({ status: 404 });
	});

	it('lädt normal, wenn die Seite aktiviert ist', async () => {
		getPageVisibility.mockResolvedValue({ vergleich: true });
		const { load } = await import('./+page.ts');
		await expect(load(EVENT)).resolves.toBeUndefined();
	});

	it('lädt fail-open, wenn der Sichtbarkeits-Fetch scheitert', async () => {
		getPageVisibility.mockRejectedValue(new Error('network error'));
		const { load } = await import('./+page.ts');
		await expect(load(EVENT)).resolves.toBeUndefined();
	});
});
