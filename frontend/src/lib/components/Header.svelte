<script lang="ts">
	import logoLight from '$lib/assets/logo/icon128.png';
	import logoDark from '$lib/assets/logo/icon128-dark.png';
	import { localizeHref } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import ThemeToggle from './ThemeToggle.svelte';
	import LangToggle from './LangToggle.svelte';
</script>

<header class="site-header">
	<a class="brand" href={localizeHref('/')}>
		<span class="logo-img">
			<img src={logoLight} alt="" class="logo-light" width="36" height="36" />
			<img src={logoDark} alt="" class="logo-dark" width="36" height="36" />
		</span>
		<span class="brand-name">Gestura</span>
		<span class="version">Index</span>
	</a>
	<nav class="site-nav">
		<a href={localizeHref('/')}>{m.nav_home()}</a>
		<a href={localizeHref('/browse')}>{m.nav_browse()}</a>
		<a href={localizeHref('/docs')}>{m.nav_docs()}</a>
	</nav>
	<div class="header-actions">
		<ThemeToggle />
		<LangToggle />
	</div>
</header>

<style>
	.site-header {
		display: flex;
		align-items: center;
		gap: 16px;
		flex-wrap: wrap;
		padding: 12px 0;
	}
	.brand {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		text-decoration: none;
		color: inherit;
	}
	.logo-img {
		width: 36px;
		height: 36px;
		border-radius: 10px;
		overflow: hidden;
		display: inline-flex;
	}
	.logo-dark {
		display: none;
	}
	:global([data-theme='dark']) .logo-dark {
		display: inline;
	}
	:global([data-theme='dark']) .logo-light {
		display: none;
	}
	.brand-name {
		font-weight: 700;
		font-size: 1.2em;
	}
	/* .version-Badge-Klasse existiert nicht in gestura-common.css -> hier
	   schlicht nach dem Muster der Extension ergänzt (klein, accent-getönt). */
	.version {
		display: inline-flex;
		align-items: center;
		padding: 2px 8px;
		border-radius: 999px;
		font-size: 0.7em;
		font-weight: 600;
		letter-spacing: 0.02em;
		color: var(--accent-color);
		background: oklch(from var(--accent-color) l c h / 12%);
	}
	.site-nav {
		display: flex;
		gap: 16px;
		margin-inline-start: auto;
	}
	.site-nav a {
		text-decoration: none;
		color: var(--text-secondary);
	}
	.header-actions {
		display: inline-flex;
		gap: 8px;
		align-items: center;
	}
</style>
