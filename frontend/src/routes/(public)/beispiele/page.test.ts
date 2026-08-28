import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/svelte';
import { m } from '$lib/paraglide/messages.js';
import Page from './+page.svelte';

describe('C5 »Beispiele«', () => {
	it('setzt die H1 auf den Seitentitel', () => {
		render(Page);
		expect(screen.getByRole('heading', { level: 1, name: m.c5_page_title() })).toBeInTheDocument();
	});

	it('zeigt den Intro-Text', () => {
		render(Page);
		expect(screen.getByText(m.c5_intro())).toBeInTheDocument();
	});

	it('zeigt vier Showcase-Karten', () => {
		const { container } = render(Page);
		expect(container.querySelectorAll('.showcase-card')).toHaveLength(4);
	});

	it('zeigt Name, Beschreibung und Gesten-Chip aller vier kuratierten Beispiele', () => {
		render(Page);
		for (const name of [
			m.c5_price_name(),
			m.c5_wiki_name(),
			m.c5_github_name(),
			m.c5_news_name()
		]) {
			expect(screen.getByText(name)).toBeInTheDocument();
		}
		for (const desc of [
			m.c5_price_desc(),
			m.c5_wiki_desc(),
			m.c5_github_desc(),
			m.c5_news_desc()
		]) {
			expect(screen.getByText(desc)).toBeInTheDocument();
		}
		for (const gesture of ['Super-Drag ↓', 'Auswahl + →', 'Rechtsklick-Menü', '↓ → Menü']) {
			expect(screen.getByText(gesture)).toBeInTheDocument();
		}
	});

	it('»Zum Index«-Links zeigen auf /index (viermal)', () => {
		render(Page);
		const links = screen.getAllByRole('link', { name: m.c5_cta_index() });
		expect(links).toHaveLength(4);
		for (const link of links) {
			expect(link.getAttribute('href')).toContain('/index');
		}
	});

	it('»Installieren«-Links enthalten #install (viermal)', () => {
		render(Page);
		const links = screen.getAllByRole('link', { name: m.c5_cta_install() });
		expect(links).toHaveLength(4);
		for (const link of links) {
			expect(link.getAttribute('href')).toContain('#install');
		}
	});
});
