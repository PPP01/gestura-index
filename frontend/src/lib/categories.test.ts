import { describe, it, expect } from 'vitest';
import { CATEGORIES, categoryIcon } from './categories';

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
});
