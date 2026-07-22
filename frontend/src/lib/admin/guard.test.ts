import { describe, it, expect, vi } from 'vitest';

vi.mock('$env/dynamic/public', () => ({ env: { PUBLIC_API_BASE: undefined } }));

const { me } = vi.hoisted(() => ({ me: vi.fn() }));
vi.mock('./api', async (orig) => ({ ...(await orig<typeof import('./api')>()), me }));

import { requireSession } from './guard';
import { AdminApiError } from './api';

describe('requireSession', () => {
	it('wirft Redirect nach /admin/login bei 401', async () => {
		me.mockRejectedValue(new AdminApiError(401, 'unauth', null));
		await expect(requireSession()).rejects.toMatchObject({ status: 302 });
	});
});
