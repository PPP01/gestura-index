<script lang="ts">
	import type { EntryListItem } from '$lib/api';
	import { localizeHref } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import { categoryLabel } from '$lib/categories';
	import Badge from './Badge.svelte';

	let { entry }: { entry: EntryListItem } = $props();
	const href = $derived(localizeHref(`/entry/${entry.formatId}`));
	const typeLabel = $derived(entry.type === 'menu' ? m.type_menu() : m.type_engine());
</script>

<a class="card entry-card" {href}>
	<div class="entry-head">
		<strong>{entry.name}</strong>
		<Badge text={typeLabel} />
	</div>
	{#if entry.description}<p class="entry-desc">{entry.description}</p>{/if}
	<div class="entry-cats">
		{#each entry.categories.slice(0, 3) as cat}
			<Badge text={categoryLabel(cat)} />
		{/each}
		{#if entry.deprecated}<Badge text={m.badge_deprecated()} variant="warning" />{/if}
	</div>
	<div class="entry-foot">
		<span>{entry.installCount}</span>
		<span>{m.installs()}</span>
	</div>
</a>

<style>
	.entry-card {
		display: flex;
		flex-direction: column;
		gap: 8px;
		text-decoration: none;
		color: inherit;
	}
	.entry-head {
		display: flex;
		justify-content: space-between;
		align-items: center;
		gap: 8px;
	}
	.entry-desc {
		color: var(--text-secondary);
		display: -webkit-box;
		-webkit-line-clamp: 2;
		line-clamp: 2;
		-webkit-box-orient: vertical;
		overflow: hidden;
	}
	.entry-cats {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}
	.entry-foot {
		display: flex;
		gap: 4px;
		color: var(--text-muted);
		font-size: 0.85em;
	}
</style>
