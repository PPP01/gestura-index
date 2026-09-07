<script lang="ts">
	import { m } from '$lib/paraglide/messages.js';
	import PageGlow from '$lib/components/PageGlow.svelte';
	import PageHeading from '$lib/components/PageHeading.svelte';
	import { TriangleAlert, Check } from '@lucide/svelte';
	/*
	 * Helles Thema: die FREISTEHENDE Verlaufs-Hand aus dem Logo-Paket
	 * (exchange/…Logo.zip, hand-only/hand-gradient.png) – keine Kachel.
	 *
	 * Aufbereitet als 137×141: Das Original wiegt 365 KB und stünde damit für
	 * ein 36px-Logo in der Kopfleiste JEDER Seite; verkleinert sind es 28 KB,
	 * dieselbe Größenordnung wie die dunkle Kachel, und bis knapp 4-fache
	 * Pixeldichte noch scharf.
	 *
	 * Die krummen Maße sind gerechnet, nicht geraten: transparenter Rand
	 * entfernt, Zeichnung auf 139 px Höhe, dann 1 px Rand zurück. Damit füllt
	 * die Zeichnung 138/141 der Datei – genau das Verhältnis, mit dem die weiße
	 * Hand ihre dunkle Kachel füllt (126/128). Gerendert ergibt das 33,96×35,23
	 * gegen 34,31×35,44 px im dunklen Thema.
	 */
	import handLight from '$lib/assets/logo/logo-hand-gradient-137.png';
	import tileDark from '$lib/assets/logo/icon128-darktile.png';

	// Zehn Merkmal-Zeilen (Screenshot 1m / README §C4, Reihenfolge wie im Brief).
	// `id` ist ein stabiler #each-Key, unabhängig von der aktiven Sprache.
	// WICHTIG: Nur `gestura` trägt echte Werte (aus Brief/Screenshot). Die drei
	// Konkurrenz-Spalten (»Erw. A/B/C«) sind bewusste Platzhalter – dafür gibt
	// es hier bewusst KEIN Daten-Array, nur das fixe »–« im Markup (s.u.). Keine
	// Konkurrenz-Fakten oder Produktnamen ergänzen (nicht verhandelbare Projektregel).
	const rows: { id: string; label: () => string; gestura: () => string }[] = [
		{ id: 'firefox', label: () => m.c4_f_firefox(), gestura: () => m.c4_v_firefox() },
		{ id: 'notrack', label: () => m.c4_f_notrack(), gestura: () => m.c4_v_notrack() },
		{ id: 'anon', label: () => m.c4_f_anon(), gestura: () => m.c4_v_anon() },
		{ id: 'free', label: () => m.c4_f_free(), gestura: () => m.c4_v_free() },
		{ id: 'os', label: () => m.c4_f_os(), gestura: () => m.c4_v_os() },
		{ id: 'engines', label: () => m.c4_f_engines(), gestura: () => m.c4_v_engines() },
		{ id: 'menus', label: () => m.c4_f_menus(), gestura: () => m.c4_v_menus() },
		{ id: 'index', label: () => m.c4_f_index(), gestura: () => m.c4_v_index() },
		{ id: 'light', label: () => m.c4_f_light(), gestura: () => m.c4_v_light() },
		{ id: 'rockerwheel', label: () => m.c4_f_rockerwheel(), gestura: () => m.c4_v_rockerwheel() }
	];
</script>

<svelte:head>
	<title>{m.c4_page_title()} · Gestura Index</title>
	<meta name="description" content={m.c4_meta_desc()} />
</svelte:head>

<div class="c4">
	<PageGlow />

	<div class="intro">
		<PageHeading lead={m.c4_h1_lead()} accent={m.c4_h1_accent()} />
		<p>{m.c4_intro()}</p>
	</div>

	<div class="warning">
		<TriangleAlert size={19} />
		<p>{m.c4_warning()}</p>
	</div>

	<div class="matrix-wrap">
		<div class="matrix">
			<div class="matrix-head">
				<div class="cell head col-feature">{m.c4_col_feature()}</div>
				<div class="cell head col-gestura">
					<span class="logo-img">
						<img src={handLight} alt="" class="logo-light" width="20" height="20" />
						<img src={tileDark} alt="" class="logo-dark" width="20" height="20" />
					</span>
					<span class="brand">Gestura</span>
				</div>
				<div class="cell head col-placeholder">{m.c4_col_a()}</div>
				<div class="cell head col-placeholder">{m.c4_col_b()}</div>
				<div class="cell head col-placeholder">{m.c4_col_c()}</div>
			</div>

			{#each rows as row (row.id)}
				<div class="matrix-row">
					<div class="cell col-feature">{row.label()}</div>
					<div class="cell col-gestura">
						<Check size={15} class="check-icon" />
						<span>{row.gestura()}</span>
					</div>
					<div class="cell col-placeholder muted">–</div>
					<div class="cell col-placeholder muted">–</div>
					<div class="cell col-placeholder muted">–</div>
				</div>
			{/each}
		</div>
	</div>

	<p class="footnote">{m.c4_footnote()}</p>
</div>

<style>
	.c4 {
		position: relative;
		display: flex;
		flex-direction: column;
		gap: 30px;
	}
	/* Der Inhalt liegt über der Verlaufs-Ebene von PageGlow. */
	.c4 > :global(:not(.page-glow):not(.page-stars)) {
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

	/* ---- Warnbanner (warning-getönt, analog dem success-Banner in C2) ---- */
	.warning {
		border-radius: 16px;
		border: 1px solid oklch(from var(--warning-color) l c h / 30%);
		background: oklch(from var(--warning-color) l c h / 8%);
		padding: 16px 20px;
		display: flex;
		gap: 14px;
		align-items: flex-start;
		color: var(--warning-color);
	}
	.warning p {
		margin: 0;
		font-size: 13.5px;
		line-height: 1.65;
		color: var(--text-secondary);
	}

	/* ---- Vergleichsmatrix ---- */
	.matrix-wrap {
		/* Sicherheitsnetz für sehr schmale Viewports: die Matrix ist als feste
		   5-Spalten-Grid konzipiert (Screenshot 1m) und quetscht sich nicht
		   zusammen – bei Bedarf wird horizontal gescrollt statt umzubrechen. */
		overflow-x: auto;
	}
	.matrix {
		min-width: 640px;
		border: 1px solid var(--border-color);
		border-radius: 16px;
		overflow: hidden;
		background: var(--bg-secondary);
	}
	.matrix-head,
	.matrix-row {
		display: grid;
		grid-template-columns: 2.2fr 1.2fr 1fr 1fr 1fr;
	}
	.matrix-row + .matrix-row {
		border-top: 1px solid var(--border-color);
	}
	.cell {
		padding: 14px 18px;
		display: flex;
		align-items: center;
	}
	.cell.head {
		padding-block: 12px;
		font-size: 10.5px;
		font-weight: 700;
		letter-spacing: 0.08em;
		text-transform: uppercase;
		color: var(--text-muted);
	}
	.col-feature {
		font-weight: 600;
	}
	.matrix-head .col-feature {
		font-weight: 700;
	}
	.col-placeholder {
		justify-content: center;
		text-align: center;
	}
	.col-placeholder.muted {
		color: var(--text-muted);
	}

	/* Gestura-Spalte hervorgehoben: Tint-Hintergrund + Accent-Border links/rechts
	   über die volle Höhe der Matrix (jede Zelle trägt die Border; da Kopf- und
	   Datenzeilen ohne Lücke aneinanderstoßen, ergibt das eine durchgehende
	   Linie). Der Tint ist bewusst aus --accent-color abgeleitet (oklch, 7 %
	   Alpha) statt der Dark-Literalfarbe aus dem Brief – so stimmt der Farbton
	   auch im Light-Theme (dort ist --accent-color #4285f4, nicht #5b9cf6). */
	.col-gestura {
		gap: 8px;
		background: oklch(from var(--accent-color) l c h / 7%);
		border-inline: 1px solid oklch(from var(--accent-color) l c h / 35%);
	}
	.matrix-head .col-gestura {
		justify-content: center;
		color: var(--accent-color);
	}
	.logo-img {
		width: 20px;
		height: 20px;
		border-radius: 6px;
		overflow: hidden;
		display: inline-flex;
		flex-shrink: 0;
	}
	/*
	 * Die freistehende Hand ist 137×141 – nicht quadratisch. Ohne `contain`
	 * würde sie auf das quadratische Kästchen gedehnt (rund 3 % zu breit).
	 * `contain` skaliert sie auf die Höhe des Kästchens und zentriert sie.
	 */
	.logo-light {
		object-fit: contain;
	}
	.logo-dark {
		display: none;
	}
	:global([data-theme='dark']) .logo-dark {
		display: inline;
	}
	:global([data-theme='dark']) .logo-light {
		display: none;
	}
	.brand {
		text-transform: uppercase;
	}
	.matrix-row .col-gestura {
		color: var(--success-color);
		font-size: 13px;
		align-items: center;
	}
	.matrix-row .col-gestura :global(.check-icon) {
		flex-shrink: 0;
	}

	.footnote {
		margin: 0;
		font-size: 13px;
		color: var(--text-muted);
	}
</style>
