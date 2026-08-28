import { describe, it, expect, vi } from 'vitest';
import { parseQuery, toSearchParams, Sequence, debounce, parseOnepagerFilter, onepagerSearchParams } from './browse-state';

describe('parseQuery', () => {
	it('liest bekannte Filter und page als Zahl', () => {
		const q = parseQuery(new URLSearchParams('q=wiki&type=menu&category=dev&page=3&unknown=x'));
		expect(q).toEqual({ q: 'wiki', type: 'menu', category: 'dev', page: 3 });
	});
	it('ignoriert ungültigen type und leere Werte', () => {
		const q = parseQuery(new URLSearchParams('type=bogus&q='));
		expect(q.type).toBeUndefined();
		expect(q.q).toBeUndefined();
	});
});

describe('toSearchParams', () => {
	it('lässt page=1 und leere Werte weg', () => {
		expect(toSearchParams({ q: 'a', page: 1, tag: '' }).toString()).toBe('q=a');
	});
	it('behält page>1', () => {
		expect(toSearchParams({ page: 2 }).get('page')).toBe('2');
	});
});

describe('Sequence', () => {
	it('markiert nur die zuletzt vergebene Nummer als aktuell', () => {
		const s = new Sequence();
		const a = s.next();
		const b = s.next();
		expect(s.isCurrent(a)).toBe(false);
		expect(s.isCurrent(b)).toBe(true);
	});
});

describe('debounce', () => {
	it('ruft fn erst nach Ablauf und nur einmal', () => {
		vi.useFakeTimers();
		const fn = vi.fn();
		const d = debounce(fn, 250);
		d();
		d();
		expect(fn).not.toHaveBeenCalled();
		vi.advanceTimersByTime(250);
		expect(fn).toHaveBeenCalledTimes(1);
		vi.useRealTimers();
	});
});

describe('parseOnepagerFilter', () => {
	it('liest CSV-Mehrfachwerte und Einzelwerte', () => {
		const f = parseOnepagerFilter(
			new URLSearchParams('q=abc&type=menu&category=dev,news&tag=x,y&lang=de,en&site=example.com&sort=best')
		);
		expect(f.q).toBe('abc');
		expect(f.type).toBe('menu');
		expect(f.categories).toEqual(['dev', 'news']);
		expect(f.tags).toEqual(['x', 'y']);
		expect(f.langs).toEqual(['de', 'en']);
		expect(f.site).toBe('example.com');
		expect(f.sort).toBe('best');
	});
	it('liefert leere Arrays und Default-Sort ohne Parameter', () => {
		const f = parseOnepagerFilter(new URLSearchParams(''));
		expect(f.categories).toEqual([]);
		expect(f.tags).toEqual([]);
		expect(f.langs).toEqual([]);
		expect(f.sort).toBe('newest');
		expect(f.q).toBeUndefined();
	});
	it('ignoriert ungültige type-/sort-Werte', () => {
		const f = parseOnepagerFilter(new URLSearchParams('type=bogus&sort=bogus'));
		expect(f.type).toBeUndefined();
		expect(f.sort).toBe('newest');
	});
});

describe('onepagerSearchParams', () => {
	it('serialisiert Arrays als CSV und lässt Leeres weg', () => {
		const params = onepagerSearchParams({
			q: 'abc',
			type: 'engine',
			categories: ['dev'],
			tags: [],
			langs: ['de', 'en'],
			site: undefined,
			sort: 'installs'
		});
		expect(params.get('q')).toBe('abc');
		expect(params.get('type')).toBe('engine');
		expect(params.get('category')).toBe('dev');
		expect(params.get('tag')).toBeNull();
		expect(params.get('lang')).toBe('de,en');
		expect(params.get('site')).toBeNull();
		expect(params.get('sort')).toBe('installs');
	});
	it('lässt den Default-Sort newest weg', () => {
		const params = onepagerSearchParams({ categories: [], tags: [], langs: [], sort: 'newest' });
		expect(params.get('sort')).toBeNull();
	});
	it('round-trip erhält den Filter', () => {
		const original = {
			q: 'z',
			type: 'menu' as const,
			categories: ['dev', 'news'],
			tags: ['x'],
			langs: ['de'],
			site: 'a.com',
			sort: 'best' as const
		};
		const back = parseOnepagerFilter(onepagerSearchParams(original));
		expect(back.categories).toEqual(original.categories);
		expect(back.tags).toEqual(original.tags);
		expect(back.langs).toEqual(original.langs);
		expect(back.sort).toBe('best');
	});
});
