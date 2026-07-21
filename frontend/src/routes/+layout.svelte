<script lang="ts">
	import '$lib/styles/gestura-common.css';
	import '$lib/styles/site.css';
	import favicon from '$lib/assets/logo/icon32.png';
	import Header from '$lib/components/Header.svelte';
	import Footer from '$lib/components/Footer.svelte';
	import { getLocale } from '$lib/paraglide/runtime';

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
</svelte:head>

<div class="page-shell">
	<Header />
	<main class="container">
		{@render children()}
	</main>
	<Footer />
</div>
