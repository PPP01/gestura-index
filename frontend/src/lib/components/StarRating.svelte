<script lang="ts">
	import { Star } from '@lucide/svelte';
	import { getLocale } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';

	let { average, count }: { average: number | null; count: number } = $props();

	// Breite des goldenen Overlays = Anteil der Bewertung an 5 möglichen Sternen.
	const pct = $derived(average === null ? 0 : Math.max(0, Math.min(100, (average / 5) * 100)));
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
			<span class="stars-row stars-base">
				{#each [1, 2, 3, 4, 5] as i (i)}<Star size={14} fill="currentColor" />{/each}
			</span>
			<span class="stars-overlay" style={`width:${pct}%`}>
				<span class="stars-row stars-fill">
					{#each [1, 2, 3, 4, 5] as i (i)}<Star size={14} fill="currentColor" />{/each}
				</span>
			</span>
		</span>
		<span class="rating-value">{valueLabel}</span>
		<span class="rating-count">· {count}</span>
	</span>
{/if}

<style>
	.rating {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		font-size: 0.85em;
	}
	/* Basis-Sterne (muted) + darüber geclipptes Gold-Overlay = exakter Teilstern,
	   nicht nur gerundet auf ganze Sterne. */
	.stars {
		position: relative;
		display: inline-flex;
		line-height: 0;
	}
	.stars-row {
		display: inline-flex;
		gap: 1px;
	}
	.stars-base {
		color: var(--star-base);
	}
	.stars-overlay {
		position: absolute;
		inset: 0;
		overflow: hidden;
	}
	.stars-overlay .stars-row {
		color: var(--star-fill);
	}
	.rating-value {
		font-variant-numeric: tabular-nums;
	}
	.rating-count,
	.rating-none {
		color: var(--text-muted);
		font-size: 0.85em;
	}
</style>
