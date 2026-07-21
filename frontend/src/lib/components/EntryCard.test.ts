import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/svelte';
import EntryCard from './EntryCard.svelte';
import type { EntryListItem } from '$lib/api';

const entry: EntryListItem = {
	formatId: 'com.example.menu',
	type: 'menu',
	name: 'Example Menu',
	description: 'Ein Beispiel',
	categories: ['dev'],
	tags: ['x'],
	domains: ['example.com'],
	installCount: 42,
	currentVersion: '1.0.0',
	deprecated: false,
	successorFormatId: null,
	screenshotUrl: null,
	updatedAt: '2026-07-01T00:00:00Z'
};

describe('EntryCard', () => {
	it('zeigt Name, Install-Zähler und einen Detail-Link', () => {
		render(EntryCard, { entry });
		expect(screen.getByText('Example Menu')).toBeInTheDocument();
		expect(screen.getByText('42')).toBeInTheDocument();
		const link = screen.getByRole('link');
		expect(link.getAttribute('href')).toContain('com.example.menu');
	});
	it('markiert veraltete Einträge', () => {
		render(EntryCard, { entry: { ...entry, deprecated: true } });
		expect(screen.getByText(/deprecated|veraltet/i)).toBeInTheDocument();
	});
});
