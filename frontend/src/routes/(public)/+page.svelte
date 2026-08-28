<script lang="ts">
	import { onMount } from 'svelte';
	import { m } from '$lib/paraglide/messages.js';
	import { localizeHref } from '$lib/paraglide/runtime';
	import {
		Move,
		Hand,
		MousePointerClick,
		SquareDashedMousePointer,
		Search,
		Menu,
		Shield
	} from '@lucide/svelte';
	import StoreBadges from '$lib/components/StoreBadges.svelte';
	import EntryBlock from '$lib/components/EntryBlock.svelte';
	import { listEntries, type EntryListItem } from '$lib/api';
	import heroHand from '$lib/assets/logo/icon128.png';

	// Feature-Grid (3×2): Icon-Kachel-Farben aus dem Design-Token-Set
	// (docs/design_handoff_gestura_index/README.md, Kategoriefarben).
	const features = [
		{
			Icon: Move,
			color: '#5b9cf6',
			title: () => m.c1_feat_gestures_title(),
			body: () => m.c1_feat_gestures_body()
		},
		{
			Icon: Hand,
			color: '#8b5cf6',
			title: () => m.c1_feat_superdrag_title(),
			body: () => m.c1_feat_superdrag_body()
		},
		{
			Icon: MousePointerClick,
			color: '#ec4899',
			title: () => m.c1_feat_wheel_title(),
			body: () => m.c1_feat_wheel_body()
		},
		{
			Icon: SquareDashedMousePointer,
			color: '#2bb8a8',
			title: () => m.c1_feat_area_title(),
			body: () => m.c1_feat_area_body()
		},
		{
			Icon: Search,
			color: '#4caf50',
			title: () => m.c1_feat_engines_title(),
			body: () => m.c1_feat_engines_body()
		},
		{
			Icon: Menu,
			color: '#e6a117',
			title: () => m.c1_feat_menus_title(),
			body: () => m.c1_feat_menus_body()
		}
	];

	// Index-Teaser: die Seite ist prerendert, daher lädt der Teaser erst im
	// Browser nach (progressive enhancement). onMount läuft während des
	// Prerenderings gar nicht – kein Fetch-Versuch zur Build-Zeit.
	let teaser = $state<EntryListItem[] | null>(null);
	let teaserTotal = $state<number | null>(null);
	let teaserFailed = $state(false);

	onMount(async () => {
		try {
			const res = await listEntries({ page: 1, perPage: 3, sort: 'newest' });
			teaser = res.items;
			teaserTotal = res.total;
		} catch {
			teaserFailed = true;
		}
	});
</script>

<svelte:head>
	<title>{m.c1_page_title()}</title>
	<meta name="description" content={m.c1_meta_desc()} />
</svelte:head>

<section class="hero">
	<div class="hero-copy">
		<span class="trust-pill"><Shield size={13} />{m.c1_trust_pill()}</span>
		<h1 class="hero-h1">
			{m.c1_hero_h1_a()}
			<span class="accent">{m.c1_hero_h1_b()}</span>
		</h1>
		<p class="hero-sub">{m.c1_hero_sub()}</p>
		<div id="install"><StoreBadges /></div>
		<a class="hero-discover" href={localizeHref('/index')}>{m.c1_hero_discover()}</a>
	</div>

	<div class="hero-mock" aria-hidden="true">
		<div class="mock-bar">
			<span></span><span></span><span></span>
			<div class="mock-url"></div>
		</div>
		<div class="mock-canvas-wrap">
			<svg viewBox="0 0 420 260" class="mock-canvas" role="presentation">
				<circle cx="120" cy="70" r="6" fill="var(--accent-color)" />
				<path
					d="M120 70 L120 190 L330 190"
					fill="none"
					stroke="var(--accent-color)"
					stroke-width="5"
					stroke-linecap="round"
					stroke-linejoin="round"
				/>
				<path d="M330 190 l-14 -8 v16 z" fill="var(--accent-color)" />
			</svg>
			<div class="mock-chip">{m.c1_mock_action()}</div>
			<img class="mock-logo" src={heroHand} alt="" width="48" height="48" />
		</div>
	</div>
</section>

<section class="features">
	<h2 class="features-heading">{m.c1_features_heading()}</h2>
	<div class="feature-grid">
		{#each features as f (f.title())}
			<div class="card feature-tile">
				<span class="icon-tile" style={`--icon-color:${f.color}`}>
					<f.Icon size={20} />
				</span>
				<h3>{f.title()}</h3>
				<p>{f.body()}</p>
			</div>
		{/each}
	</div>
</section>

<section class="card trust-strip">
	<div class="trust-points">
		<span>{m.c1_trust_anon()}</span>
		<span>{m.c1_trust_noemail()}</span>
		<span>{m.c1_trust_notrack()}</span>
		<span>{m.c1_trust_os()}</span>
	</div>
	<span class="trust-browsers">{m.c1_trust_browsers()}</span>
</section>

{#if !teaserFailed}
	<section class="teaser">
		<div class="teaser-head">
			<h2>{m.c1_teaser_heading()}</h2>
			<a class="teaser-all" href={localizeHref('/index')}>
				{teaserTotal !== null ? m.c1_teaser_all_count({ total: teaserTotal }) : m.c1_teaser_all()}
			</a>
		</div>
		<div class="card teaser-list">
			{#if teaser === null}
				{#each Array.from({ length: 3 }) as _, i (i)}
					<div class="skeleton-row" style={`animation-delay:${i * 0.15}s`}>
						<span class="skel skel-icon"></span>
						<span class="skel-lines">
							<span class="skel skel-line-a"></span>
							<span class="skel skel-line-b"></span>
						</span>
						<span class="skel skel-side"></span>
					</div>
				{/each}
			{:else}
				{#each teaser as entry (entry.formatId)}
					<EntryBlock {entry} />
				{/each}
			{/if}
		</div>
	</section>
{/if}

<style>
	/* ---- Hero ---- */
	.hero {
		display: grid;
		grid-template-columns: 1.1fr 0.9fr;
		gap: 32px;
		align-items: center;
		padding-block: 8px 32px;
	}
	.hero-copy {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 16px;
	}
	.trust-pill {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		padding: 4px 12px;
		border-radius: 999px;
		font-size: 12px;
		font-weight: 600;
		color: var(--success-color);
		background: oklch(from var(--success-color) l c h / 14%);
	}
	.hero-h1 {
		font-size: 42px;
		font-weight: 700;
		line-height: 1.15;
		margin: 0;
	}
	.hero-h1 .accent {
		color: var(--accent-color);
	}
	.hero-sub {
		font-size: 16px;
		line-height: 1.6;
		color: var(--text-secondary);
		margin: 0;
		max-width: 46ch;
	}
	.hero-discover {
		font-size: 13px;
		font-weight: 600;
		color: var(--accent-color);
		text-decoration: none;
	}
	.hero-discover:hover {
		text-decoration: underline;
	}

	/* ---- Browser-Mock (reines Inline-SVG, kein Fremd-Asset) ---- */
	.hero-mock {
		background: var(--panel-bg);
		border: 1px solid var(--border-color);
		border-radius: 20px;
		box-shadow: var(--section-shadow) 0px 6px 24px 0px;
		overflow: hidden;
	}
	.mock-bar {
		display: flex;
		align-items: center;
		gap: 6px;
		padding: 12px 16px;
		border-bottom: 1px solid var(--border-color);
	}
	.mock-bar span {
		width: 8px;
		height: 8px;
		border-radius: 50%;
		background: var(--text-muted);
		opacity: 0.5;
		flex-shrink: 0;
	}
	.mock-url {
		flex: 1 1 auto;
		height: 16px;
		border-radius: 8px;
		background: var(--bg-tertiary);
		margin-left: 8px;
	}
	.mock-canvas-wrap {
		position: relative;
		background: var(--bg-primary);
	}
	.mock-canvas {
		display: block;
		width: 100%;
		height: auto;
	}
	.mock-chip {
		position: absolute;
		top: 16px;
		right: 16px;
		font-family: var(--font-mono);
		font-size: 11.5px;
		color: var(--text-primary);
		background: var(--bg-tertiary);
		border: 1px solid var(--border-color);
		border-radius: 10px;
		padding: 6px 10px;
	}
	.mock-logo {
		position: absolute;
		left: 16px;
		bottom: 16px;
		border-radius: 12px;
		background: var(--bg-tertiary);
		border: 1px solid var(--border-color);
		padding: 4px;
	}

	/* ---- Feature-Grid (3×2) ---- */
	.features {
		padding-block: 8px 32px;
	}
	.features-heading {
		font-size: 20px;
		font-weight: 700;
		margin: 0 0 16px;
	}
	.feature-grid {
		display: grid;
		grid-template-columns: repeat(3, 1fr);
		gap: 16px;
	}
	.feature-tile {
		margin-bottom: 0;
		display: flex;
		flex-direction: column;
		gap: 10px;
	}
	.feature-tile h3 {
		font-size: 14.5px;
		font-weight: 700;
		margin: 0;
	}
	.feature-tile p {
		font-size: 12.5px;
		color: var(--text-secondary);
		line-height: 1.5;
		margin: 0;
	}

	/* ---- Vertrauens-Streifen ---- */
	.trust-strip {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		background: oklch(from var(--success-color) l c h / 8%);
		border-color: oklch(from var(--success-color) l c h / 25%);
	}
	.trust-points {
		display: flex;
		flex-wrap: wrap;
		gap: 24px;
		font-size: 13px;
		font-weight: 600;
		color: var(--success-color);
	}
	.trust-browsers {
		font-size: 12.5px;
		color: var(--text-muted);
	}

	/* ---- Index-Teaser ---- */
	.teaser {
		padding-block: 8px 8px;
	}
	.teaser-head {
		display: flex;
		align-items: baseline;
		justify-content: space-between;
		gap: 12px;
		margin-bottom: 16px;
	}
	.teaser-head h2 {
		font-size: 20px;
		font-weight: 700;
		margin: 0;
	}
	.teaser-all {
		font-size: 13px;
		font-weight: 600;
		color: var(--accent-color);
		text-decoration: none;
		white-space: nowrap;
	}
	.teaser-all:hover {
		text-decoration: underline;
	}
	.teaser-list {
		padding: 0;
		overflow: hidden;
	}
	.skeleton-row {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 11px 16px;
		border-top: 1px solid var(--border-color);
		animation: c1-pulse 1.5s ease-in-out infinite;
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
	@keyframes c1-pulse {
		0%,
		100% {
			opacity: 0.5;
		}
		50% {
			opacity: 1;
		}
	}

	@media (max-width: 880px) {
		.feature-grid {
			grid-template-columns: repeat(2, 1fr);
		}
	}
	@media (max-width: 720px) {
		.hero {
			grid-template-columns: 1fr;
		}
		.hero-h1 {
			font-size: 32px;
		}
	}
	@media (max-width: 560px) {
		.feature-grid {
			grid-template-columns: 1fr;
		}
		.trust-strip {
			flex-direction: column;
			align-items: flex-start;
		}
	}
</style>
