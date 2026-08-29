import { render, waitFor } from '@testing-library/svelte';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import Header from './Header.svelte';

// Wie bei allen anderen Tests, die (transitiv) api.ts importieren: $env/dynamic/public
// muss gemockt werden, sonst schlägt das virtuelle SvelteKit-Modul außerhalb eines
// echten Requests fehl (siehe api.test.ts, EntryBlock.test.ts).
vi.mock('$env/dynamic/public', () => ({ env: { PUBLIC_API_BASE: undefined } }));

// getPageVisibility gezielt mocken statt echten Netzwerk-Fetch laufen zu lassen –
// deterministisch statt von echter Netzwerkerreichbarkeit im Testlauf abhängig.
const getPageVisibility = vi.fn();
vi.mock('$lib/api', async (orig) => {
	const actual = await orig<typeof import('$lib/api')>();
	return { ...actual, getPageVisibility: (...a: unknown[]) => getPageVisibility(...a) };
});

describe('Header', () => {
	beforeEach(() => {
		getPageVisibility.mockReset();
	});

	it('rendert die sechs Marketing-Nav-Links und den GitHub-Button aufs Extension-Repo', async () => {
		// Kein `getByLabelText('Auf GitHub ansehen')`: die Paraglide-Locale im
		// Testkontext ist nicht deterministisch de/en (kein Cookie/URL-Präfix
		// gesetzt, Fallback auf die Basissprache) – daher über die `.gh`-Klasse
		// statt über den lokalisierten aria-label-Text selektieren.
		//
		// Simuliert einen scheiternden Sichtbarkeits-Fetch (z.B. offline/API down):
		// fail-open muss dann ALLE sechs Links zeigen.
		getPageVisibility.mockRejectedValue(new Error('network error'));
		const { container } = render(Header);
		await waitFor(() => expect(getPageVisibility).toHaveBeenCalled());
		const navLinks = container.querySelectorAll('.site-nav a');
		expect(navLinks.length).toBe(6);
		const gh = container.querySelector('a.gh') as HTMLAnchorElement;
		expect(gh.getAttribute('href')).toBe('https://github.com/PPP01/Gestura');
	});

	it('blendet eine per API deaktivierte Seite aus der Nav aus', async () => {
		getPageVisibility.mockResolvedValue({
			'was-ist-gestura': true,
			'maus-gesten': true,
			vergleich: false,
			beispiele: true
		});
		const { container } = render(Header);
		// Die Ausblendung greift erst nach dem asynchronen onMount-Fetch.
		// `.toContain('/vergleich')`-Muster wie in page.test.ts, da `localizeHref()`
		// je nach Locale ein Sprach-Präfix voranstellt (z.B. `/en/vergleich`).
		await waitFor(() => {
			const navLinks = container.querySelectorAll('.site-nav a');
			expect(navLinks.length).toBe(5);
		});
		const hrefs = [...container.querySelectorAll('.site-nav a')].map((a) => a.getAttribute('href') ?? '');
		expect(hrefs.some((h) => h.includes('/vergleich'))).toBe(false);
		expect(hrefs.some((h) => h.includes('/was-ist-gestura'))).toBe(true);
		expect(hrefs.some((h) => h.includes('/maus-gesten'))).toBe(true);
		expect(hrefs.some((h) => h.includes('/beispiele'))).toBe(true);
		expect(hrefs.some((h) => h.includes('/index'))).toBe(true);
	});
});
