import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/svelte';
import { m } from '$lib/paraglide/messages.js';
import Page from './+page.svelte';

describe('C4 »Gestura im Vergleich«', () => {
	it('setzt die H1 auf den Seitentitel', () => {
		render(Page);
		expect(screen.getByRole('heading', { level: 1, name: m.c4_page_title() })).toBeInTheDocument();
	});

	it('zeigt das Warnbanner zu den Platzhalter-Spalten', () => {
		render(Page);
		expect(screen.getByText(m.c4_warning())).toBeInTheDocument();
	});

	it('zeigt zehn Merkmal-Zeilen und drei Platzhalterspalten mit Gedankenstrich', () => {
		const { container, getAllByText } = render(Page);
		expect(container.querySelectorAll('.matrix-row').length).toBe(10);
		expect(getAllByText('–').length).toBeGreaterThanOrEqual(30);
	});

	it('zeigt für jedes der zehn Merkmale die echte Gestura-Notiz', () => {
		render(Page);
		for (const note of [
			m.c4_v_firefox(),
			m.c4_v_notrack(),
			m.c4_v_anon(),
			m.c4_v_free(),
			m.c4_v_os(),
			m.c4_v_engines(),
			m.c4_v_menus(),
			m.c4_v_index(),
			m.c4_v_light(),
			m.c4_v_rockerwheel()
		]) {
			expect(screen.getByText(note)).toBeInTheDocument();
		}
	});

	it('erfindet keine Konkurrenz-Werte: Platzhalterzellen enthalten ausschließlich den Gedankenstrich', () => {
		const { container } = render(Page);
		const placeholders = container.querySelectorAll('.matrix-row .col-placeholder');
		expect(placeholders).toHaveLength(30);
		for (const cell of placeholders) {
			expect(cell.textContent?.trim()).toBe('–');
		}
	});

	it('zeigt die Fußnote', () => {
		render(Page);
		expect(screen.getByText(m.c4_footnote())).toBeInTheDocument();
	});
});
