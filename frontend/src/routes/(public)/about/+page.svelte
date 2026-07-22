<script lang="ts">
	import { m } from '$lib/paraglide/messages.js';
</script>

<svelte:head>
	<title>{m.about_title()} · Gestura Index</title>
	<meta name="description" content={m.about_meta_description()} />
</svelte:head>

<h1>{m.about_title()}</h1>

<section class="card">
	<p>{m.about_intro()}</p>
</section>

<section class="card">
	<h2>{m.about_relation_heading()}</h2>
	<p>{m.about_relation_body()}</p>
</section>

<section class="card">
	<h2>{m.about_optional_heading()}</h2>
	<p>{m.about_optional_body()}</p>
</section>

<section class="card">
	<h2>{m.about_source_heading()}</h2>
	<p>{m.about_source_body()}</p>
	<p>
		<a href="https://github.com/PPP01/gestura-index" target="_blank" rel="noopener noreferrer"
			>{m.about_repo_link()}</a
		>
	</p>
</section>

<section class="card">
	<h2>{m.about_license_heading()}</h2>
	<p>{m.about_license_body()}</p>
</section>
