import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/svelte';
import { m } from '$lib/paraglide/messages.js';
import Page from './+page.svelte';

describe('Über-Seite', () => {
	it('setzt die H1 aus Lead und hervorgehobenem Teil zusammen', () => {
		render(Page);
		const h1 = screen.getByRole('heading', { level: 1 });
		expect(h1.textContent).toContain(m.about_h1_lead());
		expect(h1.textContent).toContain(m.about_h1_accent());
	});

	it('zeigt drei Fähigkeiten des Index', () => {
		const { container } = render(Page);
		expect(container.querySelectorAll('.ability-card')).toHaveLength(3);
		expect(screen.getByText(m.about_can_share_title())).toBeInTheDocument();
		expect(screen.getByText(m.about_can_updates_title())).toBeInTheDocument();
		expect(screen.getByText(m.about_can_sync_title())).toBeInTheDocument();
	});

	/*
	 * Der Kern der inhaltlichen Überarbeitung: Update-Check und Sync sind
	 * serverseitig fertig, aber erst mit der kommenden Version der Erweiterung
	 * nutzbar. Genau zwei Karten tragen die Kennzeichnung – das Teilen, das
	 * heute funktioniert, trägt sie nicht.
	 */
	it('kennzeichnet genau die zwei noch nicht nutzbaren Fähigkeiten', () => {
		const { container } = render(Page);
		const badges = container.querySelectorAll('.badge');
		expect(badges).toHaveLength(2);
		for (const badge of badges) {
			expect(badge.textContent?.trim()).toBe(m.about_badge_prepared());
		}
		expect(screen.getByText(m.about_prepared_note())).toBeInTheDocument();
		// Die Karte »Teilen und entdecken« ist die Ausnahme ohne Kennzeichnung.
		const shareCard = screen.getByText(m.about_can_share_title()).closest('.ability-card');
		expect(shareCard?.querySelector('.badge')).toBeNull();
	});

	it('nennt Datensparsamkeit und das Verhältnis zur Erweiterung', () => {
		render(Page);
		expect(screen.getByText(m.about_privacy_heading())).toBeInTheDocument();
		expect(screen.getByText(m.about_privacy_body())).toBeInTheDocument();
		expect(screen.getByText(m.about_relation_body())).toBeInTheDocument();
	});

	it('verlinkt das Repository', () => {
		render(Page);
		const link = screen.getByRole('link', { name: new RegExp(m.about_repo_link()) });
		expect(link.getAttribute('href')).toBe('https://github.com/PPP01/gestura-index');
		expect(link.getAttribute('rel')).toContain('noopener');
	});
});
