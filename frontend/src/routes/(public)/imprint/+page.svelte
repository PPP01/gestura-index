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
			<dd>
				<address>
					{m.imprint_value_street()}<br />
					{m.imprint_value_city()}<br />
					{m.imprint_value_country()}
				</address>
			</dd>
		</div>
		<div>
			<dt>{m.imprint_field_email()}</dt>
			<dd><a href="mailto:{m.imprint_value_email()}">{m.imprint_value_email()}</a></dd>
		</div>
	</dl>
</section>

<section class="card">
	<h2>{m.imprint_note_heading()}</h2>
	<p class="note">{m.imprint_noncommercial()}</p>
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
		align-items: flex-start;
		gap: 8px;
	}

	dt {
		font-weight: 600;
		min-width: 100px;
	}

	dd {
		margin: 0;
	}

	address {
		font-style: normal;
	}
</style>
