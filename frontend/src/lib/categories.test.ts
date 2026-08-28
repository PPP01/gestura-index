import { describe, it, expect } from 'vitest';
import { CATEGORIES, categoryIcon, categoryColor } from './categories';

describe('categories', () => {
	it('enthält genau die 10 Backend-Keys', () => {
		expect(CATEGORIES).toEqual([
			'dev',
			'shopping',
			'video',
			'news',
			'social',
			'productivity',
			'search',
			'reference',
			'entertainment',
			'other'
		]);
	});
	it('liefert für jede Kategorie ein Icon', () => {
		for (const c of CATEGORIES) expect(categoryIcon(c)).toBeTruthy();
		expect(categoryIcon('unknown')).toBeTruthy();
	});
	it('liefert für einen bekannten Key die Kategoriefarbe, sonst den Akzent-Fallback', () => {
		expect(categoryColor('dev')).toBe('#8b5cf6');
		expect(categoryColor('shopping')).toBe('#e6a117');
		expect(categoryColor('unknown')).toBe('#5b9cf6');
	});
});
