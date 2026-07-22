import { describe, it, expect, vi } from 'vitest';

vi.mock('$env/dynamic/public', () => ({ env: { PUBLIC_API_BASE: undefined } }));

const { performAssertion } = vi.hoisted(() => ({ performAssertion: vi.fn() }));
vi.mock('./webauthn', () => ({ performAssertion }));

const { stepUpOptions, stepUpVerify } = vi.hoisted(() => ({
	stepUpOptions: vi.fn(),
	stepUpVerify: vi.fn()
}));
vi.mock('./api', async (orig) => ({
	...(await orig<typeof import('./api')>()),
	stepUpOptions,
	stepUpVerify
}));

import { withStepUp } from './stepup';
import { AdminApiError } from './api';

describe('withStepUp', () => {
	it('fährt Step-up und retryt die Aktion einmal', async () => {
		stepUpOptions.mockResolvedValue({ challenge: 'c' });
		performAssertion.mockResolvedValue({ id: 'x' });
		stepUpVerify.mockResolvedValue(undefined);
		let calls = 0;
		const action = vi.fn(async () => {
			calls++;
			if (calls === 1) throw new AdminApiError(403, 'Step-up', null, true);
			return 'ok';
		});
		const res = await withStepUp(action);
		expect(res).toBe('ok');
		expect(stepUpOptions).toHaveBeenCalledOnce();
		expect(performAssertion).toHaveBeenCalledWith({ challenge: 'c' });
		expect(action).toHaveBeenCalledTimes(2);
	});

	it('reicht Nicht-Step-up-Fehler durch', async () => {
		const action = vi.fn(async () => {
			throw new AdminApiError(404, 'nope', null);
		});
		await expect(withStepUp(action)).rejects.toMatchObject({ status: 404 });
	});
});
