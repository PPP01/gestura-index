import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/svelte';
import StarRating from './StarRating.svelte';

describe('StarRating', () => {
	it('zeigt "Noch nicht bewertet" bei count 0', () => {
		render(StarRating, { average: null, count: 0 });
		expect(screen.getByText(/not yet rated|noch nicht bewertet/i)).toBeInTheDocument();
	});
	it('zeigt Wert und Anzahl bei vorhandener Bewertung', () => {
		render(StarRating, { average: 4.3, count: 12 });
		// locale-formatierter Wert (4.3 / 4,3) taucht auf
		expect(screen.getByText(/4[.,]3/)).toBeInTheDocument();
		expect(screen.getByText(/12/)).toBeInTheDocument();
	});
	it('rendert Basis- und Overlay-Sterne mit exakt geclipptem Teilstern', () => {
		const { container } = render(StarRating, { average: 3, count: 5 });
		// 5 gemutete Basis-Sterne + 5 goldene Overlay-Sterne = 10 SVGs, nicht 5
		// gerundete Sterne wie zuvor.
		expect(container.querySelectorAll('svg').length).toBe(10);
		const base = container.querySelector('.stars-base');
		const overlay = container.querySelector('.stars-overlay') as HTMLElement;
		expect(base?.querySelectorAll('svg').length).toBe(5);
		expect(overlay.querySelectorAll('svg').length).toBe(5);
		// 3 von 5 Sternen => 60% Overlay-Breite (exakter Teilstern statt Rundung).
		expect(overlay.style.width).toBe('60%');
	});
});
