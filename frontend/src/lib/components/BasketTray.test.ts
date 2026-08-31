import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import { tick } from 'svelte';
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

function item(id: string, type: EntryListItem['type'] = 'menu'): EntryListItem {
	return {
		formatId: id,
		type,
		name: id,
		description: null,
		categories: [],
		tags: [],
		domains: [],
		itemCount: 2,
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
	['b', item('b')],
	['e1', item('e1', 'engine')]
]);

beforeEach(() => {
	localStorage.clear();
	basket.clear();
	getBundle.mockReset();
	triggerJsonDownload.mockReset();
});

describe('BasketTray', () => {
	// Menüs und Suchmaschinen werden getrennt importiert – im Korb sollen sie
	// deshalb auch getrennt stehen, nicht in der Klick-Reihenfolge vermischt.
	it('gruppiert die Auswahl nach Menüs und Suchmaschinen', async () => {
		basket.toggle('e1');
		basket.toggle('a');
		render(BasketTray, { catalog });
		await fireEvent.click(screen.getByRole('button', { name: /selection \(2\)|auswahl \(2\)/i }));

		const heads = screen.getAllByRole('heading', { level: 4 }).map((h) => h.textContent?.trim());
		expect(heads?.[0]).toMatch(/menus|menüs/i);
		expect(heads?.[1]).toMatch(/search engines|suchmaschinen/i);
		// Menüs zuerst – unabhängig davon, dass die Engine zuerst gewählt wurde.
		const names = [...document.querySelectorAll('.tray-row-name')].map((n) => n.textContent);
		expect(names).toEqual(['a', 'e1']);
	});

	// Bisher schloss das Panel nur ein zweiter Klick auf den Pill – darauf muss
	// man erst kommen. Der Pill-Toggle bleibt, das X kommt hinzu.
	it('schließt das Panel über den Schließen-Knopf', async () => {
		basket.toggle('a');
		render(BasketTray, { catalog });
		await fireEvent.click(screen.getByRole('button', { name: /selection \(1\)|auswahl \(1\)/i }));
		expect(screen.getByText(/^your selection$|^deine auswahl$/i)).toBeInTheDocument();

		await fireEvent.click(
			screen.getByRole('button', { name: /close selection|auswahl schließen/i })
		);
		expect(screen.queryByText(/^your selection$|^deine auswahl$/i)).not.toBeInTheDocument();
		// Nur zu, nicht geleert.
		expect(basket.ids).toEqual(['a']);
	});

	it('bietet den Schließen-Knopf auch im Leerzustand', async () => {
		render(BasketTray, { catalog });
		await fireEvent.click(screen.getByRole('button', { name: /selection \(0\)|auswahl \(0\)/i }));
		await fireEvent.click(
			screen.getByRole('button', { name: /close selection|auswahl schließen/i })
		);
		expect(screen.queryByText(/nothing collected yet|noch nichts gesammelt/i)).not.toBeInTheDocument();
	});

	it('leert eine Gruppe, ohne die andere anzurühren', async () => {
		basket.toggle('a');
		basket.toggle('b');
		basket.toggle('e1');
		render(BasketTray, { catalog });
		await fireEvent.click(screen.getByRole('button', { name: /selection \(3\)|auswahl \(3\)/i }));

		await fireEvent.click(
			screen.getByRole('button', { name: /remove all menus|alle menüs entfernen/i })
		);
		expect(basket.ids).toEqual(['e1']);
		// Die Menü-Gruppe verschwindet mit ihrem letzten Eintrag.
		expect(screen.queryByText(/^menus$|^menüs$/i)).not.toBeInTheDocument();
		expect(screen.getByText('e1')).toBeInTheDocument();
	});

	it('behält Korb-Einträge, die der geladene Katalog nicht kennt', async () => {
		basket.toggle('unbekannt');
		render(BasketTray, { catalog });
		await fireEvent.click(screen.getByRole('button', { name: /selection \(1\)|auswahl \(1\)/i }));
		expect(screen.getByText(/not in the catalog|nicht im katalog/i)).toBeInTheDocument();
		// Ohne diese Gruppe ließe sich der Eintrag nicht mehr entfernen.
		expect(screen.getByText('unbekannt')).toBeInTheDocument();
	});

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
		// Gezielt der Zeilen-Knopf: seit der Gruppierung gibt es auch
		// »Remove all menus«, das würde beide Zeilen auf einmal treffen.
		await fireEvent.click(screen.getByRole('button', { name: /^remove: a$|^entfernen: a$/i }));
		expect(basket.ids).toEqual(['b']);
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

	it('sendet das Bundle per gestura:import-Event mit stringifiziertem Detail', async () => {
		const bundle = {
			gesturaBundle: 1,
			entries: [
				{ gesturaMenu: 1, id: 'a' },
				{ gesturaMenu: 1, id: 'b' }
			]
		};
		getBundle.mockResolvedValue(bundle);
		const importSpy = vi.fn();
		document.addEventListener('gestura:import', importSpy as EventListener);
		basket.toggle('a');
		basket.toggle('b');
		render(BasketTray, { catalog });
		await fireEvent.click(screen.getByRole('button', { name: /selection \(2\)|auswahl \(2\)/i }));
		const sendBtn = screen.getByRole('button', { name: /send to gestura|an gestura senden/i });
		expect(sendBtn).not.toBeDisabled();
		// Vertrag §2.2: Marker gehört auf den Button selbst.
		expect(sendBtn).toHaveAttribute('data-gestura-inline');
		await fireEvent.click(sendBtn);
		await waitFor(() => expect(importSpy).toHaveBeenCalledTimes(1));
		expect(getBundle).toHaveBeenCalledWith(['a', 'b']);
		const event = importSpy.mock.calls[0][0] as CustomEvent;
		// Vertrag §2.1: detail MUSS ein String sein, kein Objekt.
		expect(typeof event.detail).toBe('string');
		expect(JSON.parse(event.detail)).toEqual(bundle);
		document.removeEventListener('gestura:import', importSpy as EventListener);
	});

	it('zeigt eine eigene Fehlermeldung, wenn das Bundle beim Senden nicht geladen werden kann', async () => {
		getBundle.mockRejectedValue(new Error('network'));
		const importSpy = vi.fn();
		document.addEventListener('gestura:import', importSpy as EventListener);
		basket.toggle('a');
		render(BasketTray, { catalog });
		await fireEvent.click(screen.getByRole('button', { name: /selection \(1\)|auswahl \(1\)/i }));
		await fireEvent.click(screen.getByRole('button', { name: /send to gestura|an gestura senden/i }));
		await waitFor(() =>
			expect(screen.getByText(/not reach the index|nicht erreichbar/i)).toBeInTheDocument()
		);
		// Vertrag §2.4: schlägt der Fetch fehl, feuern wir kein Übergabe-Event.
		expect(importSpy).not.toHaveBeenCalled();
		document.removeEventListener('gestura:import', importSpy as EventListener);
	});

	// Rückweg: die "Extension" antwortet auf den Hinweg mit gestura:import-result.
	function replyOnceWith(result: unknown) {
		document.addEventListener(
			'gestura:import',
			(() =>
				document.dispatchEvent(
					new CustomEvent('gestura:import-result', { detail: JSON.stringify(result) })
				)) as EventListener,
			{ once: true }
		);
	}

	async function clickSendFor(id: string) {
		getBundle.mockResolvedValue({ gesturaBundle: 1, entries: [{ gesturaMenu: 1, id }] });
		basket.toggle(id);
		render(BasketTray, { catalog });
		await fireEvent.click(screen.getByRole('button', { name: /selection \(1\)|auswahl \(1\)/i }));
		await fireEvent.click(screen.getByRole('button', { name: /send to gestura|an gestura senden/i }));
	}

	/*
	 * Der Fall, der in der Praxis scheiterte (Nachtrag 3 des Übergabe-Vertrags):
	 * Der Nutzer steht im Import-Dialog der Erweiterung, das dauert länger als
	 * der 15-Sekunden-Hinweis. Früher meldete genau dieser Timeout den Listener
	 * ab – die Meldung kam an und traf ins Leere. Die anderen Rückweg-Tests
	 * antworten sofort und hätten das nie bemerkt.
	 */
	it('nimmt die Rückmeldung auch lange nach dem 15-Sekunden-Hinweis noch an', async () => {
		vi.useFakeTimers();
		try {
			await clickSendFor('a'); // ohne replyOnceWith: die Antwort kommt später
			await vi.advanceTimersByTimeAsync(16_000);
			await tick();
			expect(screen.getByText(/sent to gestura|an gestura gesendet/i)).toBeInTheDocument();

			// Erst jetzt bestätigt der Nutzer im Dialog der Erweiterung.
			document.dispatchEvent(
				new CustomEvent('gestura:import-result', {
					detail: JSON.stringify({ status: 'imported', menus: 1, engines: 0 })
				})
			);
			await tick();
			// Vollständig übernommen ⇒ Panel zu, Bestätigung am Pill.
			expect(
				screen.getByRole('button', { name: /imported|übernommen/i })
			).toBeInTheDocument();
			expect(basket.ids).toEqual([]);
		} finally {
			vi.useRealTimers();
		}
	});

	// Nach vollständiger Übernahme ist Aufräumen die Rückmeldung: Panel zu, die
	// gesendeten Einträge raus, Bestätigung am Pill (der Nutzer steht beim Import
	// im Tab der Erweiterung und sieht unsere Seite erst danach wieder).
	it('räumt nach vollständiger Übernahme auf und bestätigt am Pill', async () => {
		replyOnceWith({ status: 'imported', menus: 1, engines: 0 });
		await clickSendFor('a');
		await waitFor(() => expect(basket.ids).toEqual([]));
		expect(screen.queryByText(/^your selection$|^deine auswahl$/i)).not.toBeInTheDocument();
		expect(screen.getByRole('button', { name: /imported|übernommen/i })).toBeInTheDocument();
	});

	// Teilübernahme: die Rückmeldung sagt nur WIE VIELE der Nutzer behalten hat,
	// nicht WELCHE – also darf der Korb nicht angetastet werden.
	it('lässt den Korb stehen, wenn nur ein Teil übernommen wurde', async () => {
		getBundle.mockResolvedValue({ gesturaBundle: 1, entries: [{ gesturaMenu: 1, id: 'a' }] });
		basket.toggle('a');
		basket.toggle('b');
		replyOnceWith({ status: 'imported', menus: 1, engines: 0 });
		render(BasketTray, { catalog });
		await fireEvent.click(screen.getByRole('button', { name: /selection \(2\)|auswahl \(2\)/i }));
		await fireEvent.click(screen.getByRole('button', { name: /send to gestura|an gestura senden/i }));

		await waitFor(() =>
			expect(screen.getByText(/partly applied|teilweise übernommen/i)).toBeInTheDocument()
		);
		expect(basket.ids).toEqual(['a', 'b']);
	});

	it('lässt bei "cancelled" den Korb stehen und meldet den Abbruch neutral', async () => {
		replyOnceWith({ status: 'cancelled', menus: 0, engines: 0 });
		await clickSendFor('a');
		await waitFor(() =>
			expect(screen.getByText(/cancelled|abgebrochen/i)).toBeInTheDocument()
		);
		expect(basket.count).toBe(1);
	});

	it('weist bei "failed" auf den Speicherplatz hin', async () => {
		replyOnceWith({ status: 'failed' });
		await clickSendFor('a');
		await waitFor(() =>
			expect(screen.getByText(/storage space|speicherplatz/i)).toBeInTheDocument()
		);
	});

	it('fällt nach 15 s ohne Rückmeldung auf den neutralen Hinweis zurück', async () => {
		vi.useFakeTimers();
		try {
			await clickSendFor('a');
			await vi.advanceTimersByTimeAsync(15000);
			expect(screen.getByText(/gestura settings|gestura-einstellungen/i)).toBeInTheDocument();
		} finally {
			vi.useRealTimers();
		}
	});

	it('weist bei großer Auswahl auf den Datei-Download als kappenfreien Weg hin', async () => {
		const filler = 'x'.repeat(4000);
		getBundle.mockResolvedValue({
			gesturaBundle: 1,
			entries: [
				{ gesturaMenu: 1, id: 'a', big: filler },
				{ gesturaMenu: 1, id: 'b', big: filler }
			]
		});
		basket.toggle('a');
		basket.toggle('b');
		render(BasketTray, { catalog });
		await fireEvent.click(screen.getByRole('button', { name: /selection \(2\)|auswahl \(2\)/i }));
		await fireEvent.click(screen.getByRole('button', { name: /send to gestura|an gestura senden/i }));
		await waitFor(() =>
			expect(screen.getByText(/cap-free way|kappenfreie/i)).toBeInTheDocument()
		);
	});
});
