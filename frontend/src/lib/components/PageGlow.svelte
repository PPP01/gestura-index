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
		/*
		 * Greift seitlich ins Shell-Padding (24px) und nach oben über das
		 * Inhalts-Padding (28px) HINAUS bis unter die Kopfleiste (--topbar-h).
		 * Andernfalls begänne der Verlauf an deren Unterkante und stünde dort als
		 * sichtbare waagerechte Kante; so liegt er darunter und scheint durch ihre
		 * ~5 % Deckkraft hindurch. Die Leiste trägt dafür einen höheren z-index,
		 * sonst verdeckte diese Ebene Logo und Navigation.
		 */
		inset: calc(-28px - var(--topbar-h, 65px)) -24px 0;
		pointer-events: none;
		z-index: 0;

		--page-glow-a: oklch(from var(--accent-color) l c h / 16%);
		--page-glow-b: oklch(from var(--page-violet) l c h / 6%);
		--page-glow-side: oklch(from var(--accent-color) l c h / 5%);
	}
	/*
	 * Light braucht höhere Alpha-Werte als Dark: auf hellem Grund liegt ein Glow
	 * DUNKLER als der Untergrund und hat nach unten kaum Kontrastspielraum.
	 * Abgestimmt ist auf gleiche wahrgenommene Intensität, nicht auf gleiche
	 * Zahlen.
	 */
	/*
	 * Deutlich höhere Werte als im dunklen Thema – und höher, als es für den
	 * Inhaltsbereich allein nötig wäre. Der Verlauf muss hier durch die
	 * Kopfleiste hindurch wirken, und die liegt mit 65 % Weiß darüber: von einem
	 * 20%-Verlauf (rund rgb(205,220,244) auf dem Seitengrund) bleiben danach
	 * rgb(237,243,251) – knapp 18 Punkte von reinem Weiß entfernt und für das
	 * Auge nicht mehr zu unterscheiden. Erst ab etwa einem Drittel Deckkraft
	 * trägt die Farbe durch die Leiste.
	 */
	:global([data-theme='light']) .page-glow {
		--page-glow-a: oklch(from var(--accent-color) l c h / 34%);
		--page-glow-b: oklch(from var(--page-violet) l c h / 20%);
		--page-glow-side: oklch(from var(--accent-color) l c h / 12%);
	}

	.page-glow {
		background:
			/*
			 * Das Zentrum sitzt auf Höhe der Kopfleiste, nicht darüber: die Ebene
			 * reicht seit --topbar-h weiter nach oben, und ein Zentrum oberhalb
			 * ihrer Kante läge dann so weit außerhalb, dass im Kopfbereich kaum
			 * noch Farbe ankommt. So strahlt der Verlauf von der Leiste aus nach
			 * unten – wie im Handoff, wo er ebenfalls hinter der Navigation
			 * beginnt.
			 */
			radial-gradient(
				760px 420px at 50% 40px,
				var(--page-glow-a),
				var(--page-glow-b) 50%,
				transparent 72%
			),
			radial-gradient(420px 260px at 92% 44%, var(--page-glow-side), transparent 70%),
			radial-gradient(420px 260px at 8% 72%, var(--page-glow-side), transparent 70%);
	}
	/*
	 * Lesespalte: kleinere Radien, damit der Verlauf innerhalb der Fläche
	 * ausläuft, und nur der obere Glow – für zwei Seiten-Glows ist kein Platz.
	 *
	 * Die Anhebung um --topbar-h muss hier WIEDERHOLT werden: `inset` ist eine
	 * Kurzschrift und setzt alle vier Kanten neu, überschreibt also auch den
	 * oberen Wert der Grundregel. Ohne sie begann der Verlauf exakt an der
	 * Unterkante der Kopfleiste, hinter der Leiste stand nur --bg-primary, und
	 * im hellen Thema sah die Leiste dadurch rein weiß aus – unabhängig davon,
	 * wie durchlässig --bg-secondary gestellt war.
	 *
	 * Das Zentrum liegt auf denselben 40px wie in der Grundregel – also INNERHALB
	 * der Kopfleiste. Gegenüber einem Zentrum an ihrer Unterkante ist das
	 * messbar fast gleichwertig (235,238,250 statt 234,238,250 im hellen Thema);
	 * gleich zu sein ist hier der eigentliche Wert: eine Variante, die nur
	 * andere RADIEN braucht, soll nicht nebenbei auch anders sitzen.
	 */
	.page-glow.narrow {
		inset: calc(-28px - var(--topbar-h, 65px)) -24px auto;
		height: calc(460px + var(--topbar-h, 65px));
		background: radial-gradient(
			540px 300px at 50% 40px,
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
