/** Stufen für Intl.RelativeTimeFormat, absteigend von Sekunden bis Jahren. */
const DIVISIONS: { amount: number; unit: Intl.RelativeTimeFormatUnit }[] = [
	{ amount: 60, unit: 'second' },
	{ amount: 60, unit: 'minute' },
	{ amount: 24, unit: 'hour' },
	{ amount: 7, unit: 'day' },
	{ amount: 4.34524, unit: 'week' },
	{ amount: 12, unit: 'month' },
	{ amount: Number.POSITIVE_INFINITY, unit: 'year' }
];

/**
 * Formatiert eine ISO-Zeit relativ zu `now` (Default: aktueller Zeitpunkt)
 * über das eingebaute Intl.RelativeTimeFormat – ohne zusätzliche Abhängigkeit.
 * `now` ist als Parameter injizierbar, damit Aufrufer (und Tests) deterministisch
 * bleiben.
 */
export function relativeTime(iso: string, locale: string, now: Date = new Date()): string {
	let duration = (new Date(iso).getTime() - now.getTime()) / 1000;
	const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
	for (const division of DIVISIONS) {
		if (Math.abs(duration) < division.amount) {
			return rtf.format(Math.round(duration), division.unit);
		}
		duration /= division.amount;
	}
	return rtf.format(Math.round(duration), 'year');
}
