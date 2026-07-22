<script lang="ts">
	import { onMount } from 'svelte';
	import { Flag } from '@lucide/svelte';
	import { m } from '$lib/paraglide/messages.js';
	import { getLocale, localizeHref } from '$lib/paraglide/runtime';
	import { reports, resolveReport, type Report, type ReportReason } from '$lib/admin/api';
	import Spinner from '$lib/components/Spinner.svelte';
	import ErrorState from '$lib/components/ErrorState.svelte';
	import EmptyState from '$lib/components/EmptyState.svelte';
	import Badge from '$lib/components/Badge.svelte';

	let loading = $state(true);
	let loadError = $state<string | null>(null);
	let items = $state<Report[]>([]);

	let busyId = $state<number | null>(null);
	let actionError = $state<string | null>(null);

	async function loadReports() {
		loading = true;
		loadError = null;
		try {
			items = await reports();
		} catch {
			loadError = m.admin_reports_load_failed();
		} finally {
			loading = false;
		}
	}

	onMount(loadReports);

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

	async function onResolve(id: number, publish: boolean) {
		busyId = id;
		actionError = null;
		try {
			// Meldungen erledigen ist laut SP4a-Backend (ReportResolveController)
			// nicht step-up-geschützt (weder Freigeben noch Löschen prüft
			// StepUpGuard/BackupPasskeyGate) — daher Direktaufruf ohne withStepUp.
			await resolveReport(id, publish);
			await loadReports();
		} catch {
			actionError = m.admin_reports_resolve_failed();
		} finally {
			busyId = null;
		}
	}
</script>

<svelte:head>
	<title>{m.admin_nav_reports()} · Gestura Index Admin</title>
</svelte:head>

<h1><Flag size={20} />{m.admin_nav_reports()}</h1>

{#if loading}
	<Spinner />
{:else if loadError}
	<ErrorState message={loadError} onRetry={loadReports} />
{:else if items.length === 0}
	<EmptyState title={m.admin_reports_empty_title()} />
{:else}
	<div class="reports-list">
		{#each items as report (report.id)}
			<div class="card reports-card">
				<div class="reports-card-head">
					<Badge text={reasonLabel(report.reason)} variant="warning" />
					<a class="reports-format-link" href={localizeHref(`/admin/entries/${report.entryId}`)}>
						{report.formatId}
					</a>
					{#if report.submitterBanned}
						<Badge text={m.admin_entry_detail_submitter_banned_badge()} variant="warning" />
					{/if}
				</div>
				{#if report.comment}<p>{report.comment}</p>{/if}
				<span class="reports-meta">
					{new Date(report.createdAt).toLocaleDateString(getLocale())}
				</span>
				<div class="reports-actions">
					<button
						class="btn btn-primary"
						onclick={() => onResolve(report.id, true)}
						disabled={busyId === report.id}
					>
						{m.admin_reports_keep_button()}
					</button>
					<button
						class="btn btn-danger"
						onclick={() => onResolve(report.id, false)}
						disabled={busyId === report.id}
					>
						{m.admin_reports_reject_button()}
					</button>
				</div>
			</div>
		{/each}
	</div>
	{#if actionError}<p class="reports-error" role="alert">{actionError}</p>{/if}
{/if}

<style>
	h1 {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 1.3em;
		margin: 0 0 16px;
	}

	.reports-list {
		display: flex;
		flex-direction: column;
		gap: 12px;
	}

	.reports-card {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	.reports-card-head {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
	}

	.reports-format-link {
		font-weight: 600;
		color: inherit;
		text-decoration: none;
	}

	.reports-format-link:hover {
		text-decoration: underline;
	}

	.reports-meta {
		color: var(--text-secondary);
		font-size: 0.9em;
	}

	.reports-actions {
		display: flex;
		gap: 8px;
	}

	.reports-error {
		color: var(--danger-color);
		font-weight: 600;
		margin-top: 12px;
	}
</style>
