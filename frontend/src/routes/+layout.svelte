<script lang="ts">
	import '$lib/styles/gestura-common.css';
	import '$lib/styles/site.css';
	import favicon from '$lib/assets/logo/icon32.png';

	let { children } = $props();
</script>

<svelte:head>
	<link rel="icon" href={favicon} />
</svelte:head>

<div class="page-shell">
	{@render children()}
</div>
