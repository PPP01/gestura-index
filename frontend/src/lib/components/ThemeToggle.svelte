<script lang="ts">
	import { onMount } from 'svelte';
	import { Sun, Moon, MonitorSmartphone } from '@lucide/svelte';
	import { m } from '$lib/paraglide/messages.js';

	type Mode = 'auto' | 'light' | 'dark';
	let mode = $state<Mode>('auto');

	function apply(targetMode: Mode) {
		const actual =
			targetMode === 'auto'
				? matchMedia('(prefers-color-scheme: light)').matches
					? 'light'
					: 'dark'
				: targetMode;
		document.documentElement.setAttribute('data-theme', actual);
	}
	function cycle() {
		mode = mode === 'auto' ? 'light' : mode === 'light' ? 'dark' : 'auto';
		try {
			localStorage.setItem('gestura_index_theme', mode);
		} catch {
			/* ignore */
		}
		apply(mode);
	}
	onMount(() => {
		try {
			mode = (localStorage.getItem('gestura_index_theme') as Mode) || 'auto';
		} catch {
			/* ignore */
		}
	});
</script>

<button class="btn btn-icon-only" onclick={cycle} title={m.theme_label()} aria-label={m.theme_label()}>
	{#if mode === 'light'}<Sun size={18} />{:else if mode === 'dark'}<Moon size={18} />{:else}<MonitorSmartphone size={18} />{/if}
</button>
