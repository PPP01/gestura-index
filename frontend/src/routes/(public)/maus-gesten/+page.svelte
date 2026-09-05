<script lang="ts">
	import { m } from '$lib/paraglide/messages.js';
	import GestureDiagram from '$lib/components/GestureDiagram.svelte';
	import PageGlow from '$lib/components/PageGlow.svelte';
	import PageHeading from '$lib/components/PageHeading.svelte';

	// Acht Gesten-Karten (Screenshot 1l): sechs Pfeil-Gesten mit eigenem
	// Polylinien-Pfad (viewBox 0 0 150 80) + zwei Sonderfälle (Rocker/Wheel)
	// ohne Pfad-Prop. `id` ist ein stabiler #each-Key (unabhängig von der
	// aktiven Sprache – der übersetzte Label-Text taugt dafür nicht, sonst
	// würden bei einem Sprachwechsel alle Karten neu gemountet). Die
	// Mono-Kürzel sind größtenteils sprachneutrale Symbole (Pfeile, L/R für
	// die Maustasten) und daher Code-Konstanten, keine Message-Keys – einzige
	// Ausnahme: das Wheel-Kürzel enthält das deutsche Wort »Rad« und ist
	// daher ein eigener, übersetzter Key (`c3_wheel_kuerzel`).
	const cards: {
		id: string;
		kind: 'arrow' | 'rocker' | 'wheel';
		path?: string;
		label: () => string;
		kuerzel: () => string;
	}[] = [
		{ id: 'back', kind: 'arrow', path: 'M110 40 L40 40', label: () => m.c3_back(), kuerzel: () => '←' },
		{ id: 'forward', kind: 'arrow', path: 'M40 40 L110 40', label: () => m.c3_forward(), kuerzel: () => '→' },
		{ id: 'newtab', kind: 'arrow', path: 'M75 15 L75 65', label: () => m.c3_newtab(), kuerzel: () => '↓' },
		{
			id: 'closetab',
			kind: 'arrow',
			path: 'M60 20 L60 55 L105 55',
			label: () => m.c3_closetab(),
			kuerzel: () => '↓ →'
		},
		{ id: 'scrollup', kind: 'arrow', path: 'M75 65 L75 18', label: () => m.c3_scrollup(), kuerzel: () => '↑' },
		{
			id: 'reload',
			kind: 'arrow',
			path: 'M65 20 L65 58 L84 38',
			label: () => m.c3_reload(),
			kuerzel: () => '↓ ↑'
		},
		{ id: 'rocker', kind: 'rocker', label: () => m.c3_rocker(), kuerzel: () => 'L + R' },
		{ id: 'wheel', kind: 'wheel', label: () => m.c3_wheel(), kuerzel: () => m.c3_wheel_kuerzel() }
	];
</script>

<svelte:head>
	<title>{m.c3_page_title()} · Gestura Index</title>
	<meta name="description" content={m.c3_meta_desc()} />
</svelte:head>

<div class="c3">
	<PageGlow />

	<div class="intro">
		<PageHeading lead={m.c3_h1_lead()} accent={m.c3_h1_accent()} />
		<p>{m.c3_intro()}</p>
	</div>

	<div class="gesture-grid">
		{#each cards as c (c.id)}
			<div class="gesture-card">
				<GestureDiagram kind={c.kind} path={c.path} label={c.label()} />
				<span class="kuerzel" aria-hidden="true">{c.kuerzel()}</span>
				<span class="label">{c.label()}</span>
			</div>
		{/each}
	</div>

	<p class="footnote">{m.c3_footnote()}</p>
</div>

<style>
	.c3 {
		position: relative;
		display: flex;
		flex-direction: column;
		gap: 34px;
	}
	/* Der Inhalt liegt über der Verlaufs-Ebene von PageGlow. */
	.intro,
	.gesture-grid,
	.footnote {
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

	/* Eigene 4-Spalten-Klasse statt der globalen .grid-cards (auto-fill,
	   site.css) – analog C1s .feature-grid: hier sind exakt 4 feste Spalten
	   verlangt (README §C3), kein auto-fill-Zeilenumbruch. */
	.gesture-grid {
		display: grid;
		grid-template-columns: repeat(4, 1fr);
		gap: 16px;
	}
	/* Getönte Karte im v2-Ton. Alle acht tragen denselben Akzent-Tint statt je
	   einer eigenen Farbe: sie zeigen dieselbe Sache in acht Ausführungen, und
	   die Gestenspur der Erweiterung ist ebenfalls akzentfarben. */
	.gesture-card {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 10px;
		padding: 22px 16px 20px;
		text-align: center;
		border-radius: 20px;
		border: 1px solid var(--page-hairline);
		background: linear-gradient(
			160deg,
			oklch(from var(--accent-color) l c h / 8%),
			var(--page-card-end) 70%
		);
	}
	.kuerzel {
		font-family: var(--font-mono);
		font-size: 12.5px;
		color: var(--accent-color);
	}
	.label {
		font-size: 14px;
		font-weight: 700;
	}

	.footnote {
		margin: 0;
		font-size: 13px;
		color: var(--text-muted);
	}

	@media (max-width: 900px) {
		.intro {
			grid-template-columns: 1fr;
			align-items: start;
		}
		.gesture-grid {
			grid-template-columns: repeat(2, 1fr);
		}
	}
	@media (max-width: 520px) {
		.gesture-grid {
			grid-template-columns: 1fr;
		}
	}
</style>
