<script lang="ts">
	import { onMount } from 'svelte';
	import { page } from '$app/state';
	import { FileText, TriangleAlert, Flag, UserRound } from '@lucide/svelte';
	import { m } from '$lib/paraglide/messages.js';
	import { getLocale } from '$lib/paraglide/runtime';
	import {
		entryDetail,
		approveEntry,
		rejectEntry,
		banSubmitter,
		unbanSubmitter,
		AdminApiError,
		type EntryDetailAdmin,
		type ReportReason
	} from '$lib/admin/api';
	import { withStepUp } from '$lib/admin/stepup';
	import { categoryLabel } from '$lib/categories';
	import Spinner from '$lib/components/Spinner.svelte';
	import ErrorState from '$lib/components/ErrorState.svelte';
	import Badge from '$lib/components/Badge.svelte';

	// `page.params.id` statt `PageProps` (Vorgabe für diese Route) — Route-Param
	// ist bei uns immer eine Zahl (Eintrags-ID).
	const id = $derived(Number(page.params.id));

	let loading = $state(true);
	let notFound = $state(false);
	let loadError = $state<string | null>(null);
	let entry = $state<EntryDetailAdmin | null>(null);

	let entryBusy = $state(false);
	let entryActionError = $state<string | null>(null);

	let submitterBusy = $state(false);
	let submitterActionError = $state<string | null>(null);

	async function loadDetail() {
		loading = true;
		notFound = false;
		loadError = null;
		try {
			entry = await entryDetail(id);
		} catch (e) {
			if (e instanceof AdminApiError && e.status === 404) {
				notFound = true;
			} else {
				loadError = m.admin_entry_detail_load_failed();
			}
		} finally {
			loading = false;
		}
	}

	onMount(loadDetail);

	function reasonLabel(reason: ReportReason): string {
		switch (reason) {
			case 'spam':
				return m.report_spam();
			case 'broken_links':
				return m.report_broken_links();
			case 'misleading':
				return m.report_misleading();
			case 'legal':
				return m.report_legal();
		}
	}

	async function onApproveEntry() {
		entryBusy = true;
		entryActionError = null;
		try {
			await approveEntry(id);
			await loadDetail();
		} catch {
			entryActionError = m.admin_queue_approve_failed();
		} finally {
			entryBusy = false;
		}
	}

	async function onRejectEntry() {
		entryBusy = true;
		entryActionError = null;
		try {
			// Ablehnen ist step-up-geschützt (Server verlangt eine frische
			// Passkey-Bestätigung); `withStepUp` führt die Ceremony bei Bedarf
			// durch und wiederholt die Aktion genau einmal.
			await withStepUp(() => rejectEntry(id));
			await loadDetail();
		} catch (e) {
			if (e instanceof AdminApiError && e.backupRequired) {
				entryActionError = m.admin_queue_backup_required();
			} else {
				entryActionError = m.admin_queue_reject_failed();
			}
		} finally {
			entryBusy = false;
		}
	}

	async function onBanSubmitter() {
		if (!entry) return;
		submitterBusy = true;
		submitterActionError = null;
		try {
			// Sperren ist step-up-geschützt, Entsperren nicht (Server-Vorgabe) —
			// gleiches Muster wie das Ablehnen von Einträgen/Versionen.
			await withStepUp(() => banSubmitter(entry!.submitterId));
			await loadDetail();
		} catch (e) {
			if (e instanceof AdminApiError && e.backupRequired) {
				submitterActionError = m.admin_queue_backup_required();
			} else {
				submitterActionError = m.admin_entry_detail_ban_failed();
			}
		} finally {
			submitterBusy = false;
		}
	}

	async function onUnbanSubmitter() {
		if (!entry) return;
		submitterBusy = true;
		submitterActionError = null;
		try {
			await unbanSubmitter(entry.submitterId);
			await loadDetail();
		} catch {
			submitterActionError = m.admin_entry_detail_unban_failed();
		} finally {
			submitterBusy = false;
		}
	}
</script>

<svelte:head>
	<title>{entry?.name ?? m.admin_entry_detail_heading()} · Gestura Index Admin</title>
</svelte:head>

{#if loading}
	<Spinner />
{:else if notFound}
	<ErrorState message={m.admin_entry_detail_not_found()} />
{:else if loadError}
	<ErrorState message={loadError} onRetry={loadDetail} />
{:else if entry}
	<article>
		<header class="detail-head">
			<h1><FileText size={20} />{entry.name}</h1>
			<Badge text={entry.type === 'menu' ? m.type_menu() : m.type_engine()} />
			{#if entry.deprecated}<Badge text={m.badge_deprecated()} variant="warning" />{/if}
		</header>

		<p class="detail-format-id">{entry.formatId}</p>

		{#if entry.description}<p>{entry.description}</p>{/if}

		<div class="detail-cats">
			{#each entry.categories as cat (cat)}<Badge text={categoryLabel(cat)} />{/each}
			{#each entry.tags as tag (tag)}<Badge text={`#${tag}`} />{/each}
		</div>

		{#if entry.domains.length}
			<p><strong>{m.detail_domains()}:</strong> {entry.domains.join(', ')}</p>
		{/if}

		{#if entry.screenshotUrl}
			<img class="screenshot" src={entry.screenshotUrl} alt={entry.name} loading="lazy" />
		{/if}

		<div class="card detail-meta">
			<p>
				<strong>{m.admin_entry_detail_current_version()}:</strong>
				{entry.currentVersion ?? '—'}
			</p>
			<p><strong>{m.installs()}:</strong> {entry.installCount}</p>
			<p>
				<strong>{m.admin_entry_detail_updated()}:</strong>
				{new Date(entry.updatedAt).toLocaleDateString(getLocale())}
			</p>
			{#if entry.deprecated && entry.successorFormatId}
				<p><strong>{m.detail_successor()}:</strong> {entry.successorFormatId}</p>
			{/if}
		</div>

		<div class="detail-actions">
			<button class="btn btn-primary" onclick={onApproveEntry} disabled={entryBusy}>
				{m.admin_queue_approve_button()}
			</button>
			<button class="btn btn-danger" onclick={onRejectEntry} disabled={entryBusy}>
				{m.admin_queue_reject_button()}
			</button>
		</div>
		{#if entryActionError}<p class="detail-error" role="alert">{entryActionError}</p>{/if}

		<section>
			<h2>{m.detail_versions()}</h2>
			{#if entry.versions.length === 0}
				<p class="detail-section-empty">{m.admin_entry_detail_versions_empty()}</p>
			{:else}
				<ul class="versions">
					{#each entry.versions as v (v.semver)}
						<li class="card">
							<div class="version-head">
								<strong>{v.semver}</strong>
								{#if v.hasTransformCode}
									<Badge text={m.badge_transform()} variant="warning" icon={TriangleAlert} />
								{/if}
								<span class="detail-meta-text">
									{new Date(v.submittedAt).toLocaleDateString(getLocale())}
								</span>
							</div>
							{#if v.changelog}<p>{v.changelog}</p>{/if}
						</li>
					{/each}
				</ul>
			{/if}
		</section>

		<section>
			<h2><Flag size={18} />{m.admin_entry_detail_reports_heading()}</h2>
			{#if entry.openReports.length === 0}
				<p class="detail-section-empty">{m.admin_entry_detail_reports_empty()}</p>
			{:else}
				<ul class="versions">
					{#each entry.openReports as r (r.id)}
						<li class="card">
							<div class="version-head">
								<Badge text={reasonLabel(r.reason)} variant="warning" />
								<span class="detail-meta-text">
									{new Date(r.createdAt).toLocaleDateString(getLocale())}
								</span>
							</div>
							{#if r.comment}<p>{r.comment}</p>{/if}
						</li>
					{/each}
				</ul>
			{/if}
		</section>

		<section class="card">
			<h2><UserRound size={18} />{m.admin_entry_detail_submitter_heading()}</h2>
			<p>
				<strong>{m.admin_entry_detail_submitter_id()}:</strong>
				{entry.submitterId}
				{#if entry.submitterBanned}
					<Badge text={m.admin_entry_detail_submitter_banned_badge()} variant="warning" />
				{/if}
			</p>
			<div class="detail-actions">
				{#if entry.submitterBanned}
					<button class="btn btn-secondary" onclick={onUnbanSubmitter} disabled={submitterBusy}>
						{m.admin_entry_detail_unban_button()}
					</button>
				{:else}
					<button class="btn btn-danger" onclick={onBanSubmitter} disabled={submitterBusy}>
						{m.admin_entry_detail_ban_button()}
					</button>
				{/if}
			</div>
			{#if submitterActionError}<p class="detail-error" role="alert">{submitterActionError}</p>{/if}
		</section>
	</article>
{/if}

<style>
	.detail-head {
		display: flex;
		align-items: center;
		gap: 12px;
		flex-wrap: wrap;
	}

	h1 {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 1.3em;
		margin: 0;
	}

	h2 {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 1.05em;
		margin: 0 0 12px;
	}

	section {
		margin-top: 24px;
	}

	.detail-format-id {
		color: var(--text-secondary);
		font-size: 0.9em;
	}

	.detail-cats {
		display: flex;
		gap: 6px;
		flex-wrap: wrap;
		margin: 8px 0;
	}

	.screenshot {
		max-width: 100%;
		border-radius: 12px;
		border: 1px solid var(--border-color);
	}

	.detail-meta {
		display: flex;
		flex-direction: column;
		gap: 4px;
		margin: 16px 0;
	}

	.detail-meta-text {
		color: var(--text-secondary);
		font-size: 0.9em;
	}

	.detail-actions {
		display: flex;
		gap: 8px;
		margin-top: 12px;
	}

	.detail-error {
		color: var(--danger-color);
		font-weight: 600;
		margin-top: 12px;
	}

	.detail-section-empty {
		color: var(--text-secondary);
	}

	.versions {
		list-style: none;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	.version-head {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
	}
</style>
