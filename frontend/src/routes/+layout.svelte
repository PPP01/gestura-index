<script lang="ts">
	// Echter Wurzel-Layout (kein `page-shell`/Header/Footer mehr — das öffentliche
	// Chrome lebt jetzt in `(public)/+layout.svelte`). Hier nur Dinge, die für
	// öffentliche Seiten UND den Admin-Bereich gelten: globale Styles, Favicon,
	// Sprach-Attribut. So kann `admin/+layout@.svelte` per Layout-Reset auf genau
	// diese schlanke Wurzel zurücksetzen, ohne das öffentliche Header/Footer zu erben.
	import '$lib/styles/main.css';
	/*
	 * App-Kachel als Favicon. Die Verlaufs-Kachel bringt ihren eigenen Grund mit
	 * und ist damit in heller UND dunkler Tab-Leiste lesbar – eine Weiche über
	 * `media="(prefers-color-scheme: …)"` braucht es nicht.
	 *
	 * Die Weiche funktioniert (2026-09-07 in Chromium gemessen: der Browser
	 * wertet das Attribut aus und fordert nur das passende Bild an), nötig wäre
	 * sie aber nur für ein FREISTEHENDES Icon, dessen Strichfarbe zum Untergrund
	 * passen muss. Und sie müsste dann am OS-Theme hängen, nicht am Theme dieser
	 * Seite: das Icon sitzt in der Tab-Leiste, deren Farbe die Seite nicht
	 * bestimmt. Wer hier auf »hell« stellt, während das System dunkel läuft,
	 * bekäme sonst ein helles Icon in eine dunkle Leiste.
	 *
	 * Wörtliche Kopien von `icons/icon*-tile.png` aus dem Extension-Repo; dort
	 * sind `-tile` und `-darktile` seit dem Icon-Wechsel dieselbe Datei.
	 * Der Pfad-Rückfall `static/favicon.png` ist die 32er-Fassung.
	 */
	import icon16 from '$lib/assets/logo/icon16-tile.png';
	import icon32 from '$lib/assets/logo/icon32-tile.png';
	import icon48 from '$lib/assets/logo/icon48-tile.png';
	import { getLocale, localizeHref, locales } from '$lib/paraglide/runtime';
	import { page } from '$app/state';

	const SITE_URL = 'https://gestura.eu';

	let { children } = $props();

	// SPA-Fallback (200.html) wird einmal mit der Basis-Sprache gebacken; auf
	// clientseitig gerenderten Routen (/de/browse, /de/entry/...) muss lang
	// nach der Hydration korrigiert werden. Läuft nur im Browser (kein SSR/Prerender).
	$effect(() => {
		document.documentElement.lang = getLocale();
	});
</script>

<svelte:head>
	<link rel="icon" sizes="16x16" href={icon16} />
	<link rel="icon" sizes="32x32" href={icon32} />
	<link rel="icon" sizes="48x48" href={icon48} />
	{#each locales as hreflangLocale}
		<link
			rel="alternate"
			hreflang={hreflangLocale}
			href={SITE_URL + localizeHref(page.url.pathname, { locale: hreflangLocale })}
		/>
	{/each}
	<link rel="alternate" hreflang="x-default" href={SITE_URL + localizeHref(page.url.pathname, { locale: 'en' })} />
</svelte:head>

{@render children()}
