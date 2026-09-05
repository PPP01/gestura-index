<script lang="ts">
	import { localizeHref } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
</script>

<footer class="site-footer">
	<nav class="footer-nav">
		<a href={localizeHref('/about')}>{m.footer_about()}</a>
		<a href={localizeHref('/privacy')}>{m.footer_privacy()}</a>
		<a href={localizeHref('/imprint')}>{m.footer_imprint()}</a>
		<a href="https://github.com/PPP01/Gestura" target="_blank" rel="noopener noreferrer">GitHub</a>
	</nav>
	<p class="footer-note">{m.footer_works_with()}</p>
</footer>

<style>
	.site-footer {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 8px;
		padding: 24px 0;
		/* Kein eigener border-top/margin-top mehr: die Trennlinie zieht das Band
		   im öffentlichen Layout (.footbar), und zwar über die volle Rahmenbreite
		   wie bei Kopfleiste und Install-Streifen. Vorher zeichneten beide eine
		   Linie – eine volle und 32px darunter eine eingerückte. */
		color: var(--text-secondary);
		font-size: 0.9em;
	}
	.footer-nav {
		display: flex;
		flex-wrap: wrap;
		justify-content: center;
		gap: 16px;
	}
	.footer-nav a {
		color: var(--text-secondary);
		text-decoration: none;
	}
	.footer-nav a:hover {
		color: var(--text-primary);
	}
	.footer-note {
		margin: 0;
		color: var(--text-muted);
	}
</style>
