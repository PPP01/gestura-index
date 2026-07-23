<script lang="ts">
	// Layout-Reset (`@`, bare) auf die schlanke Wurzel `src/routes/+layout.svelte`:
	// das öffentliche Chrome (Header/Footer) lebt in `(public)/+layout.svelte` und
	// wird dadurch NICHT vererbt. Siehe Kommentar dort für die Begründung.
	import Sidebar from '$lib/components/admin/Sidebar.svelte';
	import BackupBanner from '$lib/components/admin/BackupBanner.svelte';
	import ThemeToggle from '$lib/components/ThemeToggle.svelte';
	import LangToggle from '$lib/components/LangToggle.svelte';
	import { session } from '$lib/admin/session.svelte';
	import { authLogout, registerUnauthorizedHandler } from '$lib/admin/api';
	import { m } from '$lib/paraglide/messages.js';
	import { goto } from '$app/navigation';
	import { localizeHref, deLocalizeUrl } from '$lib/paraglide/runtime';
	import { LogOut } from '@lucide/svelte';

	let { children } = $props();

	let loggingOut = $state(false);

	// Globaler 401-Handler: leert die Client-Session und leitet zum Login um.
	// Läuft für JEDE gescheiterte Admin-API-Anfrage — nicht nur beim Session-Load.
	// Schutz gegen Endlosschleife: auf Login-/Register-Seiten kein erneuter Redirect.
	$effect(() => {
		return registerUnauthorizedHandler(() => {
			const path = deLocalizeUrl(new URL(window.location.href)).pathname;
			if (path === '/admin/login' || path === '/admin/register') return;
			session.clear();
			goto(localizeHref('/admin/login'));
		});
	});

	async function logout() {
		loggingOut = true;
		try {
			await authLogout();
		} catch {
			// Auch wenn der Server-Aufruf scheitert (z. B. Cookie bereits abgelaufen),
			// die Client-Session verwerfen und zum Login gehen — ein Logout darf nie
			// »hängen bleiben«.
		} finally {
			session.clear();
			// Zurücksetzen, BEVOR wir navigieren: das Admin-Layout bleibt über den
			// Logout→Login-Zyklus gemountet (nur der `{#if session.user}`-Zweig
			// wechselt). Ein stehen gebliebenes `true` würde den Button nach dem
			// nächsten Login disabled lassen — erst ein Reload würde ihn befreien.
			loggingOut = false;
			goto(localizeHref('/admin/login'));
		}
	}
</script>

{#if session.user}
	<div class="admin-shell">
		<Sidebar role={session.user.role} />
		<div class="admin-main">
			<header class="admin-topbar">
				<span class="admin-topbar-user">
					{session.user.displayName} · {m.admin_topbar_role({ role: session.user.role })}
				</span>
				<div class="admin-topbar-actions">
					<ThemeToggle />
					<LangToggle />
					<button
						class="btn btn-ghost topbar-logout"
						onclick={logout}
						disabled={loggingOut}
					>
						<LogOut size={18} />
						{m.admin_logout()}
					</button>
				</div>
			</header>
			{#if session.needsBackup}<BackupBanner />{/if}
			<main class="container">{@render children()}</main>
		</div>
	</div>
{:else}
	<main class="container">{@render children()}</main>
{/if}

<style>
	.admin-shell {
		display: flex;
		gap: 24px;
		align-items: flex-start;
		max-width: var(--page-max-width, 1200px);
		margin: 0 auto;
		padding: 20px;
	}

	.admin-main {
		flex: 1;
		min-width: 0;
	}

	.admin-topbar {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		padding-bottom: 12px;
		margin-bottom: 16px;
		border-bottom: 1px solid var(--border-color);
	}

	.admin-topbar-user {
		color: var(--text-secondary);
		font-size: 0.9em;
	}

	.admin-topbar-actions {
		display: inline-flex;
		gap: 8px;
		align-items: center;
	}

	.topbar-logout {
		gap: 6px;
	}

	@media (max-width: 720px) {
		.admin-shell {
			flex-direction: column;
			padding: 12px;
		}
	}
</style>
