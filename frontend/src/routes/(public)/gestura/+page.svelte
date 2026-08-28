<script lang="ts">
	import { goto } from '$app/navigation';
	import { localizeHref } from '$lib/paraglide/runtime';
	// Alt-Landing durch C1 (Startseite) ersetzt.
	goto(localizeHref('/'), { replaceState: true });
</script>
