<script lang="ts">
	/*
	 * Der Seitenkopf der v2-Seiten: optionale Status-Pill, darunter eine
	 * Überschrift, deren zweiter Teil einen Blau-nach-Violett-Verlauf trägt.
	 *
	 * Lead und Akzent sind ZWEI Message-Keys statt eines gesplitteten Strings:
	 * welches Wort hervorgehoben wird, hängt an der Sprache (»Was ist Gestura?«
	 * / »What is Gestura?«), und ein Split über Wortgrenzen wäre in der nächsten
	 * Sprache falsch. Zusammengesetzt ergeben beide weiterhin exakt den
	 * Seitentitel – Tests, die darauf prüfen, bleiben gültig.
	 *
	 * `pill` bleibt leer, wo es keine belegbare Kurzaussage gibt: eine Pill zu
	 * erfinden, nur damit jede Seite eine hat, wäre erfundener Inhalt.
	 */
	// `lead` bleibt leer, wo der Titel aus einem einzigen Wort besteht
	// (»Datenschutz«, »Impressum«): dann trägt die ganze Überschrift den Verlauf,
	// statt ihn künstlich an einer Wortgrenze anzusetzen, die es nicht gibt.
	let {
		pill = '',
		lead = '',
		accent,
		size = 44
	}: { pill?: string; lead?: string; accent: string; size?: number } = $props();
</script>

<div class="page-heading">
	{#if pill}
		<span class="status-pill"><span class="dot"></span>{pill}</span>
	{/if}
	<!-- Das Trennzeichen gehört in den Ausdruck: als Template-Whitespace zwischen
	     {/if} und <span> wäre nicht garantiert, dass es erhalten bleibt, und der
	     zusammengesetzte Titel muss exakt dem Seitentitel entsprechen. -->
	<h1 style={`--h1-size:${size}px`}>{#if lead}{lead + ' '}{/if}<span class="grad">{accent}</span></h1>
</div>

<style>
	.page-heading {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 16px;
	}

	.status-pill {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		font-size: 12px;
		font-weight: 600;
		padding: 5px 14px;
		border-radius: 20px;
		border: 1px solid var(--border-color);
		background: var(--bg-secondary);
		color: var(--text-secondary);
		backdrop-filter: blur(6px);
	}
	.status-pill .dot {
		width: 7px;
		height: 7px;
		border-radius: 50%;
		background: var(--success-color);
		box-shadow: 0 0 8px oklch(from var(--success-color) l c h / 80%);
	}

	h1 {
		margin: 0;
		font-size: var(--h1-size);
		line-height: 1.12;
		font-weight: 700;
		letter-spacing: -0.02em;
		text-wrap: balance;
	}
	h1 .grad {
		background: linear-gradient(100deg, var(--accent-color), var(--page-violet, #8b5cf6) 70%);
		background-clip: text;
		-webkit-background-clip: text;
		color: transparent;
	}
	:global([data-theme='light']) h1 .grad {
		background: linear-gradient(100deg, var(--accent-color), var(--page-violet, #7c4fe0) 70%);
		background-clip: text;
		-webkit-background-clip: text;
		color: transparent;
	}

	@media (max-width: 640px) {
		h1 {
			font-size: min(var(--h1-size), 32px);
		}
	}
</style>
