import { render } from '@testing-library/svelte';
import { describe, it, expect } from 'vitest';
import StoreBadges from './StoreBadges.svelte';

describe('StoreBadges', () => {
	it('verlinkt auf die drei korrekten Store-URLs', () => {
		const { container } = render(StoreBadges);
		const hrefs = [...container.querySelectorAll('a')].map((a) => a.getAttribute('href'));
		expect(hrefs.some((h) => h?.includes('chromewebstore.google.com'))).toBe(true);
		expect(hrefs.some((h) => h?.includes('microsoftedge.microsoft.com'))).toBe(true);
		expect(hrefs.some((h) => h?.includes('addons.mozilla.org'))).toBe(true);
	});


	it('Chrome bekommt das eigene Badge, Edge und Firefox die Vendor-Grafiken', () => {
		const { container } = render(StoreBadges);
		const links = [...container.querySelectorAll('a')];
		const chrome = links.find((a) => a.getAttribute('href')?.includes('chromewebstore.google.com'));
		expect(chrome?.querySelector('.chrome-badge')).not.toBeNull();
		// Kein aria-label: der sichtbare Text ist der zugängliche Name (WCAG 2.5.3).
		expect(chrome?.hasAttribute('aria-label')).toBe(false);
		expect(chrome?.textContent).toContain('Chrome Web Store');
		// Die beiden anderen bleiben Bilder.
		expect(container.querySelectorAll('.store-badges > a > img')).toHaveLength(2);
	});

	it('die Höhe kommt als Prop in die Reihe', () => {
		const { container } = render(StoreBadges, { props: { height: 48 } });
		const row = container.querySelector('.store-badges') as HTMLElement;
		expect(row.style.getPropertyValue('--badge-height')).toBe('48px');
	});
});
