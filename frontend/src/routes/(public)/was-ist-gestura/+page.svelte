<script lang="ts">
	import { m } from '$lib/paraglide/messages.js';
	import { localizeHref } from '$lib/paraglide/runtime';
	import { Mouse, Search, List, ShieldCheck, Zap, ArrowRight } from '@lucide/svelte';
	import shotGestures from '$lib/assets/promo/02-gesture-to-menu.png';
	import shotEngines from '$lib/assets/promo/03-search-engines.png';
	import shotMenus from '$lib/assets/promo/04-per-site-menus.png';

	// Zielgruppen-Karten: Icon-Kachel und Verlaufs-Tint je Karte in eigener
	// Farbe (Handoff 3a: zap blau, search violett, shield grün).
	const personas = [
		{
			Icon: Zap,
			color: 'var(--accent-color)',
			title: () => m.c2_persona_surf_title(),
			body: () => m.c2_persona_surf_body()
		},
		{
			Icon: Search,
			color: 'var(--c2-violet)',
			title: () => m.c2_persona_research_title(),
			body: () => m.c2_persona_research_body()
		},
		{
			Icon: ShieldCheck,
			color: 'var(--success-color)',
			title: () => m.c2_persona_privacy_title(),
			body: () => m.c2_persona_privacy_body()
		}
	];

	// Drei alternierende Sektionen mit echten Promo-Screenshots (RULING B) statt
	// der gestrichelten Platzhalter aus dem Design-Handoff. Alt-Texte recyceln
	// die vorhandenen gestura_shot2/3/4-Keys (RULING 2).
	//
	// Die erste Sektion trägt die Gesten-Pill (die Richtungsfolge »→ ↓ →« ist
	// reine Symbolik und daher nicht übersetzt), die beiden anderen je einen
	// Textlink in den Index – so führt jede Sektion dorthin weiter, wo es das
	// Gezeigte zu holen gibt.
	const sections = [
		{
			Icon: Mouse,
			color: 'var(--accent-color)',
			title: () => m.c2_sec_gestures_title(),
			body: () => m.c2_sec_gestures_body(),
			shot: shotGestures,
			alt: () => m.gestura_shot2(),
			imageFirst: false,
			pill: () => m.c2_sec_gestures_pill(),
			link: null
		},
		{
			Icon: Search,
			color: 'var(--warning-color)',
			title: () => m.c2_sec_engines_title(),
			body: () => m.c2_sec_engines_body(),
			shot: shotEngines,
			alt: () => m.gestura_shot3(),
			imageFirst: true,
			pill: null,
			link: () => m.c2_sec_engines_link()
		},
		{
			Icon: List,
			color: 'var(--success-color)',
			title: () => m.c2_sec_menus_title(),
			body: () => m.c2_sec_menus_body(),
			shot: shotMenus,
			alt: () => m.gestura_shot4(),
			imageFirst: false,
			pill: null,
			link: () => m.c2_sec_menus_link()
		}
	];
</script>

<svelte:head>
	<title>{m.c2_page_title()} · Gestura Index</title>
	<meta name="description" content={m.c2_meta_desc()} />
</svelte:head>

<div class="c2">
	<div class="c2-glow" aria-hidden="true"></div>
	<div class="c2-stars" aria-hidden="true"></div>

	<div class="intro">
		<div class="intro-head">
			<span class="status-pill"><span class="dot"></span>{m.c2_status_pill()}</span>
			<h1>{m.c2_h1_lead()} <span class="grad">{m.c2_h1_accent()}</span></h1>
		</div>
		<p>{m.c2_intro()}</p>
	</div>

	<div class="persona-grid">
		{#each personas as p (p.title())}
			<div class="persona-card" style={`--tint:${p.color}`}>
				<span class="icon-tile persona-icon" style={`--icon-color:${p.color}`}>
					<p.Icon size={19} />
				</span>
				<span class="persona-title">{p.title()}</span>
				<span class="persona-body">{p.body()}</span>
			</div>
		{/each}
	</div>

	<div class="sections">
		{#each sections as s (s.title())}
			<div class="sec" class:image-first={s.imageFirst}>
				<div class="sec-copy">
					<h2>
						<span class="icon-tile sec-icon" style={`--icon-color:${s.color}`}>
							<s.Icon size={18} />
						</span>
						{s.title()}
					</h2>
					<p>{s.body()}</p>
					{#if s.pill}
						<span class="gesture-pill">
							<span class="arrows" aria-hidden="true">→ ↓ →</span>
							{s.pill()}
						</span>
					{/if}
					{#if s.link}
						<a class="sec-link" href={localizeHref('/index')}>
							{s.link()}<ArrowRight size={14} />
						</a>
					{/if}
				</div>
				<div class="shot-frame" style={`--shot-glow:${s.color}`}>
					<img class="sec-shot" src={s.shot} alt={s.alt()} width="340" height="212" loading="lazy" />
				</div>
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
	/*
	 * C2 »Was ist Gestura« v2 (Design-Handoff, Artboard 3a). Bewusst ruhiger als
	 * die Startseite: ein dezenter Glow oben statt des Hero-Leuchtens, kein
	 * Bento-Grid, keine schwebenden Pills. Die 900px-Lesespalte kommt aus dem
	 * öffentlichen Layout (--content-max-width) – hier wird sie nicht gesetzt.
	 *
	 * Die --c1-*-Töne der Startseite sind dort component-scoped und hier nicht
	 * sichtbar; C2 bringt seine eigenen mit. Sie absichtlich NICHT global zu
	 * ziehen hält die beiden Seiten unabhängig: C1 leuchtet, C2 flüstert.
	 */
	.c2 {
		position: relative;
		display: flex;
		flex-direction: column;
		gap: 34px;

		--c2-violet: #8b5cf6;
		--c2-hairline: rgba(255, 255, 255, 0.1);
		--c2-card-end: rgba(255, 255, 255, 0.03);
		--c2-glass: rgba(20, 20, 30, 0.6);
		--c2-shot-shadow: 0 20px 50px rgba(0, 0, 0, 0.45);
		--c2-glow-a: oklch(from var(--accent-color) l c h / 16%);
		--c2-glow-b: rgba(139, 92, 246, 0.06);
		--c2-glow-side: oklch(from var(--accent-color) l c h / 5%);
	}
	/*
	 * Light: höhere Alpha-Werte als die Handoff-Zahlen – ein Glow liegt auf
	 * hellem Grund DUNKLER als der Untergrund und hat nach unten kaum
	 * Kontrastspielraum. Abgestimmt ist auf gleiche wahrgenommene Intensität,
	 * nicht auf gleiche Zahlen (dieselbe Abwägung wie auf der Startseite).
	 */
	:global([data-theme='light']) .c2 {
		--c2-violet: #7c4fe0;
		/* Etwas kräftiger als die üblichen 7 %: der Glas-Rahmen der Screenshots
		   liegt hier hell auf hell und verlöre sonst jede Kante. */
		--c2-hairline: rgba(0, 0, 0, 0.1);
		--c2-card-end: rgba(255, 255, 255, 0.7);
		--c2-glass: rgba(255, 255, 255, 0.75);
		--c2-shot-shadow: 0 12px 32px rgba(0, 0, 0, 0.12);
		--c2-glow-a: oklch(from var(--accent-color) l c h / 15%);
		--c2-glow-b: rgba(124, 79, 224, 0.08);
		--c2-glow-side: oklch(from var(--accent-color) l c h / 7%);
	}

	/*
	 * Beide Verlaufs-Ebenen greifen seitlich und nach oben ins Shell-Padding
	 * (24px / 28px), sodass sie bündig an der Rahmenkante enden statt an der
	 * Inhaltskante zu beginnen.
	 */
	.c2-glow,
	.c2-stars {
		position: absolute;
		inset: -28px -24px 0;
		pointer-events: none;
		z-index: 0;
	}
	/*
	 * Ein Haupt-Glow oben mittig, zwei sehr schwache Seiten-Glows weiter unten –
	 * die Werte des Handoffs. Sie setzen die volle Seitenbreite voraus: auf einer
	 * schmaleren Fläche misst der 760px-Verlauf mit seinem 72%-Auslauf rund
	 * 1100px, wird an der Kante abgeschnitten und steht als sichtbares helles
	 * Rechteck hinter der Überschrift. Wer diese Seite je in eine schmale Spalte
	 * setzt, muss die Radien mitziehen.
	 */
	.c2-glow {
		background:
			radial-gradient(760px 380px at 50% -80px, var(--c2-glow-a), var(--c2-glow-b) 50%, transparent 72%),
			radial-gradient(420px 260px at 92% 44%, var(--c2-glow-side), transparent 70%),
			radial-gradient(420px 260px at 8% 72%, var(--c2-glow-side), transparent 70%);
	}
	/* Sternenrauschen nur im dunklen Thema – im hellen wäre es Schmutz. */
	.c2-stars {
		background-image:
			radial-gradient(rgba(255, 255, 255, 0.5) 0.6px, transparent 0.6px),
			radial-gradient(rgba(255, 255, 255, 0.35) 0.5px, transparent 0.5px);
		background-size:
			190px 170px,
			120px 140px;
		opacity: 0.09;
	}
	:global([data-theme='light']) .c2-stars {
		display: none;
	}

	/* Der Inhalt liegt über den beiden Verlaufs-Ebenen. */
	.intro,
	.persona-grid,
	.sections,
	.banner {
		position: relative;
		z-index: 1;
	}

	/*
	 * ---- Intro ----
	 * Zweispaltig: Überschrift links, Einleitung rechts daneben. Über die volle
	 * Shell-Breite wäre der Absatz sonst rund 1150px breit – etwa 150 Zeichen je
	 * Zeile, weit jenseits des Lesbaren. Die zweite Spalte nutzt die Fläche
	 * wirklich aus, statt sie über eine zweite Inhaltsbreite wegzuwerfen.
	 */
	.intro {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 20px 48px;
		align-items: center;
	}
	.intro-head {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 16px;
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
		backdrop-filter: blur(6px);
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
		font-size: 44px;
		line-height: 1.12;
		font-weight: 700;
		letter-spacing: -0.02em;
		text-wrap: balance;
	}
	.intro h1 .grad {
		background: linear-gradient(100deg, var(--accent-color), var(--c2-violet) 70%);
		background-clip: text;
		-webkit-background-clip: text;
		color: transparent;
	}
	.intro p {
		margin: 0;
		font-size: 16.5px;
		line-height: 1.7;
		color: var(--text-secondary);
	}

	/* ---- Zielgruppen-Karten ---- */
	.persona-grid {
		display: grid;
		grid-template-columns: repeat(3, 1fr);
		gap: 14px;
	}
	.persona-card {
		padding: 18px;
		display: flex;
		flex-direction: column;
		gap: 9px;
		border-radius: 20px;
		border: 1px solid var(--c2-hairline);
		/* Verlaufs-Tint in der Kartenfarbe, nach unten ins Neutrale auslaufend. */
		background: linear-gradient(
			160deg,
			oklch(from var(--tint) l c h / 9%),
			var(--c2-card-end) 70%
		);
	}
	.persona-icon {
		width: 40px;
		height: 40px;
		border-radius: 12px;
	}
	.persona-title {
		font-size: 14px;
		font-weight: 700;
	}
	.persona-body {
		font-size: 12.5px;
		color: var(--text-secondary);
		line-height: 1.6;
	}

	/* ---- Alternierende Sektionen ---- */
	.sections {
		display: flex;
		flex-direction: column;
		gap: 56px;
	}
	/* Breitere Bildspalte als die 340px des Handoffs: auf der vollen Shell bliebe
	   sonst eine Textspalte von rund 780px stehen – zu breit für Fließtext. */
	.sec {
		display: grid;
		grid-template-columns: 1fr 480px;
		gap: 48px;
		align-items: center;
	}
	.sec.image-first {
		grid-template-columns: 480px 1fr;
	}
	.sec.image-first .shot-frame {
		order: -1;
	}
	.sec-copy {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 12px;
	}
	.sec-copy h2 {
		margin: 0;
		display: flex;
		align-items: center;
		gap: 10px;
		font-size: 20px;
		font-weight: 700;
	}
	.sec-icon {
		width: 36px;
		height: 36px;
		border-radius: 11px;
	}
	.sec-copy p {
		margin: 0;
		font-size: 14px;
		line-height: 1.7;
		color: var(--text-secondary);
	}

	/* Gesten-Pill: zeigt eine Richtungsfolge und die Aktion, die sie auslöst. */
	.gesture-pill {
		display: inline-flex;
		align-items: center;
		gap: 10px;
		padding: 7px 15px;
		border-radius: 999px;
		font-size: 12.5px;
		font-weight: 600;
		background: oklch(from var(--accent-color) l c h / 10%);
		border: 1px solid oklch(from var(--accent-color) l c h / 25%);
	}
	.gesture-pill .arrows {
		font-family: var(--font-mono, ui-monospace, monospace);
		letter-spacing: 0.14em;
		color: var(--accent-color);
	}

	.sec-link {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		font-size: 13px;
		font-weight: 600;
		color: var(--accent-color);
		text-decoration: none;
	}
	.sec-link:hover {
		text-decoration: underline;
	}

	/* Screenshot im Glas-Rahmen, mit farbigem Soft-Glow der jeweiligen Sektion. */
	.shot-frame {
		padding: 10px;
		border-radius: 16px;
		background: var(--c2-glass);
		border: 1px solid var(--c2-hairline);
		box-shadow:
			var(--c2-shot-shadow),
			0 0 40px oklch(from var(--shot-glow) l c h / 8%);
	}
	.sec-shot {
		width: 100%;
		height: auto;
		border-radius: 8px;
		display: block;
	}

	/* ---- Abschluss-Panel (success-getönt, Ecken-Glow links oben) ---- */
	.banner {
		position: relative;
		border-radius: 20px;
		border: 1px solid oklch(from var(--success-color) l c h / 28%);
		background:
			radial-gradient(
				360px 200px at 0% 0%,
				oklch(from var(--success-color) l c h / 14%),
				transparent 70%
			),
			linear-gradient(150deg, oklch(from var(--success-color) l c h / 10%), var(--c2-card-end) 75%);
		padding: 20px 24px;
		display: flex;
		gap: 14px;
		align-items: flex-start;
		color: var(--success-color);
	}
	.banner p {
		margin: 0;
		font-size: 13.5px;
		line-height: 1.7;
		color: var(--text-secondary);
	}
	.banner p strong {
		color: var(--text-primary);
	}

	/* Unter 900px trägt die Fläche die zweite Intro-Spalte nicht mehr. */
	@media (max-width: 900px) {
		.intro {
			grid-template-columns: 1fr;
			align-items: start;
		}
	}

	@media (max-width: 640px) {
		.intro h1 {
			font-size: 34px;
		}
		.persona-grid {
			grid-template-columns: 1fr;
		}
		.sec,
		.sec.image-first {
			grid-template-columns: 1fr;
			gap: 22px;
		}
		.sec.image-first .shot-frame {
			order: 0;
		}
	}
</style>
