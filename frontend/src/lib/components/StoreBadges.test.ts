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
});
