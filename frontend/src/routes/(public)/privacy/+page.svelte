<script lang="ts">
	/*
	 * Datenschutzerklärung im Rail-Layout des Handoffs »4a« (exchange/,
	 * September 2026, zweite Fassung). Übernommen ist nur der INNERE Teil:
	 * Kopfleiste, Logo und Fußzeile stellt das öffentliche Layout.
	 *
	 * Die Rail ersetzt die Karten: eine durchgehende Linie links, je Abschnitt
	 * ein leuchtender Knoten. Deshalb nutzt diese Seite NICHT die
	 * `.text-page`-Struktur aus pages.css – die gibt jedem `section` eine
	 * getönte Karte, und Karte plus Rail wäre doppelt gerahmt. Die Rail selbst
	 * steht als `.rail-page`/`.rail-node` ebenfalls in pages.css und trägt auch
	 * das Impressum; hier unten stehen nur die Eigenheiten dieser Seite. Die
	 * Doku bleibt unberührt bei `.text-page`.
	 *
	 * Die Kurzfassung enthält AUSSCHLIESSLICH Aussagen, die der Detailtext
	 * darunter trägt – jeder Punkt verlinkt auf seinen Abschnitt. Ein
	 * pauschales »keine personenbezogenen Daten« steht bewusst nicht dort:
	 * Server-Protokolle enthalten IP-Adressen, und eine Zusammenfassung, die
	 * mehr verspricht als der Abschnitt darunter einhält, beschädigt die
	 * Glaubwürdigkeit der ganzen Erklärung.
	 *
	 * Ebenso bewusst: Die Meta-Angaben hinter der Überschrift ERSETZEN die
	 * Sätze im Fließtext nicht, sie fassen sie zusammen. Eine Rechtsgrundlage,
	 * die nur noch als Kurzform dasteht, wäre eine gekürzte Rechtsaussage – die
	 * Redundanz ist hier der sichere Weg. Sie stehen NEBEN dem `h2`, nicht
	 * darin: sonst trüge jede Überschrift im Dokumentbaum ihre Fußnote mit.
	 */
	import { m } from '$lib/paraglide/messages.js';
	import PageGlow from '$lib/components/PageGlow.svelte';
	import PageHeading from '$lib/components/PageHeading.svelte';
	import { Check, Shield } from '@lucide/svelte';

	const facts = [
		{ text: m.privacy_fact_cookies, href: '#storage' },
		{ text: m.privacy_fact_tracking, href: '#recipients' },
		{ text: m.privacy_fact_account, href: '#accounts' },
		{ text: m.privacy_fact_ip, href: '#logs' },
		{ text: m.privacy_fact_e2e, href: '#sync' },
		{ text: m.privacy_fact_eu, href: '#thirdcountry' }
	];

	/*
	 * Inhaltsverzeichnis über ALLE Abschnitte. Die Beschriftungen sind die
	 * vorhandenen Überschriften, nicht eigens gekürzte Labels: eine zweite,
	 * abweichende Bezeichnung desselben Abschnitts würde bei jeder Textpflege
	 * auseinanderlaufen. Reihenfolge und `id` müssen der Reihenfolge der
	 * Knoten unten entsprechen.
	 */
	const toc = [
		{ id: 'controller', label: m.privacy_controller_heading },
		{ id: 'logs', label: m.privacy_logs_heading },
		{ id: 'storage', label: m.privacy_storage_heading },
		{ id: 'abuse', label: m.privacy_abuse_heading },
		{ id: 'submit', label: m.privacy_submit_heading },
		{ id: 'public-content', label: m.privacy_public_content_heading },
		{ id: 'counters', label: m.privacy_counters_heading },
		{ id: 'reports', label: m.privacy_reports_heading },
		{ id: 'accounts', label: m.privacy_accounts_heading },
		{ id: 'ratings', label: m.privacy_ratings_heading },
		{ id: 'sync', label: m.privacy_sync_heading },
		{ id: 'updates', label: m.privacy_updates_heading },
		{ id: 'admin', label: m.privacy_admin_heading },
		{ id: 'recipients', label: m.privacy_recipients_heading },
		{ id: 'thirdcountry', label: m.privacy_thirdcountry_heading },
		{ id: 'rights', label: m.privacy_rights_heading },
		{ id: 'complaint', label: m.privacy_complaint_heading },
		{ id: 'profiling', label: m.privacy_no_profiling_heading },
		{ id: 'changes', label: m.privacy_changes_heading }
	];

	/*
	 * Die Schlüsselnamen sind Code-Konstanten, keine Message-Keys – sie stehen
	 * wörtlich so im localStorage (ThemeToggle.svelte, basket.svelte.ts,
	 * Header.svelte) und sind in jeder Sprache identisch. Wer sie dort ändert,
	 * muss sie hier ändern: die Erklärung benennt sie namentlich.
	 */
	const storageKeys = [
		{ key: 'gestura_index_theme', desc: m.privacy_storage_theme },
		{ key: 'gestura-basket', desc: m.privacy_storage_basket },
		{ key: 'gestura_pages_hidden', desc: m.privacy_storage_pages }
	];
</script>

<svelte:head>
	<title>{m.privacy_title()} · Gestura Index</title>
	<meta name="description" content={m.privacy_meta_description()} />
</svelte:head>

{#snippet shieldPill()}
	<Shield size={12} strokeWidth={2} />
{/snippet}

<!-- Kopfzeile eines Knotens: Titel, dahinter die Kurzform der Rechtsgrundlage
     in der Farbe des Knotenpunkts und die Speicherdauer gedämpft. Beide sind
     leer, wo der Fließtext sie nicht hergibt – erfunden wird nichts. -->
{#snippet nodeHead(title: string, basis: string, retention: string)}
	<div class="rail-head">
		<h2>{title}</h2>
		{#if basis}<span class="rail-basis">{basis}</span>{/if}
		{#if retention}<span class="rail-retention">· {retention}</span>{/if}
	</div>
{/snippet}

<div class="rail-page privacy-page">
	<PageGlow variant="narrow" />

	<div class="page-hero">
		<PageHeading pill={m.privacy_pill()} icon={shieldPill} accent={m.privacy_title()} />
		<p class="page-lead">{m.privacy_intro()}</p>
	</div>

	<div class="rail">
		<span class="rail-line" aria-hidden="true"></span>

		<section class="rail-node green rail-wide" id="facts">
			<h2>{m.privacy_facts_heading()}</h2>
			<ul class="facts">
				{#each facts as fact (fact.href)}
					<li>
						<a href={fact.href}>
							<span class="tick"><Check size={13} strokeWidth={2.6} /></span>
							<span>{fact.text()}</span>
						</a>
					</li>
				{/each}
			</ul>
		</section>

		<!-- Eigener Ton für den Wegweiser: Die Rail zeigt sonst Inhalte an; das
		     Verzeichnis ist die Ausnahme und trägt deshalb keinen der drei
		     Inhaltstöne, sondern Grau. -->
		<nav class="rail-node grey" id="toc" aria-label={m.privacy_toc_heading()}>
			<h2>{m.privacy_toc_heading()}</h2>
			<ul class="toc">
				{#each toc as item (item.id)}
					<li><a href="#{item.id}">{item.label()}</a></li>
				{/each}
			</ul>
		</nav>

		<section class="rail-node" id="controller">
			{@render nodeHead(m.privacy_controller_heading(), '', '')}
			<p>{m.privacy_controller_body()}</p>
		</section>

		<section class="rail-node violet" id="logs">
			{@render nodeHead(m.privacy_logs_heading(), m.privacy_basis_logs(), m.privacy_retention_logs())}
			<p>{m.privacy_logs_body()}</p>
		</section>

		<section class="rail-node" id="storage">
			{@render nodeHead(
				m.privacy_storage_heading(),
				m.privacy_basis_storage(),
				m.privacy_retention_storage()
			)}
			<p>{m.privacy_storage_body()}</p>
			<div class="keys">
				{#each storageKeys as entry (entry.key)}
					<div class="key-row">
						<code>{entry.key}</code>
						<span>{entry.desc()}</span>
					</div>
				{/each}
			</div>
			<p>{m.privacy_storage_legal()}</p>
		</section>

		<section class="rail-node violet" id="abuse">
			{@render nodeHead(
				m.privacy_abuse_heading(),
				m.privacy_basis_abuse(),
				m.privacy_retention_abuse()
			)}
			<p>{m.privacy_abuse_body()}</p>
		</section>

		<section class="rail-node" id="submit">
			{@render nodeHead(m.privacy_submit_heading(), m.privacy_basis_submit(), '')}
			<p>{m.privacy_submit_body()}</p>
		</section>

		<section class="rail-node violet" id="public-content">
			{@render nodeHead(m.privacy_public_content_heading(), '', '')}
			<p>{m.privacy_public_content_body()}</p>
		</section>

		<section class="rail-node" id="counters">
			{@render nodeHead(m.privacy_counters_heading(), '', '')}
			<p>{m.privacy_counters_body()}</p>
		</section>

		<section class="rail-node violet" id="reports">
			{@render nodeHead(m.privacy_reports_heading(), m.privacy_basis_reports(), '')}
			<p>{m.privacy_reports_body()}</p>
		</section>

		<section class="rail-node" id="accounts">
			{@render nodeHead(
				m.privacy_accounts_heading(),
				m.privacy_basis_accounts(),
				m.privacy_retention_accounts()
			)}
			<p>{m.privacy_accounts_body()}</p>
		</section>

		<section class="rail-node violet" id="ratings">
			{@render nodeHead(m.privacy_ratings_heading(), m.privacy_basis_ratings(), '')}
			<p>{m.privacy_ratings_body()}</p>
		</section>

		<section class="rail-node" id="sync">
			{@render nodeHead(m.privacy_sync_heading(), m.privacy_basis_sync(), m.privacy_retention_sync())}
			<p>{m.privacy_sync_body()}</p>
		</section>

		<section class="rail-node violet" id="updates">
			{@render nodeHead(
				m.privacy_updates_heading(),
				m.privacy_basis_updates(),
				m.privacy_retention_updates()
			)}
			<p>{m.privacy_updates_body()}</p>
		</section>

		<section class="rail-node" id="admin">
			{@render nodeHead(m.privacy_admin_heading(), m.privacy_basis_admin(), '')}
			<p>{m.privacy_admin_body()}</p>
		</section>

		<section class="rail-node violet" id="recipients">
			{@render nodeHead(m.privacy_recipients_heading(), '', '')}
			<p>{m.privacy_recipients_body()}</p>
		</section>

		<section class="rail-node" id="thirdcountry">
			{@render nodeHead(m.privacy_thirdcountry_heading(), '', '')}
			<p>{m.privacy_thirdcountry_body()}</p>
		</section>

		<section class="rail-node green" id="rights">
			{@render nodeHead(m.privacy_rights_heading(), '', '')}
			<p>{m.privacy_rights_intro()}</p>
			<ul class="plain">
				<li>{m.privacy_rights_access()}</li>
				<li>{m.privacy_rights_rectify()}</li>
				<li>{m.privacy_rights_erase()}</li>
				<li>{m.privacy_rights_restrict()}</li>
				<li>{m.privacy_rights_portability()}</li>
				<li>{m.privacy_rights_object()}</li>
			</ul>
			<p>{m.privacy_rights_anonymous()}</p>
			<p>{m.privacy_rights_contact()}</p>
		</section>

		<section class="rail-node" id="complaint">
			{@render nodeHead(m.privacy_complaint_heading(), '', '')}
			<p>{m.privacy_complaint_body()}</p>
		</section>

		<section class="rail-node violet" id="profiling">
			{@render nodeHead(m.privacy_no_profiling_heading(), '', '')}
			<p>{m.privacy_no_profiling_body()}</p>
		</section>

		<section class="rail-node green" id="changes">
			{@render nodeHead(m.privacy_changes_heading(), '', '')}
			<p>{m.privacy_changes_body()}</p>
		</section>
	</div>
</div>

<style>
	/*
	 * Nur Seitenspezifisches. Rail, Kopfbereich und Knoten-Grundform stehen
	 * global in pages.css – sie tragen auch das Impressum.
	 */

	/* Das Schild der Status-Pill: grün wie im Entwurf. Die Regel steht hier und
	   nicht in PageHeading, weil das Snippet im Scope DIESER Seite gerendert
	   wird – die Pill kennt weder Lucide noch die Farbe. */
	.privacy-page :global(.pill-icon) {
		color: var(--success-color);
	}

	/* ---- Kurzfassung ---- */
	.facts {
		list-style: none;
		margin: 0;
		padding: 0;
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 10px 26px;
	}
	.facts a {
		display: flex;
		align-items: flex-start;
		gap: 10px;
		text-decoration: none;
		color: var(--text-primary);
		font-size: 13.5px;
		line-height: 1.5;
	}
	.facts a:hover span:last-child {
		color: var(--accent-color);
	}
	.tick {
		flex-shrink: 0;
		margin-top: 1px;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 20px;
		height: 20px;
		border-radius: 7px;
		color: var(--success-color);
		background: oklch(from var(--success-color) l c h / 16%);
	}
	/* Wie beim PageGlow: auf hellem Grund braucht dieselbe wahrgenommene
	   Intensität einen höheren Alpha-Wert. */
	:global([data-theme='light']) .tick {
		background: oklch(from var(--success-color) l c h / 22%);
	}

	/* ---- Inhaltsverzeichnis ---- */
	/* `columns` füllt spaltenweise – erst die linke Spalte von oben nach unten,
	   dann die nächste. Genau die Lesefolge, die ein Verzeichnis braucht; ein
	   Grid würde zeilenweise füllen und die Reihenfolge der Abschnitte zerreißen. */
	.toc {
		list-style: none;
		margin: 0;
		padding: 0;
		columns: 3;
		column-gap: 32px;
	}
	.toc li {
		break-inside: avoid;
		margin-bottom: 7px;
	}
	.toc a {
		font-size: 12.5px;
		line-height: 1.5;
		color: var(--text-secondary);
		text-decoration: none;
	}
	.toc a:hover {
		color: var(--accent-color);
	}

	/* ---- Schlüssel-Tabelle: die einzige Box der Seite ---- */
	.keys {
		display: flex;
		flex-direction: column;
		border: 1px solid var(--page-hairline);
		border-radius: 12px;
		overflow: hidden;
		margin-top: 3px;
	}
	.key-row {
		display: flex;
		gap: 12px;
		align-items: baseline;
		flex-wrap: wrap;
		padding: 10px 14px;
	}
	.key-row + .key-row {
		border-top: 1px solid var(--page-hairline);
	}
	.key-row code {
		flex: none;
		font-family: var(--font-mono);
		font-size: 12px;
		color: var(--accent-color);
		background: oklch(from var(--accent-color) l c h / 10%);
		border-radius: 6px;
		padding: 2px 8px;
	}
	/* Basis = Mindestbreite, nicht `auto`: bei `flex-basis: auto` misst der
	   Umbruch-Algorithmus die INHALTSBREITE und schiebt lange Beschreibungen
	   grundlos unter den Schlüssel-Chip (siehe .claude/lessons.md). */
	.key-row span {
		flex: 1 1 260px;
		font-size: 13px;
		line-height: 1.6;
		color: var(--text-secondary);
	}

	.plain {
		margin: 0;
		padding-left: 20px;
		display: grid;
		gap: 5px;
		font-size: 13.5px;
		line-height: 1.7;
		color: var(--text-secondary);
	}

	@media (max-width: 760px) {
		.toc {
			columns: 2;
		}
	}
	@media (max-width: 640px) {
		.facts {
			grid-template-columns: 1fr;
		}
	}
	@media (max-width: 460px) {
		.toc {
			columns: 1;
		}
	}
</style>
