<script lang="ts">
	import { KeyRound, ShieldCheck } from '@lucide/svelte';
	import { m } from '$lib/paraglide/messages.js';
	import { localizeHref } from '$lib/paraglide/runtime';
	import { goto } from '$app/navigation';
	import { page } from '$app/state';
	import { registerOptions, register, AdminApiError } from '$lib/admin/api';
	import { performRegistration } from '$lib/admin/webauthn';
	import Spinner from '$lib/components/Spinner.svelte';
	import AdminError from '$lib/components/admin/AdminError.svelte';

	// Der Invite-Token kommt aus dem Link, den ein Admin per Einladung
	// verschickt (siehe `inviteUser`) – kein Session-Kontext vorhanden.
	const token = page.url.searchParams.get('token');

	let loading = $state(false);
	let error = $state<string | null>(null);
	let success = $state(false);
	// Terminal-Zustand: der Link/Token ist ungültig, abgelaufen oder verbraucht.
	// Dann ist erneutes »Passkey anlegen« sinnlos → Button ausblenden.
	let linkDead = $state(false);

	async function createPasskey() {
		if (!token) return;
		loading = true;
		error = null;
		try {
			const options = await registerOptions(token);
			const attestation = await performRegistration(options);
			await register(token, attestation);
			success = true;
		} catch (e) {
			if (e instanceof AdminApiError && e.status === 400) {
				// Token ungültig/abgelaufen/verbraucht → terminal, kein Retry möglich.
				linkDead = true;
			} else {
				// WebAuthn-Abbruch, Netzwerkfehler o. Ä. → erneut versuchbar.
				error = m.admin_register_retry();
			}
		} finally {
			loading = false;
		}
	}

	// `register` setzt (im Gegensatz zu `authLogin`) KEINE Session – der
	// SP4a-`RegisterController` meldet nach der Aktivierung nicht an. Der
	// zweite (Backup-)Passkey braucht eine authentifizierte Session und wird
	// daher erst in »Mein Konto« nach dem Login angelegt (Task 9).
	function toLogin() {
		goto(localizeHref('/admin/login'));
	}
</script>

<svelte:head>
	<title>{m.admin_register_heading()} · Gestura Index</title>
</svelte:head>

<div class="card register-card">
	{#if success}
		<h1><ShieldCheck size={20} />{m.admin_register_success_heading()}</h1>
		<p>{m.admin_register_success_body()}</p>
		<button class="btn btn-primary" onclick={toLogin}>
			{m.admin_register_login_button()}
		</button>
	{:else if !token || linkDead}
		<h1><KeyRound size={20} />{m.admin_register_heading()}</h1>
		<AdminError message={m.admin_register_invalid_link()} />
	{:else}
		<h1><KeyRound size={20} />{m.admin_register_heading()}</h1>
		<p>{m.admin_register_body()}</p>

		{#if error}<AdminError message={error} />{/if}

		<button class="btn btn-primary" onclick={createPasskey} disabled={loading}>
			{#if loading}<Spinner />{:else}{m.admin_register_button()}{/if}
		</button>
	{/if}
</div>

<style>
	.register-card {
		max-width: 360px;
		margin: 60px auto;
		text-align: center;
	}

	.register-card h1 {
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 8px;
		font-size: 1.3em;
		margin: 0 0 8px;
	}

	.register-card p {
		color: var(--text-secondary);
		margin: 0 0 16px;
	}

	.register-card .btn {
		width: 100%;
		justify-content: center;
	}
</style>
