import type { EntryType } from '$lib/api';

/**
 * Eine kuratierte Showcase-Karte für C5 »Beispiele« (Screenshot 1n). Die
 * vier Karten sind bewusst hartcodiert (keine API-Anbindung) – es handelt
 * sich um redaktionell ausgewählte Beispiele, nicht um echte Index-Einträge.
 */
export interface ShowcaseCard {
	/** Mono-Chip oben links, z. B. 'Super-Drag ↓' – sprachneutrales Symbol. */
	gesture: string;
	/** Kategorie-Key für die Icon-Kachel-Farbe (categoryColor aus $lib/categories). */
	category: string;
	/** Typ-Badge (Menü/Suchmaschine) via entryTypeLabel aus $lib/categories. */
	type: EntryType;
	/** Message-Key für den Namen der Karte. */
	nameKey: string;
	/** Message-Key für die Beschreibung der Karte. */
	descKey: string;
}

export const SHOWCASE: ShowcaseCard[] = [
	{
		gesture: 'Super-Drag ↓',
		category: 'shopping',
		type: 'menu',
		nameKey: 'c5_price_name',
		descKey: 'c5_price_desc'
	},
	{
		gesture: 'Auswahl + →',
		category: 'reference',
		type: 'engine',
		nameKey: 'c5_wiki_name',
		descKey: 'c5_wiki_desc'
	},
	{
		gesture: 'Rechtsklick-Menü',
		category: 'dev',
		type: 'menu',
		nameKey: 'c5_github_name',
		descKey: 'c5_github_desc'
	},
	{
		gesture: '↓ → Menü',
		category: 'news',
		type: 'menu',
		nameKey: 'c5_news_name',
		descKey: 'c5_news_desc'
	}
];
