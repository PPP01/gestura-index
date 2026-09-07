<script lang="ts">
	/*
	 * Impressum im Rail-Layout der Datenschutzseite (Handoff »4a«): Kopfbereich
	 * mit Status-Pill, darunter die Rail mit einem Knoten je Abschnitt. Struktur
	 * und Knotenform stehen global in pages.css (`.rail-page`, `.rail-node`) –
	 * hier unten nur die Betreiber-Felder.
	 *
	 * Die Pflichtangabe »Angaben gemäß § 5 DDG« steht in der Pill statt als
	 * eigener Absatz: sie benennt, was die Seite IST, und gehört damit neben den
	 * Titel – wie das »Stand: September 2026« der Datenschutzerklärung. Ein
	 * Einleitungsabsatz, der nur diesen einen Halbsatz trägt, wäre neben einer
	 * 44px-Überschrift ohnehin kein Absatz, sondern eine Zeile im Leeren.
	 *
	 * Zwei Knoten sind wenig für eine Rail. Das Impressum ist aber genau so
	 * kurz, und ein eigenes Layout für die eine kurze Seite hieße, dieselbe
	 * Gestalt zweimal zu pflegen.
	 */
	import { m } from '$lib/paraglide/messages.js';
	import PageGlow from '$lib/components/PageGlow.svelte';
	import PageHeading from '$lib/components/PageHeading.svelte';
	import { FileText } from '@lucide/svelte';
</script>

<svelte:head>
	<title>{m.imprint_title()} · Gestura Index</title>
	<meta name="description" content={m.imprint_meta_description()} />
</svelte:head>

<!-- Kein Schild wie auf der Datenschutzseite: das steht dort für den
     Datenschutz, nicht für »hier ist eine Pflichtangabe«. -->
{#snippet noticePill()}
	<FileText size={12} strokeWidth={2} />
{/snippet}

<div class="rail-page imprint-page">
	<PageGlow variant="narrow" />

	<div class="page-hero">
		<PageHeading pill={m.imprint_pill()} icon={noticePill} accent={m.imprint_title()} />
	</div>

	<div class="rail">
		<span class="rail-line" aria-hidden="true"></span>

		<section class="rail-node" id="operator">
			<h2>{m.imprint_operator_heading()}</h2>
			<dl class="fields">
				<div>
					<dt>{m.imprint_field_name()}</dt>
					<dd>{m.imprint_value_name()}</dd>
				</div>
				<div>
					<dt>{m.imprint_field_address()}</dt>
					<dd>
						<address>
							{m.imprint_value_street()}<br />
							{m.imprint_value_city()}<br />
							{m.imprint_value_country()}
						</address>
					</dd>
				</div>
				<div>
					<dt>{m.imprint_field_email()}</dt>
					<dd><a href="mailto:{m.imprint_value_email()}">{m.imprint_value_email()}</a></dd>
				</div>
			</dl>
		</section>

		<section class="rail-node green" id="note">
			<h2>{m.imprint_note_heading()}</h2>
			<p>{m.imprint_noncommercial()}</p>
		</section>
	</div>
</div>

<style>
	/* Die Pflichtangabe ist keine Zusicherung – deshalb der Akzent- statt des
	   grünen Tons, den die Datenschutzseite ihrem Schild gibt. */
	.imprint-page :global(.pill-icon) {
		color: var(--accent-color);
	}

	.fields {
		display: grid;
		gap: 8px;
		margin: 0;
		font-size: 13.5px;
		line-height: 1.7;
	}
	.fields div {
		display: flex;
		align-items: flex-start;
		gap: 8px;
	}
	dt {
		font-weight: 600;
		/* Feste Spaltenbreite statt Grid: die Werte sollen bündig stehen, aber
		   die Zeile darf bei sehr schmalen Fenstern umbrechen dürfen. */
		flex: 0 0 100px;
		color: var(--text-primary);
	}
	dd {
		margin: 0;
		color: var(--text-secondary);
	}
	/*
	 * Ohne diese Regel erbt der mailto-Link die Vorgabe des Browsers – gemessen
	 * rgb(0, 0, 238) mit Unterstreichung, also weder Theme-Farbe noch im
	 * dunklen Thema lesbar. Das war schon in der Kartenfassung so und fiel dort
	 * nur weniger auf. Der Entwurf verlangt Akzentfarbe; die Unterstreichung
	 * kommt beim Zeigen zurück, damit die Klickbarkeit nicht allein an der
	 * Farbe hängt.
	 */
	dd a {
		color: var(--accent-color);
		text-decoration: none;
	}
	dd a:hover,
	dd a:focus-visible {
		text-decoration: underline;
	}
	address {
		font-style: normal;
	}
</style>
