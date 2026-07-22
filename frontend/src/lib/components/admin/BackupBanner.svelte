<script lang="ts">
	import { localizeHref } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import { ShieldAlert } from '@lucide/svelte';
</script>

<div class="backup-banner" role="status">
	<ShieldAlert size={18} />
	<span>{m.admin_backup_banner()}</span>
	<a class="btn btn-sm btn-secondary" href={localizeHref('/admin/account')}>{m.admin_nav_account()}</a>
</div>

<style>
	.backup-banner {
		display: flex;
		align-items: center;
		gap: 10px;
		padding: 10px 16px;
		margin-bottom: 16px;
		border-radius: 10px;
		background: color-mix(in srgb, var(--warning-color) 12%, transparent);
		border: 1px solid var(--warning-color);
		color: var(--warning-color);
	}

	.backup-banner span {
		flex: 1;
	}
</style>
