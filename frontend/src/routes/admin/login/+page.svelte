<script lang="ts">
	import { KeyRound } from '@lucide/svelte';
	import { m } from '$lib/paraglide/messages.js';
	import { localizeHref } from '$lib/paraglide/runtime';
	import { goto } from '$app/navigation';
	import { authOptions, authLogin } from '$lib/admin/api';
	import { performAssertion } from '$lib/admin/webauthn';
	import { session } from '$lib/admin/session.svelte';
	import Spinner from '$lib/components/Spinner.svelte';

	let loading = $state(false);
	let error = $state<string | null>(null);

	async function login() {
		loading = true;
		error = null;
		try {
			const options = await authOptions();
			const assertion = await performAssertion(options);
			await authLogin(assertion);
			await session.load();
			goto(localizeHref('/admin/queue'));
		} catch (e) {
			// Sowohl AdminApiError (401/429/…) als auch WebAuthn-Abbrüche
			// (NotAllowedError etc.) landen in derselben, lokalisierten Meldung.
			error = m.admin_login_failed();
			loading = false;
		}
	}
</script>

<svelte:head>
	<title>{m.admin_login_heading()} · Gestura Index</title>
</svelte:head>

<div class="card login-card">
	<h1><KeyRound size={20} />{m.admin_login_heading()}</h1>
	<p>{m.admin_login_body()}</p>

	{#if error}<p class="login-error" role="alert">{error}</p>{/if}

	<button class="btn btn-primary" onclick={login} disabled={loading}>
		{#if loading}<Spinner />{:else}{m.admin_login_button()}{/if}
	</button>
</div>

<style>
	.login-card {
		max-width: 360px;
		margin: 60px auto;
		text-align: center;
	}

	.login-card h1 {
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 8px;
		font-size: 1.3em;
		margin: 0 0 8px;
	}

	.login-card p {
		color: var(--text-secondary);
		margin: 0 0 16px;
	}

	.login-error {
		color: var(--danger-color);
		font-weight: 600;
	}

	.login-card .btn {
		width: 100%;
		justify-content: center;
	}
</style>
