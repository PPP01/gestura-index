<script lang="ts">
	import { m } from '$lib/paraglide/messages.js';
	import ChromeBadge from './ChromeBadge.svelte';

	// Edge und Firefox: die offiziellen Vendor-Badges als Bilddatei. Chrome
	// dagegen als EIGENES Badge (ChromeBadge.svelte) – warum, steht dort.
	//
	// Ein Top-Level-`await import(...)` ist in Svelte 5 im Komponenten-<script>
	// NICHT erlaubt – stattdessen alle vorhandenen Dateien synchron per
	// `import.meta.glob` (eager) einsammeln und per Basename nachschlagen.
	// Fehlt eine Datei (z. B. weil die Assets noch nicht abgelegt wurden),
	// bleibt der jeweilige Eintrag in der Map einfach weg und der
	// Text-Button-Fallback greift – kein Bruch, auch ohne Bilddateien.
	const storeAssets = import.meta.glob('$lib/assets/stores/*', {
		eager: true,
		import: 'default'
	}) as Record<string, string>;

	function findAsset(basename: string): string | undefined {
		const match = Object.entries(storeAssets).find(([path]) => {
			const file = path.split('/').pop() ?? '';
			return file.replace(/\.[^.]+$/, '') === basename;
		});
		return match?.[1];
	}

	/** Höhe der Badge-Reihe in Pixeln (Design C1: 48). */
	let { height = 54 }: { height?: number } = $props();

	const CHROME =
		'https://chromewebstore.google.com/detail/gestura-mouse-gestures/ddcendiamegpalekoneonjkenhcamjnj';
	const EDGE =
		'https://microsoftedge.microsoft.com/addons/detail/gestura-mausgesten/dhjkaagkfcmgddieogodeioopogfpghn';
	const FIREFOX = 'https://addons.mozilla.org/firefox/addon/gestura-mouse-gestures/';

	const stores = [
		{
			href: CHROME,
			// kein `src`: dieses Badge rendert ChromeBadge.svelte – und kein `alt`,
			// weil das eigene Badge echten Text trägt. Der wird zum zugänglichen
			// Namen des Links; ein abweichendes aria-label würde ihn überschreiben
			// und die Sprachsteuerung ins Leere laufen lassen (WCAG 2.5.3).
			src: undefined,
			own: true,
			alt: undefined,
			fallback: undefined
		},
		{
			href: EDGE,
			src: findAsset('microsoft-edge'),
			own: false,
			alt: m.store_edge_alt(),
			fallback: m.store_edge_fallback()
		},
		{
			href: FIREFOX,
			src: findAsset('firefox-addon'),
			own: false,
			alt: m.store_firefox_alt(),
			fallback: m.store_firefox_fallback()
		}
	];
</script>

<div class="store-badges" style={`--badge-height:${height}px`}>
	{#each stores as s (s.href)}
		<a href={s.href} target="_blank" rel="noopener noreferrer" aria-label={s.alt ?? undefined}>
			{#if s.own}
				<ChromeBadge />
			{:else if s.src}
				<img src={s.src} alt={s.alt ?? ''} />
			{:else}
				<span class="btn">{s.fallback}</span>
			{/if}
		</a>
	{/each}
</div>

<style>
	.store-badges {
		display: flex;
		flex-wrap: wrap;
		gap: 12px;
		align-items: center;
	}
	.store-badges a {
		display: inline-flex;
		align-items: center;
		/* Muss AM Anker stehen: text-decoration vererbt sich und lässt sich von
		   Nachfahren nicht zurücknehmen – ein `text-decoration:none` im
		   ChromeBadge bliebe wirkungslos. */
		text-decoration: none;
	}
	.store-badges img {
		height: var(--badge-height);
		width: auto;
		display: block;
	}
</style>
