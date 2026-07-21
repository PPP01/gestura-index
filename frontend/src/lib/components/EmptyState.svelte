<script lang="ts">
	let { title, hint }: { title: string; hint?: string } = $props();
</script>

<div class="card" style="text-align:center; padding:32px;">
	<p style="font-weight:600;">{title}</p>
	{#if hint}<p style="color:var(--text-secondary);">{hint}</p>{/if}
</div>
