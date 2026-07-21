import type { Reroute } from '@sveltejs/kit';
import { deLocalizeUrl } from '$lib/paraglide/runtime';

// Paraglide url-Strategie: /de/browse und /en/browse zeigen beide auf die interne
// Route /browse; die Sprache wird aus dem Präfix abgeleitet.
export const reroute: Reroute = (request) => deLocalizeUrl(request.url).pathname;
