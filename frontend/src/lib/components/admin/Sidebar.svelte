<script lang="ts">
	import { page } from '$app/state';
	import { localizeHref, deLocalizeUrl } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import { ListChecks, Flag, Users, History, CircleUser, Menu, X } from '@lucide/svelte';

	let { role }: { role: 'admin' | 'moderator' } = $props();

	// Mobile: Sidebar standardmäßig eingeklappt, per Toggle-Button aufklappbar.
	let open = $state(false);

	const navItems = [
		{ href: '/admin/queue', label: m.admin_nav_queue, Icon: ListChecks, adminOnly: false },
		{ href: '/admin/reports', label: m.admin_nav_reports, Icon: Flag, adminOnly: false },
		{ href: '/admin/users', label: m.admin_nav_users, Icon: Users, adminOnly: true },
		{ href: '/admin/audit', label: m.admin_nav_audit, Icon: History, adminOnly: true },
		{ href: '/admin/account', label: m.admin_nav_account, Icon: CircleUser, adminOnly: false }
	];

	const visibleItems = $derived(navItems.filter((item) => !item.adminOnly || role === 'admin'));

	function isActive(href: string): boolean {
		const current = deLocalizeUrl(page.url).pathname;
		return current === href || current.startsWith(`${href}/`);
	}
</script>

<button
	class="btn btn-ghost btn-icon-only sidebar-toggle"
	onclick={() => (open = !open)}
	aria-label={m.admin_nav_toggle()}
	aria-expanded={open}
>
	{#if open}<X size={18} />{:else}<Menu size={18} />{/if}
</button>

<nav class="admin-sidebar" class:open aria-label={m.admin_nav_toggle()}>
	<ul>
		{#each visibleItems as item (item.href)}
			<li>
				<a
					href={localizeHref(item.href)}
					class="btn btn-ghost sidebar-link"
					class:active={isActive(item.href)}
					aria-current={isActive(item.href) ? 'page' : undefined}
					onclick={() => (open = false)}
				>
					<item.Icon size={18} />
					{item.label()}
				</a>
			</li>
		{/each}
	</ul>
</nav>

<style>
	.sidebar-toggle {
		display: none;
	}

	.admin-sidebar ul {
		display: flex;
		flex-direction: column;
		gap: 4px;
		margin: 0;
		padding: 0;
		list-style: none;
	}

	.sidebar-link {
		justify-content: flex-start;
		width: 100%;
		box-shadow: none;
	}

	.sidebar-link.active {
		color: var(--accent-color);
		background: color-mix(in srgb, var(--accent-color) 10%, transparent);
	}

	@media (max-width: 720px) {
		.sidebar-toggle {
			display: inline-flex;
		}

		.admin-sidebar {
			display: none;
			border-bottom: 1px solid var(--border-color);
			padding-bottom: 8px;
			margin-bottom: 8px;
		}

		.admin-sidebar.open {
			display: block;
		}
	}
</style>
