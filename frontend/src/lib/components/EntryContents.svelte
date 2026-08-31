<script lang="ts">
	import { getLocale } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import { toContentRows, toEngineDetails, payloadDescription } from '$lib/entry-contents';
	import type { EntryType } from '$lib/api';
	import { TriangleAlert } from '@lucide/svelte';

	let { payload, type }: { payload: unknown; type: EntryType } = $props();

	const locale = $derived(getLocale());
	const description = $derived(payloadDescription(payload, locale));
	const rows = $derived(type === 'menu' ? toContentRows(payload, locale) : []);
	const engine = $derived(type === 'engine' ? toEngineDetails(payload, locale) : null);

	let codeOpen = $state(false);
</script>

<!--
	Beantwortet die Frage vor dem Import: was steckt drin? Ziel-URLs im
	Klartext (bewusst NICHT klickbar – eingereichte Fremd-URLs), die volle
	Beschreibung (in der Kartenzeile ist sie auf eine Zeile gekürzt) und bei
	Suchmaschinen die URL-Vorlage samt Verhalten und mitgeliefertem Code.
-->
{#if description}
	<p class="desc">{description}</p>
{/if}

{#if type === 'menu'}
	{#if rows.length}
		<ul class="rows">
			{#each rows as row, i (i)}
				{#if row.kind === 'separator'}
					<li class="row-sep" role="separator"></li>
				{:else}
					<li class="row" class:hidden={row.hidden}>
						<span class="row-label">{row.label}</span>
						{#if row.hidden}
							<span class="row-note">{m.contents_hidden_hint()}</span>
						{/if}
						{#if row.target}
							<span class="row-target">{row.target}</span>
						{/if}
					</li>
				{/if}
			{/each}
		</ul>
	{:else}
		<p class="muted">{m.contents_empty()}</p>
	{/if}
{:else if engine}
	<h5 class="sub">{m.engine_url_heading()}</h5>
	<p class="url">
		{#each engine.segments as seg, i (i)}
			{#if seg.kind === 'query'}<span class="url-query">{m.engine_query_token()}</span
				>{:else}{seg.value}{/if}
		{/each}
	</p>

	{#if engine.flags.length}
		<h5 class="sub">{m.engine_flags_heading()}</h5>
		<div class="chips">
			{#each engine.flags as flag (flag)}<span class="chip">{flag}</span>{/each}
		</div>
	{/if}

	{#if engine.transformCode}
		<div class="code-warn">
			<TriangleAlert size={14} />
			<span>{m.engine_transform_warning()}</span>
		</div>
		<button class="code-toggle" onclick={() => (codeOpen = !codeOpen)} aria-expanded={codeOpen}>
			{codeOpen ? m.engine_transform_heading() : m.engine_transform_show()}
		</button>
		{#if codeOpen}
			<pre class="code">{engine.transformCode}</pre>
		{/if}
	{/if}
{/if}

<style>
	.desc {
		margin: 0 0 10px;
		font-size: 12.5px;
		line-height: 1.55;
		color: var(--text-secondary);
		white-space: pre-wrap;
	}
	.sub {
		margin: 10px 0 5px;
		font-size: 10.5px;
		font-weight: 600;
		letter-spacing: 0.08em;
		text-transform: uppercase;
		color: var(--text-muted);
	}
	.sub:first-child {
		margin-top: 0;
	}

	/* Menü-Inhalt */
	.rows {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		/* Ein Menü darf 100 Items haben – die Karte soll davon nicht zerfallen. */
		max-height: 320px;
		overflow-y: auto;
		overscroll-behavior: contain;
	}
	.row {
		display: grid;
		gap: 1px 8px;
		grid-template-columns: auto 1fr;
		padding: 6px 0;
		border-top: 1px solid var(--border-color);
	}
	.row:first-child {
		border-top: none;
		padding-top: 0;
	}
	.row.hidden .row-label {
		color: var(--text-muted);
		text-decoration: line-through;
	}
	.row-label {
		font-size: 12.5px;
		color: var(--text-primary);
		min-width: 0;
		overflow-wrap: anywhere;
	}
	.row-note {
		font-size: 10.5px;
		color: var(--warning-color);
		align-self: center;
	}
	.row-target {
		grid-column: 1 / -1;
		font-family: var(--font-mono);
		font-size: 11px;
		color: var(--text-muted);
		overflow-wrap: anywhere;
	}
	.row-sep {
		height: 1px;
		margin: 5px 0;
		background: var(--border-color-solid);
	}

	/* Engine-Details */
	.url {
		margin: 0;
		font-family: var(--font-mono);
		font-size: 11.5px;
		color: var(--text-secondary);
		overflow-wrap: anywhere;
		line-height: 1.6;
	}
	.url-query {
		background: var(--accent-tint);
		color: var(--accent-color);
		border-radius: 5px;
		padding: 1px 5px;
		margin: 0 1px;
		font-family: inherit;
	}
	.chips {
		display: flex;
		flex-wrap: wrap;
		gap: 5px;
	}
	.chip {
		font-size: 11px;
		padding: 2px 8px;
		border-radius: 999px;
		background: var(--badge-bg);
		color: var(--badge-text);
	}
	.code-warn {
		display: flex;
		align-items: flex-start;
		gap: 6px;
		margin-top: 12px;
		font-size: 11.5px;
		line-height: 1.5;
		color: var(--warning-color);
	}
	.code-toggle {
		margin-top: 8px;
		padding: 0;
		background: transparent;
		border: none;
		color: var(--accent-color);
		font-size: 12px;
		font-weight: 600;
		cursor: pointer;
	}
	.code {
		margin: 8px 0 0;
		padding: 10px;
		border-radius: 8px;
		background: var(--input-bg);
		border: 1px solid var(--border-color);
		font-family: var(--font-mono);
		font-size: 11.5px;
		line-height: 1.5;
		color: var(--text-secondary);
		max-height: 240px;
		overflow: auto;
		white-space: pre-wrap;
		overflow-wrap: anywhere;
	}
	.muted {
		margin: 0;
		font-size: 12px;
		color: var(--text-muted);
	}
</style>
