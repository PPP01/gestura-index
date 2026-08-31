import { resolveLocalized, type LocalizedString } from './localized';
import { actionLabel, type ExchangeMenuItem } from './menu-preview';
import { m } from '$lib/paraglide/messages.js';
import { baseLocale, isLocale } from '$lib/paraglide/runtime';

/**
 * Auswertung des Roh-Payloads für die Detailansicht »Inhalt«.
 *
 * Abgrenzung zu `menu-preview.ts`: Die Vorschau zeigt, was das Menü im Browser
 * ZEIGT (Items ohne Aktion fehlen dort). Hier geht es um die andere Frage – was
 * importiere ich? – und deren ehrliche Antwort ist der VOLLE Payload, inklusive
 * der Items, die im Menü nie erscheinen (sie werden als solche markiert).
 */

/** Eine Zeile der Inhaltsliste eines Menüs. */
export type ContentRow =
	| { kind: 'separator' }
	| {
			kind: 'item';
			label: string;
			/** Ziel als Klartext: URL, Engine-Verweis oder Aktionsname (null = nichts zu zeigen). */
			target: string | null;
			/** true, wenn das Item im echten Menü NICHT erscheint (keine bzw. `none`-Aktion). */
			hidden: boolean;
	  };

/** Ein Stück der Engine-URL-Vorlage; `query` markiert die Stelle des Suchbegriffs. */
export type UrlSegment = { kind: 'text'; value: string } | { kind: 'query' };

/** Importrelevante Eigenschaften einer Suchmaschine. */
export interface EngineDetails {
	segments: UrlSegment[];
	/** Übersetzte Verhaltens-Chips; leer, wenn die Engine nichts Besonderes tut. */
	flags: string[];
	/** Mitgelieferter JavaScript-Code (null = keiner). */
	transformCode: string | null;
}

function opts(locale: string) {
	return { locale: isLocale(locale) ? locale : baseLocale };
}

/** Beschreibung aus dem Payload, zur Anzeige-Locale aufgelöst. */
export function payloadDescription(payload: unknown, locale: string): string {
	const value = (payload as { description?: LocalizedString } | null)?.description;
	return resolveLocalized(value, locale);
}

/**
 * Baut die Inhaltsliste eines Menüs.
 *
 * Das Ziel folgt der Anzeige des Import-Dialogs der Extension
 * (`menu-import-dialog.js`: `customUrl || url || engineId`). Reine Aktionen
 * (»Zurück«, »Neu laden« …) haben kein Ziel; ihr Aktionsname erscheint nur
 * dann als Ziel, wenn das Item ein abweichendes eigenes Label trägt – sonst
 * stünde zweimal dasselbe untereinander.
 */
export function toContentRows(payload: unknown, locale: string): ContentRow[] {
	const items = (payload as { items?: unknown } | null)?.items;
	if (!Array.isArray(items)) return [];

	const rows: ContentRow[] = [];
	for (const raw of items) {
		if (raw === null || typeof raw !== 'object') continue;
		const it = raw as ExchangeMenuItem;

		if (it.type === 'separator') {
			rows.push({ kind: 'separator' });
			continue;
		}

		const hidden = !it.action || it.action === 'none';
		const own = resolveLocalized(it.label, locale);
		const action = it.action ? actionLabel(it.action, locale) : '';
		const label = own || action || it.id || '';

		let target: string | null = it.customUrl || it.url || null;
		if (!target && it.engineId) {
			target = m.contents_engine_ref({ id: it.engineId }, opts(locale));
		}
		if (!target && action && action !== label) target = action;

		rows.push({ kind: 'item', label, target, hidden });
	}
	return rows;
}

/**
 * Zerlegt die URL-Vorlage einer Engine in Text und die Stelle des Suchbegriffs.
 *
 * Zwei Formen, beide aus `js/search-url.js`: Enthält die URL `%s`, wird der
 * Begriff DORT eingesetzt; sonst ist die URL ein Präfix, an das er angehängt
 * wird. Ein `suffix` steht in beiden Fällen ganz am Ende.
 */
export function engineUrlSegments(url: string, suffix = ''): UrlSegment[] {
	const segments: UrlSegment[] = [];
	const push = (value: string) => {
		if (value) segments.push({ kind: 'text', value });
	};

	if (url.includes('%s')) {
		const parts = url.split('%s');
		parts.forEach((part, i) => {
			push(part);
			if (i < parts.length - 1) segments.push({ kind: 'query' });
		});
	} else {
		push(url);
		segments.push({ kind: 'query' });
	}
	push(suffix);
	return segments;
}

/** Wertet den Payload einer Suchmaschine aus; null, wenn er keine URL trägt. */
export function toEngineDetails(payload: unknown, locale: string): EngineDetails | null {
	const p = payload as Record<string, unknown> | null;
	if (!p || typeof p.url !== 'string' || p.url === '') return null;

	const suffix = typeof p.suffix === 'string' ? p.suffix : '';
	const o = opts(locale);
	const flags: string[] = [];
	if (p.type === 'image') flags.push(m.engine_flag_image({}, o));
	if (p.plus === true) flags.push(m.engine_flag_plus({}, o));
	if (p.slug === true) flags.push(m.engine_flag_slug({}, o));
	if (suffix) flags.push(m.engine_flag_suffix({ suffix }, o));
	if (p.clipboardMode === true) flags.push(m.engine_flag_clipboard({}, o));
	if (p.rawResult === true) flags.push(m.engine_flag_raw({}, o));
	if (p.transformRequired === true) flags.push(m.engine_flag_transform_required({}, o));

	return {
		segments: engineUrlSegments(p.url, suffix),
		flags,
		transformCode: typeof p.transformCode === 'string' && p.transformCode ? p.transformCode : null
	};
}
