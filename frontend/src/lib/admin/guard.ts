import { redirect } from '@sveltejs/kit';
import { localizeHref } from '$lib/paraglide/runtime';
import { session } from './session.svelte';
import type { AdminClientOpts, MeResponse } from './api';

/**
 * Route-Guard für authentifizierte Admin-Seiten: lädt die Session (`GET
 * /auth/me`); bei 401 (kein Nutzer) wird nach `/admin/login` umgeleitet.
 */
export async function requireSession(opts?: AdminClientOpts): Promise<MeResponse> {
	const u = await session.load(opts);
	if (!u) redirect(302, localizeHref('/admin/login'));
	return u;
}
