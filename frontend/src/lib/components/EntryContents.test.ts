import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/svelte';
import EntryContents from './EntryContents.svelte';

vi.mock('$env/dynamic/public', () => ({ env: { PUBLIC_API_BASE: undefined } }));

describe('EntryContents', () => {
	it('zeigt die vollständige Beschreibung (in der Kartenzeile ist sie gekürzt)', () => {
		const long = 'Zeile eins.\nZeile zwei mit deutlich mehr Text als in eine Zeile passt.';
		render(EntryContents, { payload: { description: long, items: [] }, type: 'menu' });
		expect(screen.getByText(/Zeile zwei/)).toBeInTheDocument();
	});

	it('nennt zu jedem Menü-Eintrag sein Ziel', () => {
		render(EntryContents, {
			payload: {
				items: [
					{ id: 'a', action: 'openCustomUrl', label: 'Home', customUrl: 'https://e.test/x' },
					{ id: 'b', action: 'searchLink', label: 'Suche', engineId: 'google' }
				]
			},
			type: 'menu'
		});
		expect(screen.getByText('https://e.test/x')).toBeInTheDocument();
		expect(screen.getByText(/search engine: google|suchmaschine: google/i)).toBeInTheDocument();
	});

	it('markiert Items, die im Menü nicht erscheinen', () => {
		render(EntryContents, {
			payload: { items: [{ id: 'a', action: 'none', label: 'Ballast' }] },
			type: 'menu'
		});
		expect(screen.getByText('Ballast')).toBeInTheDocument();
		expect(screen.getByText(/not shown in the menu|erscheint nicht/i)).toBeInTheDocument();
	});

	it('markiert in der Engine-URL, wo der Suchbegriff landet', () => {
		render(EntryContents, { payload: { url: 'https://e.test/?q=' }, type: 'engine' });
		expect(screen.getByText('https://e.test/?q=')).toBeInTheDocument();
		expect(screen.getByText(/search term|suchbegriff/i)).toBeInTheDocument();
	});

	// Entscheidung des Nutzers: Code wird offengelegt wie im Import-Dialog der
	// Extension – aber zugeklappt, damit er die Karte nicht überschwemmt.
	it('legt mitgelieferten Code erst auf Klick offen, mit Warnung davor', async () => {
		render(EntryContents, {
			payload: { url: 'https://e.test/?q=', transformCode: 'return evil(x);' },
			type: 'engine'
		});
		expect(screen.getByText(/executable javascript|ausführbares javascript/i)).toBeInTheDocument();
		expect(screen.queryByText('return evil(x);')).not.toBeInTheDocument();
		await fireEvent.click(screen.getByRole('button', { name: /show code|code anzeigen/i }));
		expect(screen.getByText('return evil(x);')).toBeInTheDocument();
	});

	it('rendert für eine Engine ohne URL nichts Halbfertiges', () => {
		const { container } = render(EntryContents, { payload: { gesturaEngine: 1 }, type: 'engine' });
		expect(container.textContent?.trim()).toBe('');
	});
});
