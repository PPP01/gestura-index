<script lang="ts">
	import { page } from '$app/state';
	import { localizeHref } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
</script>

<svelte:head><title>{page.status} · Gestura</title><meta name="robots" content="noindex" /></svelte:head>

<section class="error-page">
	<h1>{page.status}</h1>
	<p>{page.error?.message ?? m.error_not_found()}</p>
	<a class="btn btn-primary" href={localizeHref('/')}>{m.error_home()}</a>
</section>

<style>
	.error-page {
		text-align: center;
		padding: 80px 0;
	}
	.error-page h1 {
		font-size: 48px;
		margin: 0 0 8px;
	}
	.error-page p {
		color: var(--text-secondary);
		margin: 0 0 20px;
	}
</style>
