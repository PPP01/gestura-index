<script lang="ts">
	import { onMount } from 'svelte';
	import { page } from '$app/state';
	import { locales, getLocale, localizeHref } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import { getPageVisibility } from '$lib/api';
	import ThemeToggle from './ThemeToggle.svelte';
	import tileLight from '$lib/assets/logo/icon128-tile.png';
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

	let visibility = $state<Record<string, boolean>>({});
	onMount(async () => {
		try {
			visibility = await getPageVisibility();
		} catch {
			/* fail-open: bei Fehler alle Links zeigen; das echte Gating macht der Server */
		}
	});

	const visibleNav = $derived(
		nav.filter((item) => {
			const slug = SLUG_BY_ID[item.id];
			return slug === undefined || visibility[slug] !== false;
		})
	);
</script>

<header class="site-header">
	<a class="brand" href={localizeHref('/')}>
		<span class="logo-img">
			<img src={tileLight} alt="" class="logo-light" width="36" height="36" />
			<img src={tileDark} alt="" class="logo-dark" width="36" height="36" />
		</span>
		<span class="brand-name">Gestura</span>
		{#if isIndex}<span class="index-badge">INDEX</span>{/if}
	</a>

	<nav class="site-nav" aria-label="Hauptnavigation">
		{#each visibleNav as item (item.href)}
			<a
				href={localizeHref(item.href)}
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
			<!-- Kein `Github`-Icon in @lucide/svelte ^1.25.0 (Marken-Icons wurden
			     aus Lucide entfernt) – daher inline die offizielle GitHub-Marke. -->
			<svg viewBox="0 0 16 16" width="18" height="18" fill="currentColor" aria-hidden="true">
				<path
					d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0 0 16 8c0-4.42-3.58-8-8-8z"
				/>
			</svg>
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
		background: var(--accent-tint, oklch(from var(--accent-color) l c h / 12%));
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
		background: var(--accent-tint, var(--bg-tertiary));
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
