<script lang="ts">
	import { basket } from '$lib/basket.svelte';
	import { getBundle, type Bundle, type EntryListItem } from '$lib/api';
	import { triggerJsonDownload } from '$lib/download';
	import { resolveLocalized } from '$lib/localized';
	import { getLocale } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import { categoryColor, categoryIcon, entryTypeLabel } from '$lib/categories';
	import { Trash2, X, Download, Send, Layers } from '@lucide/svelte';

	let { catalog }: { catalog: Map<string, EntryListItem> } = $props();

	let open = $state(false);
	let downloading = $state(false);
	let downloadError = $state<string | null>(null);
	let sending = $state(false);
	let sendError = $state<string | null>(null);
	let sendDone = $state(false);
	let sizeHint = $state(false);

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
	 */
	async function send() {
		sending = true;
		sendError = null;
		sendDone = false;
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

		document.dispatchEvent(new CustomEvent('gestura:import', { detail: JSON.stringify(bundle) }));
		sendDone = true;

		// §5: exakte Schätzung der gespeicherten Form aus dem echten Bundle –
		// UTF-8-Bytes (TextEncoder), nicht String-Länge, weil die storage.sync-
		// Grenze in Bytes gilt und Umlaute je 2 Bytes belegen. Weicher Hinweis,
		// kein Deckel: die Extension ist die eigentliche Kontrolle.
		const rawBytes = new TextEncoder().encode(JSON.stringify(bundle)).length;
		if (rawBytes * STORED_SIZE_RATIO > SEND_SIZE_HINT_BYTES) {
			sizeHint = true;
		}
	}
</script>

<div class="tray">
	{#if open}
		<div class="tray-panel">
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
				<ul class="tray-list">
					{#each entries as e (e.id)}
						{@const cat = e.item?.categories[0] ?? 'other'}
						{@const RowIcon = categoryIcon(cat)}
						{@const name = e.item ? resolveLocalized(e.item.name, locale) || e.id : e.id}
						<li class="tray-row">
							<span class="icon-tile tray-row-icon" style={`--icon-color:${categoryColor(cat)}`}>
								<RowIcon size={14} />
							</span>
							<span class="tray-row-text">
								<span class="tray-row-name">{name}</span>
								{#if e.item}
									<span class="tray-row-type">{entryTypeLabel(e.item.type)}</span>
								{/if}
							</span>
							<button
								class="tray-row-remove"
								onclick={() => basket.remove(e.id)}
								aria-label={m.basket_remove_named({ name })}
								title={m.basket_remove_named({ name })}
							>
								<X size={14} />
							</button>
						</li>
					{/each}
				</ul>
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
					{#if sendDone}<p class="tray-note">{m.basket_send_done()}</p>{/if}
					{#if sizeHint}<p class="tray-note">{m.basket_size_hint()}</p>{/if}
				</div>
			{/if}
		</div>
	{/if}

	<button
		class="tray-pill"
		class:tray-pill-accent={basket.count > 0}
		onclick={() => (open = !open)}
		aria-expanded={open}
	>
		{m.basket_open({ count: basket.count })}
	</button>
</div>

<style>
	.tray {
		position: fixed;
		right: 24px;
		bottom: 24px;
		z-index: 50;
		display: flex;
		flex-direction: column;
		align-items: flex-end;
		gap: 12px;
	}
	.tray-pill {
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
	.tray-panel {
		width: min(390px, 92vw);
		max-height: 75vh;
		overflow: auto;
		background: var(--panel-bg);
		border-radius: 20px;
		box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
		padding: 20px;
	}
	.tray-head {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-bottom: 12px;
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
	.tray-row-type {
		font-size: 11.5px;
		color: var(--text-secondary);
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
