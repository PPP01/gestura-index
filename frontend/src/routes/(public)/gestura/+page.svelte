<script lang="ts">
	import { m } from '$lib/paraglide/messages.js';
	import { localizeHref } from '$lib/paraglide/runtime';
	import {
		Download,
		Puzzle,
		Move,
		Menu,
		Hand,
		SquareDashedMousePointer,
		MousePointerClick,
		ListChecks,
		Code
	} from '@lucide/svelte';
	import shot01 from '$lib/assets/promo/01-overview.png';
	import shot02 from '$lib/assets/promo/02-gesture-to-menu.png';
	import shot03 from '$lib/assets/promo/03-search-engines.png';
	import shot04 from '$lib/assets/promo/04-per-site-menus.png';
	import shot05 from '$lib/assets/promo/05-js-transform.png';

	const CHROME_URL =
		'https://chromewebstore.google.com/detail/gestura-mausgesten/ddcendiamegpalekoneonjkenhcamjnj';
	const FIREFOX_URL = 'https://addons.mozilla.org/firefox/addon/gestura-mouse-gestures/';
	const GITHUB_URL = 'https://github.com/PPP01/Gestura';

	const features = [
		{ Icon: Move, title: m.gestura_feat_gestures_title, body: m.gestura_feat_gestures_body },
		{ Icon: Menu, title: m.gestura_feat_menus_title, body: m.gestura_feat_menus_body },
		{ Icon: Hand, title: m.gestura_feat_superdrag_title, body: m.gestura_feat_superdrag_body },
		{
			Icon: SquareDashedMousePointer,
			title: m.gestura_feat_area_title,
			body: m.gestura_feat_area_body
		},
		{ Icon: MousePointerClick, title: m.gestura_feat_wheel_title, body: m.gestura_feat_wheel_body },
		{ Icon: ListChecks, title: m.gestura_feat_chains_title, body: m.gestura_feat_chains_body }
	];

	const shots = [
		{ src: shot01, caption: m.gestura_shot1 },
		{ src: shot02, caption: m.gestura_shot2 },
		{ src: shot03, caption: m.gestura_shot3 },
		{ src: shot04, caption: m.gestura_shot4 },
		{ src: shot05, caption: m.gestura_shot5 }
	];
</script>

<svelte:head>
	<title>{m.gestura_page_title()} · Gestura Index</title>
	<meta name="description" content={m.gestura_hero_tagline()} />
</svelte:head>

<section class="hero">
	<h1>{m.gestura_hero_title()}</h1>
	<p class="hero-tagline">{m.gestura_hero_tagline()}</p>
	<div class="store-ctas">
		<a class="btn btn-primary" href={CHROME_URL} target="_blank" rel="noopener noreferrer">
			<Puzzle size={18} />
			{m.gestura_cta_chrome()}
		</a>
		<a class="btn" href={FIREFOX_URL} target="_blank" rel="noopener noreferrer">
			<Download size={18} />
			{m.gestura_cta_firefox()}
		</a>
	</div>
	<img class="hero-shot" src={shot01} alt={m.gestura_shot1()} loading="lazy" width="1280" height="800" />
</section>

<section class="card">
	<p>{m.gestura_intro()}</p>
</section>

<section>
	<h2>{m.gestura_features_heading()}</h2>
	<div class="grid-cards">
		{#each features as feature (feature.title)}
			<div class="card feature-tile">
				<span class="icon-tile"><feature.Icon size={20} /></span>
				<h3>{feature.title()}</h3>
				<p>{feature.body()}</p>
			</div>
		{/each}
	</div>
</section>

<section>
	<h2>{m.gestura_shots_heading()}</h2>
	<div class="grid-cards shots">
		{#each shots as shot (shot.caption)}
			<figure class="card shot-tile">
				<img src={shot.src} alt={shot.caption()} loading="lazy" width="1280" height="800" />
				<figcaption>{shot.caption()}</figcaption>
			</figure>
		{/each}
	</div>
</section>

<section class="card">
	<h2>{m.gestura_os_heading()}</h2>
	<p>{m.gestura_os_body()}</p>
	<p>
		<a class="btn" href={GITHUB_URL} target="_blank" rel="noopener noreferrer">
			<Code size={18} />
			{m.gestura_github()}
		</a>
	</p>
</section>

<section class="card">
	<p>{m.gestura_index_note()} <a href={localizeHref('/browse')}>{m.nav_browse()}</a></p>
</section>

<section class="store-ctas repeat">
	<a class="btn btn-primary" href={CHROME_URL} target="_blank" rel="noopener noreferrer">
		<Puzzle size={18} />
		{m.gestura_cta_chrome()}
	</a>
	<a class="btn" href={FIREFOX_URL} target="_blank" rel="noopener noreferrer">
		<Download size={18} />
		{m.gestura_cta_firefox()}
	</a>
</section>

<style>
	.hero {
		padding: 24px 0;
	}
	.hero-tagline {
		font-size: 1.2em;
		color: var(--text-secondary);
		max-width: 640px;
	}
	.store-ctas {
		display: flex;
		flex-wrap: wrap;
		gap: 12px;
		margin: 20px 0;
	}
	.store-ctas.repeat {
		justify-content: center;
		padding: 12px 0 32px;
	}
	.hero-shot {
		display: block;
		max-width: 100%;
		height: auto;
		border-radius: 12px;
		border: 1px solid var(--border-color);
		margin-top: 12px;
	}
	.feature-tile {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.feature-tile h3 {
		font-size: 1em;
	}
	.feature-tile p {
		color: var(--text-secondary);
	}
	.shots {
		grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
	}
	.shot-tile img {
		display: block;
		max-width: 100%;
		height: auto;
		border-radius: 12px;
		border: 1px solid var(--border-color);
	}
	.shot-tile figcaption {
		margin-top: 10px;
		color: var(--text-secondary);
		font-size: 0.9em;
	}
</style>
