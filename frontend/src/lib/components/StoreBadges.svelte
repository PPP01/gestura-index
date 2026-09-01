<script lang="ts">
	import { m } from '$lib/paraglide/messages.js';

	// Offizielle Vendor-Badges (Bilddateien in $lib/assets/stores/). Ein
	// Top-Level-`await import(...)` ist in Svelte 5 im Komponenten-<script>
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
			src: findAsset('chrome-webstore'),
			alt: m.store_chrome_alt(),
			fallback: m.store_chrome_fallback()
		},
		{
			href: EDGE,
			src: findAsset('microsoft-edge'),
			alt: m.store_edge_alt(),
			fallback: m.store_edge_fallback()
		},
		{
			href: FIREFOX,
			src: findAsset('firefox-addon'),
			alt: m.store_firefox_alt(),
			fallback: m.store_firefox_fallback()
		}
	];
</script>

<div class="store-badges" style={`--badge-height:${height}px`}>
	{#each stores as s (s.href)}
		<a href={s.href} target="_blank" rel="noopener noreferrer" aria-label={s.alt}>
			{#if s.src}
				<img src={s.src} alt={s.alt} />
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
	.store-badges img {
		height: var(--badge-height);
		width: auto;
		display: block;
	}
</style>
