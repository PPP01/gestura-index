<script lang="ts">
	// Einheitlicher Fehler-Kasten für alle Admin-Meldungen: roter Rahmen,
	// Icon, Text. Nur Design-Tokens (kein Fixfarben).
	import { CircleAlert } from '@lucide/svelte';

	let { message }: { message: string } = $props();
</script>

<div class="admin-error" role="alert">
	<CircleAlert size={18} aria-hidden="true" />
	<span>{message}</span>
</div>

<style>
	.admin-error {
		display: flex;
		align-items: flex-start;
		gap: 8px;
		padding: 10px 12px;
		border-radius: 6px;
		border: 1px solid var(--danger-color);
		background: color-mix(in srgb, var(--danger-color) 12%, var(--bg-secondary));
		color: var(--danger-color);
		font-weight: 600;
		text-align: left;
	}

	.admin-error :global(svg) {
		flex: 0 0 auto;
		margin-top: 1px;
	}
</style>
