import { describe, it, expect, beforeEach } from 'vitest';
import { basket } from './basket.svelte';

beforeEach(() => {
	localStorage.clear();
	basket.clear();
});

describe('basket', () => {
	it('toggle fügt hinzu und entfernt', () => {
		expect(basket.has('a')).toBe(false);
		basket.toggle('a');
		expect(basket.has('a')).toBe(true);
		expect(basket.count).toBe(1);
		basket.toggle('a');
		expect(basket.has('a')).toBe(false);
		expect(basket.count).toBe(0);
	});
	it('remove und clear', () => {
		basket.toggle('a');
		basket.toggle('b');
		basket.remove('a');
		expect(basket.ids).toEqual(['b']);
		basket.clear();
		expect(basket.count).toBe(0);
	});
	it('persistiert nach localStorage', () => {
		basket.toggle('x');
		expect(JSON.parse(localStorage.getItem('gestura-basket') ?? '[]')).toEqual(['x']);
	});
	it('reconcile entfernt nicht mehr vorhandene IDs', () => {
		basket.toggle('a');
		basket.toggle('b');
		basket.reconcile(new Set(['a']));
		expect(basket.ids).toEqual(['a']);
	});
});

describe('removeMany', () => {
	it('entfernt genau die übergebenen IDs in einem Zug', () => {
		basket.clear();
		for (const id of ['a', 'b', 'c']) basket.toggle(id);
		basket.removeMany(['a', 'c', 'gibtsNicht']);
		expect(basket.ids).toEqual(['b']);
	});

	it('lässt den Zustand unangetastet, wenn nichts zu entfernen ist', () => {
		basket.clear();
		basket.toggle('a');
		const before = basket.ids;
		basket.removeMany(['x']);
		expect(basket.ids).toBe(before);
	});
});
