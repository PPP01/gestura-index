<script lang="ts">
	import { m } from '$lib/paraglide/messages.js';
	import { Share2, RefreshCw, Lock, Puzzle, ShieldCheck, Code } from '@lucide/svelte';
	import GithubMark from '$lib/components/GithubMark.svelte';

	/*
	 * Was der Index kann. Die beiden hinteren Fähigkeiten stammen aus dem
	 * Vertrag mit der Erweiterung (apiLevel 3: Update-Check und der anonyme,
	 * Ende-zu-Ende-verschlüsselte Settings-Sync). Sie sind serverseitig fertig,
	 * aber erst mit der kommenden Version der Erweiterung nutzbar – deshalb
	 * tragen sie eine Kennzeichnung statt eines Versprechens im Präsens.
	 */
	const abilities = [
		{
			Icon: Share2,
			color: 'var(--accent-color)',
			title: () => m.about_can_share_title(),
			body: () => m.about_can_share_body(),
			prepared: false
		},
		{
			Icon: RefreshCw,
			color: 'var(--about-violet)',
			title: () => m.about_can_updates_title(),
			body: () => m.about_can_updates_body(),
			prepared: true
		},
		{
			Icon: Lock,
			color: 'var(--success-color)',
			title: () => m.about_can_sync_title(),
			body: () => m.about_can_sync_body(),
			prepared: true
		}
	];
</script>

<svelte:head>
	<title>{m.about_title()} · Gestura Index</title>
	<meta name="description" content={m.about_meta_description()} />
</svelte:head>

<div class="about">
	<div class="about-glow" aria-hidden="true"></div>

	<div class="intro">
		<span class="status-pill"><span class="dot"></span>{m.about_status_pill()}</span>
		<h1>{m.about_h1_lead()} <span class="grad">{m.about_h1_accent()}</span></h1>
		<p>{m.about_intro()}</p>
	</div>

	<section class="abilities">
		<h2>{m.about_can_heading()}</h2>
		<div class="ability-grid">
			{#each abilities as a (a.title())}
				<div class="ability-card" style={`--tint:${a.color}`}>
					<!-- Kennzeichnung in der Icon-Zeile, nicht hinter dem Titel: dort
					     bräche sie je nach Titellänge mal um und mal nicht. -->
					<div class="ability-head">
						<span class="icon-tile ability-icon" style={`--icon-color:${a.color}`}>
							<a.Icon size={19} />
						</span>
						{#if a.prepared}
							<span class="badge">{m.about_badge_prepared()}</span>
						{/if}
					</div>
					<span class="ability-title">{a.title()}</span>
					<span class="ability-body">{a.body()}</span>
				</div>
			{/each}
		</div>
		<p class="prepared-note">{m.about_prepared_note()}</p>
	</section>

	<!-- Das tragende Prinzip des Projekts – hervorgehoben, nicht als einer von
	     fünf gleichrangigen Kästen wie in der alten Fassung. -->
	<section class="panel panel-accent">
		<h2><span class="icon-tile" style="--icon-color: var(--accent-color)"><Puzzle size={18} /></span>
			{m.about_relation_heading()}</h2>
		<p>{m.about_relation_body()}</p>
		<p>{m.about_optional_body()}</p>
	</section>

	<section class="panel panel-success">
		<h2><span class="icon-tile" style="--icon-color: var(--success-color)"><ShieldCheck size={18} /></span>
			{m.about_privacy_heading()}</h2>
		<p>{m.about_privacy_body()}</p>
	</section>

	<section class="source">
		<h2><span class="icon-tile" style="--icon-color: var(--text-secondary)"><Code size={18} /></span>
			{m.about_source_heading()}</h2>
		<p>{m.about_source_body()}</p>
		<p class="license">{m.about_license_body()}</p>
		<a
			class="repo-link"
			href="https://github.com/PPP01/gestura-index"
			target="_blank"
			rel="noopener noreferrer"
		>
			<GithubMark size={15} />
			{m.about_repo_link()}
		</a>
	</section>
</div>

<style>
	/*
	 * Über-Seite im ruhigen v2-Ton der Marketing-Seiten. Sie bleibt eine
	 * TEXTSEITE und damit in der 900px-Lesespalte des Layouts – die Radien der
	 * Verläufe sind entsprechend kleiner als auf den breiten Seiten: ein Verlauf,
	 * der erst jenseits der Spaltenkante ausläuft, wird dort abgeschnitten und
	 * steht als sichtbares helles Rechteck hinter der Überschrift.
	 */
	.about {
		position: relative;
		display: flex;
		flex-direction: column;
		gap: 34px;

		--about-violet: #8b5cf6;
		--about-hairline: rgba(255, 255, 255, 0.1);
		--about-card-end: rgba(255, 255, 255, 0.03);
		--about-glow-a: oklch(from var(--accent-color) l c h / 14%);
		--about-glow-b: rgba(139, 92, 246, 0.05);
	}
	:global([data-theme='light']) .about {
		--about-violet: #7c4fe0;
		--about-hairline: rgba(0, 0, 0, 0.09);
		--about-card-end: rgba(255, 255, 255, 0.7);
		--about-glow-a: oklch(from var(--accent-color) l c h / 13%);
		--about-glow-b: rgba(124, 79, 224, 0.07);
	}

	.about-glow {
		position: absolute;
		inset: -28px -24px auto;
		height: 460px;
		pointer-events: none;
		z-index: 0;
		background: radial-gradient(
			540px 300px at 50% 0,
			var(--about-glow-a),
			var(--about-glow-b) 50%,
			transparent 72%
		);
	}
	.intro,
	.abilities,
	.panel,
	.source {
		position: relative;
		z-index: 1;
	}

	/* ---- Intro ---- */
	.intro {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 15px;
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
		background: var(--bg-secondary);
		color: var(--text-secondary);
	}
	.status-pill .dot {
		width: 7px;
		height: 7px;
		border-radius: 50%;
		background: var(--success-color);
		box-shadow: 0 0 8px oklch(from var(--success-color) l c h / 80%);
	}
	.intro h1 {
		margin: 0;
		font-size: 38px;
		line-height: 1.15;
		font-weight: 700;
		letter-spacing: -0.02em;
		text-wrap: balance;
	}
	.intro h1 .grad {
		background: linear-gradient(100deg, var(--accent-color), var(--about-violet) 70%);
		background-clip: text;
		-webkit-background-clip: text;
		color: transparent;
	}
	.intro p {
		margin: 0;
		font-size: 15.5px;
		line-height: 1.7;
		color: var(--text-secondary);
	}

	/* ---- Was der Index kann ---- */
	.abilities {
		display: flex;
		flex-direction: column;
		gap: 14px;
	}
	.abilities h2 {
		margin: 0;
		font-size: 18px;
		font-weight: 700;
	}
	.ability-grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
		gap: 14px;
	}
	.ability-card {
		padding: 18px;
		display: flex;
		flex-direction: column;
		gap: 9px;
		border-radius: 20px;
		border: 1px solid var(--about-hairline);
		background: linear-gradient(
			160deg,
			oklch(from var(--tint) l c h / 9%),
			var(--about-card-end) 70%
		);
	}
	.ability-head {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 10px;
	}
	.ability-icon {
		width: 40px;
		height: 40px;
		border-radius: 12px;
	}
	.ability-title {
		font-size: 14px;
		font-weight: 700;
	}
	/* Kennzeichnung statt Versprechen: die Funktion steht, die Erweiterung
	   dafür noch nicht in den Stores. */
	.badge {
		font-size: 10.5px;
		font-weight: 700;
		letter-spacing: 0.04em;
		text-transform: uppercase;
		padding: 2px 8px;
		border-radius: 999px;
		color: var(--warning-color);
		background: oklch(from var(--warning-color) l c h / 14%);
		border: 1px solid oklch(from var(--warning-color) l c h / 30%);
	}
	.ability-body {
		font-size: 12.5px;
		color: var(--text-secondary);
		line-height: 1.6;
	}
	.prepared-note {
		margin: 0;
		font-size: 12px;
		color: var(--text-muted);
	}

	/* ---- Hervorgehobene Panels ---- */
	.panel {
		border-radius: 20px;
		padding: 22px 24px;
		display: flex;
		flex-direction: column;
		gap: 10px;
	}
	.panel h2,
	.source h2 {
		margin: 0 0 2px;
		display: flex;
		align-items: center;
		gap: 10px;
		font-size: 18px;
		font-weight: 700;
	}
	.panel :global(.icon-tile),
	.source :global(.icon-tile) {
		width: 34px;
		height: 34px;
		border-radius: 11px;
	}
	.panel p,
	.source p {
		margin: 0;
		font-size: 13.5px;
		line-height: 1.7;
		color: var(--text-secondary);
	}
	.panel-accent {
		border: 1px solid oklch(from var(--accent-color) l c h / 26%);
		background:
			radial-gradient(
				360px 200px at 0% 0%,
				oklch(from var(--accent-color) l c h / 12%),
				transparent 70%
			),
			linear-gradient(150deg, oklch(from var(--accent-color) l c h / 8%), var(--about-card-end) 75%);
	}
	.panel-success {
		border: 1px solid oklch(from var(--success-color) l c h / 28%);
		background:
			radial-gradient(
				360px 200px at 0% 0%,
				oklch(from var(--success-color) l c h / 14%),
				transparent 70%
			),
			linear-gradient(150deg, oklch(from var(--success-color) l c h / 10%), var(--about-card-end) 75%);
	}

	/* ---- Quellcode & Lizenz ---- */
	.source {
		display: flex;
		flex-direction: column;
		gap: 10px;
		padding-top: 6px;
		border-top: 1px solid var(--border-color);
	}
	.source .license {
		color: var(--text-muted);
		font-size: 12.5px;
	}
	.repo-link {
		align-self: flex-start;
		display: inline-flex;
		align-items: center;
		gap: 8px;
		font-size: 13px;
		font-weight: 600;
		text-decoration: none;
		color: var(--text-primary);
		padding: 8px 14px;
		border-radius: 10px;
		border: 1px solid var(--border-color);
		background: var(--bg-secondary);
	}
	.repo-link:hover {
		border-color: oklch(from var(--accent-color) l c h / 45%);
		color: var(--accent-color);
	}

	@media (max-width: 640px) {
		.intro h1 {
			font-size: 30px;
		}
	}
</style>
