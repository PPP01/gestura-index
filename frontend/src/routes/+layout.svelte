<script lang="ts">
	import '$lib/styles/gestura-common.css';
	import '$lib/styles/site.css';
	import favicon from '$lib/assets/logo/icon32.png';
	import Header from '$lib/components/Header.svelte';
	import Footer from '$lib/components/Footer.svelte';

	let { children } = $props();
</script>

<svelte:head>
	<link rel="icon" href={favicon} />
</svelte:head>

<div class="page-shell">
	<Header />
	<main class="container">
		{@render children()}
	</main>
	<Footer />
</div>
