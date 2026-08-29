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
		// Zustand zwischen Tests zurücksetzen: das Ausblenden läuft über ein
		// Attribut am <html> plus localStorage (No-Flash-Mechanismus).
		document.documentElement.removeAttribute('data-hidden-pages');
		try {
			localStorage.clear();
		} catch {
			/* ignore */
		}
	});

	it('rendert die sechs Marketing-Nav-Links und den GitHub-Button aufs Extension-Repo', async () => {
		// Kein `getByLabelText('Auf GitHub ansehen')`: die Paraglide-Locale im
		// Testkontext ist nicht deterministisch de/en (kein Cookie/URL-Präfix
		// gesetzt, Fallback auf die Basissprache) – daher über die `.gh`-Klasse
		// statt über den lokalisierten aria-label-Text selektieren.
		//
		// Simuliert einen scheiternden Sichtbarkeits-Fetch (z.B. offline/API down):
		// fail-open lässt den zuletzt bekannten Zustand unberührt (hier leer).
		getPageVisibility.mockRejectedValue(new Error('network error'));
		const { container } = render(Header);
		await waitFor(() => expect(getPageVisibility).toHaveBeenCalled());
		const navLinks = container.querySelectorAll('.site-nav a');
		expect(navLinks.length).toBe(6);
		// Bei Fehler wird nichts ausgeblendet (Attribut bleibt ungesetzt).
		expect(document.documentElement.getAttribute('data-hidden-pages')).toBeNull();
		const gh = container.querySelector('a.gh') as HTMLAnchorElement;
		expect(gh.getAttribute('href')).toBe('https://github.com/PPP01/Gestura');
	});

	it('markiert eine per API deaktivierte Seite zum Ausblenden (Attribut + localStorage), ohne den Link aus dem DOM zu entfernen', async () => {
		getPageVisibility.mockResolvedValue({
			'was-ist-gestura': true,
			'maus-gesten': true,
			vergleich: false,
			beispiele: true
		});
		const { container } = render(Header);

		// Der Sichtbarkeits-Zustand wird nach dem asynchronen onMount-Fetch gesetzt.
		await waitFor(() =>
			expect(document.documentElement.getAttribute('data-hidden-pages')).toContain('vergleich')
		);

		// Kein DOM-Entfernen: alle sechs Links bleiben vorhanden (CSS blendet aus).
		const navLinks = container.querySelectorAll('.site-nav a');
		expect(navLinks.length).toBe(6);

		// Der deaktivierte Link trägt sein data-page-slug (Ziel der CSS-Regel).
		const vergleichLink = container.querySelector('.site-nav a[data-page-slug="vergleich"]');
		expect(vergleichLink).not.toBeNull();

		// Nur die deaktivierte Seite ist markiert; aktive nicht.
		const hidden = document.documentElement.getAttribute('data-hidden-pages') ?? '';
		expect(hidden.split(' ')).toContain('vergleich');
		expect(hidden).not.toContain('beispiele');

		// Für den No-Flash beim nächsten Reload in localStorage gespiegelt.
		expect(localStorage.getItem('gestura_pages_hidden') ?? '').toContain('vergleich');
	});
});
