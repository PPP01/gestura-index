<script lang="ts">
	import {
		getEntry,
		listReviews,
		type EntryListItem,
		type EntryDetail,
		type ReviewItem
	} from '$lib/api';
	import { resolveLocalized, entryLanguages } from '$lib/localized';
	import { categoryLabel } from '$lib/categories';
	import { basket } from '$lib/basket.svelte';
	import { getLocale } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import Badge from './Badge.svelte';
	import StarRating from './StarRating.svelte';
	import Spinner from './Spinner.svelte';
	import { ChevronDown, Check, Plus, TriangleAlert } from '@lucide/svelte';

	let { entry, open = false }: { entry: EntryListItem; open?: boolean } = $props();

	// svelte-ignore state_referenced_locally -- Anfangswert bewusst nur einmal
	// übernommen; späteres Umschalten von `open` läuft über den $effect unten.
	let expanded = $state(open);
	let detail = $state<EntryDetail | null>(null);
	let detailLoading = $state(false);
	let detailError = $state<string | null>(null);

	let reviewsOpen = $state(false);
	let reviews = $state<ReviewItem[] | null>(null);
	let reviewsLoading = $state(false);
	let reviewsError = $state<string | null>(null);

	const locale = $derived(getLocale());
	const displayName = $derived(resolveLocalized(entry.name, locale) || entry.formatId);
	const displayDesc = $derived(resolveLocalized(entry.description, locale));
	const langs = $derived(entryLanguages(entry.name));
	const selected = $derived(basket.has(entry.formatId));
	const typeLabel = $derived(entry.type === 'menu' ? m.type_menu() : m.type_engine());

	async function toggleExpand() {
		expanded = !expanded;
		if (expanded && detail === null && !detailLoading) {
			detailLoading = true;
			detailError = null;
			try {
				detail = await getEntry(entry.formatId);
			} catch (e) {
				detailError = e instanceof Error ? e.message : String(e);
			} finally {
				detailLoading = false;
			}
		}
	}

	async function toggleReviews() {
		reviewsOpen = !reviewsOpen;
		if (reviewsOpen && reviews === null && !reviewsLoading) {
			reviewsLoading = true;
			reviewsError = null;
			try {
				const res = await listReviews(entry.formatId, 1);
				reviews = res.items;
			} catch (e) {
				reviewsError = e instanceof Error ? e.message : String(e);
			} finally {
				reviewsLoading = false;
			}
		}
	}

	// Auto-Expand, wenn open-Prop zur Laufzeit true wird (Highlight aus Task 7).
	$effect(() => {
		if (open && !expanded) toggleExpand();
	});
</script>

<article class="card block" class:selected>
	<div class="block-main">
		<button class="block-select" onclick={() => basket.toggle(entry.formatId)}
			aria-pressed={selected}
			aria-label={selected ? m.block_remove() : m.block_add()}
			title={selected ? m.block_remove() : m.block_add()}>
			{#if selected}<Check size={18} />{:else}<Plus size={18} />{/if}
		</button>
		<div class="block-body">
			<div class="block-head">
				<strong>{displayName}</strong>
				<Badge text={typeLabel} />
				{#if entry.deprecated}<Badge text={m.badge_deprecated()} variant="warning" />{/if}
			</div>
			{#if displayDesc}<p class="block-desc">{displayDesc}</p>{/if}
			<div class="block-tags">
				{#each entry.categories.slice(0, 3) as cat}<Badge text={categoryLabel(cat)} />{/each}
				{#each langs as lang}<Badge text={lang.toUpperCase()} />{/each}
			</div>
			<div class="block-foot">
				<StarRating average={entry.rating.average} count={entry.rating.count} />
				<span class="installs">{entry.installCount} {m.installs()}</span>
				<button class="block-details" onclick={toggleExpand} aria-expanded={expanded}>
					{m.block_details()} <ChevronDown size={14} class={expanded ? 'flip' : ''} />
				</button>
			</div>
		</div>
	</div>

	{#if expanded}
		<div class="block-expand">
			{#if detailLoading}
				<Spinner />
			{:else if detailError}
				<p class="err">{detailError}</p>
			{:else if detail}
				{#if detail.screenshotUrl}
					<img class="shot" src={detail.screenshotUrl} alt={m.block_screenshot_alt({ name: displayName })} loading="lazy" />
				{/if}
				{#if detail.domains.length}
					<p><strong>{m.block_domains()}:</strong> {detail.domains.join(', ')}</p>
				{/if}
				<h4>{m.block_versions()}</h4>
				<ul class="versions">
					{#each detail.versions as v (v.semver)}
						<li>
							<strong>{v.semver}</strong>
							{#if v.hasTransformCode}<Badge text={m.badge_transform()} variant="warning" icon={TriangleAlert} />{/if}
							<span class="muted">{new Date(v.submittedAt).toLocaleDateString(locale)}</span>
							{#if v.changelog}<div class="changelog">{v.changelog}</div>{/if}
						</li>
					{/each}
				</ul>

				<button class="block-details" onclick={toggleReviews} aria-expanded={reviewsOpen}>
					{m.block_show_reviews()} <ChevronDown size={14} class={reviewsOpen ? 'flip' : ''} />
				</button>
				{#if reviewsOpen}
					{#if reviewsLoading}
						<Spinner />
					{:else if reviewsError}
						<p class="err">{m.block_reviews_error()}</p>
					{:else if reviews && reviews.length}
						<ul class="reviews">
							{#each reviews as r, i (i)}
								<li>
									<StarRating average={r.stars} count={1} />
									{#if r.comment}<p>{r.comment}</p>{/if}
									<span class="muted">{new Date(r.createdAt).toLocaleDateString(locale)}</span>
								</li>
							{/each}
						</ul>
					{:else}
						<p class="muted">{m.block_reviews_empty()}</p>
					{/if}
				{/if}
			{/if}
		</div>
	{/if}
</article>

<style>
	.block {
		display: flex;
		flex-direction: column;
		gap: 10px;
	}
	.block.selected {
		outline: 2px solid var(--accent-color, #5b9cf6);
	}
	.block-main {
		display: flex;
		gap: 12px;
		align-items: flex-start;
	}
	.block-select {
		flex: 0 0 auto;
		width: 36px;
		height: 36px;
		border-radius: 10px;
		border: 1px solid var(--border-color);
		background: transparent;
		color: inherit;
		cursor: pointer;
		display: flex;
		align-items: center;
		justify-content: center;
	}
	.block.selected .block-select {
		background: var(--accent-color, #5b9cf6);
		color: #fff;
		border-color: transparent;
	}
	.block-body {
		flex: 1 1 auto;
		min-width: 0;
	}
	.block-head {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
	}
	.block-desc {
		color: var(--text-secondary);
		display: -webkit-box;
		-webkit-line-clamp: 2;
		line-clamp: 2;
		-webkit-box-orient: vertical;
		overflow: hidden;
		margin: 4px 0;
	}
	.block-tags {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}
	.block-foot {
		display: flex;
		align-items: center;
		gap: 12px;
		flex-wrap: wrap;
		margin-top: 6px;
	}
	.installs {
		color: var(--text-muted);
		font-size: 0.85em;
	}
	.block-details {
		margin-left: auto;
		background: transparent;
		border: none;
		color: var(--accent-color, #5b9cf6);
		cursor: pointer;
		display: inline-flex;
		align-items: center;
		gap: 4px;
	}
	:global(.block-details .flip) {
		transform: rotate(180deg);
	}
	.block-expand {
		border-top: 1px solid var(--border-color);
		padding-top: 10px;
	}
	.shot {
		max-width: 100%;
		border-radius: 12px;
		border: 1px solid var(--border-color);
	}
	.versions,
	.reviews {
		list-style: none;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.changelog {
		color: var(--text-secondary);
	}
	.muted {
		color: var(--text-muted);
		font-size: 0.85em;
	}
	.err {
		color: var(--danger-color, #e5484d);
	}
</style>
