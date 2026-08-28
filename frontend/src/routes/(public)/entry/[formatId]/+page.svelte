<script lang="ts">
	import { page } from '$app/state';
	import { goto } from '$app/navigation';
	import { localizeHref } from '$lib/paraglide/runtime';

	// Deep-Link auf eine Detailseite -> Onepager mit hervorgehobenem/aufgeklapptem Block.
	const formatId = page.params.formatId ?? '';
	const qs = formatId ? `?highlight=${encodeURIComponent(formatId)}` : '';
	goto(localizeHref(`/${qs}`), { replaceState: true });
</script>
