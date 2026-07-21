<script lang="ts">
	import { m } from '$lib/paraglide/messages.js';
</script>

<svelte:head>
	<title>{m.imprint_title()} · Gestura Index</title>
	<meta name="description" content={m.imprint_meta_description()} />
</svelte:head>

<h1>{m.imprint_title()}</h1>

<section class="card">
	<p>{m.imprint_intro()}</p>
	<p class="note">{m.imprint_placeholder_note()}</p>
</section>

<section class="card">
	<h2>{m.imprint_operator_heading()}</h2>
	<dl class="imprint-fields">
		<div>
			<dt>{m.imprint_field_name()}</dt>
			<dd>{m.imprint_value_name()}</dd>
		</div>
		<div>
			<dt>{m.imprint_field_address()}</dt>
			<dd>{m.imprint_value_address()}</dd>
		</div>
		<div>
			<dt>{m.imprint_field_email()}</dt>
			<dd>{m.imprint_value_email()}</dd>
		</div>
	</dl>
</section>

<style>
	.note {
		color: var(--text-secondary);
	}

	.imprint-fields {
		display: grid;
		gap: 8px;
		margin: 0;
	}

	.imprint-fields div {
		display: flex;
		gap: 8px;
	}

	dt {
		font-weight: 600;
		min-width: 100px;
	}

	dd {
		margin: 0;
	}
</style>
