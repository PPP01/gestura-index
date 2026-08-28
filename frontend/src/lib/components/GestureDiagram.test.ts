import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/svelte';
import GestureDiagram from './GestureDiagram.svelte';

describe('GestureDiagram', () => {
	it('rendert bei kind="arrow" einen Pfad und setzt das aria-label', () => {
		const { container } = render(GestureDiagram, {
			kind: 'arrow',
			path: 'M110 40 L40 40',
			label: 'Zurück'
		});
		const svg = container.querySelector('svg.gesture');
		expect(svg).toBeInTheDocument();
		expect(svg?.getAttribute('aria-label')).toBe('Zurück');
		expect(svg?.getAttribute('role')).toBe('img');
		expect(container.querySelectorAll('path').length).toBeGreaterThanOrEqual(2);
		expect(container.querySelector('circle')).toBeInTheDocument();
	});

	it('rendert bei kind="rocker" eine Maus-Silhouette ohne Pfad-Prop', () => {
		const { container } = render(GestureDiagram, { kind: 'rocker', label: 'Rocker-Geste' });
		expect(container.querySelectorAll('rect.mouse-outline').length).toBe(2);
	});

	it('rendert bei kind="wheel" eine Maus-Silhouette mit Rad', () => {
		const { container } = render(GestureDiagram, { kind: 'wheel', label: 'Wheel-Geste' });
		expect(container.querySelectorAll('rect.mouse-outline').length).toBe(1);
		expect(container.querySelector('rect.key-fill')).toBeInTheDocument();
	});
});
