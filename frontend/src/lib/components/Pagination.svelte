<script lang="ts">
	import { m } from '$lib/paraglide/messages.js';
	let { page, perPage, total, onPage }: { page: number; perPage: number; total: number; onPage: (p: number) => void } = $props();
	const lastPage = $derived(Math.max(1, Math.ceil(total / perPage)));
</script>

<nav class="filter-bar" aria-label={m.pager_nav_label()}>
	<button class="btn" disabled={page <= 1} onclick={() => onPage(page - 1)}>{m.pager_prev()}</button>
	<span>{m.pager_info({ page, total: lastPage })}</span>
	<button class="btn" disabled={page >= lastPage} onclick={() => onPage(page + 1)}>{m.pager_next()}</button>
</nav>
