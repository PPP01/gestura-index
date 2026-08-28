<script lang="ts">
	import { Star } from '@lucide/svelte';
	import { getLocale } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';

	let { average, count }: { average: number | null; count: number } = $props();

	const rounded = $derived(average === null ? 0 : Math.round(average));
	const valueLabel = $derived(
		average === null
			? ''
			: average.toLocaleString(getLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 })
	);
</script>

{#if count === 0}
	<span class="rating-none">{m.rating_none()}</span>
{:else}
	<span class="rating" title={m.rating_count({ count })}>
		<span class="stars" aria-hidden="true">
			{#each [1, 2, 3, 4, 5] as i}
				<Star size={14} fill={i <= rounded ? 'currentColor' : 'none'} />
			{/each}
		</span>
		<span class="rating-value">{valueLabel}</span>
		<span class="rating-count">· {count}</span>
	</span>
{/if}

<style>
	.rating {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		font-size: 0.85em;
	}
	.stars {
		display: inline-flex;
		color: var(--accent-color, #5b9cf6);
	}
	.rating-count,
	.rating-none {
		color: var(--text-muted);
		font-size: 0.85em;
	}
</style>
