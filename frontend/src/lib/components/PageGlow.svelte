<script lang="ts">
	/*
	 * Die Atmosphäre der v2-Seiten: ein dezenter Verlauf oben mittig, zwei sehr
	 * schwache Seiten-Glows und ein feines Punktraster. Liegt hinter dem Inhalt
	 * und ist für Screenreader unsichtbar.
	 *
	 * Der aufrufende Container MUSS `position: relative` tragen, und der Inhalt
	 * daneben braucht `position: relative; z-index: 1` – sonst liegt die Ebene
	 * darüber.
	 *
	 * `variant` ist keine Geschmacksfrage, sondern eine Notwendigkeit: die Radien
	 * müssen INNERHALB der Fläche auslaufen. Auf der schmalen Lesespalte (900px)
	 * würde der breite Verlauf an der Kante abgeschnitten und stünde als
	 * sichtbares helles Rechteck hinter der Überschrift.
	 */
	let { variant = 'wide' }: { variant?: 'wide' | 'narrow' } = $props();
</script>

<div class="page-glow" class:narrow={variant === 'narrow'} aria-hidden="true"></div>
{#if variant === 'wide'}
	<div class="page-stars" aria-hidden="true"></div>
{/if}

<style>
	.page-glow,
	.page-stars {
		position: absolute;
		/* Greift ins Shell-Padding (24px seitlich, 28px oben), damit die Ebene
		   bündig an der Rahmenkante endet statt an der Inhaltskante. */
		inset: -28px -24px 0;
		pointer-events: none;
		z-index: 0;

		--page-glow-a: oklch(from var(--accent-color) l c h / 16%);
		--page-glow-b: rgba(139, 92, 246, 0.06);
		--page-glow-side: oklch(from var(--accent-color) l c h / 5%);
	}
	/*
	 * Light braucht höhere Alpha-Werte als Dark: auf hellem Grund liegt ein Glow
	 * DUNKLER als der Untergrund und hat nach unten kaum Kontrastspielraum.
	 * Abgestimmt ist auf gleiche wahrgenommene Intensität, nicht auf gleiche
	 * Zahlen.
	 */
	:global([data-theme='light']) .page-glow {
		--page-glow-a: oklch(from var(--accent-color) l c h / 20%);
		--page-glow-b: rgba(124, 79, 224, 0.09);
		--page-glow-side: oklch(from var(--accent-color) l c h / 8%);
	}

	.page-glow {
		background:
			radial-gradient(
				760px 380px at 50% -80px,
				var(--page-glow-a),
				var(--page-glow-b) 50%,
				transparent 72%
			),
			radial-gradient(420px 260px at 92% 44%, var(--page-glow-side), transparent 70%),
			radial-gradient(420px 260px at 8% 72%, var(--page-glow-side), transparent 70%);
	}
	/* Lesespalte: kleinere Radien, damit der Verlauf innerhalb der Fläche
	   ausläuft, und nur der obere Glow – für zwei Seiten-Glows ist kein Platz. */
	.page-glow.narrow {
		inset: -28px -24px auto;
		height: 460px;
		background: radial-gradient(
			540px 300px at 50% 0,
			var(--page-glow-a),
			var(--page-glow-b) 50%,
			transparent 72%
		);
	}

	/* Sternenrauschen nur im dunklen Thema – im hellen wäre es Schmutz. */
	.page-stars {
		background-image:
			radial-gradient(rgba(255, 255, 255, 0.5) 0.6px, transparent 0.6px),
			radial-gradient(rgba(255, 255, 255, 0.35) 0.5px, transparent 0.5px);
		background-size:
			190px 170px,
			120px 140px;
		opacity: 0.09;
	}
	:global([data-theme='light']) .page-stars {
		display: none;
	}
</style>
