import { describe, it, expect } from 'vitest';
import {
	filterEntries,
	sortEntries,
	languageFacet,
	tagFacet,
	categoryFacet,
	optionCount
} from './facets';
import type { EntryListItem } from './api';
import type { OnepagerFilter } from './browse-state';

function entry(over: Partial<EntryListItem>): EntryListItem {
	return {
		formatId: 'com.example.a',
		type: 'menu',
		name: 'A',
		description: null,
		categories: [],
		tags: [],
		domains: [],
		installCount: 0,
		rating: { average: null, count: 0 },
		currentVersion: '1.0.0',
		deprecated: false,
		successorFormatId: null,
		screenshotUrl: null,
		updatedAt: '2026-01-01T00:00:00Z',
		...over
	};
}

const base: OnepagerFilter = { categories: [], tags: [], langs: [], sort: 'newest' };

describe('filterEntries', () => {
	const items = [
		entry({ formatId: 'a', name: { en: 'Alpha', de: 'Alfa' }, categories: ['dev'], tags: ['git'] }),
		entry({ formatId: 'b', name: 'Beta', categories: ['news'], tags: ['git', 'rss'] }),
		entry({ formatId: 'c', name: { de: 'Gamma' }, categories: ['dev'], tags: ['rss'] })
	];

	it('ODER innerhalb einer Facette (mehrere Kategorien)', () => {
		const r = filterEntries(items, { ...base, categories: ['dev', 'news'] }, 'en');
		expect(r.map((e) => e.formatId).sort()).toEqual(['a', 'b', 'c']);
	});
	it('UND über Facetten hinweg (Kategorie + Tag)', () => {
		const r = filterEntries(items, { ...base, categories: ['dev'], tags: ['rss'] }, 'en');
		expect(r.map((e) => e.formatId)).toEqual(['c']);
	});
	it('Sprachfacette: String-Name ist universell (*) und matcht jeden Sprachfilter', () => {
		// a={en,de}, b="Beta" (universell), c={de}. b matcht überall.
		expect(filterEntries(items, { ...base, langs: ['en'] }, 'en').map((e) => e.formatId).sort()).toEqual(['a', 'b']);
		expect(filterEntries(items, { ...base, langs: ['de'] }, 'en').map((e) => e.formatId).sort()).toEqual(['a', 'b', 'c']);
		expect(filterEntries(items, { ...base, langs: ['*'] }, 'en').map((e) => e.formatId).sort()).toEqual(['b']);
	});
	it('Freitext über aufgelösten Namen', () => {
		expect(filterEntries(items, { ...base, q: 'alfa' }, 'de').map((e) => e.formatId)).toEqual(['a']);
	});
	it('Freitext über formatId', () => {
		expect(filterEntries(items, { ...base, q: 'b' }, 'en').map((e) => e.formatId).sort()).toEqual(['b']);
	});
	it('type filtert', () => {
		const mixed = [entry({ formatId: 'm', type: 'menu' }), entry({ formatId: 'e', type: 'engine' })];
		expect(filterEntries(mixed, { ...base, type: 'engine' }, 'en').map((e) => e.formatId)).toEqual(['e']);
	});
	it('site filtert über Domains (Teilstring)', () => {
		const d = [entry({ formatId: 'd', domains: ['shop.example.com'] })];
		expect(filterEntries(d, { ...base, site: 'example' }, 'en')).toHaveLength(1);
		expect(filterEntries(d, { ...base, site: 'other' }, 'en')).toHaveLength(0);
	});
});

describe('sortEntries', () => {
	const items = [
		entry({ formatId: 'old', updatedAt: '2026-01-01T00:00:00Z', installCount: 5, rating: { average: 4.5, count: 2 } }),
		entry({ formatId: 'new', updatedAt: '2026-06-01T00:00:00Z', installCount: 1, rating: { average: null, count: 0 } }),
		entry({ formatId: 'mid', updatedAt: '2026-03-01T00:00:00Z', installCount: 9, rating: { average: 3.0, count: 8 } })
	];
	it('newest nach updatedAt absteigend', () => {
		expect(sortEntries(items, 'newest').map((e) => e.formatId)).toEqual(['new', 'mid', 'old']);
	});
	it('installs nach installCount absteigend', () => {
		expect(sortEntries(items, 'installs').map((e) => e.formatId)).toEqual(['mid', 'old', 'new']);
	});
	it('best nach rating.average, Einträge ohne Bewertung nach hinten', () => {
		expect(sortEntries(items, 'best').map((e) => e.formatId)).toEqual(['old', 'mid', 'new']);
	});
	it('mutiert das Eingabe-Array nicht', () => {
		const copy = [...items];
		sortEntries(items, 'installs');
		expect(items.map((e) => e.formatId)).toEqual(copy.map((e) => e.formatId));
	});
});

describe('Facetten-Ableitung', () => {
	const items = [
		entry({ name: { en: 'A', de: 'A' }, tags: ['git', 'rss'], categories: ['dev'] }),
		entry({ name: 'B', tags: ['git'], categories: ['dev', 'news'] }),
		entry({ name: { de: 'C' }, tags: [], categories: ['news'] })
	];
	it('languageFacet zählt Sprachen', () => {
		// {en,de} → en+de; 'B' (String) → universell (*); {de} → de.
		expect(languageFacet(items)).toEqual(
			expect.arrayContaining([
				{ value: 'de', count: 2 },
				{ value: 'en', count: 1 },
				{ value: '*', count: 1 }
			])
		);
	});
	it('tagFacet zählt Tags absteigend', () => {
		expect(tagFacet(items)).toEqual([
			{ value: 'git', count: 2 },
			{ value: 'rss', count: 1 }
		]);
	});
	it('categoryFacet zählt nur präsente Kategorien', () => {
		const f = categoryFacet(items);
		expect(f).toEqual(
			expect.arrayContaining([
				{ value: 'dev', count: 2 },
				{ value: 'news', count: 2 }
			])
		);
		expect(f.find((o) => o.value === 'shopping')).toBeUndefined();
	});
});

describe('optionCount', () => {
	const items = [
		entry({ formatId: 'a', categories: ['dev'], tags: ['git'] }),
		entry({ formatId: 'b', categories: ['dev'], tags: ['rss'] }),
		entry({ formatId: 'c', categories: ['news'], tags: ['git'] })
	];
	it('zählt eine Option unter Anwendung der übrigen Facetten, eigene ausgenommen', () => {
		// tag=git aktiv; Anzahl der category=dev-Option ignoriert die category-Facette selbst,
		// wendet aber tag=git an: a (dev,git) trifft, c (news,git) nicht dev -> 1.
		const filter: OnepagerFilter = { ...base, tags: ['git'], categories: ['news'] };
		expect(optionCount(items, filter, 'categories', 'dev', 'en')).toBe(1);
	});
});
