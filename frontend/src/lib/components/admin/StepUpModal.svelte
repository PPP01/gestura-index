<script lang="ts">
	import { m } from '$lib/paraglide/messages.js';
	import { KeyRound } from '@lucide/svelte';

	let {
		open = $bindable(false),
		onConfirm,
		onCancel
	}: {
		open?: boolean;
		onConfirm: () => void;
		onCancel?: () => void;
	} = $props();

	function cancel() {
		open = false;
		onCancel?.();
	}

	function confirm() {
		open = false;
		onConfirm();
	}
</script>

{#if open}
	<div class="stepup-overlay" role="presentation" onclick={cancel}>
		<div
			class="stepup-dialog card"
			role="dialog"
			aria-modal="true"
			aria-labelledby="stepup-title"
			tabindex="-1"
			onclick={(e) => e.stopPropagation()}
			onkeydown={(e) => e.key === 'Escape' && cancel()}
		>
			<h2 id="stepup-title"><KeyRound size={18} />{m.admin_stepup_title()}</h2>
			<p>{m.admin_stepup_body()}</p>
			<div class="stepup-actions">
				<button class="btn btn-ghost" onclick={cancel}>{m.admin_stepup_cancel()}</button>
				<button class="btn btn-primary" onclick={confirm}>{m.admin_stepup_confirm()}</button>
			</div>
		</div>
	</div>
{/if}

<style>
	.stepup-overlay {
		position: fixed;
		inset: 0;
		display: flex;
		align-items: center;
		justify-content: center;
		background: color-mix(in srgb, black 55%, transparent);
		z-index: 100;
	}

	.stepup-dialog {
		max-width: 360px;
		margin: 0 16px;
	}

	.stepup-dialog h2 {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 1.1em;
		margin: 0 0 8px;
	}

	.stepup-dialog p {
		color: var(--text-secondary);
		margin: 0 0 16px;
	}

	.stepup-actions {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
	}
</style>
