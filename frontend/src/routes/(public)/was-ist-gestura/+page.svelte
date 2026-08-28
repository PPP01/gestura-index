<script lang="ts">
	import { m } from '$lib/paraglide/messages.js';
	import { Mouse, Search, List, ShieldCheck } from '@lucide/svelte';
	import shotGestures from '$lib/assets/promo/02-gesture-to-menu.png';
	import shotEngines from '$lib/assets/promo/03-search-engines.png';
	import shotMenus from '$lib/assets/promo/04-per-site-menus.png';

	// Drei alternierende Sektionen mit echten Promo-Screenshots (RULING B) statt
	// der gestrichelten Platzhalter aus dem Design-Handoff (1k). Alt-Texte
	// recyceln die vorhandenen gestura_shot2/3/4-Keys (RULING 2).
	const sections = [
		{
			Icon: Mouse,
			color: '#5b9cf6',
			title: () => m.c2_sec_gestures_title(),
			body: () => m.c2_sec_gestures_body(),
			shot: shotGestures,
			alt: () => m.gestura_shot2(),
			imageFirst: false
		},
		{
			Icon: Search,
			color: '#e6a117',
			title: () => m.c2_sec_engines_title(),
			body: () => m.c2_sec_engines_body(),
			shot: shotEngines,
			alt: () => m.gestura_shot3(),
			imageFirst: true
		},
		{
			Icon: List,
			color: '#4caf50',
			title: () => m.c2_sec_menus_title(),
			body: () => m.c2_sec_menus_body(),
			shot: shotMenus,
			alt: () => m.gestura_shot4(),
			imageFirst: false
		}
	];
</script>

<svelte:head>
	<title>{m.c2_page_title()} · Gestura Index</title>
	<meta name="description" content={m.c2_meta_desc()} />
</svelte:head>

<div class="c2">
	<div class="intro">
		<h1>{m.c2_page_title()}</h1>
		<p>{m.c2_intro()}</p>
	</div>

	<div class="persona-grid">
		<div class="card persona-card">
			<span class="persona-title">{m.c2_persona_surf_title()}</span>
			<span class="persona-body">{m.c2_persona_surf_body()}</span>
		</div>
		<div class="card persona-card">
			<span class="persona-title">{m.c2_persona_research_title()}</span>
			<span class="persona-body">{m.c2_persona_research_body()}</span>
		</div>
		<div class="card persona-card">
			<span class="persona-title">{m.c2_persona_privacy_title()}</span>
			<span class="persona-body">{m.c2_persona_privacy_body()}</span>
		</div>
	</div>

	<div class="sections">
		{#each sections as s (s.title())}
			<div class="sec" class:image-first={s.imageFirst}>
				<div class="sec-copy">
					<h2>
						<span class="icon-tile sec-icon" style={`--icon-color:${s.color}`}>
							<s.Icon size={17} />
						</span>
						{s.title()}
					</h2>
					<p>{s.body()}</p>
				</div>
				<img class="sec-shot" src={s.shot} alt={s.alt()} width="320" height="200" loading="lazy" />
			</div>
		{/each}
	</div>

	<div class="banner">
		<ShieldCheck size={19} />
		<p>
			<strong>{m.c2_privacy_banner_title()}</strong>
			{m.c2_privacy_banner_body()}
		</p>
	</div>
</div>

<style>
	.c2 {
		display: flex;
		flex-direction: column;
		gap: 34px;
	}

	.intro {
		display: flex;
		flex-direction: column;
		gap: 14px;
	}
	.intro h1 {
		margin: 0;
		font-size: 32px;
		font-weight: 700;
	}
	.intro p {
		margin: 0;
		font-size: 15.5px;
		line-height: 1.7;
		color: var(--text-secondary);
	}

	/* ---- Persona-Karten ---- */
	.persona-grid {
		display: grid;
		grid-template-columns: repeat(3, 1fr);
		gap: 14px;
	}
	.persona-card {
		margin-bottom: 0;
		padding: 18px;
		display: flex;
		flex-direction: column;
		gap: 7px;
	}
	.persona-title {
		font-size: 13.5px;
		font-weight: 700;
	}
	.persona-body {
		font-size: 12.5px;
		color: var(--text-secondary);
		line-height: 1.55;
	}

	/* ---- Alternierende Sektionen ---- */
	.sections {
		display: flex;
		flex-direction: column;
		gap: 26px;
	}
	.sec {
		display: grid;
		grid-template-columns: 1fr 320px;
		gap: 26px;
		align-items: center;
	}
	.sec.image-first {
		grid-template-columns: 320px 1fr;
	}
	.sec.image-first .sec-shot {
		order: -1;
	}
	.sec-copy {
		display: flex;
		flex-direction: column;
		gap: 9px;
	}
	.sec-copy h2 {
		margin: 0;
		display: flex;
		align-items: center;
		gap: 9px;
		font-size: 17.5px;
		font-weight: 700;
	}
	.sec-icon {
		width: 34px;
		height: 34px;
		border-radius: 11px;
	}
	.sec-copy p {
		margin: 0;
		font-size: 13.5px;
		line-height: 1.65;
		color: var(--text-secondary);
	}
	.sec-shot {
		width: 100%;
		height: auto;
		border-radius: 14px;
		border: 1px solid var(--border-color);
		display: block;
	}

	/* ---- Abschluss-Banner (success-getönt) ---- */
	.banner {
		border-radius: 20px;
		border: 1px solid oklch(from var(--success-color) l c h / 25%);
		background: oklch(from var(--success-color) l c h / 6%);
		padding: 18px 22px;
		display: flex;
		gap: 14px;
		align-items: flex-start;
		color: var(--success-color);
	}
	.banner p {
		margin: 0;
		font-size: 13px;
		line-height: 1.65;
		color: var(--text-secondary);
	}
	.banner p strong {
		color: var(--text-primary);
	}

	@media (max-width: 640px) {
		.persona-grid {
			grid-template-columns: 1fr;
		}
		.sec,
		.sec.image-first {
			grid-template-columns: 1fr;
		}
		.sec.image-first .sec-shot {
			order: 0;
		}
	}
</style>
