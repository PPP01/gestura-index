/** Ein Textfeld, das entweder ein einfacher String oder eine Sprach-Map ist. */
export type LocalizedString = string | Record<string, string>;

/**
 * Löst ein LocalizedString zur Anzeige-Locale auf.
 * String → unverändert; Map → locale, sonst en-Fallback, sonst erster Wert;
 * null/undefined → leerer String.
 */
export function resolveLocalized(
	value: LocalizedString | null | undefined,
	locale: string
): string {
	if (value == null) return '';
	if (typeof value === 'string') return value;
	if (value[locale] != null) return value[locale];
	if (value.en != null) return value.en;
	const first = Object.values(value)[0];
	return first ?? '';
}

/** Marker für sprach-neutrale (universelle) Einträge in der Sprach-Facette. */
export const MULTILANGUAGE = '*';

/**
 * Ermittelt die Sprachen eines Eintrags aus seinem Namensfeld.
 *
 * Eine Sprach-Map führt genau ihre Schlüssel (z. B. nur `de` für eine deutsche
 * Seite, nur `fr` für die französische Wikipedia). Ein einfacher String hat
 * bewusst KEINE Sprachbindung – eine Marke wie »YouTube« oder »GitHub« ist
 * universell – und zählt daher als `*` (multilanguage), nicht als »en«.
 */
export function entryLanguages(value: LocalizedString | null | undefined): string[] {
	if (value == null) return [];
	if (typeof value === 'string') return [MULTILANGUAGE];
	return Object.keys(value);
}
