import { render } from '@testing-library/svelte';
import { describe, it, expect } from 'vitest';
import Header from './Header.svelte';

describe('Header', () => {
	it('rendert die sechs Marketing-Nav-Links und den GitHub-Button aufs Extension-Repo', () => {
		// Kein `getByLabelText('Auf GitHub ansehen')`: die Paraglide-Locale im
		// Testkontext ist nicht deterministisch de/en (kein Cookie/URL-Präfix
		// gesetzt, Fallback auf die Basissprache) – daher über die `.gh`-Klasse
		// statt über den lokalisierten aria-label-Text selektieren.
		const { container } = render(Header);
		const navLinks = container.querySelectorAll('.site-nav a');
		expect(navLinks.length).toBe(6);
		const gh = container.querySelector('a.gh') as HTMLAnchorElement;
		expect(gh.getAttribute('href')).toBe('https://github.com/PPP01/Gestura');
	});
});
