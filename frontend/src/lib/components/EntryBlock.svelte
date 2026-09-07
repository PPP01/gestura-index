<script lang="ts">
	import {
		getEntry,
		downloadVersion,
		listReviews,
		type EntryListItem,
		type EntryDetail,
		type ReviewItem
	} from '$lib/api';
	import { resolveLocalized, entryLanguages } from '$lib/localized';
	import { categoryLabel, categoryIcon, categoryColor, entryTypeLabel, languageLabel } from '$lib/categories';
	import { relativeTime } from '$lib/relative-time';
	import { basket } from '$lib/basket.svelte';
	import { getLocale } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import Badge from './Badge.svelte';
	import MenuPreview from './MenuPreview.svelte';
	import EntryContents from './EntryContents.svelte';
	import StarRating from './StarRating.svelte';
	import Spinner from './Spinner.svelte';
	import { ChevronDown, Check, Plus, TriangleAlert, Download, Image, List } from '@lucide/svelte';

	let { entry, open = false }: { entry: EntryListItem; open?: boolean } = $props();

	// svelte-ignore state_referenced_locally – Anfangswert wird bewusst nur einmal
	// übernommen; das Aufklappen bei einem späteren Wechsel von `open` übernimmt
	// der $effect weiter unten (der zusätzlich auch das Nachladen der Details
	// anstößt – siehe loadDetail()).
	let expanded = $state(open);
	let detail = $state<EntryDetail | null>(null);
	let detailLoading = $state(false);
	let detailError = $state(false);
	/**
	 * Roh-Payload der aktuellen Version – Datenquelle für BEIDE Detail-Ansichten:
	 * die Live-Vorschau (nur Menüs) und den Inhalt-Kasten (Menüs wie Engines).
	 * Ein Request, zwei Auswertungen.
	 */
	let payload = $state<unknown>(null);
	let payloadLoading = $state(false);

	let reviewsOpen = $state(false);
	let reviews = $state<ReviewItem[] | null>(null);
	let reviewsLoading = $state(false);
	let reviewsError = $state(false);

	const locale = $derived(getLocale());
	const displayName = $derived(resolveLocalized(entry.name, locale) || entry.formatId);
	const displayDesc = $derived(resolveLocalized(entry.description, locale));
	const langs = $derived(entryLanguages(entry.name));
	const selected = $derived(basket.has(entry.formatId));
	const typeLabel = $derived(entryTypeLabel(entry.type));
	const primaryCategory = $derived(entry.categories[0] ?? 'other');
	const PrimaryIcon = $derived(categoryIcon(primaryCategory));

	/**
	 * Lädt die Detaildaten genau einmal (Guard gegen Doppel-Fetch bei parallelen
	 * Auslösern: manueller Toggle UND der open-Effekt unten können beide
	 * loadDetail() aufrufen).
	 */
	async function loadDetail() {
		if (detail !== null || detailLoading) return;
		detailLoading = true;
		detailError = false;
		try {
			detail = await getEntry(entry.formatId);
		} catch {
			detailError = true;
		} finally {
			detailLoading = false;
		}
		if (detail) loadPayload(detail);
	}

	/**
	 * Holt den Roh-Payload der aktuellen Version.
	 *
	 * Bewusst der Versions-Endpunkt und NICHT `pingInstall` – das Ansehen von
	 * Details ist keine Installation und darf den Zähler nicht bewegen. Ein
	 * Fehler bleibt still: die Karte zeigt dann den Screenshot-Platzhalter und
	 * keinen Inhalt-Kasten, statt eine Fehlermeldung an eine Stelle zu setzen,
	 * an der es nichts zu tun gibt.
	 */
	async function loadPayload(loaded: EntryDetail) {
		if (!loaded.currentVersion) return;
		if (payload !== null || payloadLoading) return;
		payloadLoading = true;
		try {
			payload = await downloadVersion(loaded.formatId, loaded.currentVersion);
		} catch {
			payload = null;
		} finally {
			payloadLoading = false;
		}
	}

	function toggleExpand() {
		expanded = !expanded;
		if (expanded) loadDetail();
	}

	function onRowKeydown(e: KeyboardEvent) {
		if (e.key === 'Enter' || e.key === ' ') {
			e.preventDefault();
			toggleExpand();
		}
	}

	async function toggleReviews() {
		reviewsOpen = !reviewsOpen;
		if (reviewsOpen && reviews === null && !reviewsLoading) {
			reviewsLoading = true;
			reviewsError = false;
			try {
				const res = await listReviews(entry.formatId, 1);
				reviews = res.items;
			} catch {
				reviewsError = true;
			} finally {
				reviewsLoading = false;
			}
		}
	}

	// Fix (T4-Review, Important): open=true darf nicht nur optisch aufklappen,
	// sondern muss auch die Details laden – unabhängig davon, ob open schon beim
	// Mount true ist oder erst später gesetzt wird. Der alte Guard
	// `if (open && !expanded)` griff beim Mount nie, weil `expanded` bereits als
	// `open` initialisiert war (leeres Detail-Panel trotz aufgeklappter Zeile).
	// loadDetail() selbst guardet gegen Doppel-Fetch.
	$effect(() => {
		if (open) {
			expanded = true;
			loadDetail();
		}
	});
</script>

<article class="block" class:selected>
	<div class="block-row" class:open={expanded}>
		<button
			class="block-select"
			onclick={() => basket.toggle(entry.formatId)}
			aria-pressed={selected}
			aria-label={selected ? m.block_remove() : m.block_add()}
			title={selected ? m.block_remove() : m.block_add()}
		>
			{#if selected}<Check size={12} />{:else}<Plus size={12} />{/if}
		</button>
		<div
			class="block-clickable"
			role="button"
			tabindex="0"
			aria-expanded={expanded}
			aria-label={m.block_details()}
			onclick={toggleExpand}
			onkeydown={onRowKeydown}
		>
			<span class="icon-tile" style={`--icon-color:${categoryColor(primaryCategory)}`}>
				<PrimaryIcon size={20} />
			</span>
			<div class="block-body">
				<div class="block-head">
					<strong class="block-name">{displayName}</strong>
					<span
						class="type-badge"
						class:menu={entry.type === 'menu'}
						class:engine={entry.type === 'engine'}
					>
						{typeLabel}
					</span>
					{#if entry.deprecated}<Badge text={m.badge_deprecated()} variant="warning" />{/if}
				</div>
				{#if displayDesc}<p class="block-desc">{displayDesc}</p>{/if}
				<div class="block-tags">
					{#if entry.itemCount !== null}
						<span class="count-badge" title={m.block_items()}>
							<List size={11} />{entry.itemCount}
						</span>
					{/if}
					{#each entry.categories.slice(0, 3) as cat (cat)}
						<span class="cat-badge" style={`--cat-color:${categoryColor(cat)}`}
							>{categoryLabel(cat)}</span
						>
					{/each}
					{#each langs as lang (lang)}
						<span class="lang-badge">{languageLabel(lang)}</span>
					{/each}
				</div>
			</div>
			<div class="block-side">
				<StarRating average={entry.rating.average} count={entry.rating.count} />
				<span class="installs" title={m.installs()}><Download size={13} />{entry.installCount}</span
				>
			</div>
			<span class="block-chevron" aria-hidden="true">
				<ChevronDown size={16} class={expanded ? 'flip' : ''} />
			</span>
		</div>
	</div>

	{#if expanded}
		<div class="block-expand">
			{#if detailLoading}
				<Spinner />
			{:else if detailError}
				<p class="err">{m.block_details_error()}</p>
			{:else if detail}
				<div class="expand-grid">
					<div class="expand-col">
						{#if payload}
							<div class="expand-box">
								<h4 class="expand-heading">{m.block_contents()}</h4>
								<EntryContents {payload} type={entry.type} />
							</div>
						{/if}
						<div class="expand-box">
							<h4 class="expand-heading">{m.block_versions()}</h4>
							<ul class="versions">
								{#each detail.versions as v (v.semver)}
									<li class="version-row">
										<div class="version-top">
											<span class="semver-chip">{v.semver}</span>
											<span class="muted">{relativeTime(v.submittedAt, locale)}</span>
											{#if v.hasTransformCode}
												<Badge text={m.badge_transform()} variant="warning" icon={TriangleAlert} />
											{/if}
										</div>
										{#if v.changelog}<p class="changelog">{v.changelog}</p>{/if}
									</li>
								{/each}
							</ul>
						</div>
						{#if detail.domains.length}
							<div class="expand-box">
								<h4 class="expand-heading">{m.block_domains()}</h4>
								<div class="domain-chips">
									{#each detail.domains as d (d)}<span class="domain-chip">{d}</span>{/each}
								</div>
							</div>
						{/if}
					</div>
					<div class="expand-shot">
						{#if detail.screenshotUrl}
							<img
								src={detail.screenshotUrl}
								alt={m.block_screenshot_alt({ name: displayName })}
								loading="lazy"
							/>
						{/if}
						{#if entry.type === 'menu' && payload}
							<MenuPreview {payload} name={displayName} />
						{:else if !detail.screenshotUrl && !payloadLoading}
							<div class="shot-placeholder">
								<Image size={28} />
								<span>{m.block_screenshot_placeholder()}</span>
							</div>
						{/if}
					</div>
				</div>

				<div class="reviews-section">
					<button
						class="reviews-toggle"
						onclick={toggleReviews}
						aria-expanded={reviewsOpen}
						aria-label={m.block_show_reviews()}
					>
						<span>{m.block_reviews_heading({ count: entry.rating.count })}</span>
						<ChevronDown size={14} class={reviewsOpen ? 'flip' : ''} />
					</button>
					{#if reviewsOpen}
						{#if reviewsLoading}
							<Spinner />
						{:else if reviewsError}
							<p class="muted">{m.block_reviews_error()}</p>
						{:else if reviews && reviews.length}
							<ul class="reviews">
								{#each reviews as r, i (i)}
									<li class="review-row">
										<StarRating average={r.stars} count={1} />
										<span class="muted"
											>{m.block_review_anon()} · {relativeTime(r.createdAt, locale)}</span
										>
										{#if r.comment}<p class="review-comment">{r.comment}</p>{/if}
									</li>
								{/each}
							</ul>
						{:else}
							<p class="muted">{m.block_reviews_empty()}</p>
						{/if}
					{/if}
				</div>
			{/if}
		</div>
	{/if}
</article>

<style>
	.block {
		position: relative;
		border-radius: 10px;
		overflow: hidden;
	}
	.block.selected {
		outline: 2px solid var(--accent-color);
	}
	.block-row {
		display: flex;
		align-items: flex-start;
		gap: 12px;
		padding: 11px 16px;
		border-top: 1px solid var(--border-color);
		flex-wrap: wrap;
	}
	.block-row.open {
		background: color-mix(in srgb, var(--accent-color) 5%, transparent);
	}
	.block-select {
		flex: 0 0 auto;
		width: 20px;
		height: 20px;
		margin-top: 2px;
		border-radius: 6px;
		border: 1.5px solid var(--border-color);
		background: transparent;
		color: var(--text-muted);
		cursor: pointer;
		display: flex;
		align-items: center;
		justify-content: center;
	}
	.block.selected .block-select {
		background: var(--accent-color);
		color: #fff;
		border-color: transparent;
	}
	.block-clickable {
		/* flex-basis 0 statt auto: der Umbruch-Algorithmus von flex-wrap misst die
		   HYPOTHETISCHE Hauptgröße (bei `auto` = Inhaltsbreite), nicht min-width.
		   Mit `auto` rutscht die Zeile in schmalen Containern (Teaser-Panel der
		   Startseite) komplett unter den Auswahl-Toggle. */
		flex: 1 1 0;
		min-width: 0;
		display: flex;
		align-items: flex-start;
		gap: 12px;
		flex-wrap: wrap;
		cursor: pointer;
	}
	.block-clickable:focus-visible {
		outline: none;
		box-shadow: 0 0 0 3px var(--input-focus-border-color);
		border-radius: 10px;
	}
	.block-body {
		/* flex-basis = min-width (nicht `auto`), damit Bewertung/Zähler und Chevron
		   in schmalen Containern auf derselben Zeile bleiben statt darunter zu
		   rutschen – gleicher Umbruch-Effekt wie bei .block-clickable oben. Erst
		   wenn wirklich zu wenig Platz ist (mobil), bricht die rechte Spalte um. */
		flex: 1 1 200px;
		min-width: 200px;
		display: flex;
		flex-direction: column;
		gap: 3px;
	}
	.block-head {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
	}
	.block-name {
		font-size: 14px;
		font-weight: 600;
		color: var(--text-primary);
	}
	.type-badge {
		font-size: 10.5px;
		font-weight: 600;
		padding: 1.5px 7px;
		border-radius: 999px;
	}
	.type-badge.menu {
		background: var(--accent-tint);
		color: var(--accent-color);
	}
	.type-badge.engine {
		background: oklch(from var(--success-color) l c h / 16%);
		color: var(--success-color);
	}
	.block-desc {
		font-size: 12.5px;
		color: var(--text-secondary);
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		max-width: 520px;
		margin: 0;
	}
	.block-tags {
		display: flex;
		flex-wrap: wrap;
		gap: 5px;
		margin-top: 2px;
	}
	.cat-badge {
		font-size: 10.5px;
		font-weight: 500;
		padding: 2px 8px;
		border-radius: 999px;
		color: var(--cat-color);
		background: oklch(from var(--cat-color) l c h / 12%);
	}
	/* Zahl statt »6 Einträge«: hält den Chip kurz und umgeht die Pluralform –
	   dasselbe Muster wie der Install-Zähler rechts. */
	.count-badge {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		font-size: 10.5px;
		font-weight: 600;
		padding: 2px 7px 2px 6px;
		border-radius: 999px;
		border: 1px solid var(--border-color);
		color: var(--text-secondary);
	}
	.lang-badge {
		font-size: 10px;
		font-weight: 600;
		letter-spacing: 0.05em;
		text-transform: uppercase;
		padding: 2px 7px;
		border-radius: 999px;
		border: 1px solid var(--border-color);
		color: var(--text-muted);
	}
	.block-side {
		display: flex;
		flex-direction: column;
		align-items: flex-end;
		gap: 4px;
		margin-left: auto;
		flex: 0 0 auto;
	}
	.installs {
		display: inline-flex;
		align-items: center;
		gap: 5px;
		font-size: 11.5px;
		color: var(--text-muted);
		font-variant-numeric: tabular-nums;
	}
	.block-chevron {
		flex: 0 0 auto;
		color: var(--text-muted);
		display: flex;
		align-items: center;
		margin-top: 2px;
	}
	.block-row.open .block-chevron {
		color: var(--accent-color);
	}
	:global(.block-chevron .flip),
	:global(.reviews-toggle .flip) {
		transform: rotate(180deg);
	}
	.block-expand {
		border-top: 1px solid var(--border-color);
		padding: 14px 16px 16px 68px;
	}
	.expand-grid {
		display: grid;
		grid-template-columns: 1fr 300px;
		gap: 16px;
	}
	@media (max-width: 640px) {
		.block-expand {
			padding-left: 16px;
		}
		.expand-grid {
			grid-template-columns: 1fr;
		}
	}
	.expand-col {
		display: flex;
		flex-direction: column;
		gap: 12px;
		min-width: 0;
	}
	.expand-box {
		background: var(--bg-tertiary);
		border-radius: 12px;
		padding: 12px 14px;
	}
	.expand-heading {
		font-size: 10.5px;
		font-weight: 600;
		letter-spacing: 0.08em;
		text-transform: uppercase;
		color: var(--text-muted);
		margin: 0 0 8px;
	}
	.versions {
		list-style: none;
		padding: 0;
		margin: 0;
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.version-row + .version-row {
		border-top: 1px solid var(--border-color);
		padding-top: 8px;
	}
	.version-top {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
	}
	.semver-chip {
		font-family: var(--font-mono);
		font-size: 12.5px;
		color: var(--text-primary);
	}
	.changelog {
		color: var(--text-secondary);
		font-size: 0.9em;
		margin: 2px 0 0;
	}
	.domain-chips {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}
	.domain-chip {
		font-family: var(--font-mono);
		font-size: 11.5px;
		padding: 2px 8px;
		border-radius: 999px;
		background: var(--bg-secondary);
		border: 1px solid var(--border-color);
		color: var(--text-secondary);
	}
	.expand-shot {
		display: flex;
		flex-direction: column;
		gap: 12px;
		min-width: 0;
	}
	.expand-shot img {
		width: 100%;
		max-width: 300px;
		height: 180px;
		object-fit: cover;
		border-radius: 12px;
		border: 1px solid var(--border-color);
	}
	.shot-placeholder {
		width: 100%;
		max-width: 300px;
		height: 180px;
		border-radius: 12px;
		border: 1.5px dashed var(--border-color);
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 8px;
		color: var(--text-muted);
		font-size: 12px;
		text-align: center;
		padding: 12px;
	}
	.reviews-section {
		margin-top: 16px;
	}
	.reviews-toggle {
		background: transparent;
		border: none;
		color: var(--accent-color);
		cursor: pointer;
		display: inline-flex;
		align-items: center;
		gap: 6px;
		font-size: 12.5px;
		font-weight: 600;
		padding: 0;
	}
	.reviews {
		list-style: none;
		padding: 0;
		margin: 10px 0 0;
		display: flex;
		flex-direction: column;
		gap: 10px;
	}
	.review-row {
		border-top: 1px solid var(--border-color);
		padding-top: 10px;
		display: flex;
		flex-direction: column;
		gap: 4px;
	}
	.review-comment {
		color: var(--text-primary);
		font-size: 0.9em;
		margin: 0;
	}
	.muted {
		color: var(--text-muted);
		font-size: 0.85em;
	}
	.err {
		color: var(--danger-color);
	}
</style>
