import { deLocalizeUrl } from '$lib/paraglide/runtime';
import { session } from '$lib/admin/session.svelte';
import { requireSession } from '$lib/admin/guard';
import type { LayoutLoad } from './$types';

// Admin ist rein client-seitig (SPA über adapter-static fallback 200.html),
// überschreibt das prerender=true des Root-Layouts für diesen Zweig.
export const prerender = false;
export const ssr = false;

// Öffentliche Auth-Routen: hier NIE umleiten, sonst Redirect-Loop
// (Login/Registrierung müssen ohne Session erreichbar bleiben).
const PUBLIC_PATHS = new Set(['/admin/login', '/admin/register']);

/**
 * Bootstrapped die Session bei jedem (harten) Laden des Admin-Bereichs —
 * insbesondere beim Reload/Deep-Link, wenn `session.user` noch `null` ist
 * und `admin/+layout@.svelte` sonst ohne Chrome rendern würde. Auf
 * geschützten Routen leitet `requireSession()` bei fehlender/abgelaufener
 * Session nach `/admin/login` um (siehe `$lib/admin/guard`).
 */
export const load: LayoutLoad = async ({ url }) => {
	const path = deLocalizeUrl(url).pathname;

	if (PUBLIC_PATHS.has(path)) {
		// Best-effort: falls bereits eingeloggt, z.B. für spätere Redirects
		// nützlich — aber auf diesen Routen nie umleiten.
		await session.load().catch(() => {});
		return {};
	}

	await requireSession();
	return {};
};
