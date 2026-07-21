import { describe, it, expect, vi } from 'vitest';
import { parseQuery, toSearchParams, Sequence, debounce } from './browse-state';

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
