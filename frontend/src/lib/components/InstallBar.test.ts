import { render, screen } from '@testing-library/svelte';
import { describe, it, expect } from 'vitest';
import { m } from '$lib/paraglide/messages.js';
import InstallBar from './InstallBar.svelte';

describe('InstallBar', () => {
	it('zeigt die Überschrift und die drei Store-Links', () => {
		const { container } = render(InstallBar);
		expect(screen.getByText(m.install_bar_title())).toBeInTheDocument();
		const hrefs = [...container.querySelectorAll('a')].map((a) => a.getAttribute('href'));
		expect(hrefs.some((h) => h?.includes('chromewebstore.google.com'))).toBe(true);
		expect(hrefs.some((h) => h?.includes('microsoftedge.microsoft.com'))).toBe(true);
		expect(hrefs.some((h) => h?.includes('addons.mozilla.org'))).toBe(true);
	});

	it('nimmt eine eigene Überschrift an', () => {
		render(InstallBar, { props: { title: 'Eigener Text' } });
		expect(screen.getByText('Eigener Text')).toBeInTheDocument();
	});

	it('trägt kein id="install" – der Anker gehört dem Hero der Startseite', () => {
		// Der Streifen steckt im Layout und damit auf JEDER Seite; ein id="install"
		// hier wäre auf der Startseite ein zweiter Anker mit derselben id
		// (/beispiele verlinkt viermal auf /#install).
		const { container } = render(InstallBar);
		expect(container.querySelector('#install')).toBeNull();
	});
});
