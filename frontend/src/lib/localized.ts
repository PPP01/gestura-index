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

/**
 * Ermittelt die im Namensfeld vorhandenen Sprachen (Format-Konvention:
 * einfacher String = en-Fallback).
 */
export function entryLanguages(value: LocalizedString | null | undefined): string[] {
	if (value == null) return [];
	if (typeof value === 'string') return ['en'];
	return Object.keys(value);
}
