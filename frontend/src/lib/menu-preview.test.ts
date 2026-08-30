import { describe, it, expect } from 'vitest';
import { toPreviewItems, monogramDataUri } from './menu-preview';
import { MENU_ICONS, menuIcon } from './menu-icons';

const menu = (items: unknown[]) => ({ gesturaMenu: 1, id: 'x', version: '1.0.0', name: 'X', items });

describe('toPreviewItems', () => {
	it('löst Sprach-Maps zur Anzeige-Locale auf', () => {
		const out = toPreviewItems(
			menu([
				{
					id: 'a',
					action: 'openCustomUrl',
					label: { en: 'Home', de: 'Start' },
					customUrl: 'https://e.test/'
				}
			]),
			'de'
		);
		expect(out).toEqual([
			{ kind: 'item', label: 'Start', iconSvg: null, monogram: null, target: 'https://e.test/' }
		]);
	});

	it('fällt für ein Item ohne Label auf den Aktionsnamen zurück', () => {
		const out = toPreviewItems(menu([{ id: 'a', action: 'back' }]), 'de');
		expect(out[0]).toMatchObject({ kind: 'item', label: 'Zurück' });
	});

	// Spiegelt buildItems() in js/content.js: Items ohne Aktion bzw. mit 'none'
	// erscheinen im echten Menü nicht – die Vorschau darf sie also auch nicht zeigen.
	it('lässt Items ohne Aktion und mit action "none" weg', () => {
		const out = toPreviewItems(
			menu([
				{ id: 'a', action: 'none', label: 'Nix' },
				{ id: 'b', label: 'Ohne' },
				{ id: 'c', action: 'back' }
			]),
			'de'
		);
		expect(out).toHaveLength(1);
		expect(out[0]).toMatchObject({ label: 'Zurück' });
	});

	it('übernimmt Separatoren', () => {
		const out = toPreviewItems(
			menu([{ id: 's', type: 'separator' }, { id: 'a', action: 'back' }]),
			'en'
		);
		expect(out[0]).toEqual({ kind: 'separator' });
		expect(out).toHaveLength(2);
	});

	it('liefert zu einem bekannten Icon-Namen das Inline-SVG der Extension', () => {
		const out = toPreviewItems(menu([{ id: 'a', action: 'back', icon: 'house' }]), 'en');
		expect(out[0]).toMatchObject({ iconSvg: MENU_ICONS.house });
	});

	// Wie die Extension: ein Name außerhalb des kuratierten Sets rendert im echten
	// Menü ein LEERES Icon-Feld. Die Vorschau erfindet deshalb keinen Ersatz.
	it('lässt ein unbekanntes Icon leer, statt es zu ersetzen', () => {
		const out = toPreviewItems(menu([{ id: 'a', action: 'back', icon: 'gibtsNicht' }]), 'en');
		expect(out[0]).toMatchObject({ iconSvg: null, monogram: null });
	});

	// Sicherheits-Invariante: iconSvg landet in der Komponente in einem {@html}.
	// Der Payload darf dort ausschliesslich einen SCHLÜSSEL wählen, niemals Markup
	// liefern – sonst wäre die Vorschau ein XSS-Vektor für jede Einreichung.
	it('lässt niemals Payload-Markup nach iconSvg durch', () => {
		const attacks = [
			'<img src=x onerror=alert(1)>',
			'<svg onload=alert(1)></svg>',
			'house"><script>alert(1)</script>',
			'__proto__',
			'constructor'
		];
		for (const icon of attacks) {
			const out = toPreviewItems(menu([{ id: 'a', action: 'back', icon }]), 'en');
			expect(out[0]).toMatchObject({ iconSvg: null, monogram: null });
		}
	});

	it('erzeugt für icon:"favicon" ein Monogramm statt einer Fremd-URL', () => {
		const out = toPreviewItems(
			menu([
				{
					id: 'a',
					action: 'openCustomUrl',
					label: 'Wiki',
					icon: 'favicon',
					customUrl: 'https://w.test/'
				}
			]),
			'en'
		);
		const item = out[0];
		expect(item.kind).toBe('item');
		if (item.kind !== 'item') return;
		expect(item.monogram).toBe(monogramDataUri('Wiki'));
		expect(item.monogram).toMatch(/^data:image\/svg\+xml,/);
		expect(item.iconSvg).toBeNull();
	});

	it('nimmt engineId als Ziel, wenn ein searchLink keine URL hat', () => {
		const out = toPreviewItems(menu([{ id: 'a', action: 'searchLink', engineId: 'google' }]), 'en');
		expect(out[0]).toMatchObject({ label: 'Open search link', target: 'google' });
	});

	it('verträgt kaputte oder fremde Payloads ohne zu werfen', () => {
		expect(toPreviewItems(null, 'en')).toEqual([]);
		expect(toPreviewItems({}, 'en')).toEqual([]);
		expect(toPreviewItems({ items: 'keine Liste' }, 'en')).toEqual([]);
		expect(toPreviewItems(menu([null, 42, 'x']), 'en')).toEqual([]);
	});
});

describe('monogramDataUri', () => {
	it('nimmt den ersten alphanumerischen Buchstaben in Großschreibung', () => {
		expect(decodeURIComponent(monogramDataUri('»wiki«'))).toContain('>W<');
		expect(decodeURIComponent(monogramDataUri('…'))).toContain('>?<');
	});

	it('ist stabil für denselben Namen', () => {
		expect(monogramDataUri('GitHub')).toBe(monogramDataUri('GitHub'));
		expect(monogramDataUri('GitHub')).not.toBe(monogramDataUri('GitLab'));
	});
});

describe('menuIcon', () => {
	it('gibt bei unbekanntem oder fehlendem Namen null zurück', () => {
		expect(menuIcon(undefined)).toBeNull();
		expect(menuIcon('gibtsNicht')).toBeNull();
		// Prototyp-Ketten-Treffer dürfen kein SVG vortäuschen.
		expect(menuIcon('toString')).toBeNull();
	});
});
