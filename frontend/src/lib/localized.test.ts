import { describe, it, expect } from 'vitest';
import { resolveLocalized, entryLanguages } from './localized';

describe('resolveLocalized', () => {
	it('gibt einen einfachen String unverändert zurück', () => {
		expect(resolveLocalized('Hello', 'de')).toBe('Hello');
	});
	it('wählt die passende Sprache aus einer Map', () => {
		expect(resolveLocalized({ en: 'Hi', de: 'Hallo' }, 'de')).toBe('Hallo');
	});
	it('fällt auf en zurück, wenn die Locale fehlt', () => {
		expect(resolveLocalized({ en: 'Hi', fr: 'Salut' }, 'de')).toBe('Hi');
	});
	it('nimmt den ersten Wert, wenn weder Locale noch en existieren', () => {
		expect(resolveLocalized({ fr: 'Salut' }, 'de')).toBe('Salut');
	});
	it('gibt für null/undefined einen leeren String zurück', () => {
		expect(resolveLocalized(null, 'de')).toBe('');
		expect(resolveLocalized(undefined, 'de')).toBe('');
	});
});

describe('entryLanguages', () => {
	it('zählt einen String-Namen als multilanguage (*)', () => {
		expect(entryLanguages('Hello')).toEqual(['*']);
	});
	it('liefert alle Map-Schlüssel', () => {
		expect(entryLanguages({ en: 'Hi', de: 'Hallo' }).sort()).toEqual(['de', 'en']);
	});
	it('liefert [] für eine leere Map', () => {
		expect(entryLanguages({})).toEqual([]);
	});
});
