<script lang="ts">
	import { page } from '$app/state';
	import { goto } from '$app/navigation';
	import { localizeHref } from '$lib/paraglide/runtime';
	import { onepagerSearchParams, type OnepagerFilter, type OnepagerSort } from '$lib/browse-state';

	// Alt-Parameter (Einzelwerte) auf das Onepager-Filterformat abbilden.
	const sp = page.url.searchParams;
	const single = (k: string) => {
		const v = sp.get(k);
		return v && v.trim() !== '' ? v : undefined;
	};
	const type = single('type');
	const sort = single('sort');
	const filter: OnepagerFilter = {
		q: single('q'),
		type: type === 'menu' || type === 'engine' ? type : undefined,
		categories: single('category') ? [single('category')!] : [],
		tags: single('tag') ? [single('tag')!] : [],
		langs: [],
		site: single('site'),
		sort: sort === 'installs' || sort === 'best' ? (sort as OnepagerSort) : 'newest'
	};
	const qs = onepagerSearchParams(filter).toString();
	goto(localizeHref(`/${qs ? `?${qs}` : ''}`), { replaceState: true });
</script>
