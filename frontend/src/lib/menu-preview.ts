import { resolveLocalized, type LocalizedString } from './localized';
import { menuIcon } from './menu-icons';
import { m } from '$lib/paraglide/messages.js';
import { baseLocale, isLocale } from '$lib/paraglide/runtime';

/**
 * Ein Item im Austauschformat (Schema-$defs.menu.items). Bewusst tolerant
 * typisiert: die Vorschau bekommt den Roh-Payload des Versions-Endpunkts und
 * darf an keinem unerwarteten Feld scheitern.
 */
export interface ExchangeMenuItem {
	id?: string;
	type?: string;
	action?: string;
	label?: LocalizedString;
	icon?: string;
	customUrl?: string;
	url?: string;
	engineId?: string;
}

/** Ein gerendertes Vorschau-Item – Separator oder Zeile. */
export type PreviewItem =
	| { kind: 'separator' }
	| {
			kind: 'item';
			label: string;
			/** Inline-SVG aus dem Icon-Set der Extension (null = leeres Icon-Feld). */
			iconSvg: string | null;
			/** Data-URI-Monogramm für `icon: "favicon"` (null = keins). */
			monogram: string | null;
			/** Ziel für den Titel-Tooltip: URL bzw. Engine-ID. */
			target: string | null;
	  };

/**
 * Anzeigenamen der erlaubten Aktionen – wortgleich zu den Extension-Locales
 * (`_locales/<lang>/messages.json`, Schlüssel `actionBack` usw.). Wird nur
 * gebraucht, wenn ein Item kein eigenes Label mitbringt; die Extension macht
 * es genauso (`msg(ACTION_KEYS[it.action])`).
 *
 * Die Locale wird explizit durchgereicht (statt implizit über `getLocale()`),
 * damit die Funktion allein von ihren Argumenten abhängt – das Label aus dem
 * Payload und der Aktions-Fallback dürfen nicht auseinanderlaufen.
 */
export function actionLabel(action: string, locale: string): string {
	const opts = { locale: isLocale(locale) ? locale : baseLocale };
	switch (action) {
		case 'back':
			return m.menu_action_back({}, opts);
		case 'forward':
			return m.menu_action_forward({}, opts);
		case 'refresh':
			return m.menu_action_refresh({}, opts);
		case 'newTab':
			return m.menu_action_new_tab({}, opts);
		case 'scrollUp':
			return m.menu_action_scroll_up({}, opts);
		case 'scrollDown':
			return m.menu_action_scroll_down({}, opts);
		case 'scrollToTop':
			return m.menu_action_scroll_to_top({}, opts);
		case 'scrollToBottom':
			return m.menu_action_scroll_to_bottom({}, opts);
		case 'openCustomUrl':
			return m.menu_action_open_url({}, opts);
		case 'searchLink':
			return m.menu_action_search_link({}, opts);
		default:
			return action;
	}
}

/**
 * Erzeugt das Monogramm-Icon, das die Extension als Sofort-Platzhalter für
 * `icon: "favicon"` zeigt (js/favicon-util.js → monogramDataUri). Auf der
 * Website bleibt es beim Monogramm: echte Favicons kämen von fremden Servern
 * und würden die Besucher-IP dorthin tragen – das verbietet die
 * Datensparsamkeits-Regel des Projekts (keine Fremd-URLs).
 */
export function monogramDataUri(name: string): string {
	const match = String(name ?? '').match(/[a-z0-9]/i);
	const letter = match ? match[0].toUpperCase() : '?';

	let hash = 0;
	const source = String(name ?? '');
	for (let i = 0; i < source.length; i++) hash = (hash * 31 + source.charCodeAt(i)) >>> 0;
	const bg = hslToHex(hash % 360, 62, 46);

	const svg =
		`<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32">` +
		`<rect width="32" height="32" rx="7" fill="${bg}"/>` +
		`<text x="16" y="17" font-family="system-ui,Segoe UI,Arial,sans-serif" font-size="18" ` +
		`font-weight="600" fill="#ffffff" text-anchor="middle" dominant-baseline="central">${letter}</text>` +
		`</svg>`;
	return 'data:image/svg+xml,' + encodeURIComponent(svg);
}

function hslToHex(h: number, s: number, l: number): string {
	s /= 100;
	l /= 100;
	const k = (n: number) => (n + h / 30) % 12;
	const a = s * Math.min(l, 1 - l);
	const f = (n: number) => {
		const c = l - a * Math.max(-1, Math.min(k(n) - 3, Math.min(9 - k(n), 1)));
		return Math.round(255 * c)
			.toString(16)
			.padStart(2, '0');
	};
	return `#${f(0)}${f(8)}${f(4)}`;
}

/**
 * Bildet den Roh-Payload einer Menü-Version auf die Vorschau-Items ab –
 * exakt nach dem Vorbild von `buildItems()` in `js/content.js`:
 * Items ohne Aktion (bzw. mit `none`) erscheinen im echten Menü NICHT, das
 * Label fällt auf den Aktionsnamen zurück, und ein unbekannter Icon-Name
 * lässt das Icon-Feld leer, statt einen Ersatz zu erfinden.
 *
 * Nimmt `unknown` entgegen: der Payload kommt roh aus der API.
 */
export function toPreviewItems(payload: unknown, locale: string): PreviewItem[] {
	const items = (payload as { items?: unknown } | null)?.items;
	if (!Array.isArray(items)) return [];

	const out: PreviewItem[] = [];
	for (const raw of items) {
		if (raw === null || typeof raw !== 'object') continue;
		const it = raw as ExchangeMenuItem;

		if (it.type === 'separator') {
			out.push({ kind: 'separator' });
			continue;
		}
		if (!it.action || it.action === 'none') continue;

		const target = it.customUrl || it.url || it.engineId || null;
		const label = resolveLocalized(it.label, locale) || actionLabel(it.action, locale);
		const isFavicon = it.icon === 'favicon';

		out.push({
			kind: 'item',
			label,
			iconSvg: isFavicon ? null : menuIcon(it.icon),
			monogram: isFavicon && target ? monogramDataUri(label) : null,
			target
		});
	}
	return out;
}
