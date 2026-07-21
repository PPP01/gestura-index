<script lang="ts">
	import { LoaderCircle } from '@lucide/svelte';
	let { label }: { label?: string } = $props();
</script>

<span class="spinner" role="status" aria-live="polite">
	<LoaderCircle size={20} class="spin" />
	{#if label}<span>{label}</span>{/if}
</span>

<style>
	.spinner {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		color: var(--text-secondary);
	}
	:global(.spin) {
		animation: spin 1s linear infinite;
	}
	@keyframes spin {
		to {
			transform: rotate(360deg);
		}
	}
</style>
