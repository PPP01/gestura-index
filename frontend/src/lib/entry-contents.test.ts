import { describe, it, expect } from 'vitest';
import {
	toContentRows,
	toEngineDetails,
	engineUrlSegments,
	payloadDescription
} from './entry-contents';

const menu = (items: unknown[]) => ({
	gesturaMenu: 1,
	id: 'x',
	version: '1.0.0',
	name: 'X',
	items
});

describe('toContentRows', () => {
	it('zeigt Label und Ziel-URL eines Links', () => {
		const rows = toContentRows(
			menu([
				{
					id: 'a',
					action: 'openCustomUrl',
					label: { en: 'Home', de: 'Start' },
					customUrl: 'https://e.test/x'
				}
			]),
			'de'
		);
		expect(rows[0]).toEqual({
			kind: 'item',
			label: 'Start',
			target: 'https://e.test/x',
			hidden: false
		});
	});

	// Die Entscheidung des Nutzers: die Liste beantwortet »was importiere ich«,
	// nicht »was sehe ich« – Items ohne Aktion gehören also hinein, markiert.
	it('führt Items, die im Menü nicht erscheinen, als hidden auf', () => {
		const rows = toContentRows(
			menu([
				{ id: 'a', action: 'none', label: 'Platzhalter' },
				{ id: 'b', label: 'Ohne Aktion' },
				{ id: 'c', action: 'back' }
			]),
			'de'
		);
		expect(rows).toHaveLength(3);
		expect(rows[0]).toMatchObject({ label: 'Platzhalter', hidden: true });
		expect(rows[1]).toMatchObject({ label: 'Ohne Aktion', hidden: true });
		expect(rows[2]).toMatchObject({ label: 'Zurück', hidden: false });
	});

	it('verweist bei searchLink ohne URL auf die Engine-ID', () => {
		const rows = toContentRows(menu([{ id: 'a', action: 'searchLink', engineId: 'google' }]), 'de');
		expect(rows[0]).toMatchObject({ target: 'Suchmaschine: google' });
	});

	// Sonst stünde »Zurück« zweimal untereinander.
	it('lässt das Ziel weg, wenn es nur das Label wiederholte', () => {
		const rows = toContentRows(menu([{ id: 'a', action: 'back' }]), 'de');
		expect(rows[0]).toMatchObject({ label: 'Zurück', target: null });
	});

	it('nennt die Aktion als Ziel, wenn das Item ein eigenes Label trägt', () => {
		const rows = toContentRows(menu([{ id: 'a', action: 'refresh', label: 'Neu' }]), 'de');
		expect(rows[0]).toMatchObject({ label: 'Neu', target: 'Aktuellen Tab aktualisieren' });
	});

	it('übernimmt Separatoren und verträgt kaputte Payloads', () => {
		expect(toContentRows(menu([{ id: 's', type: 'separator' }]), 'en')).toEqual([
			{ kind: 'separator' }
		]);
		expect(toContentRows(null, 'en')).toEqual([]);
		expect(toContentRows({ items: 'kaputt' }, 'en')).toEqual([]);
	});
});

describe('engineUrlSegments', () => {
	it('hängt den Suchbegriff bei einer Präfix-URL hinten an', () => {
		expect(engineUrlSegments('https://e.test/?q=')).toEqual([
			{ kind: 'text', value: 'https://e.test/?q=' },
			{ kind: 'query' }
		]);
	});

	// Legacy-Form aus js/search-url.js: %s wird ersetzt, nicht angehängt.
	it('setzt den Suchbegriff an die Stelle von %s', () => {
		expect(engineUrlSegments('https://e.test/%s/page')).toEqual([
			{ kind: 'text', value: 'https://e.test/' },
			{ kind: 'query' },
			{ kind: 'text', value: '/page' }
		]);
	});

	it('stellt den Suffix ans Ende', () => {
		expect(engineUrlSegments('https://e.test/?q=', '&lang=de')).toEqual([
			{ kind: 'text', value: 'https://e.test/?q=' },
			{ kind: 'query' },
			{ kind: 'text', value: '&lang=de' }
		]);
	});
});

describe('toEngineDetails', () => {
	it('liefert null ohne URL', () => {
		expect(toEngineDetails({ gesturaEngine: 1 }, 'en')).toBeNull();
		expect(toEngineDetails(null, 'en')).toBeNull();
	});

	it('nennt nur die tatsächlich gesetzten Verhaltens-Flags', () => {
		const plain = toEngineDetails({ url: 'https://e.test/?q=' }, 'de');
		expect(plain?.flags).toEqual([]);
		const fancy = toEngineDetails(
			{ url: 'https://e.test/?q=', plus: true, type: 'image', clipboardMode: true },
			'de'
		);
		expect(fancy?.flags).toEqual(['Bildsuche', 'Leerzeichen als +', 'Nutzt die Zwischenablage']);
	});

	it('reicht mitgelieferten Code durch, damit er lesbar wird', () => {
		const d = toEngineDetails({ url: 'https://e.test/?q=', transformCode: 'return x;' }, 'en');
		expect(d?.transformCode).toBe('return x;');
		expect(toEngineDetails({ url: 'https://e.test/?q=', transformCode: '' }, 'en')?.transformCode)
			.toBeNull();
	});
});

describe('payloadDescription', () => {
	it('löst die Beschreibung zur Anzeige-Locale auf', () => {
		expect(payloadDescription({ description: { en: 'A', de: 'B' } }, 'de')).toBe('B');
		expect(payloadDescription({}, 'de')).toBe('');
	});
});
