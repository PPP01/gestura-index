import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

// Wie bei allen Tests, die (transitiv) api.ts importieren: $env/dynamic/public
// muss gemockt werden, sonst schlägt das virtuelle SvelteKit-Modul außerhalb
// eines echten Requests fehl (siehe api.test.ts, Header.test.ts).
vi.mock('$env/dynamic/public', () => ({ env: { PUBLIC_API_BASE: 'http://api.test' } }));

// Die load-Gates laufen nur im Dev – im Prod-Build übernimmt Symfony das
// Gating, dort tut der Code nichts.
vi.mock('$app/environment', () => ({ dev: true }));

// BEWUSST kein Mock von `$lib/api`: geprüft wird, dass das Event-`fetch`
// tatsächlich bis in `request()` durchreicht – also echtes Verhalten durch die
// echte api.ts, nicht bloß ein Aufrufprotokoll eines Doubles.

/** Die vier schaltbaren Marketing-Seiten und ihr Sichtbarkeits-Slug. */
const PAGES = [
	{ slug: 'was-ist-gestura', load: () => import('./was-ist-gestura/+page.ts') },
	{ slug: 'maus-gesten', load: () => import('./maus-gesten/+page.ts') },
	{ slug: 'vergleich', load: () => import('./vergleich/+page.ts') },
	{ slug: 'beispiele', load: () => import('./beispiele/+page.ts') }
];

/** Minimale Antwort: `request()` liest nur `ok`, der Aufrufer nur `json()`. */
function visibilityResponse(slug: string) {
	return { ok: true, json: async () => ({ [slug]: true }) } as unknown as Response;
}

const originalFetch = globalThis.fetch;

describe('Marketing-Gates: Sichtbarkeit über das load-`fetch` holen', () => {
	let globalFetch: ReturnType<typeof vi.fn>;

	beforeEach(() => {
		// Deterministisch statt umgebungsabhängig: ein globales `fetch`, das
		// jeden Treffer als Fehler ausweist. So schlägt der Test auch dann fehl,
		// wenn das Event-`fetch` ignoriert wird, statt still ins Netz zu gehen.
		globalFetch = vi.fn(async () => {
			throw new Error('global fetch must not be used inside load()');
		});
		globalThis.fetch = globalFetch as unknown as typeof fetch;
	});

	afterEach(() => {
		globalThis.fetch = originalFetch;
	});

	for (const page of PAGES) {
		it(`»${page.slug}« nutzt das Event-\`fetch\`, nicht das globale`, async () => {
			const eventFetch = vi.fn(
				async (_url: string, _init?: RequestInit) => visibilityResponse(page.slug)
			);
			const { load } = await page.load();

			await load({ fetch: eventFetch } as never);

			expect(eventFetch).toHaveBeenCalledTimes(1);
			expect(eventFetch.mock.calls[0][0]).toBe('http://api.test/api/v1/pages');
			expect(globalFetch).not.toHaveBeenCalled();
		});
	}
});
