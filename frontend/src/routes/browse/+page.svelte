<script lang="ts">
	import { page } from '$app/state';
	import { goto } from '$app/navigation';
	import { listEntries, type EntryListResponse, type EntryQuery } from '$lib/api';
	import { parseQuery, toSearchParams, Sequence, debounce } from '$lib/browse-state';
	import { localizeHref } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import { CATEGORIES, categoryLabel } from '$lib/categories';
	import EntryCard from '$lib/components/EntryCard.svelte';
	import Spinner from '$lib/components/Spinner.svelte';
	import EmptyState from '$lib/components/EmptyState.svelte';
	import ErrorState from '$lib/components/ErrorState.svelte';
	import Pagination from '$lib/components/Pagination.svelte';

	const seq = new Sequence();
	let result = $state<EntryListResponse | null>(null);
	let loading = $state(false);
	let error = $state<string | null>(null);

	// Freitext-Feld: eigener State, damit Tippen flüssig bleibt; Debounce schreibt in die URL.
	let qField = $state(parseQuery(page.url.searchParams).q ?? '');

	// Aktueller Filter direkt aus der URL abgeleitet (Single Source of Truth).
	const query = $derived<EntryQuery>(parseQuery(page.url.searchParams));

	async function load(q: EntryQuery) {
		const ticket = seq.next();
		loading = true;
		error = null;
		try {
			const res = await listEntries(q);
			if (seq.isCurrent(ticket)) result = res;
		} catch (e) {
			if (seq.isCurrent(ticket)) error = e instanceof Error ? e.message : String(e);
		} finally {
			if (seq.isCurrent(ticket)) loading = false;
		}
	}

	// Bei jeder URL-Änderung neu laden.
	$effect(() => {
		load(query);
	});

	function updateUrl(next: EntryQuery) {
		const qs = toSearchParams(next).toString();
		goto(localizeHref(`/browse${qs ? `?${qs}` : ''}`), { replaceState: true, keepFocus: true, noScroll: true });
	}

	const pushQ = debounce((value: string) => {
		updateUrl({ ...query, q: value || undefined, page: undefined });
	}, 250);

	function onQInput() {
		pushQ(qField);
	}
	function setFilter(patch: Partial<EntryQuery>) {
		updateUrl({ ...query, ...patch, page: undefined });
	}
	function setPage(p: number) {
		updateUrl({ ...query, page: p });
	}
</script>

<svelte:head>
	<title>{m.browse_title()} · Gestura Index</title>
</svelte:head>

<h1>{m.browse_title()}</h1>

<div class="filter-bar">
	<input
		type="search"
		bind:value={qField}
		oninput={onQInput}
		placeholder={m.search_placeholder()}
		aria-label={m.search_placeholder()}
	/>
	<select value={query.type ?? ''} onchange={(e) => setFilter({ type: (e.currentTarget.value || undefined) as EntryQuery['type'] })}>
		<option value="">{m.filter_type_all()}</option>
		<option value="menu">{m.type_menu()}</option>
		<option value="engine">{m.type_engine()}</option>
	</select>
	<select value={query.category ?? ''} onchange={(e) => setFilter({ category: e.currentTarget.value || undefined })}>
		<option value="">{m.filter_category_all()}</option>
		{#each CATEGORIES as cat}
			<option value={cat}>{categoryLabel(cat)}</option>
		{/each}
	</select>
	<input
		type="text"
		value={query.tag ?? ''}
		onchange={(e) => setFilter({ tag: e.currentTarget.value || undefined })}
		placeholder={m.filter_tag()}
		aria-label={m.filter_tag()}
	/>
	<input
		type="text"
		value={query.site ?? ''}
		onchange={(e) => setFilter({ site: e.currentTarget.value || undefined })}
		placeholder={m.filter_site()}
		aria-label={m.filter_site()}
	/>
	{#if loading}<Spinner />{/if}
</div>

{#if error}
	<ErrorState message={error} onRetry={() => load(query)} />
{:else if result && result.items.length === 0}
	<EmptyState title={m.state_empty_title()} hint={m.browse_empty_hint()} />
{:else if result}
	<p style="color:var(--text-secondary);">{m.results_count({ total: result.total })}</p>
	<div class="grid-cards">
		{#each result.items as entry (entry.formatId)}
			<EntryCard {entry} />
		{/each}
	</div>
	<Pagination page={result.page} perPage={result.perPage} total={result.total} onPage={setPage} />
{:else}
	<Spinner />
{/if}
