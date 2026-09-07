<script lang="ts">
	import { onMount } from 'svelte';
	import { page } from '$app/state';
	import { locales, getLocale, localizeHref } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import { getPageVisibility } from '$lib/api';
	import ThemeToggle from './ThemeToggle.svelte';
	import GithubMark from './GithubMark.svelte';
	/*
	 * Helles Thema: die FREISTEHENDE Verlaufs-Hand aus dem Logo-Paket
	 * (exchange/…Logo.zip, hand-only/hand-gradient.png) – keine Kachel.
	 *
	 * Aufbereitet als 137×141: Das Original wiegt 365 KB und stünde damit für
	 * ein 36px-Logo in der Kopfleiste JEDER Seite; verkleinert sind es 28 KB,
	 * dieselbe Größenordnung wie die dunkle Kachel, und bis knapp 4-fache
	 * Pixeldichte noch scharf.
	 *
	 * Die krummen Maße sind gerechnet, nicht geraten: transparenter Rand
	 * entfernt, Zeichnung auf 139 px Höhe, dann 1 px Rand zurück. Damit füllt
	 * die Zeichnung 138/141 der Datei – genau das Verhältnis, mit dem die weiße
	 * Hand ihre dunkle Kachel füllt (126/128). Gerendert ergibt das 33,96×35,23
	 * gegen 34,31×35,44 px im dunklen Thema.
	 */
	import handLight from '$lib/assets/logo/logo-hand-gradient-137.png';
	import tileDark from '$lib/assets/logo/icon128-darktile.png';

	const GITHUB_URL = 'https://github.com/PPP01/Gestura';

	// Nav-Einträge: href-Ziel + Route-ID für den Aktivzustand.
	const nav = [
		{ href: '/', id: '/(public)', label: () => m.nav_home() },
		{ href: '/was-ist-gestura', id: '/(public)/was-ist-gestura', label: () => m.nav_what() },
		{ href: '/maus-gesten', id: '/(public)/maus-gesten', label: () => m.nav_gestures() },
		{ href: '/vergleich', id: '/(public)/vergleich', label: () => m.nav_compare() },
		{ href: '/beispiele', id: '/(public)/beispiele', label: () => m.nav_examples() },
		{ href: '/index', id: '/(public)/index', label: () => m.nav_index(), accent: true }
	];
	const isIndex = $derived(page.route.id === '/(public)/index');
	const activeId = $derived(page.route.id);

	// Slug je schaltbarer Nav-Route (Route-ID → Slug).
	const SLUG_BY_ID: Record<string, string> = {
		'/(public)/was-ist-gestura': 'was-ist-gestura',
		'/(public)/maus-gesten': 'maus-gesten',
		'/(public)/vergleich': 'vergleich',
		'/(public)/beispiele': 'beispiele'
	};

	// Ausblenden geschieht per CSS über das Attribut `data-hidden-pages` am <html>
	// (kein Entfernen aus dem DOM → kein Hydration-Mismatch, kein Flash). Ein
	// Inline-Head-Script in app.html setzt das Attribut schon VOR dem ersten Paint
	// aus dem zuletzt bekannten localStorage-Wert; dieser Fetch aktualisiert es
	// danach autoritativ und schreibt den Wert für den nächsten Reload zurück.
	function applyHidden(hidden: string[]): void {
		const value = hidden.join(' ');
		try {
			if (value) localStorage.setItem('gestura_pages_hidden', value);
			else localStorage.removeItem('gestura_pages_hidden');
		} catch {
			/* localStorage nicht verfügbar – Attribut wird trotzdem gesetzt */
		}
		document.documentElement.setAttribute('data-hidden-pages', value);
	}

	onMount(async () => {
		try {
			const vis = await getPageVisibility();
			const hidden = Object.values(SLUG_BY_ID).filter((slug) => vis[slug] === false);
			applyHidden(hidden);
		} catch {
			/* fail-open: bei Fehler den zuletzt bekannten Zustand (Inline-Head-Script)
			   belassen; das echte Gating macht ohnehin der Server */
		}
	});
</script>

<header class="site-header">
	<a class="brand" href={localizeHref('/')}>
		<span class="logo-img">
			<img src={handLight} alt="" class="logo-light" width="36" height="36" />
			<img src={tileDark} alt="" class="logo-dark" width="36" height="36" />
		</span>
		<span class="brand-name">Gestura</span>
		{#if isIndex}<span class="index-badge">INDEX</span>{/if}
	</a>

	<nav class="site-nav" aria-label="Hauptnavigation">
		{#each nav as item (item.href)}
			<a
				href={localizeHref(item.href)}
				data-page-slug={SLUG_BY_ID[item.id]}
				class:active={activeId === item.id}
				class:accent={item.accent}>{item.label()}</a
			>
		{/each}
	</nav>

	<div class="header-actions">
		<div class="lang-seg" role="group" aria-label="Sprache">
			{#each locales as locale}
				<a
					href={localizeHref(page.url.pathname, { locale })}
					data-sveltekit-reload
					class:active={getLocale() === locale}>{locale.toUpperCase()}</a
				>
			{/each}
		</div>
		<ThemeToggle />
		<a
			class="btn btn-icon-only gh"
			href={GITHUB_URL}
			target="_blank"
			rel="noopener noreferrer"
			aria-label={m.header_github()}
			title={m.header_github()}
		>
			<GithubMark />
		</a>
	</div>
</header>

<style>
	.site-header {
		display: flex;
		align-items: center;
		gap: 20px;
		flex-wrap: wrap;
		padding: 12px 0;
	}
	.brand {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		text-decoration: none;
		color: inherit;
	}
	.logo-img {
		width: 36px;
		height: 36px;
		border-radius: 10px;
		overflow: hidden;
		display: inline-flex;
	}
	/*
	 * Die freistehende Hand ist 137×141 – nicht quadratisch. Ohne `contain`
	 * würde sie auf das quadratische Kästchen gedehnt (rund 3 % zu breit).
	 * `contain` skaliert sie auf die Höhe des Kästchens und zentriert sie.
	 */
	.logo-light {
		object-fit: contain;
	}
	.logo-dark {
		display: none;
	}
	:global([data-theme='dark']) .logo-dark {
		display: inline;
	}
	:global([data-theme='dark']) .logo-light {
		display: none;
	}
	.brand-name {
		font-weight: 700;
		font-size: 17px;
	}
	.index-badge {
		font-size: 10.5px;
		font-weight: 700;
		letter-spacing: 0.04em;
		padding: 2px 6px;
		border-radius: 6px;
		color: var(--accent-color);
		background: var(--accent-tint);
	}
	.site-nav {
		display: flex;
		gap: 18px;
		margin-inline-start: auto;
		flex-wrap: wrap;
		font-size: 13px;
	}
	.site-nav a {
		text-decoration: none;
		color: var(--text-secondary);
	}
	.site-nav a.active {
		color: var(--text-primary);
		font-weight: 600;
	}
	.site-nav a.accent {
		color: var(--accent-color);
	}
	/* Deaktivierte Seiten aus der Nav ausblenden – rein per CSS über das
	   `data-hidden-pages`-Attribut am <html> (vom Inline-Head-Script vor dem
	   ersten Paint gesetzt und vom Sichtbarkeits-Fetch aktualisiert). Kein
	   DOM-Entfernen ⇒ kein Hydration-Mismatch, kein Flash. */
	:global(html[data-hidden-pages~='was-ist-gestura']) .site-nav a[data-page-slug='was-ist-gestura'],
	:global(html[data-hidden-pages~='maus-gesten']) .site-nav a[data-page-slug='maus-gesten'],
	:global(html[data-hidden-pages~='vergleich']) .site-nav a[data-page-slug='vergleich'],
	:global(html[data-hidden-pages~='beispiele']) .site-nav a[data-page-slug='beispiele'] {
		display: none;
	}
	.header-actions {
		display: inline-flex;
		gap: 8px;
		align-items: center;
	}
	.lang-seg {
		display: inline-flex;
		border: 1px solid var(--border-color);
		border-radius: 9px;
		overflow: hidden;
	}
	.lang-seg a {
		padding: 5px 9px;
		text-decoration: none;
		color: var(--text-secondary);
		font-size: 12px;
	}
	.lang-seg a.active {
		background: var(--accent-tint);
		color: var(--text-primary);
	}
	.gh {
		width: 32px;
		height: 32px;
		border-radius: 9px;
		border: 1px solid var(--border-color);
	}
	@media (max-width: 720px) {
		.site-nav {
			order: 3;
			width: 100%;
			margin-inline-start: 0;
			overflow-x: auto;
		}
	}
</style>
