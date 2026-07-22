<script lang="ts">
	import Header from '$lib/components/Header.svelte';
	import Footer from '$lib/components/Footer.svelte';

	let { children } = $props();
</script>

<div class="page-shell">
	<Header />
	<main class="container">
		{@render children()}
	</main>
	<Footer />
</div>
