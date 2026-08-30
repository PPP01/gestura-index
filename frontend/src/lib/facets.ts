import type { EntryListItem } from './api';
import type { OnepagerFilter, OnepagerSort } from './browse-state';
import { resolveLocalized, entryLanguages, MULTILANGUAGE } from './localized';
import { CATEGORIES } from './categories';

export interface FacetOption {
	value: string;
	count: number;
}

type Dimension = 'categories' | 'tags' | 'langs';

function hasIntersection(a: string[], b: string[]): boolean {
	return a.some((x) => b.includes(x));
}

/** Prüft, ob ein Eintrag den Filter erfüllt (ODER innerhalb, UND über Facetten). */
export function entryMatchesFilter(
	item: EntryListItem,
	filter: OnepagerFilter,
	locale: string
): boolean {
	if (filter.type && item.type !== filter.type) return false;
	if (filter.categories.length && !hasIntersection(item.categories, filter.categories)) return false;
	if (filter.tags.length && !hasIntersection(item.tags, filter.tags)) return false;
	// Universelle (*) Einträge sind in jeder Sprache nutzbar – sie matchen jeden
	// Sprachfilter, statt nur unter »*« auffindbar zu sein (sonst blendete die
	// per-Locale vorbelegte Facette z. B. YouTube für deutsche Nutzer aus).
	const itemLangs = entryLanguages(item.name);
	if (
		filter.langs.length &&
		!itemLangs.includes(MULTILANGUAGE) &&
		!hasIntersection(itemLangs, filter.langs)
	) {
		return false;
	}
	if (filter.site) {
		const needle = filter.site.toLowerCase();
		if (!item.domains.some((d) => d.toLowerCase().includes(needle))) return false;
	}
	if (filter.q) {
		const needle = filter.q.toLowerCase();
		const haystack = [
			resolveLocalized(item.name, locale),
			resolveLocalized(item.description, locale),
			item.formatId
		]
			.join(' ')
			.toLowerCase();
		if (!haystack.includes(needle)) return false;
	}
	return true;
}

export function filterEntries(
	items: EntryListItem[],
	filter: OnepagerFilter,
	locale: string
): EntryListItem[] {
	return items.filter((item) => entryMatchesFilter(item, filter, locale));
}

/** Sortiert (kopiert, mutiert die Eingabe nicht). */
export function sortEntries(items: EntryListItem[], sort: OnepagerSort): EntryListItem[] {
	const copy = [...items];
	if (sort === 'installs') {
		copy.sort((a, b) => b.installCount - a.installCount);
	} else if (sort === 'best') {
		copy.sort((a, b) => {
			const av = a.rating.average;
			const bv = b.rating.average;
			if (av === null && bv === null) return 0;
			if (av === null) return 1; // ohne Bewertung nach hinten
			if (bv === null) return -1;
			if (bv !== av) return bv - av;
			return b.rating.count - a.rating.count;
		});
	} else {
		copy.sort((a, b) => b.updatedAt.localeCompare(a.updatedAt));
	}
	return copy;
}

function countBy(items: EntryListItem[], values: (i: EntryListItem) => string[]): Map<string, number> {
	const m = new Map<string, number>();
	for (const item of items) {
		for (const v of values(item)) m.set(v, (m.get(v) ?? 0) + 1);
	}
	return m;
}

export function languageFacet(items: EntryListItem[]): FacetOption[] {
	const m = countBy(items, (i) => entryLanguages(i.name));
	return [...m.entries()].map(([value, count]) => ({ value, count })).sort((a, b) => b.count - a.count);
}

export function tagFacet(items: EntryListItem[]): FacetOption[] {
	const m = countBy(items, (i) => i.tags);
	return [...m.entries()].map(([value, count]) => ({ value, count })).sort((a, b) => b.count - a.count);
}

/** Kategorie-Facette in fester CATEGORIES-Reihenfolge, nur präsente Werte. */
export function categoryFacet(items: EntryListItem[]): FacetOption[] {
	const m = countBy(items, (i) => i.categories);
	return CATEGORIES.filter((c) => m.has(c)).map((c) => ({ value: c, count: m.get(c)! }));
}

function dimensionValues(item: EntryListItem, dimension: Dimension): string[] {
	if (dimension === 'categories') return item.categories;
	if (dimension === 'tags') return item.tags;
	return entryLanguages(item.name);
}

/**
 * Anzahl der Einträge, die die Option `value` in `dimension` träfe – unter
 * Anwendung der übrigen Facetten, die eigene ausgenommen (Standard-Faceted-Search).
 */
export function optionCount(
	items: EntryListItem[],
	filter: OnepagerFilter,
	dimension: Dimension,
	value: string,
	locale: string
): number {
	const without: OnepagerFilter = { ...filter, [dimension]: [] } as OnepagerFilter;
	return items.filter(
		(item) => entryMatchesFilter(item, without, locale) && dimensionValues(item, dimension).includes(value)
	).length;
}
