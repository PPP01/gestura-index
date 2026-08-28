<script lang="ts">
	import { m } from '$lib/paraglide/messages.js';
	import GestureDiagram from '$lib/components/GestureDiagram.svelte';

	// Acht Gesten-Karten (Screenshot 1l): sechs Pfeil-Gesten mit eigenem
	// Polylinien-Pfad (viewBox 0 0 150 80) + zwei Sonderfälle (Rocker/Wheel)
	// ohne Pfad-Prop. Die Mono-Kürzel sind sprachneutrale Symbole (Pfeile,
	// L/R für die Maustasten) und daher Code-Konstanten, keine Message-Keys –
	// einzige Ausnahme: das Wheel-Kürzel ersetzt das deutsche Wort »Rad« durch
	// das Auf-/Ab-Symbol, damit es ebenfalls sprachneutral bleibt.
	const cards: { kind: 'arrow' | 'rocker' | 'wheel'; path?: string; label: () => string; kuerzel: string }[] = [
		{ kind: 'arrow', path: 'M110 40 L40 40', label: () => m.c3_back(), kuerzel: '←' },
		{ kind: 'arrow', path: 'M40 40 L110 40', label: () => m.c3_forward(), kuerzel: '→' },
		{ kind: 'arrow', path: 'M75 15 L75 65', label: () => m.c3_newtab(), kuerzel: '↓' },
		{ kind: 'arrow', path: 'M60 20 L60 55 L105 55', label: () => m.c3_closetab(), kuerzel: '↓ →' },
		{ kind: 'arrow', path: 'M75 65 L75 18', label: () => m.c3_scrollup(), kuerzel: '↑' },
		{ kind: 'arrow', path: 'M65 20 L65 58 L84 38', label: () => m.c3_reload(), kuerzel: '↓ ↑' },
		{ kind: 'rocker', label: () => m.c3_rocker(), kuerzel: 'L + R' },
		{ kind: 'wheel', label: () => m.c3_wheel(), kuerzel: 'R + ↕' }
	];
</script>

<svelte:head>
	<title>{m.c3_page_title()} · Gestura Index</title>
	<meta name="description" content={m.c3_meta_desc()} />
</svelte:head>

<div class="c3">
	<div class="intro">
		<h1>{m.c3_page_title()}</h1>
		<p>{m.c3_intro()}</p>
	</div>

	<div class="gesture-grid">
		{#each cards as c (c.label())}
			<div class="card gesture-card">
				<GestureDiagram kind={c.kind} path={c.path} label={c.label()} />
				<span class="kuerzel">{c.kuerzel}</span>
				<span class="label">{c.label()}</span>
			</div>
		{/each}
	</div>

	<p class="footnote">{m.c3_footnote()}</p>
</div>

<style>
	.c3 {
		display: flex;
		flex-direction: column;
		gap: 30px;
	}

	.intro {
		display: flex;
		flex-direction: column;
		gap: 14px;
	}
	.intro h1 {
		margin: 0;
		font-size: 32px;
		font-weight: 700;
	}
	.intro p {
		margin: 0;
		max-width: 760px;
		font-size: 15.5px;
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
	.gesture-card {
		margin-bottom: 0;
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 10px;
		padding: 22px 16px 20px;
		text-align: center;
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
