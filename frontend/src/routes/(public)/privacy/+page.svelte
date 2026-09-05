<script lang="ts">
	import { m } from '$lib/paraglide/messages.js';
	import PageGlow from '$lib/components/PageGlow.svelte';
	import PageHeading from '$lib/components/PageHeading.svelte';
</script>

<svelte:head>
	<title>{m.privacy_title()} · Gestura Index</title>
	<meta name="description" content={m.privacy_meta_description()} />
</svelte:head>

<div class="text-page">
	<PageGlow variant="narrow" />
	<PageHeading accent={m.privacy_title()} size={38} />

<section class="lead-section">
	<p>{m.privacy_intro()}</p>
</section>

<section>
	<h2>{m.privacy_no_ip_heading()}</h2>
	<p>{m.privacy_no_ip_body()}</p>
</section>

<section>
	<h2>{m.privacy_counters_heading()}</h2>
	<p>{m.privacy_counters_body()}</p>
</section>

<section>
	<h2>{m.privacy_no_accounts_heading()}</h2>
	<p>{m.privacy_no_accounts_body()}</p>
</section>

<section>
	<h2>{m.privacy_public_content_heading()}</h2>
	<p>{m.privacy_public_content_body()}</p>
</section>

<section>
	<h2>{m.privacy_reports_heading()}</h2>
	<p>{m.privacy_reports_body()}</p>
</section>
</div>
