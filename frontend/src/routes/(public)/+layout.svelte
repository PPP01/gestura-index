<script lang="ts">
	import { page } from '$app/state';
	import Header from '$lib/components/Header.svelte';
	import Footer from '$lib/components/Footer.svelte';

	let { children } = $props();

	// Der Onepager (Katalog unter /index), die C1-Marketing-Startseite (/) UND
	// C3 »Was sind Maus-Gesten« (4-Spalten-Gesten-Grid, RULING D) nutzen die
	// breite 1200px-Shell (Design-Token »Seite«, siehe
	// docs/design_handoff_gestura_index/README.md); reine Textseiten
	// (docs, about, privacy …, C2-»Textspalte«) bleiben in der schmalen
	// 900px-Lesespalte.
	const wide = $derived(
		page.route.id === '/(public)/index' ||
			page.route.id === '/(public)' ||
			page.route.id === '/(public)/maus-gesten'
	);
</script>

<div class="pub">
	<div class="frame">
		<div class="topbar">
			<div class="bar-inner"><Header /></div>
		</div>
		<main>
			<div class="content" class:wide>
				{@render children()}
			</div>
		</main>
		<div class="footbar">
			<div class="bar-inner"><Footer /></div>
		</div>
	</div>
</div>

<style>
	/*
	 * body trägt global padding:20px (gestura-common.css). Wir heben das für die
	 * öffentliche Hülle auf und steuern Abstände selbst.
	 *
	 * Schmal/mobil: randlose, abgesetzte Kopfleiste, Inhalt in zentrierter Shell.
	 * Ab 1280px: die ganze Hülle wird zu einem gerundeten, schwebenden Kasten,
	 * der sich über einen leicht abgedunkelten Hintergrund vom Rand absetzt
	 * (»gerahmt«, nie randlos – wie in der Design-Referenz).
	 */
	.pub {
		margin: -20px -20px 0;
	}

	/* Abgesetzte Kopfleiste: eigener Hintergrund + Trennlinie. */
	.topbar {
		background: var(--bg-secondary);
		border-bottom: 1px solid var(--border-color);
	}

	/* Zentrierte Shell für Kopf, Inhalt und Fuß – gleiche Kante, kein Versatz. */
	.bar-inner,
	.content {
		max-width: var(--page-max-width); /* 1200px */
		margin: 0 auto;
		padding-inline: 24px;
	}

	.content {
		padding-block: 28px;
	}

	/* Textseiten: schmale Lesespalte. */
	.content:not(.wide) {
		max-width: var(--content-max-width); /* 900px */
	}

	.footbar {
		border-top: 1px solid var(--border-color);
		margin-top: 24px;
	}

	/* ---- Ab 1280px: gerahmter, schwebender Kasten ---- */
	@media (min-width: 1280px) {
		.pub {
			/* Etwas dunkler als die Seitenfläche, damit der Kasten sich abhebt
			   (theme-sicher aus --bg-primary abgeleitet). */
			background: color-mix(in srgb, var(--bg-primary) 90%, #000);
			padding: 28px;
			min-height: 100vh;
			box-sizing: border-box;
		}
		.frame {
			max-width: 1200px;
			margin: 0 auto;
			background: var(--bg-primary);
			border: 1px solid var(--border-color);
			border-radius: 20px;
			box-shadow: 0 12px 40px rgba(0, 0, 0, 0.22);
		}
		/* Ecken einzeln runden (kein overflow:hidden am Rahmen – das würde die
		   sticky-Filter-Sidebar des Onepagers brechen). */
		.topbar {
			border-radius: 20px 20px 0 0;
		}
		.footbar {
			border-radius: 0 0 20px 20px;
			margin-top: 24px;
		}
		/* Im Rahmen übernimmt der Rahmen die Zentrierung; Leisten/Inhalt laufen
		   bis an die Rahmenkante (Textseiten bleiben schmal, im Rahmen zentriert). */
		.bar-inner,
		.content.wide {
			max-width: none;
			margin: 0;
		}
	}
</style>
