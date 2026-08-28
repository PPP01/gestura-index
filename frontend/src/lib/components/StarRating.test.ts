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
	it('rendert fünf Stern-Icons', () => {
		const { container } = render(StarRating, { average: 3, count: 5 });
		expect(container.querySelectorAll('svg').length).toBe(5);
	});
});
