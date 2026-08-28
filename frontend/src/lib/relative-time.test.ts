import { describe, it, expect } from 'vitest';
import { relativeTime } from './relative-time';

describe('relativeTime', () => {
	it('formatiert 3 Tage Differenz auf Englisch relativ zu einem festen "now"', () => {
		const now = new Date('2026-08-28T00:00:00Z');
		const iso = new Date('2026-08-25T00:00:00Z').toISOString();
		expect(relativeTime(iso, 'en', now)).toMatch(/3 days ago/);
	});

	it('formatiert 3 Tage Differenz auf Deutsch relativ zu einem festen "now"', () => {
		const now = new Date('2026-08-28T00:00:00Z');
		const iso = new Date('2026-08-25T00:00:00Z').toISOString();
		expect(relativeTime(iso, 'de', now)).toMatch(/vor 3 Tagen/);
	});

	it('nutzt den aktuellen Zeitpunkt, wenn "now" weggelassen wird', () => {
		const iso = new Date(Date.now() - 5000).toISOString();
		expect(relativeTime(iso, 'en')).toMatch(/second|now/);
	});
});
