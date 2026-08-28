import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/svelte';
import { m } from '$lib/paraglide/messages.js';
import Page from './+page.svelte';

describe('C3 »Was sind Maus-Gesten«', () => {
	it('setzt die H1 auf den Seitentitel', () => {
		render(Page);
		expect(screen.getByRole('heading', { level: 1, name: m.c3_page_title() })).toBeInTheDocument();
	});

	it('zeigt acht Gesten-Karten mit je einem Diagramm', () => {
		const { container } = render(Page);
		expect(container.querySelectorAll('.gesture-card')).toHaveLength(8);
		expect(container.querySelectorAll('svg.gesture')).toHaveLength(8);
	});

	it('beschriftet jede Karte mit ihrem Aktions-Label', () => {
		render(Page);
		for (const label of [
			m.c3_back(),
			m.c3_forward(),
			m.c3_newtab(),
			m.c3_closetab(),
			m.c3_scrollup(),
			m.c3_reload(),
			m.c3_rocker(),
			m.c3_wheel()
		]) {
			expect(screen.getByText(label)).toBeInTheDocument();
		}
	});

	it('zeigt die Fußnote zu den frei belegbaren Gesten', () => {
		render(Page);
		expect(screen.getByText(m.c3_footnote())).toBeInTheDocument();
	});

	it('zeigt das übersetzte Wheel-Kürzel (»R + Rad«, kein sprachneutrales Symbol-Literal)', () => {
		const { container } = render(Page);
		expect(screen.getByText(m.c3_wheel_kuerzel())).toBeInTheDocument();
		expect(container.textContent).not.toContain('R + ↕');
	});

	it('versteckt alle Mono-Kürzel vor Screenreadern (Info steckt bereits im Label/aria-label)', () => {
		const { container } = render(Page);
		const kuerzel = container.querySelectorAll('.kuerzel');
		expect(kuerzel).toHaveLength(8);
		for (const el of kuerzel) {
			expect(el.getAttribute('aria-hidden')).toBe('true');
		}
	});
});
