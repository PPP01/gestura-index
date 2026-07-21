<script lang="ts">
	import type { Component } from 'svelte';
	let { text, variant = 'default', icon }: { text: string; variant?: 'default' | 'warning'; icon?: Component } = $props();
	const Icon = icon;
</script>

<span class="badge" class:badge-warning={variant === 'warning'}>
	{#if Icon}<Icon size={14} />{/if}
	{text}
</span>
