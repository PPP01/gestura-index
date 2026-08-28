import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/svelte';
import { m } from '$lib/paraglide/messages.js';
import Page from './+page.svelte';

describe('C2 »Was ist Gestura«', () => {
	it('setzt die H1 auf den Seitentitel', () => {
		render(Page);
		expect(screen.getByRole('heading', { level: 1, name: m.c2_page_title() })).toBeInTheDocument();
	});

	it('zeigt drei Persona-Karten', () => {
		const { container } = render(Page);
		expect(container.querySelectorAll('.persona-card')).toHaveLength(3);
		expect(screen.getByText(m.c2_persona_surf_title())).toBeInTheDocument();
		expect(screen.getByText(m.c2_persona_research_title())).toBeInTheDocument();
		expect(screen.getByText(m.c2_persona_privacy_title())).toBeInTheDocument();
	});

	it('zeigt drei Sektions-Screenshots mit Alt-Text', () => {
		const { container } = render(Page);
		const images = [...container.querySelectorAll('img')];
		expect(images).toHaveLength(3);
		for (const img of images) {
			expect(img.getAttribute('alt')).toBeTruthy();
		}
		expect(images.map((img) => img.getAttribute('alt'))).toEqual([
			m.gestura_shot2(),
			m.gestura_shot3(),
			m.gestura_shot4()
		]);
	});

	it('zeigt das Abschluss-Banner (fett gesetzter Lead-in + Fließtext im selben Absatz)', () => {
		const { container } = render(Page);
		// Titel steht als eigenes <strong>, per getByText matchbar.
		expect(screen.getByText(m.c2_privacy_banner_title())).toBeInTheDocument();
		// Der Fließtext folgt im selben <p> als eigener Textknoten – dafür reicht
		// der reine Text-Container-Vergleich (kein separates DOM-Element).
		expect(container.querySelector('.banner')?.textContent).toContain(m.c2_privacy_banner_body());
	});
});
