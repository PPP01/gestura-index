<script lang="ts">
	import { page } from '$app/state';
	import { locales, getLocale, localizeHref } from '$lib/paraglide/runtime';
</script>

<div class="lang-toggle">
	{#each locales as locale}
		<a
			href={localizeHref(page.url.pathname, { locale })}
			data-sveltekit-reload
			class:active={getLocale() === locale}>{locale.toUpperCase()}</a
		>
	{/each}
</div>

<style>
	.lang-toggle {
		display: inline-flex;
		gap: 4px;
	}
	.lang-toggle a {
		padding: 2px 8px;
		border-radius: 8px;
		text-decoration: none;
		color: var(--text-secondary);
	}
	.lang-toggle a.active {
		background: var(--bg-tertiary);
		color: var(--text-primary);
	}
</style>
