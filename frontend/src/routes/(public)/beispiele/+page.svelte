<script lang="ts">
	import { m } from '$lib/paraglide/messages.js';
	import PageGlow from '$lib/components/PageGlow.svelte';
	import PageHeading from '$lib/components/PageHeading.svelte';
	import { localizeHref } from '$lib/paraglide/runtime';
	import { Play } from '@lucide/svelte';
	import { categoryColor, categoryIcon, entryTypeLabel } from '$lib/categories';
	import { SHOWCASE } from '$lib/marketing/showcase';

	// nameKey/descKey sind dynamische Message-Keys (analog categoryLabel() in
	// $lib/categories) – `m` ist zur Laufzeit ein Objekt aus Funktionen, das
	// TypeScript nur mit einem Cast dynamisch indizieren lässt.
	const messages = m as unknown as Record<string, () => string>;
</script>

<svelte:head>
	<title>{m.c5_page_title()} · Gestura Index</title>
	<meta name="description" content={m.c5_meta_desc()} />
</svelte:head>

<div class="c5">
	<PageGlow />

	<div class="intro">
		<PageHeading lead={m.c5_h1_lead()} accent={m.c5_h1_accent()} />
		<p>{m.c5_intro()}</p>
	</div>

	<div class="showcase-grid">
		{#each SHOWCASE as card (card.nameKey)}
			{@const Icon = categoryIcon(card.category)}
			<article class="showcase-card">
				<div class="preview">
					<span class="gesture-chip">{card.gesture}</span>
					<div class="play-circle" aria-hidden="true"><Play size={22} fill="currentColor" /></div>
					<span class="preview-label">{m.c5_preview_label()}</span>
				</div>
				<div class="showcase-body">
					<div class="showcase-head">
						<span class="icon-tile" style={`--icon-color:${categoryColor(card.category)}`}>
							<Icon size={20} />
						</span>
						<div class="showcase-title">
							<strong class="showcase-name">{messages[card.nameKey]()}</strong>
							<span
								class="type-badge"
								class:menu={card.type === 'menu'}
								class:engine={card.type === 'engine'}
							>
								{entryTypeLabel(card.type)}
							</span>
						</div>
					</div>
					<p class="showcase-desc">{messages[card.descKey]()}</p>
					<div class="showcase-cta">
						<a class="btn btn-primary btn-sm" href={localizeHref('/') + '#install'}
							>{m.c5_cta_install()}</a
						>
						<a class="cta-outline" href={localizeHref('/index')}>{m.c5_cta_index()}</a>
					</div>
				</div>
			</article>
		{/each}
	</div>
</div>

<style>
	.c5 {
		position: relative;
		display: flex;
		flex-direction: column;
		gap: 30px;
	}
	/* Der Inhalt liegt über der Verlaufs-Ebene von PageGlow. */
	.c5 > :global(:not(.page-glow):not(.page-stars)) {
		position: relative;
		z-index: 1;
	}

	/* Kopf wie auf den anderen v2-Seiten: Überschrift links, Einleitung als
	   zweite Spalte daneben – über die volle Shell-Breite wäre der Absatz sonst
	   rund 150 Zeichen breit. */
	.intro {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 20px 48px;
		align-items: center;
	}
	.intro p {
		margin: 0;
		font-size: 16.5px;
		line-height: 1.7;
		color: var(--text-secondary);
	}

	@media (max-width: 900px) {
		.intro {
			grid-template-columns: 1fr;
			align-items: start;
		}
	}

	/* ---- 2×2-Showcase-Grid (Screenshot 1n) ---- */
	.showcase-grid {
		display: grid;
		grid-template-columns: repeat(2, 1fr);
		gap: 20px;
	}
	/* Getönte Karte im v2-Ton statt der flachen globalen .card. */
	.showcase-card {
		padding: 0;
		overflow: hidden;
		display: flex;
		flex-direction: column;
		border-radius: 20px;
		border: 1px solid var(--page-hairline);
		background: linear-gradient(
			160deg,
			oklch(from var(--accent-color) l c h / 7%),
			var(--page-card-end) 70%
		);
	}

	/* Animierte-Vorschau-Platzhalter: 210px Gradient-Fläche mit Gesten-Chip
	   oben links und zentriertem Play-Kreis + Label (README §C5 / 1n). Echte
	   Screenshots/GIFs ersetzen dies später (bewusster Platzhalter). */
	.preview {
		position: relative;
		height: 210px;
		flex-shrink: 0;
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		gap: 10px;
		background: linear-gradient(
			160deg,
			color-mix(in srgb, var(--accent-color) 14%, var(--bg-tertiary)) 0%,
			var(--bg-tertiary) 65%
		);
	}
	.gesture-chip {
		position: absolute;
		top: 14px;
		left: 14px;
		font-family: var(--font-mono);
		font-size: 12px;
		font-weight: 600;
		color: var(--accent-color);
		background: var(--accent-tint, oklch(from var(--accent-color) l c h / 14%));
		border-radius: 999px;
		padding: 4px 11px;
	}
	.play-circle {
		width: 52px;
		height: 52px;
		border-radius: 50%;
		display: flex;
		align-items: center;
		justify-content: center;
		color: var(--text-muted);
		background: oklch(from var(--text-primary) l c h / 8%);
	}
	.preview-label {
		font-size: 12px;
		color: var(--text-muted);
		text-align: center;
		max-width: 70%;
	}

	.showcase-body {
		display: flex;
		flex-direction: column;
		gap: 10px;
		padding: 20px 22px 22px;
	}
	.showcase-head {
		display: flex;
		align-items: center;
		gap: 12px;
	}
	.showcase-title {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
	}
	.showcase-name {
		font-size: 15px;
		font-weight: 700;
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
	.showcase-desc {
		margin: 0;
		font-size: 13.5px;
		line-height: 1.6;
		color: var(--text-secondary);
	}
	.showcase-cta {
		display: flex;
		gap: 10px;
		margin-top: 4px;
		flex-wrap: wrap;
	}

	/* Outline-Variante der CTA (»Zum Index«) – im globalen Button-Set gibt es
	   keine Accent-Outline (nur primary/secondary/ghost/danger/dashed), daher
	   lokal analog zu .btn/.btn-sm nachgebaut. */
	.cta-outline {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		gap: 5px;
		padding: 6px 12px;
		font-size: 12.5px;
		font-weight: 600;
		border-radius: 6px;
		border: 1.5px solid var(--accent-color);
		color: var(--accent-color);
		background: transparent;
		text-decoration: none;
		white-space: nowrap;
	}
	.cta-outline:hover {
		background: color-mix(in srgb, var(--accent-color) 8%, transparent);
	}

	@media (max-width: 780px) {
		.showcase-grid {
			grid-template-columns: 1fr;
		}
	}
</style>
