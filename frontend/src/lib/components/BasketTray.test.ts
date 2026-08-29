import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import BasketTray from './BasketTray.svelte';
import type { EntryListItem } from '$lib/api';
import { basket } from '$lib/basket.svelte';

// Wie bei allen Tests, die (transitiv) api.ts importieren: $env/dynamic/public
// muss gemockt werden (siehe EntryBlock.test.ts, api.test.ts).
vi.mock('$env/dynamic/public', () => ({ env: { PUBLIC_API_BASE: undefined } }));

const getBundle = vi.fn();
const triggerJsonDownload = vi.fn();
vi.mock('$lib/api', async (orig) => {
	const actual = await orig<typeof import('$lib/api')>();
	return { ...actual, getBundle: (...a: unknown[]) => getBundle(...a) };
});
vi.mock('$lib/download', () => ({
	triggerJsonDownload: (...a: unknown[]) => triggerJsonDownload(...a),
	buildDownloadFilename: () => 'x.json'
}));

function item(id: string): EntryListItem {
	return {
		formatId: id,
		type: 'menu',
		name: id,
		description: null,
		categories: [],
		tags: [],
		domains: [],
		installCount: 0,
		rating: { average: null, count: 0 },
		currentVersion: '1.2.0',
		deprecated: false,
		successorFormatId: null,
		screenshotUrl: null,
		updatedAt: '2026-01-01T00:00:00Z'
	};
}
const catalog = new Map([
	['a', item('a')],
	['b', item('b')]
]);

beforeEach(() => {
	localStorage.clear();
	basket.clear();
	getBundle.mockReset();
	triggerJsonDownload.mockReset();
});

describe('BasketTray', () => {
	it('zeigt einen neutralen Pill mit Leerzustand, solange die Auswahl leer ist', async () => {
		render(BasketTray, { catalog });
		const pill = screen.getByRole('button', { name: /selection \(0\)|auswahl \(0\)/i });
		expect(pill).toBeInTheDocument();
		expect(pill.className).not.toMatch(/accent/);
		await fireEvent.click(pill);
		expect(screen.getByText(/nothing collected yet|noch nichts gesammelt/i)).toBeInTheDocument();
	});

	it('zeigt den Zähler und öffnet das Panel', async () => {
		basket.toggle('a');
		render(BasketTray, { catalog });
		const opener = screen.getByRole('button', { name: /selection \(1\)|auswahl \(1\)/i });
		expect(opener.className).toMatch(/accent/);
		await fireEvent.click(opener);
		expect(screen.getByText(/^your selection$|^deine auswahl$/i)).toBeInTheDocument();
	});

	it('entfernt einen Eintrag im Panel', async () => {
		basket.toggle('a');
		basket.toggle('b');
		render(BasketTray, { catalog });
		await fireEvent.click(screen.getByRole('button', { name: /selection \(2\)|auswahl \(2\)/i }));
		const removeButtons = screen.getAllByRole('button', { name: /remove|entfernen/i });
		await fireEvent.click(removeButtons[0]);
		expect(basket.count).toBe(1);
	});

	it('"alle entfernen" leert den Korb', async () => {
		basket.toggle('a');
		basket.toggle('b');
		render(BasketTray, { catalog });
		await fireEvent.click(screen.getByRole('button', { name: /selection \(2\)|auswahl \(2\)/i }));
		await fireEvent.click(screen.getByRole('button', { name: /clear all|alle entfernen/i }));
		expect(basket.count).toBe(0);
	});

	it('lädt das Bundle über einen einzigen Request herunter', async () => {
		getBundle.mockResolvedValue({
			gesturaBundle: 1,
			entries: [
				{ gesturaMenu: 1, id: 'a' },
				{ gesturaMenu: 1, id: 'b' }
			]
		});
		basket.toggle('a');
		basket.toggle('b');
		render(BasketTray, { catalog });
		await fireEvent.click(screen.getByRole('button', { name: /selection \(2\)|auswahl \(2\)/i }));
		await fireEvent.click(screen.getByRole('button', { name: /download json|als json/i }));
		await waitFor(() => expect(triggerJsonDownload).toHaveBeenCalled());
		expect(getBundle).toHaveBeenCalledTimes(1);
		expect(getBundle).toHaveBeenCalledWith(['a', 'b']);
		const [bundle, filename] = triggerJsonDownload.mock.calls[0];
		expect(bundle).toEqual({
			gesturaBundle: 1,
			entries: [
				{ gesturaMenu: 1, id: 'a' },
				{ gesturaMenu: 1, id: 'b' }
			]
		});
		expect(filename).toBe('gestura-bundle.json');
	});

	it('meldet fehlende IDs, die der Endpunkt aus dem Bundle ausgelassen hat', async () => {
		getBundle.mockResolvedValue({ gesturaBundle: 1, entries: [{ gesturaMenu: 1, id: 'a' }] });
		basket.toggle('a');
		basket.toggle('b');
		render(BasketTray, { catalog });
		await fireEvent.click(screen.getByRole('button', { name: /selection \(2\)|auswahl \(2\)/i }));
		await fireEvent.click(screen.getByRole('button', { name: /download json|als json/i }));
		await waitFor(() => expect(triggerJsonDownload).toHaveBeenCalled());
		const [bundle] = triggerJsonDownload.mock.calls[0];
		expect(bundle).toEqual({ gesturaBundle: 1, entries: [{ gesturaMenu: 1, id: 'a' }] });
		await waitFor(() => expect(screen.getByText(/could not be added|konnten nicht hinzugef/i)).toBeInTheDocument());
	});

	it('"An Gestura senden" ist deaktiviert', async () => {
		basket.toggle('a');
		render(BasketTray, { catalog });
		await fireEvent.click(screen.getByRole('button', { name: /selection \(1\)|auswahl \(1\)/i }));
		const sendBtn = screen.getByRole('button', { name: /send to gestura|an gestura senden/i });
		expect(sendBtn).toBeDisabled();
	});
});
