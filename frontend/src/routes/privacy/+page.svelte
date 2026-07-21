<script lang="ts">
	import { m } from '$lib/paraglide/messages.js';
</script>

<svelte:head>
	<title>{m.privacy_title()} · Gestura Index</title>
	<meta name="description" content={m.privacy_meta_description()} />
</svelte:head>

<h1>{m.privacy_title()}</h1>

<section class="card">
	<p>{m.privacy_intro()}</p>
</section>

<section class="card">
	<h2>{m.privacy_no_ip_heading()}</h2>
	<p>{m.privacy_no_ip_body()}</p>
</section>

<section class="card">
	<h2>{m.privacy_counters_heading()}</h2>
	<p>{m.privacy_counters_body()}</p>
</section>

<section class="card">
	<h2>{m.privacy_no_accounts_heading()}</h2>
	<p>{m.privacy_no_accounts_body()}</p>
</section>

<section class="card">
	<h2>{m.privacy_public_content_heading()}</h2>
	<p>{m.privacy_public_content_body()}</p>
</section>

<section class="card">
	<h2>{m.privacy_reports_heading()}</h2>
	<p>{m.privacy_reports_body()}</p>
</section>
