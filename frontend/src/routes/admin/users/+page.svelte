<script lang="ts">
	import { onMount } from 'svelte';
	import { Users, UserPlus } from '@lucide/svelte';
	import { m } from '$lib/paraglide/messages.js';
	import {
		listUsers,
		inviteUser,
		disableUser,
		enableUser,
		reinviteUser,
		AdminApiError,
		type AdminUser,
		type AdminRole
	} from '$lib/admin/api';
	import { withStepUp } from '$lib/admin/stepup';
	import Spinner from '$lib/components/Spinner.svelte';
	import ErrorState from '$lib/components/ErrorState.svelte';
	import EmptyState from '$lib/components/EmptyState.svelte';
	import Badge from '$lib/components/Badge.svelte';

	let loading = $state(true);
	let loadError = $state<string | null>(null);
	let users = $state<AdminUser[]>([]);

	let inviteName = $state('');
	let inviteEmail = $state('');
	let inviteRole = $state<AdminRole>('moderator');
	let inviting = $state(false);
	let inviteError = $state<string | null>(null);

	let busyId = $state<number | null>(null);
	let actionError = $state<string | null>(null);

	async function loadUsers() {
		loading = true;
		loadError = null;
		try {
			users = await listUsers();
		} catch {
			loadError = m.admin_users_load_failed();
		} finally {
			loading = false;
		}
	}

	onMount(loadUsers);

	function statusLabel(status: AdminUser['status']): string {
		switch (status) {
			case 'invited':
				return m.admin_users_status_invited();
			case 'active':
				return m.admin_users_status_active();
			case 'disabled':
				return m.admin_users_status_disabled();
		}
	}

	function statusVariant(status: AdminUser['status']): 'default' | 'warning' {
		return status === 'disabled' ? 'warning' : 'default';
	}

	function roleLabel(role: AdminRole): string {
		return role === 'admin' ? m.admin_users_role_admin() : m.admin_users_role_moderator();
	}

	async function onInvite(e: Event) {
		e.preventDefault();
		inviting = true;
		inviteError = null;
		try {
			// Einladen ist step-up-geschützt (Server verlangt eine frische
			// Passkey-Bestätigung + mindestens 2 Passkeys als Backup); `withStepUp`
			// führt die Ceremony bei Bedarf durch und wiederholt die Aktion genau
			// einmal.
			await withStepUp(() =>
				inviteUser({
					displayName: inviteName.trim(),
					email: inviteEmail.trim(),
					role: inviteRole
				})
			);
			inviteName = '';
			inviteEmail = '';
			inviteRole = 'moderator';
			await loadUsers();
		} catch (e) {
			// 409 mit `backupRequired`: der Server verlangt für die Step-up-Ceremony
			// selbst mindestens 2 Passkeys als Backup.
			if (e instanceof AdminApiError && e.backupRequired) {
				inviteError = m.admin_users_invite_backup_required();
			} else {
				inviteError = m.admin_users_invite_failed();
			}
		} finally {
			inviting = false;
		}
	}

	async function onDisable(id: number) {
		busyId = id;
		actionError = null;
		try {
			await withStepUp(() => disableUser(id));
			await loadUsers();
		} catch (e) {
			if (e instanceof AdminApiError && e.backupRequired) {
				actionError = m.admin_users_action_backup_required();
			} else {
				actionError = m.admin_users_disable_failed();
			}
		} finally {
			busyId = null;
		}
	}

	async function onEnable(id: number) {
		busyId = id;
		actionError = null;
		try {
			await withStepUp(() => enableUser(id));
			await loadUsers();
		} catch (e) {
			if (e instanceof AdminApiError && e.backupRequired) {
				actionError = m.admin_users_action_backup_required();
			} else {
				actionError = m.admin_users_enable_failed();
			}
		} finally {
			busyId = null;
		}
	}

	async function onReinvite(id: number) {
		busyId = id;
		actionError = null;
		try {
			await withStepUp(() => reinviteUser(id));
			await loadUsers();
		} catch (e) {
			if (e instanceof AdminApiError && e.backupRequired) {
				actionError = m.admin_users_action_backup_required();
			} else {
				actionError = m.admin_users_reinvite_failed();
			}
		} finally {
			busyId = null;
		}
	}
</script>

<svelte:head>
	<title>{m.admin_users_heading()} · Gestura Index Admin</title>
</svelte:head>

<h1><Users size={20} />{m.admin_users_heading()}</h1>

{#if loading}
	<Spinner />
{:else if loadError}
	<ErrorState message={loadError} onRetry={loadUsers} />
{:else if users.length === 0}
	<EmptyState title={m.admin_users_empty_title()} />
{:else}
	<div class="card">
		<div class="users-table" role="table">
			<div class="users-row users-row-head" role="row">
				<span role="columnheader">{m.admin_users_name_header()}</span>
				<span role="columnheader">{m.admin_users_email_header()}</span>
				<span role="columnheader">{m.admin_users_role_header()}</span>
				<span role="columnheader">{m.admin_users_status_header()}</span>
				<span role="columnheader" class="users-actions">{m.admin_users_actions_header()}</span>
			</div>
			{#each users as u (u.id)}
				<div class="users-row" role="row">
					<span role="cell">{u.displayName}</span>
					<span role="cell">{u.email}</span>
					<span role="cell">{roleLabel(u.role)}</span>
					<span role="cell"><Badge text={statusLabel(u.status)} variant={statusVariant(u.status)} /></span>
					<span role="cell" class="users-actions">
						{#if u.status === 'disabled'}
							<button
								class="btn btn-sm btn-primary"
								onclick={() => onEnable(u.id)}
								disabled={busyId === u.id}
							>
								{m.admin_users_enable_button()}
							</button>
						{:else}
							<button
								class="btn btn-sm btn-danger"
								onclick={() => onDisable(u.id)}
								disabled={busyId === u.id}
							>
								{m.admin_users_disable_button()}
							</button>
							{#if u.status === 'invited'}
								<button
									class="btn btn-sm btn-secondary"
									onclick={() => onReinvite(u.id)}
									disabled={busyId === u.id}
								>
									{m.admin_users_reinvite_button()}
								</button>
							{/if}
						{/if}
					</span>
				</div>
			{/each}
		</div>
	</div>

	{#if actionError}<p class="users-error" role="alert">{actionError}</p>{/if}
{/if}

<div class="card">
	<h2>{m.admin_users_invite_heading()}</h2>
	<form class="invite-form" onsubmit={onInvite}>
		<input
			type="text"
			bind:value={inviteName}
			placeholder={m.admin_users_invite_name_input()}
			aria-label={m.admin_users_invite_name_input()}
			required
		/>
		<input
			type="email"
			bind:value={inviteEmail}
			placeholder={m.admin_users_invite_email_input()}
			aria-label={m.admin_users_invite_email_input()}
			required
		/>
		<select bind:value={inviteRole} aria-label={m.admin_users_invite_role_input()}>
			<option value="moderator">{m.admin_users_role_moderator()}</option>
			<option value="admin">{m.admin_users_role_admin()}</option>
		</select>
		<button class="btn btn-primary" type="submit" disabled={inviting}>
			{#if inviting}<Spinner />{:else}<UserPlus size={16} />{m.admin_users_invite_button()}{/if}
		</button>
	</form>
	{#if inviteError}<p class="users-error" role="alert">{inviteError}</p>{/if}
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

	.users-table {
		display: flex;
		flex-direction: column;
	}

	.users-row {
		display: grid;
		grid-template-columns: 1.2fr 1.6fr 0.8fr 0.8fr auto;
		gap: 12px;
		align-items: center;
		padding: 10px 0;
		border-bottom: 1px solid var(--border-color);
	}

	.users-row:last-child {
		border-bottom: none;
	}

	.users-row-head {
		color: var(--text-secondary);
		font-size: 0.85em;
		font-weight: 600;
	}

	.users-actions {
		display: flex;
		gap: 8px;
		justify-content: flex-end;
	}

	.invite-form {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
	}

	.invite-form input,
	.invite-form select {
		flex: 1 1 160px;
	}

	.users-error {
		color: var(--danger-color);
		font-weight: 600;
		margin-top: 12px;
	}

	@media (max-width: 640px) {
		.users-row {
			grid-template-columns: 1fr auto;
			grid-template-areas:
				'name actions'
				'email email'
				'role status';
		}

		.users-row-head {
			display: none;
		}

		.users-row span:nth-child(1) {
			grid-area: name;
		}
		.users-row span:nth-child(2) {
			grid-area: email;
		}
		.users-row span:nth-child(3) {
			grid-area: role;
		}
		.users-row span:nth-child(4) {
			grid-area: status;
		}
		.users-row span:nth-child(5) {
			grid-area: actions;
		}
	}
</style>
