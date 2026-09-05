<script lang="ts">
	import { onMount } from 'svelte';
	import { m } from '$lib/paraglide/messages.js';
	import { localizeHref, getLocale } from '$lib/paraglide/runtime';
	import {
		Mouse,
		Move,
		Zap,
		Scan,
		Search,
		List,
		ArrowRight,
		Shield,
		Check,
		EyeOff
	} from '@lucide/svelte';
	import StoreBadges from '$lib/components/StoreBadges.svelte';
	import GithubMark from '$lib/components/GithubMark.svelte';
	import EntryBlock from '$lib/components/EntryBlock.svelte';
	import { listEntries, type EntryListItem } from '$lib/api';
	import heroHand from '$lib/assets/logo/icon128.png';

	/**
	 * Schwebende Gesten-Pills im Browser-Mock. `arrow` ist reine Symbolik und
	 * daher nicht übersetzt; `tone` wählt die Rahmen-/Schriftfarbe (die Pink-
	 * und Violett-Töne sind laut Handoff Verlaufs-, keine UI-Farben – sie
	 * kommen ausschließlich hier und in der Gestenspur vor).
	 */
	const pills = [
		{ arrow: '↓ →', tone: 'pink', label: () => m.c1_pill_close(), pos: 'p1' },
		{ arrow: '→ ↑', tone: 'violet', label: () => m.c1_pill_newtab(), pos: 'p2' },
		{ arrow: '↑ ↓', tone: 'accent', label: () => m.c1_pill_reload(), pos: 'p3' },
		{ arrow: '→ ↓ ←', tone: 'success', label: () => m.c1_pill_menu(), pos: 'p4' }
	];

	// Bento-Grid: die erste Karte ist die breite Hero-Karte (span 4) mit eigener
	// Mini-Gestenspur, die übrigen fünf sind span-2-Karten.
	const features = [
		{ Icon: Move, tone: 'violet', title: () => m.c1_feat_superdrag_title(), body: () => m.c1_feat_superdrag_body() },
		{ Icon: Zap, tone: 'pink', title: () => m.c1_feat_wheel_title(), body: () => m.c1_feat_wheel_body() },
		{ Icon: Scan, tone: 'teal', title: () => m.c1_feat_area_title(), body: () => m.c1_feat_area_body() },
		{ Icon: Search, tone: 'gold', title: () => m.c1_feat_engines_title(), body: () => m.c1_feat_engines_body() },
		{ Icon: List, tone: 'success', title: () => m.c1_feat_menus_title(), body: () => m.c1_feat_menus_body(), tint: true }
	];

	// Index-Teaser: die Seite ist prerendert, daher lädt der Teaser erst im
	// Browser nach (progressive enhancement). onMount läuft während des
	// Prerenderings gar nicht – kein Fetch-Versuch zur Build-Zeit. Dieselbe
	// Antwort speist das Zahlen-Band: die Anzahl im Index wird NICHT hart
	// verdrahtet (das Design zeigt dort Platzhalter), sondern kommt live aus
	// der API – ist sie stumm, entfällt die Zahl, statt eine zu erfinden.
	let teaser = $state<EntryListItem[] | null>(null);
	let teaserTotal = $state<number | null>(null);
	let teaserFailed = $state(false);

	const totalLabel = $derived(teaserTotal === null ? null : teaserTotal.toLocaleString(getLocale()));

	onMount(async () => {
		try {
			const res = await listEntries({ page: 1, perPage: 3, sort: 'newest' });
			teaser = res.items;
			teaserTotal = res.total;
		} catch {
			teaserFailed = true;
		}
	});
</script>

<svelte:head>
	<title>{m.c1_page_title()}</title>
	<meta name="description" content={m.c1_meta_desc()} />
</svelte:head>

<div class="c1">
	<!-- Atmosphärischer Hintergrund: mehrere Glows plus ein feines Sternenrauschen
	     (nur Dark). Beide Ebenen liegen hinter dem Inhalt und fangen keine Klicks. -->
	<div class="c1-glow" aria-hidden="true"></div>
	<div class="c1-stars" aria-hidden="true"></div>

	<div class="c1-inner">
		<section class="hero">
			<span class="status-pill"><span class="dot"></span>{m.c1_status_pill()}</span>
			<h1 class="hero-h1">
				{m.c1_hero_h1_a()}<br /><span class="grad">{m.c1_hero_h1_b()}</span>
			</h1>
			<p class="hero-sub">{m.c1_hero_sub()}</p>
			<div id="install" class="hero-install"><StoreBadges height={48} /></div>
			<a class="hero-discover" href={localizeHref('/index')}>
				{m.c1_hero_discover()}<ArrowRight size={14} />
			</a>
		</section>

		<!-- Browser-Mock. Reine Illustration: jede Aussage darin steht als echter
		     Text im Bento-Grid weiter unten, deshalb für Screenreader ausgeblendet
		     statt sie mit Pfeilsymbolen zu fluten. -->
		<div class="mock-wrap" aria-hidden="true">
			<div class="mock-blob"></div>
			<div class="out-pill out-pill-a float">
				<span class="key">←</span><span class="txt">{m.c1_pill_back()}</span>
			</div>
			<div class="out-pill out-pill-b float">
				<span class="key violet">{m.c1_pill_wheel_keys()}</span><span class="txt">{m.c1_pill_tabs()}</span>
			</div>

			<div class="mock">
				<div class="mock-bar">
					<span class="dot"></span><span class="dot"></span><span class="dot"></span>
					<span class="mock-url"><Shield size={11} />gestura.app</span>
				</div>
				<div class="mock-canvas">
					<svg class="trail" viewBox="0 0 1040 300" fill="none" preserveAspectRatio="xMidYMid slice">
						<defs>
							<linearGradient id="c1-trail" x1="0" y1="0" x2="0.55" y2="1">
								<stop class="stop-accent" offset="0" />
								<stop offset="0.55" stop-color="#8b5cf6" />
								<stop offset="1" stop-color="#ec4899" />
							</linearGradient>
							<linearGradient id="c1-trail-h" x1="0" y1="0" x2="1" y2="0">
								<stop class="stop-accent" offset="0" />
								<stop offset="0.6" stop-color="#8b5cf6" />
								<stop offset="1" stop-color="#ec4899" />
							</linearGradient>
							<filter id="c1-glow" x="-40%" y="-40%" width="180%" height="180%">
								<feGaussianBlur stdDeviation="7" result="b" />
								<feMerge><feMergeNode in="b" /><feMergeNode in="SourceGraphic" /></feMerge>
							</filter>
						</defs>
						<path
							d="M440 55 C 442 130, 438 185, 452 218 C 462 240, 500 238, 560 234 L 690 226"
							stroke="url(#c1-trail)"
							stroke-width="6"
							stroke-linecap="round"
							filter="url(#c1-glow)"
						/>
						<path
							d="M668 204 L694 226 L666 244"
							stroke="#ec4899"
							stroke-width="6"
							stroke-linecap="round"
							stroke-linejoin="round"
							filter="url(#c1-glow)"
						/>
						<circle class="trail-start" cx="440" cy="55" r="8" filter="url(#c1-glow)" />
						<circle cx="450" cy="200" r="3" fill="#8b5cf6" opacity="0.8" />
					</svg>

					<div class="hud">
						<img src={heroHand} alt="" width="26" height="26" />
						<span class="hud-label">{m.c1_mock_detected()}</span>
						<span class="key">↓ →</span>
						<span class="hud-action">{m.c1_mock_action()}</span>
					</div>

					{#each pills as p (p.pos)}
						<div class="pill float {p.pos} tone-{p.tone}">
							<span class="key">{p.arrow}</span><span class="txt">{p.label()}</span>
						</div>
					{/each}
				</div>
			</div>
		</div>

		<div class="stats">
			<div class="stats-inner">
				<div class="stat"><span class="stat-value">0</span><span class="stat-label">{m.c1_stat_trackers_label()}</span></div>
				<div class="stat"><span class="stat-value">3</span><span class="stat-label">{m.c1_stat_browsers_label()}</span></div>
				{#if !teaserFailed}
					<div class="stat">
						{#if totalLabel === null}
							<span class="stat-value"><span class="stat-skel"></span></span>
						{:else}
							<span class="stat-value">{totalLabel}</span>
						{/if}
						<span class="stat-label">{m.c1_stat_entries_label()}</span>
					</div>
				{/if}
				<div class="stat"><span class="stat-value accent">AGPL</span><span class="stat-label">{m.c1_stat_license_label()}</span></div>
			</div>
		</div>

		<section class="bento">
			<div class="section-head">
				<h2>{m.c1_bento_heading()}</h2>
				<span>{m.c1_bento_sub()}</span>
			</div>
			<div class="bento-grid">
				<div class="bento-card bento-hero feature-tile">
					<div class="bento-hero-copy">
						<span class="icon-tile tone-accent"><Mouse size={20} /></span>
						<span class="bento-title">{m.c1_feat_gestures_title()}</span>
						<span class="bento-body">{m.c1_feat_gestures_body()}</span>
					</div>
					<svg class="mini-trail" width="230" height="130" viewBox="0 0 230 130" fill="none" aria-hidden="true">
						<path
							d="M35 95 C 80 20, 150 20, 200 70"
							stroke="url(#c1-trail-h)"
							stroke-width="5"
							stroke-linecap="round"
							filter="url(#c1-glow)"
						/>
						<path
							d="M186 52 L202 72 L178 76"
							stroke="#ec4899"
							stroke-width="5"
							stroke-linecap="round"
							stroke-linejoin="round"
						/>
						<circle class="trail-start" cx="35" cy="95" r="6" />
					</svg>
				</div>

				{#each features as f (f.title())}
					<div class="bento-card feature-tile" class:tinted={f.tint}>
						<span class="icon-tile tone-{f.tone}"><f.Icon size={20} /></span>
						<span class="bento-title">{f.title()}</span>
						<span class="bento-body">{f.body()}</span>
					</div>
				{/each}
			</div>
		</section>

		{#if !teaserFailed}
			<section class="teaser">
				<div class="teaser-panel">
					<div class="teaser-corner" aria-hidden="true"></div>
					<div class="teaser-copy">
						<span class="eyebrow">{m.c1_teaser_eyebrow()}</span>
						<h2>
							{totalLabel === null
								? m.c1_teaser_heading()
								: m.c1_teaser_heading_count({ total: totalLabel })}
						</h2>
						<span class="teaser-body">{m.c1_teaser_body()}</span>
						<a class="teaser-cta" href={localizeHref('/index')}>
							{m.c1_teaser_cta()}<ArrowRight size={14} />
						</a>
					</div>
					<div class="teaser-list">
						{#if teaser === null}
							{#each Array.from({ length: 3 }) as _, i (i)}
								<div class="skeleton-row" style={`animation-delay:${i * 0.15}s`}>
									<span class="skel skel-icon"></span>
									<span class="skel-lines">
										<span class="skel skel-line-a"></span>
										<span class="skel skel-line-b"></span>
									</span>
									<span class="skel skel-side"></span>
								</div>
							{/each}
						{:else}
							{#each teaser as entry (entry.formatId)}
								<EntryBlock {entry} />
							{/each}
						{/if}
					</div>
				</div>
			</section>
		{/if}

		<div class="trustline">
			<span><Shield size={15} color="var(--success-color)" />{m.c1_trust_anon()}</span>
			<span class="sep">·</span>
			<span><Check size={15} color="var(--success-color)" />{m.c1_trust_noemail()}</span>
			<span class="sep">·</span>
			<span><EyeOff size={15} color="var(--success-color)" />{m.c1_trust_notrack()}</span>
			<span class="sep">·</span>
			<span class="gh-item"><GithubMark size={15} />{m.c1_trust_os()}</span>
		</div>
	</div>
</div>

<style>
	/*
	 * C1-Startseite v2 (Design-Handoff, Artboard 2a/2b). Die Sektion bricht aus
	 * dem 24px-Innenabstand der Shell aus, damit Glow und Zahlen-Band über die
	 * volle Rahmenbreite laufen; die inneren Sektionen bringen ihr Padding selbst
	 * mit (900px Hero, 1100px Mock/Bento/Teaser – wie im Design).
	 */
	.c1 {
		position: relative;
		margin-inline: -24px;
		margin-block: -28px -28px;
		padding-block: 28px;
		overflow: hidden;

		/* Glow-Töne: Violett/Pink sind laut Handoff reine Verlaufsfarben. */
		--c1-violet: #8b5cf6;
		--c1-pink: #ec4899;
		--c1-teal: #2bb8a8;
		--c1-gold: #e6a117;
		--c1-glow-1: oklch(from var(--accent-color) l c h / 28%);
		--c1-glow-2: rgba(139, 92, 246, 0.12);
		--c1-glow-3: rgba(139, 92, 246, 0.14);
		--c1-glow-4: oklch(from var(--accent-color) l c h / 8%);
		--c1-surface: rgba(255, 255, 255, 0.035);
		--c1-surface-strong: rgba(255, 255, 255, 0.055);
		--c1-hairline: rgba(255, 255, 255, 0.1);
		--c1-glass: rgba(20, 20, 30, 0.75);
		--c1-glass-deep: rgba(10, 10, 18, 0.78);
		--c1-panel-end: oklch(from var(--bg-primary) l c h / 40%);
		--c1-card-shadow: none;
	}
	:global([data-theme='light']) .c1 {
		--c1-violet: #7c4fe0;
		--c1-pink: #d63384;
		--c1-teal: #1f9587;
		--c1-gold: #b07d0e;
		/*
		 * Deutlich höhere Alpha-Werte als die 16/7/8/6 % des Handoffs: auf dem
		 * dunklen Grund liegt ein Glow HELLER als der Untergrund und hat nach oben
		 * viel Kontrastspielraum – auf dem hellen Grund liegt er DUNKLER und
		 * gesättigter, und nach unten ist kaum Spielraum. Gleiche Alpha-Werte
		 * ergeben deshalb sehr unterschiedliche Wirkung; hier ist auf gleiche
		 * WAHRGENOMMENE Intensität abgestimmt, nicht auf gleiche Zahlen.
		 */
		--c1-glow-1: oklch(from var(--accent-color) l c h / 32%);
		--c1-glow-2: rgba(139, 92, 246, 0.18);
		--c1-glow-3: rgba(139, 92, 246, 0.17);
		--c1-glow-4: oklch(from var(--accent-color) l c h / 14%);
		--c1-surface: #ffffff;
		--c1-surface-strong: rgba(255, 255, 255, 0.7);
		--c1-hairline: rgba(0, 0, 0, 0.07);
		--c1-glass: rgba(255, 255, 255, 0.86);
		--c1-glass-deep: rgba(255, 255, 255, 0.94);
		--c1-panel-end: #ffffff;
		--c1-card-shadow: 0 3px 14px rgba(0, 0, 0, 0.05);
	}

	.c1-glow,
	.c1-stars {
		position: absolute;
		inset: 0;
		pointer-events: none;
	}
	.c1-glow {
		background:
			radial-gradient(900px 520px at 50% 470px, var(--c1-glow-1), var(--c1-glow-2) 45%, transparent 70%),
			radial-gradient(700px 300px at 85% -60px, var(--c1-glow-3), transparent 70%),
			radial-gradient(600px 300px at 8% 120px, var(--c1-glow-4), transparent 70%);
	}
	/* Sternenrauschen nur im dunklen Thema – im hellen wäre es Schmutz. */
	.c1-stars {
		display: none;
		background-image:
			radial-gradient(rgba(255, 255, 255, 0.5) 0.6px, transparent 0.6px),
			radial-gradient(rgba(255, 255, 255, 0.35) 0.5px, transparent 0.5px);
		background-size:
			190px 170px,
			120px 140px;
		background-position:
			30px 40px,
			80px 90px;
		opacity: 0.14;
	}
	:global([data-theme='dark']) .c1-stars {
		display: block;
	}

	.c1-inner {
		position: relative;
	}

	/* ---- Hero (zentriert, 900px) ---- */
	.hero {
		max-width: 900px;
		margin: 0 auto;
		padding: 46px 24px 0;
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 22px;
		text-align: center;
		box-sizing: border-box;
	}
	.status-pill {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		font-size: 12px;
		font-weight: 600;
		padding: 5px 14px;
		border-radius: 20px;
		border: 1px solid var(--border-color);
		background: var(--c1-surface-strong);
		color: var(--text-secondary);
		backdrop-filter: blur(6px);
	}
	.status-pill .dot {
		width: 7px;
		height: 7px;
		border-radius: 50%;
		background: var(--success-color);
		box-shadow: 0 0 8px oklch(from var(--success-color) l c h / 80%);
	}
	.hero-h1 {
		margin: 0;
		font-size: 62px;
		line-height: 1.08;
		font-weight: 700;
		letter-spacing: -0.02em;
		text-wrap: balance;
	}
	.hero-h1 .grad {
		background: linear-gradient(100deg, var(--accent-color), var(--c1-violet) 60%, var(--c1-pink));
		background-clip: text;
		-webkit-background-clip: text;
		color: transparent;
	}
	.hero-sub {
		margin: 0;
		font-size: 17px;
		line-height: 1.65;
		color: var(--text-secondary);
		max-width: 620px;
		text-wrap: pretty;
	}
	.hero-install {
		margin-top: 6px;
	}
	/* Die Store-Badges sind offizielle Vendor-Grafiken (StoreBadges.svelte); die
	   Design-Höhe von 48px kommt als Prop, hier bleibt nur die Hero-Ausrichtung. */
	.hero-install :global(.store-badges) {
		justify-content: center;
		gap: 16px;
	}
	.hero-discover {
		display: inline-flex;
		align-items: center;
		gap: 7px;
		font-size: 13.5px;
		color: var(--text-secondary);
		text-decoration: none;
	}
	.hero-discover:hover {
		color: var(--accent-color);
	}

	/* ---- Browser-Mock (1100px) ---- */
	.mock-wrap {
		position: relative;
		max-width: 1100px;
		/* 56px statt der 26px des Designs: die beiden Pills »außerhalb oberhalb
		   des Mocks« (Handoff) brauchen diesen Platz, sonst liegen sie auf der
		   URL-Zeile. */
		margin: 56px auto 0;
		padding: 0 24px;
		box-sizing: border-box;
	}
	.mock-blob {
		position: absolute;
		left: 50%;
		top: 56%;
		transform: translate(-50%, -50%);
		width: 640px;
		height: 340px;
		max-width: 90%;
		background: radial-gradient(
			closest-side,
			oklch(from var(--accent-color) l c h / 50%),
			rgba(139, 92, 246, 0.22) 55%,
			transparent
		);
		filter: blur(6px);
		pointer-events: none;
	}
	.mock {
		position: relative;
		border-radius: 18px;
		background: var(--c1-glass);
		border: 1px solid var(--border-color);
		box-shadow:
			0 -10px 60px oklch(from var(--accent-color) l c h / 15%),
			0 30px 80px rgba(0, 0, 0, 0.5);
		backdrop-filter: blur(10px);
		overflow: hidden;
		margin-bottom: 56px;
	}
	:global([data-theme='light']) .mock {
		box-shadow:
			0 -10px 60px oklch(from var(--accent-color) l c h / 12%),
			0 30px 80px rgba(0, 0, 0, 0.14);
	}
	.mock-bar {
		display: flex;
		gap: 6px;
		align-items: center;
		padding: 12px 16px;
		border-bottom: 1px solid var(--border-color);
	}
	.mock-bar .dot {
		width: 10px;
		height: 10px;
		border-radius: 50%;
		background: var(--star-base);
		flex: none;
	}
	.mock-url {
		margin-left: 12px;
		display: flex;
		align-items: center;
		gap: 7px;
		flex: 1;
		height: 26px;
		border-radius: 8px;
		background: var(--bg-tertiary);
		padding: 0 12px;
		font-size: 11.5px;
		color: var(--text-muted);
	}
	.mock-canvas {
		position: relative;
		height: 300px;
	}
	.trail {
		width: 100%;
		height: 100%;
		display: block;
	}
	/* Der erste Verlaufsstopp und der Startpunkt folgen der Akzentfarbe des
	   Themas (Dark #5b9cf6 / Light #4285f4) – Violett und Pink bleiben fest. */
	.stop-accent {
		stop-color: var(--accent-color);
	}
	.trail-start {
		fill: var(--accent-color);
	}

	.hud {
		position: absolute;
		left: 12%;
		top: 50%;
		transform: translateY(-50%);
		display: flex;
		align-items: center;
		gap: 10px;
		background: var(--c1-glass-deep);
		border: 1px solid var(--border-color);
		border-radius: 14px;
		padding: 13px 20px;
		box-shadow: 0 14px 44px rgba(0, 0, 0, 0.55);
		backdrop-filter: blur(8px);
		white-space: nowrap;
	}
	:global([data-theme='light']) .hud {
		box-shadow: 0 14px 44px rgba(0, 0, 0, 0.14);
	}
	.hud-label {
		font-size: 14px;
		font-weight: 600;
	}
	.hud .key {
		font-size: 14px;
	}
	.hud-action {
		font-size: 13px;
		color: var(--text-secondary);
	}

	.key {
		font-family: var(--font-mono);
		font-size: 13px;
		font-weight: 700;
		color: var(--accent-color);
	}
	.key.violet {
		color: var(--c1-violet);
	}

	.pill,
	.out-pill {
		position: absolute;
		display: flex;
		align-items: center;
		gap: 8px;
		background: var(--c1-glass);
		border: 1px solid var(--border-color);
		padding: 8px 15px;
		box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
		backdrop-filter: blur(8px);
		white-space: nowrap;
	}
	:global([data-theme='light']) .pill,
	:global([data-theme='light']) .out-pill {
		box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
	}
	.pill {
		border-radius: 999px;
	}
	.out-pill {
		border-radius: 12px;
		padding: 9px 14px;
		z-index: 1;
	}
	.pill .txt,
	.out-pill .txt {
		font-size: 12px;
	}
	/* bottom:100% setzt die Unterkante der Pill auf die Oberkante des Mocks –
	   sie schwebt damit komplett darüber; margin-bottom staffelt die beiden. */
	.out-pill-a {
		left: 12%;
		bottom: 100%;
		margin-bottom: 6px;
		border-color: oklch(from var(--accent-color) l c h / 35%);
		animation-duration: 5s;
	}
	.out-pill-b {
		right: 10%;
		bottom: 100%;
		margin-bottom: 18px;
		border-color: rgba(139, 92, 246, 0.35);
		animation-duration: 6s;
		animation-delay: 0.8s;
	}
	.p1 {
		right: 6%;
		top: 26px;
		animation-duration: 5s;
	}
	.p2 {
		right: 15%;
		top: 86px;
		animation-duration: 6s;
		animation-delay: 0.7s;
	}
	.p3 {
		right: 4%;
		bottom: 78px;
		animation-duration: 5.5s;
		animation-delay: 1.4s;
	}
	.p4 {
		right: 17%;
		bottom: 24px;
		animation-duration: 6.5s;
		animation-delay: 2.1s;
	}
	.tone-pink {
		border-color: oklch(from var(--c1-pink) l c h / 40%);
	}
	.tone-pink .key {
		color: var(--c1-pink);
	}
	.tone-violet {
		border-color: oklch(from var(--c1-violet) l c h / 40%);
	}
	.tone-violet .key {
		color: var(--c1-violet);
	}
	.tone-accent {
		border-color: oklch(from var(--accent-color) l c h / 40%);
	}
	.tone-success {
		border-color: oklch(from var(--success-color) l c h / 40%);
	}
	.tone-success .key {
		color: var(--success-color);
	}

	.float {
		animation-name: c1-float;
		animation-timing-function: ease-in-out;
		animation-iteration-count: infinite;
	}
	@keyframes c1-float {
		0%,
		100% {
			transform: translateY(0);
		}
		50% {
			transform: translateY(-10px);
		}
	}

	/* ---- Zahlen-Band (volle Rahmenbreite) ---- */
	.stats {
		border-top: 1px solid var(--border-color);
		border-bottom: 1px solid var(--border-color);
		background: var(--c1-surface-strong);
	}
	:global([data-theme='dark']) .stats {
		background: rgba(255, 255, 255, 0.025);
	}
	.stats-inner {
		max-width: 1100px;
		margin: 0 auto;
		padding: 22px 24px;
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
		gap: 20px;
		box-sizing: border-box;
	}
	.stat {
		display: flex;
		flex-direction: column;
		gap: 3px;
		align-items: center;
		text-align: center;
	}
	.stat-value {
		font-size: 26px;
		font-weight: 700;
		line-height: 1.2;
		font-variant-numeric: tabular-nums;
	}
	.stat-value.accent {
		color: var(--accent-color);
	}
	.stat-label {
		font-size: 12px;
		color: var(--text-muted);
	}
	.stat-skel {
		display: block;
		width: 56px;
		height: 20px;
		margin: 5px 0;
		border-radius: 6px;
		background: var(--star-base);
		animation: c1-pulse 1.5s ease-in-out infinite;
	}

	/* ---- Bento-Grid (1100px) ---- */
	.bento,
	.teaser {
		max-width: 1100px;
		margin: 0 auto;
		padding: 56px 24px 0;
		box-sizing: border-box;
	}
	.section-head {
		display: flex;
		flex-direction: column;
		gap: 6px;
		margin-bottom: 22px;
	}
	.section-head h2 {
		margin: 0;
		font-size: 28px;
		font-weight: 700;
		letter-spacing: -0.01em;
	}
	.section-head span {
		font-size: 14px;
		color: var(--text-secondary);
	}
	.bento-grid {
		display: grid;
		grid-template-columns: repeat(6, 1fr);
		gap: 14px;
	}
	.bento-card {
		grid-column: span 2;
		border-radius: 20px;
		border: 1px solid var(--c1-hairline);
		background: var(--c1-surface);
		box-shadow: var(--c1-card-shadow);
		padding: 24px;
		display: flex;
		flex-direction: column;
		gap: 9px;
	}
	.bento-card.tinted {
		background: linear-gradient(
			150deg,
			oklch(from var(--success-color) l c h / 9%),
			var(--c1-surface) 60%
		);
	}
	.bento-hero {
		grid-column: span 4;
		flex-direction: row;
		align-items: center;
		gap: 22px;
		overflow: hidden;
		background: linear-gradient(
			150deg,
			oklch(from var(--accent-color) l c h / 10%),
			var(--c1-surface) 55%
		);
	}
	.bento-hero-copy {
		flex: 1;
		min-width: 0;
		display: flex;
		flex-direction: column;
		gap: 9px;
	}
	.bento-hero .bento-title {
		font-size: 16.5px;
	}
	.bento-hero .bento-body {
		font-size: 13px;
	}
	.mini-trail {
		flex: none;
	}
	.bento-title {
		font-size: 15px;
		font-weight: 700;
	}
	.bento-body {
		font-size: 12.5px;
		color: var(--text-secondary);
		line-height: 1.6;
	}
	/* Icon-Kacheln: .icon-tile aus site.css, hier nur die Farbe je Kachel. */
	.icon-tile.tone-accent {
		--icon-color: var(--accent-color);
	}
	.icon-tile.tone-violet {
		--icon-color: var(--c1-violet);
	}
	.icon-tile.tone-pink {
		--icon-color: var(--c1-pink);
	}
	.icon-tile.tone-teal {
		--icon-color: var(--c1-teal);
	}
	.icon-tile.tone-gold {
		--icon-color: var(--c1-gold);
	}
	.icon-tile.tone-success {
		--icon-color: var(--success-color);
	}

	/* ---- Index-Teaser-Panel ---- */
	.teaser-panel {
		position: relative;
		border-radius: 24px;
		border: 1px solid oklch(from var(--accent-color) l c h / 25%);
		background: linear-gradient(
			160deg,
			oklch(from var(--accent-color) l c h / 12%),
			rgba(139, 92, 246, 0.07) 50%,
			var(--c1-panel-end)
		);
		box-shadow: var(--c1-card-shadow);
		padding: 34px 36px;
		display: grid;
		grid-template-columns: 1fr 1.2fr;
		gap: 34px;
		align-items: center;
		overflow: hidden;
	}
	.teaser-corner {
		position: absolute;
		right: -80px;
		top: -80px;
		width: 300px;
		height: 300px;
		background: radial-gradient(closest-side, rgba(139, 92, 246, 0.25), transparent);
		pointer-events: none;
	}
	.teaser-copy {
		position: relative;
		display: flex;
		flex-direction: column;
		gap: 12px;
	}
	.eyebrow {
		font-size: 11px;
		font-weight: 700;
		letter-spacing: 0.09em;
		color: var(--accent-color);
	}
	.teaser-copy h2 {
		margin: 0;
		font-size: 26px;
		font-weight: 700;
		letter-spacing: -0.01em;
		text-wrap: balance;
	}
	.teaser-body {
		font-size: 13.5px;
		color: var(--text-secondary);
		line-height: 1.6;
	}
	.teaser-cta {
		margin-top: 6px;
		display: inline-flex;
		align-items: center;
		gap: 8px;
		height: 42px;
		padding: 0 20px;
		border-radius: 11px;
		background: var(--c1-surface-strong);
		border: 1px solid var(--border-color);
		color: var(--text-primary);
		font-size: 13.5px;
		font-weight: 600;
		text-decoration: none;
		align-self: flex-start;
		backdrop-filter: blur(6px);
	}
	.teaser-cta:hover {
		border-color: oklch(from var(--accent-color) l c h / 55%);
		color: var(--accent-color);
	}
	.teaser-list {
		position: relative;
		border-radius: 16px;
		background: var(--c1-glass-deep);
		border: 1px solid var(--c1-hairline);
		overflow: hidden;
		backdrop-filter: blur(8px);
	}
	/* Der erste Block bringt seine eigene Trennlinie mit – im Panel wäre sie
	   eine Doppellinie direkt unter der Panelkante. */
	.teaser-list :global(.block:first-child .block-row:first-child) {
		border-top: none;
	}

	.skeleton-row {
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 11px 16px;
		border-top: 1px solid var(--border-color);
		animation: c1-pulse 1.5s ease-in-out infinite;
	}
	.skeleton-row:first-child {
		border-top: none;
	}
	.skel {
		border-radius: 8px;
		background: var(--star-base);
		flex-shrink: 0;
	}
	.skel-icon {
		width: 40px;
		height: 40px;
		border-radius: 12px;
	}
	.skel-lines {
		flex: 1 1 auto;
		min-width: 0;
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.skel-line-a {
		height: 12px;
		width: 55%;
	}
	.skel-line-b {
		height: 10px;
		width: 35%;
	}
	.skel-side {
		width: 70px;
		height: 14px;
	}
	@keyframes c1-pulse {
		0%,
		100% {
			opacity: 0.5;
		}
		50% {
			opacity: 1;
		}
	}

	/* ---- Vertrauens-Zeile ---- */
	.trustline {
		max-width: 1100px;
		margin: 0 auto;
		padding: 40px 24px 0;
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 26px;
		flex-wrap: wrap;
		font-size: 13px;
		color: var(--text-secondary);
		box-sizing: border-box;
	}
	.trustline span {
		display: inline-flex;
		align-items: center;
		gap: 8px;
	}
	.trustline .sep {
		color: var(--border-color);
	}
	.trustline .gh-item {
		color: var(--text-secondary);
	}
	.trustline .gh-item :global(svg) {
		color: var(--success-color);
	}

	/* ---- Bewegung respektieren (Handoff fordert es ausdrücklich) ---- */
	@media (prefers-reduced-motion: reduce) {
		.float,
		.skeleton-row,
		.stat-skel {
			animation: none;
		}
	}

	/* ---- Schmalere Viewports ---- */
	@media (max-width: 1000px) {
		.hero-h1 {
			font-size: 48px;
		}
		.teaser-panel {
			grid-template-columns: 1fr;
			padding: 28px;
		}
		/* Die schwebenden Pills brauchen Platz neben der Spur – darunter
		   überlagern sie den HUD-Chip, deshalb entfallen sie. */
		.pill,
		.out-pill {
			display: none;
		}
		.hud {
			left: 50%;
			transform: translate(-50%, -50%);
		}
	}
	@media (max-width: 780px) {
		.bento-grid {
			grid-template-columns: repeat(2, 1fr);
		}
		.bento-hero {
			grid-column: span 2;
		}
		.mini-trail {
			display: none;
		}
	}
	@media (max-width: 560px) {
		.hero {
			padding-top: 28px;
		}
		.hero-h1 {
			font-size: 34px;
		}
		.hero-sub {
			font-size: 15px;
		}
		.bento-grid {
			grid-template-columns: 1fr;
		}
		.bento-card,
		.bento-hero {
			grid-column: span 1;
		}
		.mock-canvas {
			height: 210px;
		}
		.hud {
			padding: 10px 14px;
			gap: 7px;
		}
		.hud-label,
		.hud .key {
			font-size: 12.5px;
		}
		.hud-action {
			font-size: 12px;
		}
	}
</style>
