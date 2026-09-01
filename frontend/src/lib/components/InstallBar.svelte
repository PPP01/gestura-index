<script lang="ts">
	import { m } from '$lib/paraglide/messages.js';
	import StoreBadges from './StoreBadges.svelte';

	/*
	 * Platzierbarer Install-Streifen: eine Zeile Text über den drei Store-Badges,
	 * zentriert, mit eigener Trennlinie nach oben. Steckt im öffentlichen Layout
	 * über dem Footer und ist damit auf jeder öffentlichen Seite verfügbar; kann
	 * genauso einzeln in eine Seite gesetzt werden.
	 *
	 * Bewusst OHNE id="install": diesen Anker trägt der Hero der Startseite
	 * (/beispiele verlinkt viermal auf /#install). Ein zweites Element mit
	 * derselben id auf derselben Seite wäre ein doppelter Anker.
	 */
	let { title = m.install_bar_title(), height = 48 }: { title?: string; height?: number } =
		$props();
</script>

<div class="install-bar">
	<p class="install-bar-title">{title}</p>
	<StoreBadges {height} />
</div>

<style>
	.install-bar {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 14px;
		padding-block: 28px;
		text-align: center;
	}
	.install-bar-title {
		margin: 0;
		font-size: 13.5px;
		color: var(--text-secondary);
	}
	/* StoreBadges bringt seine Reihe linksbündig mit – hier zentriert. */
	.install-bar :global(.store-badges) {
		justify-content: center;
		gap: 16px;
	}
</style>
