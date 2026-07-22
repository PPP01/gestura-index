<script lang="ts">
	// Echter Wurzel-Layout (kein `page-shell`/Header/Footer mehr — das öffentliche
	// Chrome lebt jetzt in `(public)/+layout.svelte`). Hier nur Dinge, die für
	// öffentliche Seiten UND den Admin-Bereich gelten: globale Styles, Favicon,
	// Sprach-Attribut. So kann `admin/+layout@.svelte` per Layout-Reset auf genau
	// diese schlanke Wurzel zurücksetzen, ohne das öffentliche Header/Footer zu erben.
	import '$lib/styles/gestura-common.css';
	import '$lib/styles/site.css';
	import favicon from '$lib/assets/logo/icon32.png';
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
	<link rel="icon" href={favicon} />
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
