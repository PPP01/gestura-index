<script lang="ts">
	import { basket } from '$lib/basket.svelte';
	import { getBundle, type Bundle, type EntryListItem } from '$lib/api';
	import { triggerJsonDownload } from '$lib/download';
	import { resolveLocalized } from '$lib/localized';
	import { getLocale } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import { categoryColor, categoryIcon } from '$lib/categories';
	import { Trash2, X, Download, Send, Layers, Check } from '@lucide/svelte';

	let { catalog }: { catalog: Map<string, EntryListItem> } = $props();

	let open = $state(false);
	let downloading = $state(false);
	let downloadError = $state<string | null>(null);
	let sending = $state(false);
	let sendError = $state<string | null>(null);
	let sendNote = $state<string | null>(null);
	let sizeHint = $state(false);
	/** true zwischen »An Gestura senden« und der Rückmeldung der Erweiterung. */
	let awaitingResult = $state(false);
	let pendingTimer: ReturnType<typeof setTimeout> | null = null;
	/** IDs des laufenden Sendevorgangs – nur diese werden nach Erfolg entfernt. */
	let sentIds: string[] = [];
	/** Gesetzt nach vollständiger Übernahme; färbt den Pill, bis der Nutzer weitermacht. */
	let importDone = $state<{ menus: number; engines: number } | null>(null);

	/**
	 * Schwelle für den weichen Speicher-Hinweis (§5 des Extension-Berichts).
	 * Bewusst konservativ: die echte Grenze (chrome.storage.sync, 8192 Bytes je
	 * Item, minus schon belegter Delta-Speicher) kennen wir nicht – der Nutzer
	 * hat sie, wir nicht. Wir schätzen nur die gespeicherte Form unseres Korbs.
	 */
	const SEND_SIZE_HINT_BYTES = 5000;
	/** Gespeicherte Form ist ~15–20 % kleiner als das rohe JSON (Labels auf eine
	 *  Sprache eingedampft, Item-IDs neu vergeben) – Bericht §5. */
	const STORED_SIZE_RATIO = 0.82;

	const locale = $derived(getLocale());
	const entries = $derived(basket.ids.map((id) => ({ id, item: catalog.get(id) ?? null })));

	/**
	 * Menüs und Suchmaschinen getrennt – sie werden auch getrennt importiert und
	 * verhalten sich unterschiedlich. Die dritte Gruppe fängt Korb-IDs auf, zu
	 * denen der geladene Katalog (noch) keinen Eintrag kennt: ohne sie fielen
	 * sie aus der Liste und ließen sich nicht mehr entfernen.
	 */
	const groups = $derived(
		[
			{
				key: 'menu',
				label: m.basket_group_menus(),
				clearLabel: m.basket_clear_menus(),
				rows: entries.filter((e) => e.item?.type === 'menu')
			},
			{
				key: 'engine',
				label: m.basket_group_engines(),
				clearLabel: m.basket_clear_engines(),
				rows: entries.filter((e) => e.item?.type === 'engine')
			},
			{
				key: 'unknown',
				label: m.basket_group_unknown(),
				clearLabel: m.basket_clear_group(),
				rows: entries.filter((e) => !e.item)
			}
		].filter((g) => g.rows.length > 0)
	);

	/**
	 * Holt das Bundle für die gesamte Auswahl über einen einzigen Request
	 * (`getBundle`). Der Endpunkt lässt unbekannte/unveröffentlichte IDs
	 * stillschweigend aus – daher wird die zurückgelieferte Ergebnismenge
	 * gegen die angefragten IDs abgeglichen, um fehlende Einträge gesammelt
	 * zu melden. Ein Teil-Fehler darf den Download der übrigen Einträge
	 * nicht verhindern.
	 */
	async function download() {
		downloading = true;
		downloadError = null;
		const requested = basket.ids;
		let bundle: Bundle;
		try {
			bundle = await getBundle(requested);
		} catch {
			downloadError = m.basket_download_error({ ids: requested.join(', ') });
			return;
		} finally {
			downloading = false;
		}

		if (bundle.entries.length) {
			triggerJsonDownload(bundle, 'gestura-bundle.json');
		}

		const delivered = new Set(bundle.entries.map((e) => e.id ?? ''));
		const failed = requested.filter((id) => !delivered.has(id));
		if (failed.length) {
			downloadError = m.basket_download_error({ ids: failed.join(', ') });
		}
	}

	/**
	 * Live-Übergabe an die Extension (Vertrag §2). Die Seite holt das Bundle
	 * selbst und reicht es per DOM-Event weiter – die Extension fetcht auf
	 * diesem Weg nichts (kein Fetch-Proxy-Missbrauch, keine Cross-Origin-Frage).
	 *
	 * Vertrags-Feinheiten, die hier zwingend eingehalten werden:
	 * - `detail` ist ein **String** (§2.1): überquert die Welten-Grenze ohne
	 *   Firefox-Sonderbehandlung, und die Größenprüfung der Extension greift so
	 *   vor dem Parsen.
	 * - `data-gestura-inline` sitzt am Button selbst (§2.2, siehe Template).
	 * - Schlägt `getBundle` fehl, zeigen wir unsere eigene Meldung – die
	 *   Extension meldet nichts (§2.4).
	 *
	 * Rückweg (Nachtrag zum Vertrag): die Extension meldet die Nutzer-
	 * Entscheidung per `gestura:import-result` zurück (String-`detail` wie auf
	 * dem Hinweg, `status` = imported|cancelled|failed plus Zähler). Die Meldung
	 * KANN ausbleiben (Tab/Options-Seite geschlossen, Extension neu geladen),
	 * darum bleibt der eigene 15-s-Fallback bestehen – wer zuerst kommt, gewinnt.
	 */
	/**
	 * Rückkanal der Erweiterung (`gestura:import-result`, Nachtrag 3 des
	 * Übergabe-Vertrags). Der Listener hängt am Lebenszyklus der Komponente,
	 * NICHT am Sendevorgang: zwischen Klick und Meldung steht der Nutzer im
	 * Import-Dialog der Erweiterung, und das dauert regelmäßig länger als jeder
	 * Timeout, den man dem Sendevorgang mitgeben würde. Vorher meldete genau
	 * dieser Timeout den Listener ab – die Meldung kam an und traf ins Leere.
	 *
	 * `document`, weil die Erweiterung dort auslöst; sie steigt zwar seit
	 * Extension-Commit 58a3af9 auf, aber am Auslöseort zu lauschen ist
	 * unabhängig davon richtig.
	 */
	$effect(() => {
		function onResult(e: Event) {
			if (!awaitingResult) return; // fremde Meldung ohne eigenen Sendevorgang
			awaitingResult = false;
			if (pendingTimer !== null) {
				clearTimeout(pendingTimer);
				pendingTimer = null;
			}
			try {
				const r = JSON.parse((e as CustomEvent).detail) as {
					status?: string;
					menus?: number;
					engines?: number;
				};
				if (r.status === 'imported') {
					const menus = r.menus ?? 0;
					const engines = r.engines ?? 0;
					if (sentIds.length > 0 && menus + engines >= sentIds.length) {
						// Vollständig übernommen: aufräumen statt bestätigen. Entfernt werden
						// gezielt die GESENDETEN IDs – hätte der Nutzer inzwischen etwas
						// hinzugelegt, bliebe das erhalten.
						basket.removeMany(sentIds);
						open = false;
						sendNote = null;
						importDone = { menus, engines };
					} else {
						// Teilübernahme: der Korb bleibt. Welche Einträge der Nutzer im
						// Dialog abgewählt hat, verrät die Rückmeldung nicht – nur wie viele.
						sendNote = m.basket_send_partial({ menus, engines });
					}
				} else if (r.status === 'cancelled') {
					sendNote = m.basket_send_cancelled();
				} else if (r.status === 'failed') {
					sendNote = m.basket_send_failed();
				}
			} catch {
				/* fehlerhaftes detail ignorieren – dann bleibt schlicht keine Meldung stehen */
			}
		}
		document.addEventListener('gestura:import-result', onResult);
		return () => {
			document.removeEventListener('gestura:import-result', onResult);
			if (pendingTimer !== null) clearTimeout(pendingTimer);
		};
	});

	// Der Bestätigungs-Pill hält ohne Timer – er verschwindet, sobald der Nutzer
	// weitermacht (neu sammelt oder das Panel öffnet). Ein Zeitablauf wäre hier
	// wertlos: der Nutzer steht beim Import im Tab der Erweiterung und sieht
	// unsere Seite erst danach wieder.
	$effect(() => {
		if (basket.count > 0) importDone = null;
	});

	async function send() {
		sending = true;
		sendError = null;
		sendNote = null;
		sizeHint = false;
		let bundle: Bundle;
		try {
			bundle = await getBundle(basket.ids);
		} catch {
			sendError = m.basket_send_error();
			return;
		} finally {
			sending = false;
		}

		// §2.1: detail als String – einmal serialisieren, für Event und Schätzung nutzen.
		const payload = JSON.stringify(bundle);

		// Der Rückkanal-Listener hängt NICHT an diesem Aufruf (siehe $effect oben).
		awaitingResult = true;
		sentIds = basket.ids.slice();
		if (pendingTimer !== null) clearTimeout(pendingTimer);
		// Weicher Hinweis nach 15 s – er räumt bewusst NICHTS ab: der Nutzer steht
		// währenddessen im Import-Dialog der Erweiterung, das dauert regelmäßig
		// länger. Kommt die echte Rückmeldung danach, ersetzt sie den Hinweis.
		pendingTimer = setTimeout(() => {
			pendingTimer = null;
			if (awaitingResult) sendNote = m.basket_send_pending();
		}, 15000);

		document.dispatchEvent(new CustomEvent('gestura:import', { detail: payload }));

		// §5: exakte Schätzung der gespeicherten Form aus dem echten Bundle –
		// UTF-8-Bytes (TextEncoder), nicht String-Länge, weil die storage.sync-
		// Grenze in Bytes gilt und Umlaute je 2 Bytes belegen. Weicher Hinweis,
		// kein Deckel: die Extension ist die eigentliche Kontrolle.
		const rawBytes = new TextEncoder().encode(payload).length;
		if (rawBytes * STORED_SIZE_RATIO > SEND_SIZE_HINT_BYTES) {
			sizeHint = true;
		}
	}
</script>

<div class="tray">
	{#if open}
		<div class="tray-panel">
			<button
				class="tray-close"
				onclick={() => (open = false)}
				aria-label={m.basket_close()}
				title={m.basket_close()}
			>
				<X size={16} />
			</button>
			<div class="tray-scroll">
				{#if basket.count === 0}
					<div class="tray-empty">
						<span class="icon-tile tray-empty-icon"><Layers size={24} /></span>
						<h3 class="tray-empty-title">{m.basket_empty_title()}</h3>
						<p class="tray-empty-hint">{m.basket_empty_hint()}</p>
					</div>
				{:else}
					<div class="tray-head">
						<strong class="tray-title">{m.basket_title()}</strong>
						<span class="tray-count-badge">{basket.count}</span>
						<button class="tray-clear" onclick={() => basket.clear()}>
							<Trash2 size={13} /> {m.basket_clear()}
						</button>
					</div>
					{#each groups as group (group.key)}
						<section class="tray-group">
							<h4 class="tray-group-head">
								{group.label}<span class="tray-group-count">{group.rows.length}</span>
								<button
									class="tray-clear tray-group-clear"
									onclick={() => basket.removeMany(group.rows.map((r) => r.id))}
									title={group.clearLabel}
								>
									<Trash2 size={12} /> {group.clearLabel}
								</button>
							</h4>
							<ul class="tray-list">
								{#each group.rows as e (e.id)}
									{@const cat = e.item?.categories[0] ?? 'other'}
									{@const RowIcon = categoryIcon(cat)}
									{@const name = e.item ? resolveLocalized(e.item.name, locale) || e.id : e.id}
									<li class="tray-row">
										<span class="icon-tile tray-row-icon" style={`--icon-color:${categoryColor(cat)}`}>
											<RowIcon size={14} />
										</span>
										<span class="tray-row-text">
											<span class="tray-row-name">{name}</span>
										</span>
										<button
											class="tray-row-remove"
											onclick={() => basket.remove(e.id)}
											aria-label={m.basket_remove_named({ name })}
											title={m.basket_remove_named({ name })}
										>
											<Trash2 size={14} />
										</button>
									</li>
								{/each}
							</ul>
						</section>
					{/each}
					<div class="tray-footer">
						<button class="btn btn-primary tray-footer-btn" onclick={download} disabled={downloading}>
							<Download size={16} /> {m.basket_download()}
						</button>
						<button
							class="btn btn-secondary tray-footer-btn tray-send"
							data-gestura-inline
							onclick={send}
							disabled={sending}
						>
							<Send size={16} /> {m.basket_send()}
						</button>
						<p class="tray-hint">{m.basket_local_hint()}</p>
						{#if downloadError}<p class="tray-err">{downloadError}</p>{/if}
						{#if sendError}<p class="tray-err">{sendError}</p>{/if}
						{#if sendNote}<p class="tray-note">{sendNote}</p>{/if}
						{#if sizeHint}<p class="tray-note">{m.basket_size_hint()}</p>{/if}
					</div>
				{/if}
			</div>
		</div>
	{/if}

	<button
		class="tray-pill"
		class:tray-pill-accent={basket.count > 0}
		class:tray-pill-done={importDone !== null}
		onclick={() => {
			importDone = null;
			open = !open;
		}}
		aria-expanded={open}
	>
		{#if importDone}
			<Check size={15} />{m.basket_pill_done()}
		{:else}
			{m.basket_open({ count: basket.count })}
		{/if}
	</button>
</div>

<style>
	.tray {
		position: fixed;
		/*
		 * Fixiert, aber NICHT am Fensterrand: auf breiten Monitoren klebte der
		 * Knopf weit außerhalb der zentrierten Shell. Der Ausdruck hält ihn 24px
		 * innerhalb der Shell-Kante und fällt bei schmalen Fenstern (Shell füllt
		 * die Breite) auf schlichte 24px zurück. 50% statt 50vw – bei fixed ist
		 * der Bezug das Viewport OHNE Scrollbar, vw rechnet sie mit.
		 */
		right: max(24px, calc(50% - var(--page-max-width) / 2 + 24px));
		bottom: 24px;
		z-index: 50;
		display: flex;
		flex-direction: column;
		align-items: flex-end;
		gap: 12px;
	}
	.tray-pill {
		display: inline-flex;
		align-items: center;
		gap: 7px;
		border: none;
		border-radius: 999px;
		padding: 12px 24px;
		font-size: 13px;
		font-weight: 600;
		cursor: pointer;
		background: var(--bg-tertiary);
		color: var(--text-secondary);
		box-shadow: var(--section-shadow) 0 8px 20px 0px;
	}
	.tray-pill-accent {
		background: var(--accent-color);
		color: #fff;
		box-shadow: 0 12px 32px color-mix(in srgb, var(--accent-color) 40%, transparent);
	}
	/* Nach vollständiger Übernahme – steht ohne Timer, bis der Nutzer weitermacht. */
	.tray-pill-done {
		background: var(--success-color);
		color: #fff;
		box-shadow: 0 12px 32px color-mix(in srgb, var(--success-color) 40%, transparent);
	}
	.tray-panel {
		position: relative;
		width: min(390px, 92vw);
		/* Gescrollt wird INNEN (.tray-scroll), damit der Schließen-Knopf bei
		   langer Auswahl nicht nach oben aus dem Panel scrollt. */
		overflow: hidden;
		background: var(--panel-bg);
		border-radius: 20px;
		box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
		padding: 20px;
	}
	.tray-scroll {
		max-height: calc(75vh - 40px);
		overflow: auto;
		overscroll-behavior: contain;
	}
	.tray-close {
		position: absolute;
		/* Dicht in die Ecke – so liest sich das X klar als »Fenster zu« und
		   konkurriert nicht mit den Aktionen der Kopfzeile. */
		top: 5px;
		right: 5px;
		z-index: 2;
		display: flex;
		align-items: center;
		justify-content: center;
		width: 28px;
		height: 28px;
		border-radius: 8px;
		border: none;
		background: none;
		color: var(--text-muted);
		cursor: pointer;
	}
	.tray-close:hover {
		background: var(--bg-tertiary);
		color: var(--text-primary);
	}
	.tray-head {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-bottom: 12px;
		/* Platz für den absolut gesetzten Schließen-Knopf, damit »Alle
		   entfernen« nicht darunter rutscht. */
		padding-inline-end: 30px;
	}
	.tray-title {
		font-size: 15px;
		color: var(--text-primary);
	}
	.tray-count-badge {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		min-width: 20px;
		height: 20px;
		padding: 0 6px;
		border-radius: 999px;
		background: var(--accent-color);
		color: #fff;
		font-size: 11px;
		font-weight: 700;
	}
	.tray-clear {
		margin-left: auto;
		display: inline-flex;
		align-items: center;
		gap: 5px;
		background: none;
		border: none;
		color: var(--text-secondary);
		cursor: pointer;
		padding: 0;
		font-size: 12px;
		text-align: right;
	}
	.tray-clear:hover {
		color: var(--danger-color);
	}
	/* Die Gruppen sind durch Überschrift UND Trennlinie geschieden – ein
	   Abstand allein liest sich in einer dichten Liste nicht als Grenze. */
	.tray-group + .tray-group {
		margin-top: 18px;
		padding-top: 14px;
		border-top: 1px solid var(--border-color-solid);
	}
	.tray-group-head {
		display: flex;
		align-items: center;
		/* Auf schmalen Panels (92vw) passt »Alle Suchmaschinen entfernen« nicht
		   neben die Überschrift – dann rutscht der Knopf in die nächste Zeile,
		   statt seinen Text umzubrechen. */
		flex-wrap: wrap;
		gap: 4px 7px;
		margin: 0 0 2px;
		font-size: 10.5px;
		font-weight: 600;
		letter-spacing: 0.08em;
		text-transform: uppercase;
		color: var(--text-muted);
	}
	.tray-group-clear {
		margin-inline-start: auto;
		font-size: 10.5px;
		font-weight: 600;
		letter-spacing: 0;
		text-transform: none;
	}
	.tray-group-count {
		font-size: 10px;
		letter-spacing: 0;
		padding: 1px 6px;
		border-radius: 999px;
		background: var(--badge-bg);
		color: var(--badge-text);
	}
	.tray-list {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
	}
	.tray-row {
		display: flex;
		align-items: center;
		gap: 10px;
		padding: 10px 0;
		border-top: 1px solid var(--border-color);
	}
	.tray-row:first-child {
		border-top: none;
	}
	.tray-row-icon {
		width: 30px;
		height: 30px;
		border-radius: 9px;
	}
	.tray-row-text {
		flex: 1 1 auto;
		min-width: 0;
		display: flex;
		flex-direction: column;
	}
	.tray-row-name {
		font-size: 13.5px;
		font-weight: 600;
		color: var(--text-primary);
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}
	.tray-row-remove {
		flex: 0 0 auto;
		background: none;
		border: none;
		color: var(--text-muted);
		cursor: pointer;
		display: inline-flex;
		padding: 4px;
	}
	.tray-row-remove:hover {
		color: var(--danger-color);
	}
	.tray-footer {
		display: flex;
		flex-direction: column;
		gap: 8px;
		margin-top: 14px;
	}
	.tray-footer-btn {
		width: 100%;
	}
	.tray-send:disabled {
		opacity: 0.6;
		cursor: not-allowed;
	}
	.tray-hint {
		margin: 2px 0 0;
		font-size: 11px;
		color: var(--text-muted);
		text-align: center;
	}
	.tray-err {
		margin: 0;
		font-size: 12px;
		color: var(--danger-color);
	}
	.tray-note {
		margin: 0;
		font-size: 12px;
		color: var(--text-secondary);
	}
	.tray-empty {
		display: flex;
		flex-direction: column;
		align-items: center;
		text-align: center;
		gap: 8px;
		padding: 16px 8px;
	}
	.tray-empty-icon {
		width: 52px;
		height: 52px;
		border-radius: 16px;
		margin-bottom: 4px;
	}
	.tray-empty-title {
		font-size: 15px;
		margin: 0;
		color: var(--text-primary);
	}
	.tray-empty-hint {
		font-size: 12.5px;
		color: var(--text-secondary);
		margin: 0;
	}
	@media (max-width: 720px) {
		.tray {
			right: auto;
			left: 50%;
			transform: translateX(-50%);
			align-items: center;
		}
	}
</style>
