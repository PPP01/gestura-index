import { dev } from '$app/environment';
import { error } from '@sveltejs/kit';
import { getPageVisibility } from '$lib/api';

// C5 »Beispiele«: statisch prerendern (SEO), wie C1–C4.
export const prerender = true;
export const ssr = true;

// Nur im Dev (Vite rendert die Seite) prüfen wir das Flag und werfen ein echtes
// 404. Im Prod-Build ist dev=false ⇒ dieser Code tut nichts, die Seite wird
// normal prerendered und Symfony (MarketingPageController) übernimmt das Gating.
export const load = async () => {
	if (!dev) {
		return;
	}
	let vis: Record<string, boolean>;
	try {
		vis = await getPageVisibility();
	} catch {
		return; // fail-open im Dev
	}
	if (vis['beispiele'] === false) {
		error(404, 'Seite deaktiviert');
	}
};
