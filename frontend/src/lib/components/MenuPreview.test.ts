import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/svelte';
import MenuPreview from './MenuPreview.svelte';

vi.mock('$env/dynamic/public', () => ({ env: { PUBLIC_API_BASE: undefined } }));

const menu = (items: unknown[]) => ({ gesturaMenu: 1, id: 'x', version: '1.0.0', name: 'X', items });

describe('MenuPreview', () => {
	it('rendert das Inline-SVG aus dem Icon-Set der Extension', () => {
		const { container } = render(MenuPreview, {
			payload: menu([{ id: 'a', action: 'openCustomUrl', label: 'Home', icon: 'house', customUrl: 'https://e.test/' }]),
			name: 'X'
		});
		expect(container.querySelector('.fm-ctx-icon svg.lucide-house')).not.toBeNull();
		// Ziel-URL steckt als Tooltip an der Zeile – im Index will man wissen, wohin ein Link führt.
		expect(container.querySelector('.fm-ctx-item')?.getAttribute('title')).toBe('https://e.test/');
	});

	it('rendert für icon:"favicon" ein Monogramm-Bild ohne Fremd-URL', () => {
		const { container } = render(MenuPreview, {
			payload: menu([{ id: 'a', action: 'openCustomUrl', label: 'Wiki', icon: 'favicon', customUrl: 'https://w.test/' }]),
			name: 'X'
		});
		const img = container.querySelector('.fm-ctx-icon img');
		expect(img?.getAttribute('src')).toMatch(/^data:image\/svg\+xml,/);
	});

	it('meldet ein Menü ohne anzeigbare Einträge, statt einen leeren Rahmen zu zeigen', () => {
		const { container } = render(MenuPreview, {
			payload: menu([{ id: 'a', action: 'none', label: 'Nix' }]),
			name: 'X'
		});
		expect(container.querySelector('.fm-ctx-frame')).toBeNull();
		expect(screen.getByText(/no displayable entries|keine anzeigbaren/i)).toBeInTheDocument();
	});

	it('beschriftet die Liste für Screenreader mit dem Eintragsnamen', () => {
		render(MenuPreview, { payload: menu([{ id: 'a', action: 'back' }]), name: 'WhatsApp Web' });
		expect(screen.getByRole('list', { name: /WhatsApp Web/ })).toBeInTheDocument();
	});
});
