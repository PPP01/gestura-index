<script lang="ts">
	import { getLocale } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import { toPreviewItems } from '$lib/menu-preview';

	let { payload, name }: { payload: unknown; name: string } = $props();

	const locale = $derived(getLocale());
	const items = $derived(toPreviewItems(payload, locale));
</script>

<!--
	Echte Vorschau des In-Page-Menüs – keine Bilddatei, sondern dieselbe
	Darstellung, die die Erweiterung auf der Website zeigt. Markup und CSS sind
	1:1 aus der Extension übernommen (js/context-menu.js für Liste/Items,
	ContentContextMenu.generateStyles() in js/content.js für den Rahmen).
	Bewusst NICHT interaktiv: es ist eine Vorschau, kein Menü – deshalb Liste
	statt role="menu" und keine Klick-Handler.
-->
{#if items.length}
	<figure class="preview-panel">
		<figcaption class="preview-label">{m.block_preview_caption()}</figcaption>
		<div class="preview-stage">
			<div class="fm-ctx-frame">
				<div class="fm-ctx-root">
					<ul class="fm-ctx-list" aria-label={m.block_preview_label({ name })}>
						{#each items as item, i (i)}
							{#if item.kind === 'separator'}
								<li class="fm-ctx-sep" role="separator"></li>
							{:else}
								<li class="fm-ctx-item" title={item.target ?? undefined}>
									<span class="fm-ctx-icon" aria-hidden="true">
										{#if item.iconSvg}
											<!--
												{@html} ist hier unbedenklich: iconSvg stammt AUSSCHLIESSLICH aus der
												Konstante MENU_ICONS (menu-icons.ts). Der Payload wählt über `item.icon`
												nur einen Schlüssel aus, er liefert nie Markup – menuIcon() gibt bei
												jedem unbekannten Namen null zurück (hasOwnProperty-Guard, also auch bei
												`toString` & Co.). Diese Invariante ist in menu-preview.test.ts
												festgenagelt; wer sie aufweicht, öffnet hier ein XSS.
											-->
											{@html item.iconSvg}
										{:else if item.monogram}
											<img src={item.monogram} alt="" draggable="false" />
										{/if}
									</span>
									<span class="fm-ctx-label">{item.label}</span>
								</li>
							{/if}
						{/each}
					</ul>
				</div>
			</div>
		</div>
	</figure>
{:else}
	<p class="preview-empty">{m.block_preview_empty()}</p>
{/if}

<style>
	/*
	 * Alle Maße und Farben stammen aus der Extension und werden hier NICHT
	 * weiterentwickelt. Einziger Unterschied zur Extension: dort ist Hell der
	 * Basisfall und Dunkel die Ausnahme – hier ist es wie überall auf der
	 * Website umgekehrt (:root = dunkel, [data-theme="light"] überschreibt),
	 * damit die Vorschau auch ohne JavaScript zum Seiten-Theme passt.
	 */
	/*
	 * Bühne + Chip-Label sind das Vorschau-Muster der Extension selbst
	 * (css-editor-page.js: .preview-panel/.preview-label/.preview-stage). Das
	 * Karo-Raster steht für die Website, über der das Menü schwebt – ohne diesen
	 * Untergrund verschwände der halbtransparente Rahmen auf der Karte.
	 */
	.preview-panel {
		position: relative;
		margin: 0;
		min-width: 0;
		max-width: 300px;
		overflow: hidden;
		border-radius: 12px;
		border: 1px solid var(--border-color);
	}
	.preview-label {
		position: absolute;
		top: 10px;
		inset-inline-start: 14px;
		font-size: 11px;
		font-weight: 600;
		letter-spacing: 0.8px;
		text-transform: uppercase;
		color: var(--text-muted);
		background: var(--bg-secondary);
		padding: 3px 8px;
		border-radius: 6px;
		border: 1px solid var(--section-border);
		z-index: 2;
		pointer-events: none;
	}
	.preview-stage {
		/*
		 * Der Untergrund steht für die WEBSITE, über der das Menü schwebt – nicht
		 * für die Karte. Deshalb im Dunkelmodus bewusst dunkler als die Karte:
		 * das Menü ist mit rgba(30,30,32,.95) ≈ #1d1d1f selbst schon dunkel und
		 * verschwände auf einem Untergrund in Karten-Helligkeit. Reale dunkle
		 * Seiten liegen ebenfalls tiefer (GitHub #0d1117, YouTube #0f0f0f), die
		 * dunkle Bühne ist also zugleich die realistischere.
		 * Im Hellmodus tragen die Token-Werte den Kontrast bereits – dort bleibt
		 * es beim Muster der Extension (css-editor-page.js).
		 */
		--stage-bg: #08080a;
		--stage-tile: rgba(255, 255, 255, 0.03);
		display: flex;
		align-items: safe center;
		justify-content: safe center;
		min-height: 180px;
		padding: 38px 12px 12px;
		background-color: var(--stage-bg);
		background-image:
			linear-gradient(45deg, var(--stage-tile) 25%, transparent 25%),
			linear-gradient(-45deg, var(--stage-tile) 25%, transparent 25%),
			linear-gradient(45deg, transparent 75%, var(--stage-tile) 75%),
			linear-gradient(-45deg, transparent 75%, var(--stage-tile) 75%);
		background-size: 18px 18px;
		background-position:
			0 0,
			0 9px,
			9px -9px,
			-9px 0;
		overflow: auto;
	}
	:global([data-theme='light']) .preview-stage {
		--stage-bg: var(--bg-primary);
		--stage-tile: var(--bg-secondary);
	}

	/* content.js → ContentContextMenu.generateStyles() */
	.fm-ctx-frame {
		box-shadow:
			0 2px 12px rgba(0, 0, 0, 0.12),
			0 0 0 0.5px rgba(0, 0, 0, 0.12);
		border-radius: 8px;
		backdrop-filter: blur(8px);
		background: rgba(30, 30, 32, 0.95);
		overflow: hidden;
		max-width: 100%;
	}
	@supports (corner-shape: superellipse(1.4)) {
		.fm-ctx-frame {
			corner-shape: superellipse(1.4);
			border-radius: calc(8px * 1.4);
		}
	}
	:global([data-theme='light']) .fm-ctx-frame {
		background: rgba(255, 255, 255, 0.92);
	}

	/* context-menu.js → FmContextMenu.styles */
	.fm-ctx-root {
		font-family: 'Segoe UI', sans-serif;
		font-size: 12.5px;
		line-height: 19px;
		color: #e5e5e7;
		display: flex;
		flex-direction: column;
		width: max-content;
		min-width: 160px;
		max-width: 340px;
		user-select: none;
		/* Nicht in der Extension: dort wächst das Menü frei, hier deckelt die
		   Karte die Höhe – lange Menüs scrollen statt die Karte zu sprengen. */
		max-height: 288px;
		overflow-y: auto;
		overscroll-behavior: contain;
	}
	:global([data-theme='light']) .fm-ctx-root {
		color: #1d1d1f;
	}
	.fm-ctx-list {
		list-style: none;
		margin: 0;
		padding: 4px 0;
		min-width: 0;
	}
	.fm-ctx-item {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 4px 12px;
		min-height: 16px;
		cursor: default;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}
	.fm-ctx-item:hover {
		background: rgba(255, 255, 255, 0.1);
	}
	:global([data-theme='light']) .fm-ctx-item:hover {
		background: rgba(0, 0, 0, 0.08);
	}
	.fm-ctx-icon {
		width: 16px;
		height: 16px;
		flex-shrink: 0;
		display: flex;
		align-items: center;
		justify-content: center;
	}
	.fm-ctx-icon img {
		width: 16px;
		height: 16px;
		object-fit: contain;
		border-radius: 3px;
	}
	.fm-ctx-icon :global(svg) {
		width: 16px;
		height: 16px;
		display: block;
	}
	.fm-ctx-label {
		flex: 1;
		overflow: hidden;
		text-overflow: ellipsis;
	}
	.fm-ctx-sep {
		height: 1px;
		margin: 4px 10px;
		background: rgba(255, 255, 255, 0.1);
	}
	:global([data-theme='light']) .fm-ctx-sep {
		background: rgba(0, 0, 0, 0.1);
	}

	.preview-empty {
		margin: 0;
		font-size: 12px;
		color: var(--text-muted);
	}
</style>
