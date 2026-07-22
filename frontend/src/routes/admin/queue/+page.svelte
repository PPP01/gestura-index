<script lang="ts">
	import { onMount } from 'svelte';
	import { ListChecks, TriangleAlert } from '@lucide/svelte';
	import { m } from '$lib/paraglide/messages.js';
	import { getLocale, localizeHref } from '$lib/paraglide/runtime';
	import {
		queue,
		approveEntry,
		rejectEntry,
		approveVersion,
		rejectVersion,
		AdminApiError,
		type QueueEntry,
		type QueueVersion
	} from '$lib/admin/api';
	import { withStepUp } from '$lib/admin/stepup';
	import Spinner from '$lib/components/Spinner.svelte';
	import ErrorState from '$lib/components/ErrorState.svelte';
	import EmptyState from '$lib/components/EmptyState.svelte';
	import Badge from '$lib/components/Badge.svelte';

	let loading = $state(true);
	let loadError = $state<string | null>(null);
	let entries = $state<QueueEntry[]>([]);
	let versions = $state<QueueVersion[]>([]);

	let busyEntryId = $state<number | null>(null);
	let entryActionError = $state<string | null>(null);

	let busyVersionId = $state<number | null>(null);
	let versionActionError = $state<string | null>(null);

	async function loadQueue() {
		loading = true;
		loadError = null;
		try {
			const res = await queue();
			entries = res.entries;
			versions = res.versions;
		} catch {
			loadError = m.admin_queue_load_failed();
		} finally {
			loading = false;
		}
	}

	onMount(loadQueue);

	function typeLabel(type: 'menu' | 'engine') {
		return type === 'menu' ? m.type_menu() : m.type_engine();
	}

	async function onApproveEntry(id: number) {
		busyEntryId = id;
		entryActionError = null;
		try {
			await approveEntry(id);
			await loadQueue();
		} catch {
			entryActionError = m.admin_queue_approve_failed();
		} finally {
			busyEntryId = null;
		}
	}

	async function onRejectEntry(id: number) {
		busyEntryId = id;
		entryActionError = null;
		try {
			// Ablehnen ist step-up-geschützt (Server verlangt eine frische
			// Passkey-Bestätigung); `withStepUp` führt die Ceremony bei Bedarf
			// durch und wiederholt die Aktion genau einmal.
			await withStepUp(() => rejectEntry(id));
			await loadQueue();
		} catch (e) {
			// 409 mit `backupRequired`: der Server verlangt für die Step-up-Ceremony
			// selbst mindestens 2 Passkeys als Backup.
			if (e instanceof AdminApiError && e.backupRequired) {
				entryActionError = m.admin_queue_backup_required();
			} else {
				entryActionError = m.admin_queue_reject_failed();
			}
		} finally {
			busyEntryId = null;
		}
	}

	async function onApproveVersion(id: number) {
		busyVersionId = id;
		versionActionError = null;
		try {
			await approveVersion(id);
			await loadQueue();
		} catch {
			versionActionError = m.admin_queue_approve_failed();
		} finally {
			busyVersionId = null;
		}
	}

	async function onRejectVersion(id: number) {
		busyVersionId = id;
		versionActionError = null;
		try {
			await withStepUp(() => rejectVersion(id));
			await loadQueue();
		} catch (e) {
			if (e instanceof AdminApiError && e.backupRequired) {
				versionActionError = m.admin_queue_backup_required();
			} else {
				versionActionError = m.admin_queue_reject_failed();
			}
		} finally {
			busyVersionId = null;
		}
	}
</script>

<svelte:head>
	<title>{m.admin_queue_heading()} · Gestura Index</title>
</svelte:head>

<h1><ListChecks size={20} />{m.admin_queue_heading()}</h1>

{#if loading}
	<Spinner />
{:else if loadError}
	<ErrorState message={loadError} onRetry={loadQueue} />
{:else if entries.length === 0 && versions.length === 0}
	<EmptyState title={m.admin_queue_empty_title()} />
{:else}
	<section>
		<h2>{m.admin_queue_entries_heading()}</h2>
		{#if entries.length === 0}
			<p class="queue-section-empty">{m.admin_queue_empty_title()}</p>
		{:else}
			<div class="queue-list">
				{#each entries as entry (entry.id)}
					<div class="card queue-card">
						<div class="queue-card-head">
							<Badge text={typeLabel(entry.type)} />
							<a class="queue-format-link" href={localizeHref(`/admin/entries/${entry.id}`)}>
								{entry.formatId}
							</a>
						</div>
						<span class="queue-meta">
							{new Date(entry.createdAt).toLocaleDateString(getLocale())}
						</span>
						<div class="queue-actions">
							<button
								class="btn btn-primary"
								onclick={() => onApproveEntry(entry.id)}
								disabled={busyEntryId === entry.id}
							>
								{m.admin_queue_approve_button()}
							</button>
							<button
								class="btn btn-danger"
								onclick={() => onRejectEntry(entry.id)}
								disabled={busyEntryId === entry.id}
							>
								{m.admin_queue_reject_button()}
							</button>
						</div>
					</div>
				{/each}
			</div>
		{/if}
		{#if entryActionError}<p class="queue-error" role="alert">{entryActionError}</p>{/if}
	</section>

	<section>
		<h2>{m.admin_queue_versions_heading()}</h2>
		{#if versions.length === 0}
			<p class="queue-section-empty">{m.admin_queue_empty_title()}</p>
		{:else}
			<div class="queue-list">
				{#each versions as version (version.id)}
					<div class="card queue-card">
						<div class="queue-card-head">
							<a
								class="queue-format-link"
								href={localizeHref(`/admin/entries/${version.entryId}`)}
							>
								{version.formatId}
							</a>
							<span class="queue-semver">{version.semver}</span>
							{#if version.hasTransformCode}
								<Badge
									text={m.admin_queue_transform_badge()}
									variant="warning"
									icon={TriangleAlert}
								/>
							{/if}
						</div>
						<span class="queue-meta">
							{new Date(version.submittedAt).toLocaleDateString(getLocale())}
						</span>
						<div class="queue-actions">
							<button
								class="btn btn-primary"
								onclick={() => onApproveVersion(version.id)}
								disabled={busyVersionId === version.id}
							>
								{m.admin_queue_approve_button()}
							</button>
							<button
								class="btn btn-danger"
								onclick={() => onRejectVersion(version.id)}
								disabled={busyVersionId === version.id}
							>
								{m.admin_queue_reject_button()}
							</button>
						</div>
					</div>
				{/each}
			</div>
		{/if}
		{#if versionActionError}<p class="queue-error" role="alert">{versionActionError}</p>{/if}
	</section>
{/if}

<style>
	h1 {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 1.3em;
		margin: 0 0 16px;
	}

	section {
		margin-bottom: 24px;
	}

	h2 {
		font-size: 1.05em;
		margin: 0 0 12px;
	}

	.queue-section-empty {
		color: var(--text-secondary);
	}

	.queue-list {
		display: flex;
		flex-direction: column;
		gap: 12px;
	}

	.queue-card {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 12px;
	}

	.queue-card-head {
		display: flex;
		align-items: center;
		gap: 8px;
		flex: 1 1 auto;
	}

	.queue-format-link {
		font-weight: 600;
		color: inherit;
		text-decoration: none;
	}

	.queue-format-link:hover {
		text-decoration: underline;
	}

	.queue-semver {
		color: var(--text-secondary);
	}

	.queue-meta {
		color: var(--text-secondary);
		font-size: 0.9em;
	}

	.queue-actions {
		display: flex;
		gap: 8px;
	}

	.queue-error {
		color: var(--danger-color);
		font-weight: 600;
		margin-top: 12px;
	}
</style>
