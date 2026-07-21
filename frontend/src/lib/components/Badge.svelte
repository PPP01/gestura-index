<script lang="ts">
	import type { Component } from 'svelte';
	let { text, variant = 'default', icon }: { text: string; variant?: 'default' | 'warning'; icon?: Component } = $props();
</script>

<span class="badge" class:badge-warning={variant === 'warning'}>
	{#if icon}{@const Icon = icon}<Icon size={14} />{/if}
	{text}
</span>
