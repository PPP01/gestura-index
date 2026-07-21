import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/svelte';
import Pagination from './Pagination.svelte';

describe('Pagination', () => {
	it('deaktiviert Zurück auf Seite 1 und ruft onPage bei Weiter', async () => {
		const onPage = vi.fn();
		render(Pagination, { page: 1, perPage: 20, total: 60, onPage });
		const prev = screen.getByRole('button', { name: /prev|zurück/i });
		expect(prev).toBeDisabled();
		const next = screen.getByRole('button', { name: /next|weiter/i });
		next.click();
		expect(onPage).toHaveBeenCalledWith(2);
	});
});
