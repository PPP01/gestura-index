<script lang="ts">
	import { browser } from '$app/environment';
	import { page } from '$app/state';
	import { goto } from '$app/navigation';
	import { onDestroy } from 'svelte';
	import { localizeHref, getLocale } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import type { EntryListItem } from '$lib/api';
	import { loadCatalog, INITIAL_LOAD } from '$lib/catalog';
	import {
		filterEntries,
		sortEntries,
		languageFacet,
		tagFacet,
		categoryFacet,
		optionCount
	} from '$lib/facets';
	import {
		parseOnepagerFilter,
		onepagerSearchParams,
		debounce,
		type OnepagerFilter,
		type OnepagerSort
	} from '$lib/browse-state';
	import { categoryLabel, categoryIcon, categoryColor, entryTypeLabel } from '$lib/categories';
	import EntryBlock from '$lib/components/EntryBlock.svelte';
	import ErrorState from '$lib/components/ErrorState.svelte';
	import BasketTray from '$lib/components/BasketTray.svelte';
	import { basket } from '$lib/basket.svelte';
	import { Search, SearchX, X } from '@lucide/svelte';

	// Sprach-Weiche: die nackte Wurzel / auf die lokalisierte URL lenken.
	if (browser && location.pathname === '/') {
		location.replace(localizeHref('/', { locale: getLocale() }));
	}

	// --- Katalog-Zustand ---
	let items = $state<EntryListItem[]>([]);
	let loaded = $state(0);
	let total = $state(0);
	let complete = $state(false);
	let loadError = $state<string | null>(null);
	let started = false;

	function startLoad() {
		if (started) return;
		started = true;
		items = [];
		loaded = 0;
		complete = false;
		loadError = null;
		const seen = new Set<string>();
		loadCatalog({
			onBatch: (batch, ld, tot) => {
				const fresh = batch.filter((e) => !seen.has(e.formatId));
				for (const e of fresh) seen.add(e.formatId);
				items = [...items, ...fresh];
				loaded = ld;
				total = tot;
			},
			onComplete: () => (complete = true),
			onError: (msg) => (loadError = msg)
		});
	}

	$effect(() => {
		if (browser) startLoad();
	});

	function retry() {
		started = false;
		startLoad();
	}

	function applyLangDefault(f: OnepagerFilter, locale: string): OnepagerFilter {
		// Vorbelegung der Sprachfacette folgt der URL-Locale, solange der Nutzer
		// nichts gewählt hat; frei änderbar.
		return f.langs.length === 0 ? { ...f, langs: [locale] } : f;
	}

	// --- Filter-Zustand ---
	// Bewusst lokaler $state statt eines reinen $derived aus page.url: die
	// Sidebar-Interaktionen (Chips/Zeilen) müssen die Liste SOFORT neu filtern,
	// unabhängig davon, wann/ob `goto()` die URL tatsächlich zurückspiegelt
	// (im Test ist `goto` ein No-Op-Mock, in Produktion asynchron). Die URL
	// bleibt Quelle für den Erstaufruf (Teilbarkeit/Deep-Links); `updateUrl`
	// hält Zustand und URL danach synchron.
	let filter = $state<OnepagerFilter>(
		applyLangDefault(parseOnepagerFilter(page.url.searchParams), getLocale())
	);

	// Freitextfeld mit eigenem State für flüssiges Tippen.
	// svelte-ignore state_referenced_locally – Anfangswert wird bewusst nur
	// einmal übernommen; spätere Änderungen von `filter.q` (z. B. Entfernen
	// des Suche-Chips) übernimmt der $effect direkt darunter.
	let qField = $state(filter.q ?? '');
	$effect(() => {
		qField = filter.q ?? '';
	});

	const locale = $derived(getLocale());
	const filtered = $derived(sortEntries(filterEntries(items, filter, locale), filter.sort));

	// Katalog-Map für den Sammelkorb (Auflösen von formatId -> Eintrag, u. a.
	// für currentVersion beim Bundle-Download).
	const catalogMap = $derived(new Map(items.map((e) => [e.formatId, e])));

	// Nicht mehr vorhandene Auswahl-IDs still abräumen, sobald der Katalog
	// vollständig geladen ist (sonst würden bereits während des Nachladens
	// vorhandene Einträge fälschlich als »nicht mehr im Katalog« gelten).
	$effect(() => {
		if (complete) basket.reconcile(new Set(items.map((e) => e.formatId)));
	});

	// Facetten aus dem geladenen Katalog.
	const cats = $derived(categoryFacet(items));
	const langs = $derived(languageFacet(items));
	const allTags = $derived(tagFacet(items));
	let showAllTags = $state(false);
	const TOP_TAGS = 12;
	const tags = $derived(showAllTags ? allTags : allTags.slice(0, TOP_TAGS));

	// --- URL schreiben (ohne Reload) ---
	function updateUrl(next: OnepagerFilter) {
		filter = next;
		const qs = onepagerSearchParams(next).toString();
		goto(localizeHref(`/${qs ? `?${qs}` : ''}`), { replaceState: true, keepFocus: true, noScroll: true });
	}
	function setFilter(patch: Partial<OnepagerFilter>) {
		updateUrl({ ...filter, ...patch });
	}
	function toggleIn(list: string[], value: string): string[] {
		return list.includes(value) ? list.filter((v) => v !== value) : [...list, value];
	}
	function resetAll() {
		qField = '';
		updateUrl({ ...filter, q: undefined, type: undefined, categories: [], tags: [], langs: [], site: undefined });
	}
	function removeSearch() {
		qField = '';
		setFilter({ q: undefined });
	}

	const pushQ = debounce((value: string) => setFilter({ q: value || undefined }), 250);
	onDestroy(() => pushQ.cancel());

	// Aktive Filter (für die entfernbare Chip-Zeile); die Sprachfacette bleibt
	// bewusst ausgeklammert, da sie per URL-Locale vorbelegt ist und sonst
	// dauerhaft als »aktiv« erschiene, auch ohne Nutzerzutun.
	const hasActiveFilters = $derived(
		Boolean(filter.type || filter.categories.length || filter.tags.length || filter.site || filter.q)
	);

	// Highlight-Scroll: springt zum per URL hervorgehobenen Eintrag, sobald er
	// im DOM existiert (Anker `e-<formatId>`) – wird ab Task 6/7 vom
	// Sammelkorb genutzt, um zu einem gemerkten Eintrag zu springen.
	$effect(() => {
		if (!browser || !filter.highlight) return;
		void filtered.length;
		document.getElementById(`e-${filter.highlight}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
	});
</script>

<svelte:head>
	<title>{m.onepager_title()}</title>
	<meta name="description" content={m.hero_tagline()} />
</svelte:head>

<div class="op-body">
	<aside class="card op-sidebar">
		<div class="sidebar-head">
			<h2>Filter</h2>
			<button class="link-btn" onclick={resetAll}>{m.filter_reset()}</button>
		</div>

		<div class="facet-group">
			<span class="facet-title">{m.filter_group_type()}</span>
			<div class="segmented">
				<button class:active={!filter.type} onclick={() => setFilter({ type: undefined })}>
					{m.filter_type_all()}
				</button>
				<button class:active={filter.type === 'menu'} onclick={() => setFilter({ type: 'menu' })}>
					{m.type_menu()}
				</button>
				<button class:active={filter.type === 'engine'} onclick={() => setFilter({ type: 'engine' })}>
					{m.type_engine()}
				</button>
			</div>
		</div>

		{#if cats.length}
			<div class="facet-group">
				<span class="facet-title">{m.facet_categories()}</span>
				<div class="facet-rows">
					{#each cats as opt (opt.value)}
						{@const Icon = categoryIcon(opt.value)}
						<button
							class="facet-row"
							class:on={filter.categories.includes(opt.value)}
							style={`--row-color:${categoryColor(opt.value)}`}
							onclick={() => setFilter({ categories: toggleIn(filter.categories, opt.value) })}
						>
							<span class="facet-row-icon"><Icon size={15} /></span>
							<span class="facet-row-label">{categoryLabel(opt.value)}</span>
							<span class="facet-row-count">{optionCount(items, filter, 'categories', opt.value, locale)}</span>
						</button>
					{/each}
				</div>
			</div>
		{/if}

		{#if allTags.length}
			<div class="facet-group">
				<span class="facet-title">{m.facet_tags()}</span>
				<div class="chips">
					{#each tags as opt (opt.value)}
						<button class="chip" class:on={filter.tags.includes(opt.value)}
							onclick={() => setFilter({ tags: toggleIn(filter.tags, opt.value) })}>
							#{opt.value}
							<span class="chip-count">{optionCount(items, filter, 'tags', opt.value, locale)}</span>
						</button>
					{/each}
				</div>
				{#if allTags.length > TOP_TAGS}
					<button class="link-btn" onclick={() => (showAllTags = !showAllTags)}>
						{showAllTags ? m.facet_less() : `+ ${allTags.length - TOP_TAGS} ${m.facet_more()}`}
					</button>
				{/if}
			</div>
		{/if}

		{#if langs.length}
			<div class="facet-group">
				<span class="facet-title">{m.facet_languages()}</span>
				<div class="facet-rows">
					{#each langs as opt (opt.value)}
						<button
							class="facet-row"
							class:on={filter.langs.includes(opt.value)}
							onclick={() => setFilter({ langs: toggleIn(filter.langs, opt.value) })}
						>
							<span class="facet-row-label">{opt.value.toUpperCase()}</span>
							<span class="facet-row-count">{optionCount(items, filter, 'langs', opt.value, locale)}</span>
						</button>
					{/each}
				</div>
			</div>
		{/if}

		<div class="facet-group">
			<label class="facet-title" for="op-site">{m.filter_site()}</label>
			<input id="op-site" type="text" value={filter.site ?? ''}
				onchange={(e) => setFilter({ site: e.currentTarget.value || undefined })}
				placeholder={m.filter_site()} />
		</div>
	</aside>

	<section class="op-main">
		<div class="search-field">
			<Search size={16} class="search-icon" />
			<input
				type="search"
				bind:value={qField}
				oninput={() => pushQ(qField)}
				placeholder={m.search_placeholder()}
				aria-label={m.search_placeholder()}
			/>
			<kbd class="search-kbd">/</kbd>
		</div>

		{#if hasActiveFilters}
			<div class="active-filters">
				<span class="active-filters-label">{m.filter_active()}</span>
				<div class="active-chips">
					{#if filter.type}
						<button class="chip chip-active" onclick={() => setFilter({ type: undefined })}>
							{m.filter_group_type()}: {entryTypeLabel(filter.type)}
							<X size={12} />
						</button>
					{/if}
					{#each filter.categories as cat (cat)}
						<button class="chip chip-active" onclick={() => setFilter({ categories: toggleIn(filter.categories, cat) })}>
							{m.facet_categories()}: {categoryLabel(cat)}
							<X size={12} />
						</button>
					{/each}
					{#each filter.tags as tag (tag)}
						<button class="chip chip-active" onclick={() => setFilter({ tags: toggleIn(filter.tags, tag) })}>
							#{tag}
							<X size={12} />
						</button>
					{/each}
					{#if filter.site}
						<button class="chip chip-active" onclick={() => setFilter({ site: undefined })}>
							{m.filter_site()}: {filter.site}
							<X size={12} />
						</button>
					{/if}
					{#if filter.q}
						<button class="chip chip-active" onclick={removeSearch}>
							{filter.q}
							<X size={12} />
						</button>
					{/if}
					<button class="link-btn" onclick={resetAll}>{m.filter_reset()}</button>
				</div>
			</div>
		{/if}

		{#snippet skeletonRows(count: number)}
			{#each Array.from({ length: count }) as _, i (i)}
				<div class="skeleton-row" style={`animation-delay:${i * 0.2}s`}>
					<span class="skel skel-icon"></span>
					<span class="skel-lines">
						<span class="skel skel-line-a"></span>
						<span class="skel skel-line-b"></span>
					</span>
					<span class="skel skel-side"></span>
				</div>
			{/each}
		{/snippet}

		{#if loadError && items.length === 0}
			<ErrorState message={loadError} onRetry={retry} />
		{:else}
			{#if items.length === 0 && !complete}
				<div class="card list-card">
					{@render skeletonRows(3)}
				</div>
			{:else if filtered.length === 0}
				<div class="card empty-card">
					<span class="icon-tile empty-icon"><SearchX size={28} /></span>
					<h2 class="empty-title">{m.state_empty_title()}{#if qField}: {qField}{/if}</h2>
					<p class="empty-hint">{m.browse_empty_hint()}</p>
					<button class="btn btn-ghost" onclick={resetAll}>{m.empty_reset_cta()}</button>
				</div>
			{:else}
				<div class="result-header">
					<span class="result-count">
						{#if complete}
							{m.results_count({ total: filtered.length })}
						{:else}
							{m.results_of({ shown: filtered.length, total })}
						{/if}
					</span>
					<select value={filter.sort} onchange={(e) => setFilter({ sort: e.currentTarget.value as OnepagerSort })}>
						<option value="newest">{m.sort_newest()}</option>
						<option value="installs">{m.sort_installs()}</option>
						<option value="best">{m.sort_best()}</option>
					</select>
				</div>
				<div class="card list-card">
					{#each filtered as entry (entry.formatId)}
						<div id={`e-${entry.formatId}`}>
							<EntryBlock {entry} open={filter.highlight === entry.formatId} />
						</div>
					{/each}
				</div>
			{/if}

			{#if !complete && items.length > 0 && loaded >= INITIAL_LOAD}
				<div class="card list-card loading-more-card">
					<p class="loading-more-hint">{m.loading_more()}</p>
					{@render skeletonRows(3)}
				</div>
			{/if}
		{/if}
	</section>
</div>

<BasketTray catalog={catalogMap} />

<style>
	.op-body {
		display: grid;
		grid-template-columns: 268px 1fr;
		gap: 24px;
		align-items: start;
		padding: 16px 0;
	}
	.op-sidebar {
		display: flex;
		flex-direction: column;
		gap: 14px;
		position: sticky;
		top: 16px;
		padding: 18px 20px;
	}
	.sidebar-head {
		display: flex;
		align-items: baseline;
		justify-content: space-between;
		gap: 8px;
	}
	.sidebar-head h2 {
		font-size: 16px;
		font-weight: 700;
		margin: 0;
	}
	.facet-group {
		display: flex;
		flex-direction: column;
		gap: 8px;
		padding-top: 14px;
		border-top: 1px solid var(--border-color);
	}
	.facet-group:first-of-type {
		padding-top: 0;
		border-top: none;
	}
	.facet-title {
		font-size: 10.5px;
		font-weight: 600;
		letter-spacing: 0.08em;
		text-transform: uppercase;
		color: var(--text-muted);
	}
	.segmented {
		display: inline-flex;
		background: var(--bg-tertiary);
		border-radius: 8px;
		padding: 3px;
		gap: 2px;
	}
	.segmented button {
		flex: 1;
		border: none;
		background: transparent;
		color: var(--text-secondary);
		padding: 5px 8px;
		border-radius: 6px;
		font-size: 12.5px;
		cursor: pointer;
		white-space: nowrap;
	}
	.segmented button.active {
		background: var(--bg-secondary);
		color: var(--text-primary);
		font-weight: 600;
	}
	.facet-rows {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}
	.facet-row {
		display: flex;
		align-items: center;
		gap: 8px;
		border: none;
		background: transparent;
		color: var(--text-secondary);
		padding: 6px 8px;
		border-radius: 8px;
		cursor: pointer;
		font-size: 13px;
		text-align: left;
	}
	.facet-row:hover {
		background: var(--bg-tertiary);
	}
	.facet-row.on {
		background: var(--accent-tint);
		color: var(--accent-color);
	}
	.facet-row-icon {
		display: inline-flex;
		color: var(--row-color, var(--accent-color));
		flex-shrink: 0;
	}
	.facet-row.on .facet-row-icon {
		color: var(--accent-color);
	}
	.facet-row-label {
		flex: 1;
		min-width: 0;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}
	.facet-row-count {
		color: var(--text-muted);
		font-size: 0.85em;
		font-variant-numeric: tabular-nums;
	}
	.facet-row.on .facet-row-count {
		color: var(--accent-color);
	}
	.chips {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}
	.chip {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		padding: 4px 10px;
		border-radius: 999px;
		border: 1px solid var(--border-color);
		background: transparent;
		color: inherit;
		cursor: pointer;
		font-size: 0.85em;
	}
	.chip.on {
		background: var(--accent-tint);
		color: var(--accent-color);
		border-color: transparent;
	}
	.chip-count {
		color: var(--text-muted);
		font-size: 0.85em;
	}
	.chip.on .chip-count {
		color: var(--accent-color);
	}
	.link-btn {
		align-self: flex-start;
		background: none;
		border: none;
		color: var(--accent-color);
		cursor: pointer;
		padding: 0;
		font-size: 12.5px;
	}
	#op-site {
		width: 100%;
	}
	.op-main {
		min-width: 0;
		display: flex;
		flex-direction: column;
		gap: 16px;
	}
	.search-field {
		display: flex;
		align-items: center;
		gap: 10px;
		height: 50px;
		padding: 0 16px;
		border-radius: 14px;
		background: var(--bg-secondary);
		border: 1px solid var(--border-color);
	}
	.search-field:focus-within {
		border-color: color-mix(in srgb, var(--accent-color) 55%, transparent);
		box-shadow: 0 0 0 3px var(--input-focus-border-color);
	}
	:global(.search-icon) {
		color: var(--text-muted);
		flex-shrink: 0;
	}
	.search-field input {
		flex: 1;
		border: none;
		box-shadow: none;
		background: transparent;
		padding: 0;
		font-size: 14px;
	}
	.search-field input:focus {
		box-shadow: none;
	}
	.search-kbd {
		font-family: var(--font-mono);
		font-size: 11px;
		color: var(--text-muted);
		border: 1px solid var(--border-color);
		border-radius: 6px;
		padding: 2px 6px;
	}
	.active-filters {
		display: flex;
		align-items: baseline;
		gap: 12px;
		flex-wrap: wrap;
	}
	.active-filters-label {
		font-size: 10.5px;
		font-weight: 600;
		letter-spacing: 0.08em;
		text-transform: uppercase;
		color: var(--text-muted);
		flex-shrink: 0;
	}
	.active-chips {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
	}
	.chip-active {
		background: var(--accent-tint);
		color: var(--accent-color);
		border-color: transparent;
	}
	.result-header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
	}
	.result-count {
		color: var(--text-secondary);
		font-weight: 600;
		font-size: 13px;
	}
	.list-card {
		padding: 0;
		overflow: hidden;
	}
	.empty-card {
		display: flex;
		flex-direction: column;
		align-items: center;
		text-align: center;
		gap: 8px;
		padding: 40px 24px;
	}
	.empty-icon {
		width: 56px;
		height: 56px;
		border-radius: 16px;
		margin-bottom: 8px;
	}
	.empty-title {
		font-size: 16px;
		margin: 0;
	}
	.empty-hint {
		color: var(--text-secondary);
		margin: 0 0 8px;
	}
	.loading-more-card {
		padding: 12px 0;
	}
	.loading-more-hint {
		color: var(--text-muted);
		font-size: 12.5px;
		padding: 0 16px 8px;
	}
	.skeleton-row {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 11px 16px;
		border-top: 1px solid var(--border-color);
		animation: op-pulse 1.5s ease-in-out infinite;
	}
	.skeleton-row:first-child {
		border-top: none;
	}
	.skel {
		border-radius: 8px;
		background: rgba(255, 255, 255, 0.08);
		flex-shrink: 0;
	}
	:global([data-theme='light']) .skel {
		background: rgba(0, 0, 0, 0.07);
	}
	.skel-icon {
		width: 40px;
		height: 40px;
		border-radius: 12px;
	}
	.skel-lines {
		flex: 1 1 auto;
		min-width: 0;
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.skel-line-a {
		height: 12px;
		width: 55%;
	}
	.skel-line-b {
		height: 10px;
		width: 35%;
	}
	.skel-side {
		width: 70px;
		height: 14px;
	}
	@keyframes op-pulse {
		0%,
		100% {
			opacity: 0.5;
		}
		50% {
			opacity: 1;
		}
	}
	@media (max-width: 720px) {
		.op-body {
			grid-template-columns: 1fr;
		}
		.op-sidebar {
			position: static;
		}
	}
</style>
