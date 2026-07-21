<script lang="ts">
	import { m } from '$lib/paraglide/messages.js';

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

<h1>{m.docs_title()}</h1>

<section class="card">
	<p>{m.docs_intro()}</p>
	<p class="note">{m.docs_optional_note()}</p>
</section>

<section class="card">
	<h2>{m.docs_menu_heading()}</h2>
	<p>{m.docs_menu_body()}</p>
</section>

<section class="card">
	<h2>{m.docs_engine_heading()}</h2>
	<p>{m.docs_engine_body()}</p>
</section>

<section class="card">
	<h2>{m.docs_fields_heading()}</h2>
	<p>{m.docs_fields_body()}</p>
</section>

<section class="card">
	<h2>{m.docs_rules_heading()}</h2>
	<p>{m.docs_rules_body()}</p>
</section>

<section class="card">
	<h2>{m.docs_example_heading()}</h2>
	<pre>{menuExample}</pre>
	<pre>{engineExample}</pre>
</section>

<style>
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
