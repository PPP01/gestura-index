<script lang="ts">
	import { browser } from '$app/environment';
	import { goto } from '$app/navigation';
	import { localizeHref, getLocale } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import { CATEGORIES, categoryLabel, categoryIcon } from '$lib/categories';

	// Sprach-Weiche: die nackte Wurzel / (ohne Präfix) auf die lokalisierte URL lenken.
	if (browser && location.pathname === '/') {
		location.replace(localizeHref('/', { locale: getLocale() }));
	}

	let query = $state('');
	function submitSearch(e: Event) {
		e.preventDefault();
		const q = query.trim();
		goto(localizeHref(`/browse${q ? `?q=${encodeURIComponent(q)}` : ''}`));
	}
</script>

<svelte:head>
	<title>Gestura Index</title>
	<meta name="description" content={m.hero_tagline()} />
</svelte:head>

<section class="hero">
	<h1>Gestura Index</h1>
	<p class="hero-tagline">{m.hero_tagline()}</p>
	<p class="hero-sub">{m.hero_sub()}</p>
	<form onsubmit={submitSearch} class="hero-search">
		<input type="search" bind:value={query} placeholder={m.search_placeholder()} aria-label={m.search_placeholder()} />
	</form>
</section>

<section>
	<h2>{m.home_categories()}</h2>
	<div class="grid-cards">
		{#each CATEGORIES as cat}
			{@const Icon = categoryIcon(cat)}
			<a class="card cat-tile" href={localizeHref(`/browse?category=${cat}`)}>
				<span class="icon-tile"><Icon size={20} /></span>
				<span>{categoryLabel(cat)}</span>
			</a>
		{/each}
	</div>
</section>

<section>
	<a class="btn" href={localizeHref('/docs')}>{m.home_docs_cta()}</a>
</section>

<style>
	.hero {
		padding: 24px 0;
	}
	.hero-tagline {
		font-size: 1.2em;
		color: var(--text-secondary);
	}
	.hero-search input {
		width: 100%;
		max-width: 480px;
	}
	.cat-tile {
		display: flex;
		align-items: center;
		gap: 12px;
		text-decoration: none;
		color: inherit;
	}
</style>
