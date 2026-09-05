<script lang="ts">
	import { page } from '$app/state';
	import Header from '$lib/components/Header.svelte';
	import Footer from '$lib/components/Footer.svelte';
	import InstallBar from '$lib/components/InstallBar.svelte';

	let { children } = $props();

	// Der Onepager (Katalog unter /index) und ALLE Marketing-Seiten nutzen die
	// breite 1200px-Shell (Design-Token »Seite«, siehe
	// docs/design_handoff_gestura_index/README.md): C1 (/), C2 »Was ist
	// Gestura«, C3 »Was sind Maus-Gesten«, C4 »Gestura im Vergleich« und C5
	// »Beispiele«. C2 lief bis zum v2-Umbau als einzige davon in der schmalen
	// Spalte – das war eine Inkonsistenz, keine Absicht. Nur die reinen
	// Textseiten (docs, about, privacy, imprint) bleiben in der 900px-
	// Lesespalte; eine dritte Breite gibt es bewusst nicht.
	const wide = $derived(
		page.route.id === '/(public)/index' ||
			page.route.id === '/(public)' ||
			page.route.id === '/(public)/was-ist-gestura' ||
			page.route.id === '/(public)/maus-gesten' ||
			page.route.id === '/(public)/vergleich' ||
			page.route.id === '/(public)/beispiele'
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
		<div class="installbar">
			<div class="bar-inner"><InstallBar /></div>
		</div>
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

		/*
		 * Höhe der Kopfleiste. PageGlow zieht seine Ebene um diesen Betrag nach
		 * oben, damit der Verlauf unter der Leiste liegt statt an ihrer Unterkante
		 * zu beginnen. Ein Näherungswert genügt: der Verlauf ist weich, und ein
		 * paar Pixel Abweichung sind unsichtbar – anders als die harte Kante, die
		 * entsteht, wenn er erst unterhalb der Leiste ansetzt.
		 */
		--topbar-h: 65px;
	}
	@media (max-width: 720px) {
		.pub {
			--topbar-h: 56px;
		}
	}

	/*
	 * Abgesetzte Kopfleiste: eigener Hintergrund + Trennlinie.
	 *
	 * Sie liegt bewusst ÜBER der Verlaufs-Ebene der Seiten (PageGlow zieht sich
	 * um --topbar-h nach oben unter die Leiste). Ohne z-index läge der Glow
	 * darüber – er steht im DOM nach der Leiste – und würde Logo und Navigation
	 * überdecken.
	 *
	 * Der Hintergrund ist bewusst nur zu 55 % deckend, damit der Verlauf
	 * darunter deutlich erkennbar bleibt – die Navigation schwimmt fast auf ihm,
	 * wie im Ursprungsdesign. Nötig ist das vor allem im HELLEN Thema: dort ist
	 * --bg-secondary reines Weiß und würde alles verdecken, während es im
	 * dunklen ohnehin nur rund 5 % Deckkraft hat. Die Absetzung bleibt – sie
	 * trägt die Trennlinie und die verbleibende Aufhellung –, wird aber
	 * durchlässig statt undurchsichtig. Das Weichzeichnen dahinter hält die
	 * Navigation über dem Verlauf ruhig lesbar; ohne es würde der Text bei
	 * dieser Durchlässigkeit auf der Struktur darunter flimmern.
	 */
	.topbar {
		position: relative;
		z-index: 2;
		background: color-mix(in srgb, var(--bg-secondary) 55%, transparent);
		backdrop-filter: blur(10px);
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

	/* Install-Streifen zwischen Inhalt und Fuß: er übernimmt die Trennlinie nach
	   oben, der Footer behält seine eigene – zwei Bänder, zwei Kanten. */
	.installbar {
		border-top: 1px solid var(--border-color);
		margin-top: 24px;
	}
	.footbar {
		border-top: 1px solid var(--border-color);
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
