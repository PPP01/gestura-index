<script lang="ts">
	import { m } from '$lib/paraglide/messages.js';
	let { message, onRetry }: { message: string; onRetry?: () => void } = $props();
</script>

<div class="card" style="text-align:center; padding:32px;">
	<p style="color:var(--danger-color); font-weight:600;">{message}</p>
	{#if onRetry}<button class="btn" onclick={onRetry}>{m.state_error_retry()}</button>{/if}
</div>
