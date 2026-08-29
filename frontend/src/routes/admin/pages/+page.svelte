<script lang="ts">
	import { onMount } from 'svelte';
	import { FileText } from '@lucide/svelte';
	import { m } from '$lib/paraglide/messages.js';
	import { pages, setPageEnabled, type AdminPageSetting } from '$lib/admin/api';
	import Spinner from '$lib/components/Spinner.svelte';
	import ErrorState from '$lib/components/ErrorState.svelte';

	let items = $state<AdminPageSetting[]>([]);
	let loading = $state(true);
	let loadError = $state(false);
	let busy = $state<string | null>(null);

	// Slug → Anzeigename; die Slugs entsprechen der Server-Whitelist
	// (was-ist-gestura|maus-gesten|vergleich|beispiele).
	const DISPLAY: Record<string, () => string> = {
		'was-ist-gestura': () => m.admin_page_was_ist_gestura(),
		'maus-gesten': () => m.admin_page_maus_gesten(),
		vergleich: () => m.admin_page_vergleich(),
		beispiele: () => m.admin_page_beispiele()
	};

	async function reload() {
		loading = true;
		loadError = false;
		try {
			items = await pages();
		} catch {
			loadError = true;
		} finally {
			loading = false;
		}
	}

	async function toggle(item: AdminPageSetting) {
		busy = item.pageKey;
		try {
			await setPageEnabled(item.pageKey, !item.enabled);
			await reload();
		} catch {
			loadError = true;
		} finally {
			busy = null;
		}
	}

	onMount(reload);
</script>

<svelte:head>
	<title>{m.admin_nav_pages()} · Gestura Index Admin</title>
</svelte:head>

<h1><FileText size={20} />{m.admin_pages_title()}</h1>
<p class="hint">{m.admin_pages_hint()}</p>

{#if loading}
	<Spinner />
{:else if loadError}
	<ErrorState message={m.admin_pages_load_error()} onRetry={reload} />
{:else}
	<ul class="page-list">
		{#each items as item (item.pageKey)}
			<li class="card page-card">
				<span class="name">{DISPLAY[item.pageKey]?.() ?? item.pageKey}</span>
				<button
					class="btn"
					class:btn-primary={item.enabled}
					disabled={busy === item.pageKey}
					onclick={() => toggle(item)}
				>
					{item.enabled ? m.admin_pages_active() : m.admin_pages_inactive()}
				</button>
			</li>
		{/each}
	</ul>
{/if}

<style>
	h1 {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 1.3em;
		margin: 0 0 8px;
	}

	.hint {
		color: var(--text-secondary);
		margin: 0 0 16px;
	}

	.page-list {
		list-style: none;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 10px;
	}

	.page-card {
		display: flex;
		align-items: center;
		justify-content: space-between;
	}

	.name {
		font-weight: 600;
	}
</style>
