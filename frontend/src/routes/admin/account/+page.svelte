<script lang="ts">
	import { onMount } from 'svelte';
	import { KeyRound, Plus, Pencil, Trash2, Check, X } from '@lucide/svelte';
	import { m } from '$lib/paraglide/messages.js';
	import { getLocale } from '$lib/paraglide/runtime';
	import {
		listCredentials,
		addCredentialOptions,
		addCredential,
		renameCredential,
		removeCredential,
		AdminApiError,
		type Credential
	} from '$lib/admin/api';
	import { performRegistration } from '$lib/admin/webauthn';
	import { withStepUp } from '$lib/admin/stepup';
	import { session } from '$lib/admin/session.svelte';
	import Spinner from '$lib/components/Spinner.svelte';
	import ErrorState from '$lib/components/ErrorState.svelte';
	import EmptyState from '$lib/components/EmptyState.svelte';

	let loading = $state(true);
	let loadError = $state<string | null>(null);
	let credentials = $state<Credential[]>([]);

	let adding = $state(false);
	let addLabel = $state('');
	let addError = $state<string | null>(null);

	let editingId = $state<number | null>(null);
	let editLabel = $state('');
	let renameError = $state<string | null>(null);

	let busyId = $state<number | null>(null);
	let removeError = $state<string | null>(null);

	async function loadList() {
		loading = true;
		loadError = null;
		try {
			credentials = await listCredentials();
		} catch {
			loadError = m.admin_account_load_failed();
		} finally {
			loading = false;
		}
	}

	onMount(loadList);

	async function addPasskey() {
		adding = true;
		addError = null;
		try {
			const options = await addCredentialOptions();
			const attestation = await performRegistration(options);
			const label = addLabel.trim() || m.admin_account_default_label();
			await addCredential(attestation, label);
			addLabel = '';
			// Ein zusätzlicher Passkey ändert `credentialCount` — die Session neu
			// laden, damit `session.needsBackup` (Backup-Banner) mitzieht.
			await loadList();
			await session.load();
		} catch {
			// Sowohl AdminApiError als auch WebAuthn-Abbrüche landen in derselben,
			// lokalisierten Meldung (Muster wie Login/Registrierung).
			addError = m.admin_account_add_failed();
		} finally {
			adding = false;
		}
	}

	function startEdit(c: Credential) {
		editingId = c.id;
		editLabel = c.label;
		renameError = null;
	}

	function cancelEdit() {
		editingId = null;
		editLabel = '';
	}

	async function saveEdit(id: number) {
		const label = editLabel.trim();
		if (!label) return;
		busyId = id;
		renameError = null;
		try {
			await renameCredential(id, label);
			editingId = null;
			await loadList();
		} catch {
			renameError = m.admin_account_rename_failed();
		} finally {
			busyId = null;
		}
	}

	async function remove(id: number) {
		busyId = id;
		removeError = null;
		try {
			// Entfernen ist step-up-geschützt (Server verlangt eine frische
			// Passkey-Bestätigung); `withStepUp` führt die Ceremony bei Bedarf
			// durch und wiederholt die Aktion genau einmal.
			await withStepUp(() => removeCredential(id));
			await loadList();
		} catch (e) {
			// 409 mit `backupRequired`: der Server lässt weniger als 2 Passkeys
			// nicht zu (Aussperr-Schutz) — Liste bleibt unverändert.
			if (e instanceof AdminApiError && e.backupRequired) {
				removeError = m.admin_account_remove_backup_required();
			} else {
				removeError = m.admin_account_remove_failed();
			}
		} finally {
			busyId = null;
		}
	}
</script>

<svelte:head>
	<title>{m.admin_account_heading()} · Gestura Index</title>
</svelte:head>

<h1><KeyRound size={20} />{m.admin_account_heading()}</h1>

{#if loading}
	<Spinner />
{:else if loadError}
	<ErrorState message={loadError} onRetry={loadList} />
{:else if credentials.length === 0}
	<EmptyState title={m.admin_account_empty_title()} />
{:else}
	<div class="card">
		<div class="credential-table" role="table">
			<div class="credential-row credential-row-head" role="row">
				<span role="columnheader">{m.admin_account_label_header()}</span>
				<span role="columnheader">{m.admin_account_created_header()}</span>
				<span role="columnheader">{m.admin_account_last_used_header()}</span>
				<span role="columnheader" class="credential-actions">{m.admin_account_actions_header()}</span
				>
			</div>
			{#each credentials as c (c.id)}
				<div class="credential-row" role="row">
					<span role="cell">
						{#if editingId === c.id}
							<input
								type="text"
								bind:value={editLabel}
								aria-label={m.admin_account_rename_label_input()}
							/>
						{:else}
							{c.label}
						{/if}
					</span>
					<span role="cell">{new Date(c.createdAt).toLocaleDateString(getLocale())}</span>
					<span role="cell">
						{c.lastUsedAt
							? new Date(c.lastUsedAt).toLocaleDateString(getLocale())
							: m.admin_account_last_used_never()}
					</span>
					<span role="cell" class="credential-actions">
						{#if editingId === c.id}
							<button
								class="btn btn-sm btn-primary btn-icon-only"
								onclick={() => saveEdit(c.id)}
								disabled={busyId === c.id}
								aria-label={m.admin_account_rename_save()}
							>
								<Check size={16} />
							</button>
							<button
								class="btn btn-sm btn-secondary btn-icon-only"
								onclick={cancelEdit}
								disabled={busyId === c.id}
								aria-label={m.admin_account_rename_cancel()}
							>
								<X size={16} />
							</button>
						{:else}
							<button
								class="btn btn-sm btn-secondary btn-icon-only"
								onclick={() => startEdit(c)}
								disabled={busyId === c.id}
								aria-label={m.admin_account_rename_button()}
							>
								<Pencil size={16} />
							</button>
							<button
								class="btn btn-sm btn-danger btn-icon-only"
								onclick={() => remove(c.id)}
								disabled={busyId === c.id}
								aria-label={m.admin_account_remove_button()}
							>
								<Trash2 size={16} />
							</button>
						{/if}
					</span>
				</div>
			{/each}
		</div>
	</div>

	{#if renameError}<p class="account-error" role="alert">{renameError}</p>{/if}
	{#if removeError}<p class="account-error" role="alert">{removeError}</p>{/if}
{/if}

<div class="card">
	<h2>{m.admin_account_add_heading()}</h2>
	<div class="add-passkey-row">
		<input
			type="text"
			bind:value={addLabel}
			placeholder={m.admin_account_default_label()}
			aria-label={m.admin_account_add_label_input()}
		/>
		<button class="btn btn-primary" onclick={addPasskey} disabled={adding}>
			{#if adding}<Spinner />{:else}<Plus size={16} />{m.admin_account_add_button()}{/if}
		</button>
	</div>
	{#if addError}<p class="account-error" role="alert">{addError}</p>{/if}
</div>

<style>
	h1 {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 1.3em;
		margin: 0 0 16px;
	}

	h2 {
		font-size: 1.05em;
		margin: 0 0 12px;
	}

	.credential-table {
		display: flex;
		flex-direction: column;
	}

	.credential-row {
		display: grid;
		grid-template-columns: 1.4fr 1fr 1fr auto;
		gap: 12px;
		align-items: center;
		padding: 10px 0;
		border-bottom: 1px solid var(--border-color);
	}

	.credential-row:last-child {
		border-bottom: none;
	}

	.credential-row-head {
		color: var(--text-secondary);
		font-size: 0.85em;
		font-weight: 600;
	}

	.credential-actions {
		display: flex;
		gap: 8px;
		justify-content: flex-end;
	}

	.add-passkey-row {
		display: flex;
		gap: 8px;
	}

	.add-passkey-row input {
		flex: 1;
	}

	.account-error {
		color: var(--danger-color);
		font-weight: 600;
		margin-top: 12px;
	}

	@media (max-width: 640px) {
		.credential-row {
			grid-template-columns: 1fr auto;
			grid-template-areas:
				'label actions'
				'created created'
				'lastused lastused';
		}

		.credential-row-head {
			display: none;
		}

		.credential-row span:nth-child(1) {
			grid-area: label;
		}
		.credential-row span:nth-child(2) {
			grid-area: created;
		}
		.credential-row span:nth-child(3) {
			grid-area: lastused;
		}
		.credential-row span:nth-child(4) {
			grid-area: actions;
		}
	}
</style>
