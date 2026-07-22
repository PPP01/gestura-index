import { render, screen } from '@testing-library/svelte';
import { describe, it, expect } from 'vitest';
import Sidebar from './Sidebar.svelte';

describe('Sidebar', () => {
	it('zeigt Nutzer+Audit nur für admin', () => {
		render(Sidebar, { role: 'admin' });
		expect(screen.getByRole('link', { name: /nutzer|users/i })).toBeInTheDocument();
		expect(screen.getByRole('link', { name: /audit/i })).toBeInTheDocument();
	});

	it('verbirgt Nutzer+Audit für moderator', () => {
		render(Sidebar, { role: 'moderator' });
		expect(screen.queryByRole('link', { name: /nutzer|users/i })).toBeNull();
		expect(screen.queryByRole('link', { name: /audit/i })).toBeNull();
		expect(screen.getByRole('link', { name: /queue|warteschlange/i })).toBeInTheDocument();
	});
});
