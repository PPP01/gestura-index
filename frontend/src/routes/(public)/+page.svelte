<script lang="ts">
	import { goto } from '$app/navigation';
	import { localizeHref } from '$lib/paraglide/runtime';
	// Übergang bis Task 3 (C1). Danach ersetzt die Marketing-Startseite diese Datei.
	goto(localizeHref('/index'), { replaceState: true });
</script>
