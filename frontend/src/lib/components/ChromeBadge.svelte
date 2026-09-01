<script lang="ts">
	import logo from '$lib/assets/stores/chrome-webstore-logo.svg';

	/*
	 * EIGENES Chrome-Badge – bewusst nicht Googles Badge-Grafik.
	 *
	 * Grund: Googles Badge trägt seinen Text fest in Google Grey 700 und braucht
	 * deshalb eine sehr helle Fläche; auf dem dunklen Hero war es der hellste
	 * Fleck der Seite. Eine dunkle Variante liefert Google nicht, und
	 * »Don't modify the badge in any way, other than resizing« verbietet eine
	 * eigene (developer.chrome.com/docs/webstore/branding).
	 *
	 * Also ein eigener Button in der Formensprache der Seite: Fläche in Google
	 * Grey 900 (Chromes eigener Dark-UI-Ton), Typografie der Website, eigener
	 * Wortlaut – und das Chrome-Web-Store-Logo als Marke, damit die Reihe neben
	 * den Edge- und Firefox-Badges als Satz lesbar bleibt. Das Logo liegt LOKAL
	 * (assets/stores/chrome-webstore-logo.svg, Quelle fonts.gstatic.com); es wird
	 * NICHT von Google nachgeladen – die Startseite stellt keine Fremd-Requests.
	 *
	 * Alle Maße hängen an --badge-height, damit die Reihe als Ganzes skaliert.
	 */
</script>

<!-- lang="en": Der Wortlaut ist bewusst englisch, damit die Reihe zu den
     englischen Edge- und Firefox-Grafiken passt. Auf einer deutschen Seite
     braucht dieser Einschub die Sprachauszeichnung, sonst liest ein
     Screenreader ihn mit deutscher Aussprache vor. -->
<span class="chrome-badge" lang="en">
	<img src={logo} alt="" />
	<span class="lines">
		<span class="top">Get it from the</span>
		<span class="store">Chrome Web Store</span>
	</span>
</span>

<style>
	/*
	 * Alle Proportionen sind am Edge-Badge ausgemessen (1178×312), damit die
	 * beiden nebeneinander als Satz wirken – umgerechnet auf --badge-height:
	 *   Radius        25/312 = 8,0 %   (3,8px bei 48px – deutlich kantiger als
	 *                                   es »Badge« vermuten lässt)
	 *   Logo          182/312 = 58,3 % (28px bei 48px)
	 *   linker Rand    65/312 = 20,8 % (10px bei 48px)
	 *   Textblock     211/312 = 67,6 % (32,5px bei 48px, beide Zeilen zusammen)
	 */
	.chrome-badge {
		box-sizing: border-box;
		height: var(--badge-height);
		display: inline-flex;
		align-items: center;
		gap: calc(var(--badge-height) * 0.15);
		padding-inline: calc(var(--badge-height) * 0.208);
		border-radius: calc(var(--badge-height) * 0.08);
		/* Dunkel: Google Grey 900 – Chromes eigener Dark-UI-Ton, dazu der helle
		   Hairline-Rand des Edge-Badges. */
		background: #202124;
		border: 1px solid rgba(210, 210, 210, 0.45);
		white-space: nowrap;
		user-select: none;
		/* Der Hero setzt text-align:center – ohne das hier stünden die beiden
		   Zeilen mittig statt linksbündig wie beim Edge-Badge. */
		text-align: left;
	}
	/*
	 * 74 % statt der 58,3 % des Edge-Logos: die Store-Grafik füllt ihre
	 * 192er-Zeichenfläche nur von y=20 bis y=172 (79 %). Bei gleicher Bildhöhe
	 * wäre die sichtbare Tasche also ein Fünftel kleiner als der Edge-Kreis.
	 * 0,583 / 0,79 = 0,738 – damit sind die SICHTBAREN Logos gleich groß.
	 */
	.chrome-badge img {
		height: calc(var(--badge-height) * 0.738);
		width: auto;
		display: block;
	}
	.lines {
		display: flex;
		flex-direction: column;
		justify-content: center;
		line-height: 1.15;
	}
	.top {
		font-size: calc(var(--badge-height) * 0.235);
		font-weight: 400;
		color: rgba(248, 248, 249, 0.88);
	}
	.store {
		font-size: calc(var(--badge-height) * 0.357);
		font-weight: 600;
		color: #f8f8f9;
		letter-spacing: -0.005em;
	}

	/*
	 * Helles Thema: helle Fläche wie bei Googles gerahmtem Original-Badge
	 * (#f8f8f8 + Rahmen #dadce0), hier als leichter Verlauf von Weiß nach
	 * Google Grey 100 – das gibt der Karte auf der ohnehin hellen Seite eine
	 * Kante, ohne hart zu wirken. Schriftfarben ebenfalls Googles Grautöne.
	 */
	:global([data-theme='light']) .chrome-badge {
		background: linear-gradient(180deg, #ffffff, #f1f3f4);
		border-color: #dadce0;
	}
	:global([data-theme='light']) .top {
		color: #5f6368;
	}
	:global([data-theme='light']) .store {
		color: #202124;
	}
</style>
