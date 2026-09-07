<script lang="ts">
	import { m } from '$lib/paraglide/messages.js';
	import PageGlow from '$lib/components/PageGlow.svelte';
	import PageHeading from '$lib/components/PageHeading.svelte';

	const menuExample = `{
  "gesturaMenu": 1,
  "id": "example.com.quick-links",
  "version": "1.0.0",
  "name": "Quick links",
  "patterns": ["*://example.com/*"],
  "items": [
    { "id": "back", "action": "back", "label": "Back" },
    { "id": "sep-1", "type": "separator" },
    {
      "id": "open-docs",
      "action": "openCustomUrl",
      "label": "Docs",
      "customUrl": "https://example.com/docs"
    }
  ]
}`;

	const engineExample = `{
  "gesturaEngine": 1,
  "id": "example.com.search",
  "version": "1.0.0",
  "name": "Example Search",
  "url": "https://example.com/search?q={searchTerms}"
}`;
</script>

<svelte:head>
	<title>{m.docs_title()} · Gestura Index</title>
	<meta name="description" content={m.docs_meta_description()} />
</svelte:head>

<div class="text-page">
	<PageGlow variant="narrow" />
	<PageHeading lead={m.docs_h1_lead()} accent={m.docs_h1_accent()} size={38} />

<section class="lead-section">
	<p>{m.docs_intro()}</p>
	<p class="note">{m.docs_optional_note()}</p>
</section>

<section class="tinted-card">
	<h2>{m.docs_menu_heading()}</h2>
	<p>{m.docs_menu_body()}</p>
</section>

<section class="tinted-card">
	<h2>{m.docs_engine_heading()}</h2>
	<p>{m.docs_engine_body()}</p>
</section>

<section class="tinted-card">
	<h2>{m.docs_fields_heading()}</h2>
	<p>{m.docs_fields_body()}</p>
</section>

<section class="tinted-card">
	<h2>{m.docs_rules_heading()}</h2>
	<p>{m.docs_rules_body()}</p>
</section>

<section class="tinted-card">
	<h2>{m.docs_example_heading()}</h2>
	<pre>{menuExample}</pre>
	<pre>{engineExample}</pre>
</section>

</div>

<style>
	/*
	 * Lesespalte mit getönten Abschnitten – die ältere Form der Textseiten.
	 * Stand bis September 2026 als .text-page global in pages.css, gebaut für
	 * drei Seiten; seit dem Rail-Umbau von Datenschutz und Impressum ist diese
	 * hier die einzige, die sie noch nutzt. Eine Seitenform für eine Seite
	 * gehört in die Seite.
	 *
	 * Die Kartenform selbst kommt aus .tinted-card (elements.css) und liegt im
	 * Markup, nicht hier: die Einleitung ist bewusst KEINE Karte, und das
	 * lässt sich am Abschnitt selbst besser ablesen als an einer Ausnahme im
	 * Stylesheet.
	 */
	.text-page {
		position: relative;
		display: flex;
		flex-direction: column;
		gap: 20px;
	}
	.text-page > :global(*:not(.page-glow):not(.page-stars)) {
		position: relative;
		z-index: 1;
	}
	.text-page section {
		--card-tint-alpha: 6%;
		padding: 20px 24px;
		display: flex;
		flex-direction: column;
		gap: 9px;
		margin: 0;
	}
	.text-page section :global(h2) {
		margin: 0;
		font-size: 17px;
		font-weight: 700;
	}
	.text-page section :global(p) {
		margin: 0;
		font-size: 13.5px;
		line-height: 1.7;
		color: var(--text-secondary);
	}
	/* Die Einleitung trägt keine Karte, nur mehr Schriftgröße. */
	.text-page section.lead-section {
		padding: 0 0 4px;
	}
	.text-page section.lead-section :global(p) {
		font-size: 15.5px;
	}

	.note {
		color: var(--text-secondary);
	}

	pre {
		background: var(--bg-tertiary);
		border: 1px solid var(--border-color);
		border-radius: 12px;
		padding: 14px 16px;
		overflow-x: auto;
		font-family: var(--font-mono);
		font-size: 0.9em;
		white-space: pre;
	}

	pre + pre {
		margin-top: 16px;
	}
</style>
