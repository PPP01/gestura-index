<script lang="ts">
	import { History } from '@lucide/svelte';
	import { m } from '$lib/paraglide/messages.js';
	import { getLocale } from '$lib/paraglide/runtime';
	import { audit, type AuditItem } from '$lib/admin/api';
	import Spinner from '$lib/components/Spinner.svelte';
	import ErrorState from '$lib/components/ErrorState.svelte';
	import EmptyState from '$lib/components/EmptyState.svelte';

	const perPage = 50;

	let page = $state(1);
	let loading = $state(true);
	let loadError = $state<string | null>(null);
	let items = $state<AuditItem[]>([]);

	// Die API liefert kein `total` — »Weiter« ist deshalb nur aktiv, wenn die
	// aktuelle Seite komplett gefüllt ist (impliziert: es könnte eine weitere
	// Seite geben). Siehe Team-Absprache in task-15-brief.md.
	const hasNext = $derived(items.length === perPage);

	async function loadAudit(p: number) {
		loading = true;
		loadError = null;
		try {
			const res = await audit(p, perPage);
			items = res.items;
		} catch {
			loadError = m.admin_audit_load_failed();
		} finally {
			loading = false;
		}
	}

	// Läuft beim Mount und bei jeder Änderung von `page`.
	$effect(() => {
		loadAudit(page);
	});

	function actorLabel(actor: string | null): string {
		return actor ?? m.admin_audit_actor_system();
	}
</script>

<svelte:head>
	<title>{m.admin_audit_heading()} · Gestura Index Admin</title>
</svelte:head>

<h1><History size={20} />{m.admin_audit_heading()}</h1>

{#if loading}
	<Spinner />
{:else if loadError}
	<ErrorState message={loadError} onRetry={() => loadAudit(page)} />
{:else if items.length === 0}
	<EmptyState title={m.admin_audit_empty_title()} />
{:else}
	<div class="card">
		<div class="audit-table" role="table">
			<div class="audit-row audit-row-head" role="row">
				<span role="columnheader">{m.admin_audit_actor_header()}</span>
				<span role="columnheader">{m.admin_audit_action_header()}</span>
				<span role="columnheader">{m.admin_audit_target_header()}</span>
				<span role="columnheader">{m.admin_audit_detail_header()}</span>
				<span role="columnheader">{m.admin_audit_created_header()}</span>
			</div>
			{#each items as entry (entry.id)}
				<div class="audit-row" role="row">
					<span role="cell">{actorLabel(entry.actor)}</span>
					<span role="cell">{entry.action}</span>
					<span role="cell">{entry.targetType} #{entry.targetId}</span>
					<span role="cell" class="audit-detail">
						{#if entry.detail !== null && entry.detail !== undefined}
							<code>{JSON.stringify(entry.detail)}</code>
						{:else}
							{m.admin_audit_detail_none()}
						{/if}
					</span>
					<span role="cell">{new Date(entry.createdAt).toLocaleDateString(getLocale())}</span>
				</div>
			{/each}
		</div>
	</div>

	<nav class="filter-bar audit-pager" aria-label={m.pager_nav_label()}>
		<button class="btn" disabled={page <= 1} onclick={() => (page -= 1)}>{m.pager_prev()}</button>
		<span>{m.admin_audit_page_info({ page })}</span>
		<button class="btn" disabled={!hasNext} onclick={() => (page += 1)}>{m.pager_next()}</button>
	</nav>
{/if}

<style>
	h1 {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 1.3em;
		margin: 0 0 16px;
	}

	.audit-table {
		display: flex;
		flex-direction: column;
	}

	.audit-row {
		display: grid;
		grid-template-columns: 1.1fr 1fr 1.1fr 1.6fr 0.9fr;
		gap: 12px;
		align-items: center;
		padding: 10px 0;
		border-bottom: 1px solid var(--border-color);
	}

	.audit-row:last-child {
		border-bottom: none;
	}

	.audit-row-head {
		color: var(--text-secondary);
		font-size: 0.85em;
		font-weight: 600;
	}

	.audit-detail code {
		display: inline-block;
		max-width: 100%;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		font-size: 0.85em;
	}

	.audit-pager {
		margin-top: 16px;
	}

	@media (max-width: 640px) {
		.audit-row {
			grid-template-columns: 1fr auto;
			grid-template-areas:
				'actor date'
				'action target'
				'detail detail';
		}

		.audit-row-head {
			display: none;
		}

		.audit-row span:nth-child(1) {
			grid-area: actor;
		}
		.audit-row span:nth-child(2) {
			grid-area: action;
		}
		.audit-row span:nth-child(3) {
			grid-area: target;
		}
		.audit-row span:nth-child(4) {
			grid-area: detail;
		}
		.audit-row span:nth-child(5) {
			grid-area: date;
		}
	}
</style>
