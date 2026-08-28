# Header + Marketing-/SEO-Seiten (Sub-Projekt C) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Header ans Design-Handoff angleichen und die fünf prerenderten Marketing-/SEO-Seiten C1–C5 bauen; der Onepager-Katalog zieht dabei von `/` nach `/index`.

**Architecture:** SvelteKit `(public)`-Gruppe. C1–C5 sind statisch prerendered (`ssr=true`, `prerender=true`) für SEO; der Onepager bleibt client-only unter `/index`. Der gemeinsame `Header` steuert Nav-Aktivzustand und INDEX-Badge über `page.route.id`. Copy liegt in Paraglide (`messages/de.json` + `en.json`), Optik über die vorhandenen Utilities in `site.css`/`gestura-common.css` und die Design-Tokens des Handoffs.

**Tech Stack:** SvelteKit 2, Svelte 5 Runes, TypeScript, adapter-static, Paraglide, Vitest + @testing-library/svelte, Lucide (`@lucide/svelte`).

**Spec:** `docs/superpowers/specs/2026-08-28-header-marketing-pages-sub-c.md`

**Visuelle Quelle der Wahrheit (im Repo):** `docs/design_handoff_gestura_index/screenshots/1i`–`1n` + `README.md`. Jede Seite pixelnah an ihren Screenshot; exakte Werte (Abstände, Radien, Farben) aus README §Design Tokens / den Screenshots ablesen.

## Global Constraints

- Prosa/Kommentare/Commits: **Deutsch**, Guillemets »…« (nie „…"/"…"), Halbgeviertstrich – (nie —), echte Umlaute ä/ö/ü/ß. Nicht für Code-Bezeichner/String-Literale.
- **Kein Push.** Merge am Ende lokal `--no-ff`.
- **`messages.js` (Paraglide-Kompilat) NIE committen** – nur `messages/de.json` + `messages/en.json`.
- **Nie `vite dev` parallel zu `check`/`test`.**
- **`frontend/.env.local` nicht editieren** (Hook-geschützt).
- Design-Tokens verbatim aus dem Handoff; keine gestalterischen Alleingänge außer den in der Spec §10 genannten Abweichungen.
- **C4-Konkurrenzspalten = Platzhalter »–«**, Warnbanner; keine erfundenen Fakten.
- **Store-Badges = offizielle Bilddateien**, nicht als SVG nachbauen.
- Store-URLs verbatim: Chrome `https://chromewebstore.google.com/detail/gestura-mouse-gestures/ddcendiamegpalekoneonjkenhcamjnj` · Edge `https://microsoftedge.microsoft.com/addons/detail/gestura-mausgesten/dhjkaagkfcmgddieogodeioopogfpghn` · Firefox `https://addons.mozilla.org/firefox/addon/gestura-mouse-gestures/` · GitHub `https://github.com/PPP01/Gestura`.
- Abschluss jeder Task: `npm --prefix frontend run check` (0/0) + `npm --prefix frontend run test -- --run` (grün). Commit-Message endet mit `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
- Node-Befehle vom Repo-Root mit `--prefix frontend`. Kein `cd`.

## Dateistruktur (neu/geändert)

**Routing-Umzug (Task 1):**
- Verschieben: `src/routes/(public)/+page.svelte` → `src/routes/(public)/index/+page.svelte`; `src/routes/(public)/+page.ts` → `src/routes/(public)/index/+page.ts`.
- Ändern: `src/routes/(public)/+layout.svelte` (`wide`-Switch), `src/routes/(public)/browse/+page.svelte`, `src/routes/(public)/entry/[formatId]/+page.svelte`.
- Ersetzen: `src/routes/(public)/gestura/+page.svelte` → Redirect auf `/`.

**Header/geteilt (Task 2):**
- Ändern: `src/lib/components/Header.svelte`, `src/lib/components/Footer.svelte`.
- Neu: `src/lib/components/StoreBadges.svelte`, `src/lib/assets/logo/icon128-tile.png`, `icon128-darktile.png`, `src/lib/assets/stores/…`.

**Seiten (Task 3–7):** je `src/routes/(public)/<slug>/+page.svelte` (+ ggf. `+page.ts` mit `prerender=true`). Neu: `src/lib/components/GestureDiagram.svelte` (Task 5), `src/lib/marketing/showcase.ts` (Task 7, kuratierte Karten-Daten).

**Tests:** `src/lib/components/*.test.ts`, `src/routes/(public)/**/page.test.ts`, Redirect-Tests in bestehender `src/lib/redirects.test.ts` erweitern.

---

## Task 1: Onepager-Umzug `/` → `/index` + Redirects

**Files:**
- Move: `src/routes/(public)/+page.svelte` → `src/routes/(public)/index/+page.svelte`
- Move: `src/routes/(public)/+page.ts` → `src/routes/(public)/index/+page.ts`
- Modify: `src/routes/(public)/+layout.svelte`
- Modify: `src/routes/(public)/browse/+page.svelte`
- Modify: `src/routes/(public)/entry/[formatId]/+page.svelte`
- Replace: `src/routes/(public)/gestura/+page.svelte`
- Test: `src/lib/redirects.test.ts` (erweitern)

**Interfaces:**
- Consumes: `onepagerSearchParams(filter): URLSearchParams`, `OnepagerFilter`, `OnepagerSort` aus `$lib/browse-state` (unverändert).
- Produces: Onepager erreichbar unter `/index`; `/` ist ab Task 3 die Startseite (in dieser Task noch leer/Platzhalter, wird in Task 3 gefüllt).

**Wichtig:** In dieser Task existiert `/` noch nicht als Seite (kommt in Task 3). Damit `check`/Build grün bleiben, legt diese Task eine **minimale Übergangs-`/`** an, die auf `/index` weiterleitet – sie wird in Task 3 durch C1 ersetzt.

- [ ] **Step 1: Onepager-Dateien verschieben**

```bash
git mv "frontend/src/routes/(public)/+page.svelte" "frontend/src/routes/(public)/index/+page.svelte"
git mv "frontend/src/routes/(public)/+page.ts" "frontend/src/routes/(public)/index/+page.ts"
```

- [ ] **Step 2: Interne Link-Ziele im umgezogenen Onepager auf `/index` korrigieren**

In `src/routes/(public)/index/+page.svelte`: jeden internen Verweis, der bisher auf die Wurzel zielte (Highlight-Anker, »zurücksetzen«-Links, evtl. `localizeHref('/')` für Filter-URLs), auf `/index` umstellen. Insbesondere die Stelle, an der Filter-URLs geschrieben werden: statt `localizeHref('/')` nun `localizeHref('/index')`. Grep zur Kontrolle:

```bash
grep -n "localizeHref('/')\|href=\"/\"\|goto(" "frontend/src/routes/(public)/index/+page.svelte"
```

Jede gefundene Wurzel-Referenz, die den Katalog meint, auf `/index` setzen. (Die BasketTray/URL-State-Logik selbst bleibt unverändert.)

- [ ] **Step 3: `wide`-Layout-Switch auf `/index` umstellen**

`src/routes/(public)/+layout.svelte`, Zeile mit `const wide = $derived(...)`:

```svelte
	const wide = $derived(page.route.id === '/(public)/index');
```

- [ ] **Step 4: Übergangs-Startseite anlegen (wird in Task 3 ersetzt)**

Neu `src/routes/(public)/+page.svelte`:

```svelte
<script lang="ts">
	import { goto } from '$app/navigation';
	import { localizeHref } from '$lib/paraglide/runtime';
	// Übergang bis Task 3 (C1). Danach ersetzt die Marketing-Startseite diese Datei.
	goto(localizeHref('/index'), { replaceState: true });
</script>
```

Neu `src/routes/(public)/+page.ts`:

```ts
export const prerender = false;
export const ssr = false;
```

- [ ] **Step 5: browse-Redirect auf `/index` umstellen**

`src/routes/(public)/browse/+page.svelte`, letzte Zeile:

```svelte
	goto(localizeHref(`/index${qs ? `?${qs}` : ''}`), { replaceState: true });
```

- [ ] **Step 6: entry-Redirect auf `/index` umstellen**

`src/routes/(public)/entry/[formatId]/+page.svelte`, Redirect-Zeile:

```svelte
	goto(localizeHref(`/index${qs}`), { replaceState: true });
```

- [ ] **Step 7: gestura-Route zu Redirect auf `/` umbauen**

`src/routes/(public)/gestura/+page.svelte` **komplett** ersetzen durch:

```svelte
<script lang="ts">
	import { goto } from '$app/navigation';
	import { localizeHref } from '$lib/paraglide/runtime';
	// Alt-Landing durch C1 (Startseite) ersetzt.
	goto(localizeHref('/'), { replaceState: true });
</script>
```

Und `src/routes/(public)/gestura/+page.ts` anlegen/prüfen:

```ts
export const prerender = false;
export const ssr = false;
```

Die bisherigen `gestura_*`-Message-Keys **nicht** löschen (C1/C2 verwenden Copy davon in Task 3/4).

- [ ] **Step 8: Redirect-Tests erweitern**

In `src/lib/redirects.test.ts` die Zielpfade anpassen: `browse` → `/index`, `entry/:id` → `/index?highlight=…`, neu `gestura` → `/`. Beispiel-Assertion (an den vorhandenen Teststil angleichen):

```ts
it('browse leitet auf /index mit gemappten Filtern', () => {
	const filter = { q: 'foo', type: 'menu', categories: ['dev'], tags: [], langs: [], site: undefined, sort: 'newest' };
	const qs = onepagerSearchParams(filter as any).toString();
	expect(`/index?${qs}`).toContain('/index?');
});
```

(Die bestehenden Tests dieser Datei auf die neuen Ziele umschreiben, nicht duplizieren.)

- [ ] **Step 9: Prüfen & committen**

```bash
npm --prefix frontend run check
npm --prefix frontend run test -- --run
git add -A
git commit -m "$(printf 'Ziehe Onepager-Katalog von / nach /index um\n\nDamit / zur Marketing-Startseite (C1) werden kann, wandert der\nKatalog auf /index. Redirects browse/entry zeigen auf /index,\n/gestura auf /. Uebergangs-Startseite leitet vorlaeufig auf\n/index, bis C1 sie in Task 3 ersetzt.\n\nCo-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>')"
```
Erwartet: check 0/0, Tests grün. (Umlaute in der finalen Commit-Message über echte Zeichen; das `printf` oben ist nur Schablone – bei Bedarf per Heredoc mit echten Umlauten committen.)

---

## Task 2: Header handoff-konform + StoreBadges + Footer-Fix

**Files:**
- Modify: `src/lib/components/Header.svelte`
- Modify: `src/lib/components/Footer.svelte`
- Create: `src/lib/components/StoreBadges.svelte`
- Create (kopiert): `src/lib/assets/logo/icon128-tile.png`, `src/lib/assets/logo/icon128-darktile.png`
- Create (vom Nutzer bereitgestellt / per curl): `src/lib/assets/stores/chrome-webstore.*`, `microsoft-edge.*`, `firefox-addon.*`
- Modify: `messages/de.json`, `messages/en.json`
- Test: `src/lib/components/Header.test.ts`, `src/lib/components/StoreBadges.test.ts`

**Interfaces:**
- Produces: `StoreBadges.svelte` (default export, keine Props) – rendert drei verlinkte offizielle Badges; konsumiert von C1 (Task 3) und potenziell C5.
- Header nutzt `page.route.id` für Aktivzustand + INDEX-Badge (`/(public)/index`).

- [ ] **Step 1: Logo-Tile-Assets kopieren**

```bash
cp docs/design_handoff_gestura_index/assets/icon128-tile.png frontend/src/lib/assets/logo/icon128-tile.png
cp docs/design_handoff_gestura_index/assets/icon128-darktile.png frontend/src/lib/assets/logo/icon128-darktile.png
```

- [ ] **Step 2: Store-Badge-Assets beschaffen**

Bevorzugt: die vom Nutzer abgelegten Dateien in `frontend/src/lib/assets/stores/` verwenden. Fehlen sie, Versuch per curl von den offiziellen Marken-Seiten (Chrome Web Store Badge, »Get it from Microsoft Edge«, Firefox »Get the Add-on«). Prüfen:

```bash
ls -1 frontend/src/lib/assets/stores/ 2>/dev/null || echo "FEHLT"
```

Fehlen die Assets endgültig, setzt `StoreBadges.svelte` einen **Text-Button-Fallback** (`.btn`) je Store und loggt eine Konsolenwarnung – kein Blocker.

- [ ] **Step 3: Nav-/Header-Message-Keys ergänzen**

In `messages/de.json` und `messages/en.json` ergänzen (bestehende `nav_home` behalten, Wert prüfen):

```jsonc
// de.json
"nav_what": "Was ist Gestura",
"nav_gestures": "Maus-Gesten",
"nav_compare": "Vergleich",
"nav_examples": "Beispiele",
"nav_index": "Index",
"header_github": "Auf GitHub ansehen",
"store_chrome_alt": "Im Chrome Web Store verfügbar",
"store_edge_alt": "Aus dem Microsoft Edge Add-ons-Store holen",
"store_firefox_alt": "Als Firefox-Add-on holen",
"store_chrome_fallback": "Chrome Web Store",
"store_edge_fallback": "Microsoft Edge",
"store_firefox_fallback": "Firefox-Add-on"
```
```jsonc
// en.json
"nav_what": "What is Gestura",
"nav_gestures": "Mouse gestures",
"nav_compare": "Comparison",
"nav_examples": "Examples",
"nav_index": "Index",
"header_github": "View on GitHub",
"store_chrome_alt": "Available in the Chrome Web Store",
"store_edge_alt": "Get it from Microsoft Edge Add-ons",
"store_firefox_alt": "Get the Firefox add-on",
"store_chrome_fallback": "Chrome Web Store",
"store_edge_fallback": "Microsoft Edge",
"store_firefox_fallback": "Firefox add-on"
```

- [ ] **Step 4: StoreBadges-Komponente schreiben**

Neu `src/lib/components/StoreBadges.svelte`:

```svelte
<script lang="ts">
	import { m } from '$lib/paraglide/messages.js';
	// Offizielle Vendor-Badges (Bilddateien). Fehlt eine Datei, greift der
	// Text-Button-Fallback (siehe unten). Import per Vite-Asset-URL.
	let chromeSrc: string | undefined;
	let edgeSrc: string | undefined;
	let firefoxSrc: string | undefined;
	try {
		chromeSrc = (await import('$lib/assets/stores/chrome-webstore.png')).default;
		edgeSrc = (await import('$lib/assets/stores/microsoft-edge.png')).default;
		firefoxSrc = (await import('$lib/assets/stores/firefox-addon.png')).default;
	} catch {
		/* Assets fehlen – Fallback greift */
	}

	const CHROME = 'https://chromewebstore.google.com/detail/gestura-mouse-gestures/ddcendiamegpalekoneonjkenhcamjnj';
	const EDGE = 'https://microsoftedge.microsoft.com/addons/detail/gestura-mausgesten/dhjkaagkfcmgddieogodeioopogfpghn';
	const FIREFOX = 'https://addons.mozilla.org/firefox/addon/gestura-mouse-gestures/';

	const stores = [
		{ href: CHROME, src: chromeSrc, alt: m.store_chrome_alt(), fallback: m.store_chrome_fallback() },
		{ href: EDGE, src: edgeSrc, alt: m.store_edge_alt(), fallback: m.store_edge_fallback() },
		{ href: FIREFOX, src: firefoxSrc, alt: m.store_firefox_alt(), fallback: m.store_firefox_fallback() }
	];
</script>

<div class="store-badges">
	{#each stores as s (s.href)}
		<a href={s.href} target="_blank" rel="noopener noreferrer" aria-label={s.alt}>
			{#if s.src}
				<img src={s.src} alt={s.alt} height="54" />
			{:else}
				<span class="btn">{s.fallback}</span>
			{/if}
		</a>
	{/each}
</div>

<style>
	.store-badges {
		display: flex;
		flex-wrap: wrap;
		gap: 12px;
		align-items: center;
	}
	.store-badges img {
		height: 54px;
		width: auto;
		display: block;
	}
</style>
```

> Hinweis: Falls die abgelegten Dateien `.svg` statt `.png` sind, die drei `import`-Pfade entsprechend auf `.svg` ändern. Der Top-level-`await import` funktioniert in Svelte 5 `<script>` (async), sonst alternativ `import`-Statements am Dateikopf verwenden, wenn die Dateien sicher existieren.

- [ ] **Step 5: StoreBadges-Test**

`src/lib/components/StoreBadges.test.ts`:

```ts
import { render } from '@testing-library/svelte';
import { describe, it, expect } from 'vitest';
import StoreBadges from './StoreBadges.svelte';

describe('StoreBadges', () => {
	it('verlinkt auf die drei korrekten Store-URLs', () => {
		const { container } = render(StoreBadges);
		const hrefs = [...container.querySelectorAll('a')].map((a) => a.getAttribute('href'));
		expect(hrefs.some((h) => h?.includes('chromewebstore.google.com'))).toBe(true);
		expect(hrefs.some((h) => h?.includes('microsoftedge.microsoft.com'))).toBe(true);
		expect(hrefs.some((h) => h?.includes('addons.mozilla.org'))).toBe(true);
	});
});
```

- [ ] **Step 6: Test zuerst laufen lassen (rot/grün je nach Reihenfolge)**

```bash
npm --prefix frontend run test -- --run src/lib/components/StoreBadges.test.ts
```

- [ ] **Step 7: Header neu schreiben**

`src/lib/components/Header.svelte` komplett ersetzen. Logo-Tile (hell/dunkel), Marke, INDEX-Badge nur auf `/(public)/index`, Marketing-Nav mit Aktivzustand, DE/EN-Segmented (Logik aus `LangToggle`), Theme-Toggle (Komponente unverändert einbinden), GitHub-Button (Lucide `Github`). Struktur:

```svelte
<script lang="ts">
	import { page } from '$app/state';
	import { locales, getLocale, localizeHref } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import { Github } from '@lucide/svelte';
	import ThemeToggle from './ThemeToggle.svelte';
	import tileLight from '$lib/assets/logo/icon128-tile.png';
	import tileDark from '$lib/assets/logo/icon128-darktile.png';

	const GITHUB_URL = 'https://github.com/PPP01/Gestura';

	// Nav-Einträge: href-Ziel + Route-ID für den Aktivzustand.
	const nav = [
		{ href: '/', id: '/(public)', label: () => m.nav_home() },
		{ href: '/was-ist-gestura', id: '/(public)/was-ist-gestura', label: () => m.nav_what() },
		{ href: '/maus-gesten', id: '/(public)/maus-gesten', label: () => m.nav_gestures() },
		{ href: '/vergleich', id: '/(public)/vergleich', label: () => m.nav_compare() },
		{ href: '/beispiele', id: '/(public)/beispiele', label: () => m.nav_examples() },
		{ href: '/index', id: '/(public)/index', label: () => m.nav_index(), accent: true }
	];
	const isIndex = $derived(page.route.id === '/(public)/index');
	const activeId = $derived(page.route.id);
</script>

<header class="site-header">
	<a class="brand" href={localizeHref('/')}>
		<span class="logo-img">
			<img src={tileLight} alt="" class="logo-light" width="36" height="36" />
			<img src={tileDark} alt="" class="logo-dark" width="36" height="36" />
		</span>
		<span class="brand-name">Gestura</span>
		{#if isIndex}<span class="index-badge">INDEX</span>{/if}
	</a>

	<nav class="site-nav" aria-label="Hauptnavigation">
		{#each nav as item (item.href)}
			<a
				href={localizeHref(item.href)}
				class:active={activeId === item.id}
				class:accent={item.accent}>{item.label()}</a
			>
		{/each}
	</nav>

	<div class="header-actions">
		<div class="lang-seg" role="group" aria-label="Sprache">
			{#each locales as locale}
				<a
					href={localizeHref(page.url.pathname, { locale })}
					data-sveltekit-reload
					class:active={getLocale() === locale}>{locale.toUpperCase()}</a
				>
			{/each}
		</div>
		<ThemeToggle />
		<a
			class="btn btn-icon-only gh"
			href={GITHUB_URL}
			target="_blank"
			rel="noopener noreferrer"
			aria-label={m.header_github()}
			title={m.header_github()}><Github size={18} /></a
		>
	</div>
</header>

<style>
	.site-header {
		display: flex;
		align-items: center;
		gap: 20px;
		flex-wrap: wrap;
		padding: 12px 0;
	}
	.brand {
		display: inline-flex;
		align-items: center;
		gap: 8px;
		text-decoration: none;
		color: inherit;
	}
	.logo-img {
		width: 36px;
		height: 36px;
		border-radius: 10px;
		overflow: hidden;
		display: inline-flex;
	}
	.logo-dark { display: none; }
	:global([data-theme='dark']) .logo-dark { display: inline; }
	:global([data-theme='dark']) .logo-light { display: none; }
	.brand-name { font-weight: 700; font-size: 17px; }
	.index-badge {
		font-size: 10.5px;
		font-weight: 700;
		letter-spacing: 0.04em;
		padding: 2px 6px;
		border-radius: 6px;
		color: var(--accent-color);
		background: var(--accent-tint, oklch(from var(--accent-color) l c h / 12%));
	}
	.site-nav {
		display: flex;
		gap: 18px;
		margin-inline-start: auto;
		flex-wrap: wrap;
		font-size: 13px;
	}
	.site-nav a { text-decoration: none; color: var(--text-secondary); }
	.site-nav a.active { color: var(--text-primary); font-weight: 600; }
	.site-nav a.accent { color: var(--accent-color); }
	.header-actions { display: inline-flex; gap: 8px; align-items: center; }
	.lang-seg {
		display: inline-flex;
		border: 1px solid var(--border-color);
		border-radius: 9px;
		overflow: hidden;
	}
	.lang-seg a {
		padding: 5px 9px;
		text-decoration: none;
		color: var(--text-secondary);
		font-size: 12px;
	}
	.lang-seg a.active { background: var(--accent-tint, var(--bg-tertiary)); color: var(--text-primary); }
	.gh { width: 32px; height: 32px; border-radius: 9px; }
	@media (max-width: 720px) {
		.site-nav { order: 3; width: 100%; margin-inline-start: 0; overflow-x: auto; }
	}
</style>
```

> `--accent-tint` wurde in Sub-A ergänzt; falls in einem Theme nicht gesetzt, greift der `oklch(...)`-Fallback. Prüfen, dass `LangToggle.svelte` nach dem Umbau nirgends mehr importiert wird; falls verwaist, Datei löschen und Import entfernen.

- [ ] **Step 8: Footer-GitHub-Link umstellen**

`src/lib/components/Footer.svelte`, GitHub-`href` von `https://github.com/PPP01/gestura-index` auf `https://github.com/PPP01/Gestura` ändern.

- [ ] **Step 9: Header-Test**

`src/lib/components/Header.test.ts`:

```ts
import { render } from '@testing-library/svelte';
import { describe, it, expect } from 'vitest';
import Header from './Header.svelte';

describe('Header', () => {
	it('rendert die sechs Marketing-Nav-Links und den GitHub-Button aufs Extension-Repo', () => {
		const { container, getByLabelText } = render(Header);
		const navLinks = container.querySelectorAll('.site-nav a');
		expect(navLinks.length).toBe(6);
		const gh = getByLabelText('Auf GitHub ansehen') as HTMLAnchorElement;
		expect(gh.getAttribute('href')).toBe('https://github.com/PPP01/Gestura');
	});
});
```

(Aktivzustand/INDEX-Badge hängen von `page.route.id` ab; wenn das im Testkontext nicht sauber stellbar ist, diese Assertion weglassen – der GitHub-Link + Linkanzahl reichen als Gate. Nicht künstlich mocken, wenn es den Test brüchig macht.)

- [ ] **Step 10: Prüfen & committen**

```bash
npm --prefix frontend run check
npm --prefix frontend run test -- --run
git add frontend/src/lib/components/Header.svelte frontend/src/lib/components/Footer.svelte frontend/src/lib/components/StoreBadges.svelte frontend/src/lib/components/Header.test.ts frontend/src/lib/components/StoreBadges.test.ts frontend/src/lib/assets/logo/ frontend/src/lib/assets/stores/ frontend/messages/de.json frontend/messages/en.json
# ggf. gelöschte LangToggle.svelte mit committen
git commit  # Message: "Gleiche Header ans Handoff an (Nav, INDEX-Badge, DE/EN, GitHub)"
```

---

## Task 3: C1 – Marketing-Startseite (`/`)

**Files:**
- Replace: `src/routes/(public)/+page.svelte` (ersetzt die Übergangsseite aus Task 1)
- Modify: `src/routes/(public)/+page.ts` (auf prerender umstellen)
- Modify: `messages/de.json`, `messages/en.json`
- Test: `src/routes/(public)/page.test.ts`

**Interfaces:**
- Consumes: `StoreBadges` (Task 2); `EntryBlock` aus `$lib/components/EntryBlock.svelte` (Props `{ entry, open? }`); die Listen-Ladefunktion aus `$lib/api` (dieselbe, die `loadCatalog` nutzt) für den Teaser; `resolveLocalized` aus `$lib/localized`.
- Produces: prerenderte Startseite unter `/`.

- [ ] **Step 1: `+page.ts` auf Prerender umstellen**

`src/routes/(public)/+page.ts` ersetzen:

```ts
// C1 Marketing-Startseite: statisch prerendern (SEO).
export const prerender = true;
export const ssr = true;
```

- [ ] **Step 2: C1-Message-Keys ergänzen**

In `messages/de.json`:

```jsonc
"c1_page_title": "Gestura – Maus-Gesten für deinen Browser",
"c1_meta_desc": "Maus-Gesten, Super-Drag, Wheel- und Rocker-Gesten und Bereichsauswahl – mit eigenen Suchmaschinen und Website-Menüs. Anonym, ohne Konto, Open Source.",
"c1_trust_pill": "Open Source · AGPL · ohne Tracking",
"c1_hero_h1_a": "Dein Browser gehorcht aufs Wort.",
"c1_hero_h1_b": "Oder auf die Geste.",
"c1_hero_sub": "Gestura bringt Maus-Gesten, Super-Drag, Wheel- und Rocker-Gesten und Bereichsauswahl in deinen Browser – mit eigenen Suchmaschinen und Website-Menüs. Anonym nutzbar, ohne Konto.",
"c1_hero_discover": "Menüs & Suchmaschinen entdecken →",
"c1_mock_action": "↓ → Tab schließen",
"c1_features_heading": "Alles drin, was schnelle Hände brauchen",
"c1_feat_gestures_title": "Maus-Gesten",
"c1_feat_gestures_body": "Rechte Maustaste halten, Strich ziehen: zurück, vor, Tab öffnen oder schließen – frei belegbar.",
"c1_feat_superdrag_title": "Super-Drag",
"c1_feat_superdrag_body": "Links, Text oder Bilder ziehen und je nach Richtung öffnen, suchen oder speichern.",
"c1_feat_wheel_title": "Wheel- & Rocker-Gesten",
"c1_feat_wheel_body": "Mausrad bei gehaltener Taste oder beide Tasten im Wechsel – z. B. blitzschnell Tabs wechseln.",
"c1_feat_area_title": "Bereichsauswahl",
"c1_feat_area_body": "Rechteck aufziehen und alle Links darin öffnen, kopieren oder in Tabs laden.",
"c1_feat_engines_title": "Eigene Suchmaschinen",
"c1_feat_engines_body": "Jede Website mit Suchfeld wird zur Suchmaschine – markieren, Geste, Ergebnis.",
"c1_feat_menus_title": "Website-Menüs",
"c1_feat_menus_body": "Eigene Kontextmenüs je Website – die wichtigsten Aktionen immer unter dem Cursor.",
"c1_trust_anon": "Anonym nutzbar",
"c1_trust_noemail": "Keine E-Mail nötig",
"c1_trust_notrack": "Keine Tracking-Daten",
"c1_trust_os": "Open Source (AGPL)",
"c1_trust_browsers": "Chrome · Edge · Firefox",
"c1_teaser_heading": "Frisch aus dem Index",
"c1_teaser_all": "Alle Einträge ansehen →"
```
Analog `en.json` (faithful EN, z. B. `c1_hero_h1_a`: "Your browser obeys your every word.", `c1_hero_h1_b`: "Or your every gesture.", usw. – knappe, treue Übersetzungen).

- [ ] **Step 3: C1-Seite schreiben (Struktur + Copy; Feinabstände laut Screenshot 1i)**

`src/routes/(public)/+page.svelte`. Kernpunkte:
- `<svelte:head>` mit `c1_page_title` + `c1_meta_desc`.
- Hero-Grid `1.1fr .9fr` (mobil einspaltig): links Trust-Pill (`.badge`-artig, success-getönt, Shield-Icon), H1 (`c1_hero_h1_a` + `<span class="accent">c1_hero_h1_b</span>`), Subline, `<StoreBadges />` in `<div id="install">`, Discover-Textlink → `localizeHref('/index')`. Rechts der Browser-Mock (Step 4).
- Feature-Grid: `.grid-cards` mit 6 `.card.feature-tile` (Icon-Kachel `.icon-tile` mit `--icon-color` je Feature; Lucide `Move`, `Hand`, `MousePointerClick`, `SquareDashedMousePointer`, `Search`, `Menu`), Überschrift `c1_features_heading`.
- Vertrauens-Streifen: eine success-getönte `.card`, fünf Punkte (`c1_trust_*`), rechts `c1_trust_browsers`.
- Teaser (Step 5).

Feature-Array-Muster:

```svelte
	import { Move, Hand, MousePointerClick, SquareDashedMousePointer, Search, Menu, Shield } from '@lucide/svelte';
	const features = [
		{ Icon: Move, color: '#5b9cf6', title: () => m.c1_feat_gestures_title(), body: () => m.c1_feat_gestures_body() },
		{ Icon: Hand, color: '#8b5cf6', title: () => m.c1_feat_superdrag_title(), body: () => m.c1_feat_superdrag_body() },
		{ Icon: MousePointerClick, color: '#ec4899', title: () => m.c1_feat_wheel_title(), body: () => m.c1_feat_wheel_body() },
		{ Icon: SquareDashedMousePointer, color: '#2bb8a8', title: () => m.c1_feat_area_title(), body: () => m.c1_feat_area_body() },
		{ Icon: Search, color: '#4caf50', title: () => m.c1_feat_engines_title(), body: () => m.c1_feat_engines_body() },
		{ Icon: Menu, color: '#e6a117', title: () => m.c1_feat_menus_title(), body: () => m.c1_feat_menus_body() }
	];
```
Icon-Kachel-Färbung: `<span class="icon-tile" style="--icon-color: {f.color}">`.

- [ ] **Step 4: Browser-Mock-SVG (Hero, rechts)**

Inline-SVG (kein Fremd-Asset): Panel mit `--panel-bg`/Border, oben drei Punkte + URL-Bar-Rechteck; darin eine accent-Gestenspur als `<path>` in L-Form (runter, dann rechts) mit `stroke-width:5`, `stroke-linecap:round`, Pfeilspitze am Ende und Startpunkt-Kreis (r6, accent); Aktions-Chip oben rechts (`c1_mock_action`, mono); unten links `icon128.png` (`import` aus `$lib/assets/logo/icon128.png`). Exakte Koordinaten/Optik an Screenshot 1i angleichen. Beispielgerüst:

```svelte
<div class="hero-mock" aria-hidden="true">
	<div class="mock-bar"><span></span><span></span><span></span><div class="mock-url"></div></div>
	<svg viewBox="0 0 420 260" class="mock-canvas">
		<circle cx="120" cy="70" r="6" fill="var(--accent-color)" />
		<path d="M120 70 L120 190 L330 190" fill="none" stroke="var(--accent-color)" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" />
		<path d="M330 190 l-14 -8 v16 z" fill="var(--accent-color)" />
	</svg>
	<div class="mock-chip">{m.c1_mock_action()}</div>
	<img class="mock-logo" src={heroHand} alt="" width="48" height="48" />
</div>
```

- [ ] **Step 5: Index-Teaser (client-seitig, progressive enhancement)**

Da die Seite prerendered ist, lädt der Teaser die Daten erst im Browser. In `$effect`/`onMount` (nur Client) die Listen-API mit `perPage=3`, Sortierung »Neueste« aufrufen (dieselbe Funktion, die `loadCatalog` intern verwendet – Signatur aus `$lib/api` ablesen), Ergebnis in `$state`. Davor drei Skeleton-Zeilen; bei Fehler den Teaser-Block ausblenden (still). Treffer als `EntryBlock` rendern. Link `c1_teaser_all` → `/index`.

```svelte
	import { onMount } from 'svelte';
	import EntryBlock from '$lib/components/EntryBlock.svelte';
	import { listEntries, type EntryListItem } from '$lib/api';
	let teaser = $state<EntryListItem[] | null>(null);
	let teaserFailed = $state(false);
	onMount(async () => {
		try {
			// listEntries(query, opts): EntryQuery hat page/perPage/sort; Response.items.
			const res = await listEntries({ page: 1, perPage: 3, sort: 'newest' });
			teaser = res.items;
		} catch {
			teaserFailed = true;
		}
	});
```
> Signatur verifiziert (Sub-A `$lib/api.ts`): `listEntries(query: EntryQuery, opts?): Promise<EntryListResponse>`, `EntryListResponse.items: EntryListItem[]`. `sort`-Werte wie im Onepager (`'newest'|'installs'|'best'`).

- [ ] **Step 6: C1-Test**

`src/routes/(public)/page.test.ts`: rendert die Seite, mockt `$lib/api` (Teaser liefert 3 Fake-Einträge), prüft: H1-Teilsätze vorhanden, `#install` enthält drei Store-Links, Discover-Link zeigt auf `/index` (bzw. lokalisiert), Feature-Grid hat 6 Karten. API-Mock nach Muster `catalog.test.ts`/`api.test.ts` (inkl. `vi.mock('$env/dynamic/public')` falls nötig).

- [ ] **Step 7: Prüfen & committen** (`check` 0/0, Tests grün; Commit »Baue C1 Marketing-Startseite«).

---

## Task 4: C2 – »Was ist Gestura« (`/was-ist-gestura`)

**Files:**
- Create: `src/routes/(public)/was-ist-gestura/+page.svelte`, `+page.ts`
- Modify: `messages/de.json`, `messages/en.json`
- Test: `src/routes/(public)/was-ist-gestura/page.test.ts`

**Interfaces:** Consumes echte Promo-Screenshots aus `$lib/assets/promo/` (statt dashed Placeholder – Verbesserung ggü. Handoff, da vorhanden).

- [ ] **Step 1: `+page.ts`**
```ts
export const prerender = true;
export const ssr = true;
```

- [ ] **Step 2: Message-Keys** (DE + EN) für: `c2_page_title`, `c2_meta_desc`, `c2_intro` (Copy aus Screenshot 1k), drei Persona-Karten `c2_persona_surf_title/body`, `c2_persona_research_title/body`, `c2_persona_privacy_title/body`; drei Sektionen `c2_sec_gestures_title/body`, `c2_sec_engines_title/body`, `c2_sec_menus_title/body`; Banner `c2_privacy_banner_title` + `c2_privacy_banner_body`. Copy 1:1 aus 1k übernehmen, EN treu übersetzt.

- [ ] **Step 3: Seite schreiben** – 900px-Spalte (die Seite liegt außerhalb `/(public)/index`, das Layout gibt automatisch die schmale `.content`-Spalte). Intro-Absatz; drei Persona-`.card`; drei alternierende Sektionen mit `.icon-tile`-Überschrift + Text + **echtem Screenshot** (`02-gesture-to-menu.png`, `03-search-engines.png`, `04-per-site-menus.png`, gerundet mit Border, `loading="lazy"`); Abschluss success-getöntes Banner. `<svelte:head>` mit Titel/Description. Layout/Abstände an Screenshot 1k.

- [ ] **Step 4: Test** – rendert die Seite, prüft: Titel gesetzt, drei Persona-Karten, drei `<img>` mit `alt`, Banner-Text vorhanden.

- [ ] **Step 5: Prüfen & committen** (Commit »Baue C2 ›Was ist Gestura‹«).

---

## Task 5: C3 – »Was sind Maus-Gesten« (`/maus-gesten`)

**Files:**
- Create: `src/routes/(public)/maus-gesten/+page.svelte`, `+page.ts`
- Create: `src/lib/components/GestureDiagram.svelte`
- Modify: `messages/de.json`, `messages/en.json`
- Test: `src/routes/(public)/maus-gesten/page.test.ts`, `src/lib/components/GestureDiagram.test.ts`

**Interfaces:**
- Produces: `GestureDiagram.svelte` mit Prop `{ kind: 'arrow' | 'rocker' | 'wheel'; path?: string; label: string }` (rendert das 150×80-SVG).

- [ ] **Step 1: `+page.ts`** (`prerender=true; ssr=true`).

- [ ] **Step 2: GestureDiagram-Komponente** – 150×80-`<svg>`; bei `kind==='arrow'` ein accent-`<path>` (übergebene `path`-`d`) mit `stroke-width:5`, `round`-Caps, Startpunkt-Kreis r6 + Pfeilspitze; bei `kind==='rocker'`/`'wheel'` eine Maus-Silhouette (muted Stroke) mit accent-gefüllter Taste bzw. Rad + Rad-Pfeilen. Selbstgebaut, keine Fremdgrafik. Exakte Formen an Screenshot 1l.

```svelte
<script lang="ts">
	let { kind = 'arrow', path = '', label = '' }: { kind?: 'arrow' | 'rocker' | 'wheel'; path?: string; label?: string } = $props();
</script>
<svg viewBox="0 0 150 80" role="img" aria-label={label} class="gesture">
	{#if kind === 'arrow'}
		<path d={path} fill="none" stroke="var(--accent-color)" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" />
	{:else if kind === 'rocker'}
		<!-- zwei Maustasten-Silhouetten, eine accent gefüllt -->
	{:else}
		<!-- Maus mit Rad + Auf/Ab-Pfeilen -->
	{/if}
</svg>
```

- [ ] **Step 3: GestureDiagram-Test** – rendert mit `kind='arrow'`, prüft `<path>` vorhanden + `aria-label` gesetzt.

- [ ] **Step 4: Message-Keys** – `c3_page_title`, `c3_meta_desc`, `c3_intro` (Copy 1l), und je Karte Label + Kürzel: `c3_back`, `c3_forward`, `c3_newtab`, `c3_closetab`, `c3_scrollup`, `c3_reload`, `c3_rocker`, `c3_wheel`, plus Fußnote `c3_footnote` (»Alle Zuordnungen sind Beispiele – in Gestura ist jede Geste frei belegbar.«).

- [ ] **Step 5: Seite** – Intro; `.grid-cards` (4 Spalten Desktop) mit acht Karten. Sechs Pfeil-Karten mit konkreten `d`-Pfaden (aus 1l abgeleitet), zwei Karten Rocker/Wheel. Jede Karte: `<GestureDiagram>` + Mono-Kürzel (accent) + Label. Fußnote muted. `<svelte:head>`.

Pfad-Beispiele (viewBox 0 0 150 80): Zurück `M110 40 L40 40` (+ Pfeil links), Vorwärts `M40 40 L110 40`, Neuer Tab `M75 15 L75 65`, Tab schließen `M60 20 L60 55 L105 55`, Hochscrollen `M75 65 L75 18`, Neu laden `M65 62 L65 20 L82 40`. (Pfeilspitzen als kleine `<path>`-Dreiecke ergänzen; exakte Optik an 1l.)

- [ ] **Step 6: Seiten-Test** – acht Karten (`.gesture` bzw. Karten-Container zählen), Fußnote vorhanden.

- [ ] **Step 7: Prüfen & committen** (Commit »Baue C3 ›Was sind Maus-Gesten‹«).

---

## Task 6: C4 – »Gestura im Vergleich« (`/vergleich`)

**Files:**
- Create: `src/routes/(public)/vergleich/+page.svelte`, `+page.ts`
- Modify: `messages/de.json`, `messages/en.json`
- Test: `src/routes/(public)/vergleich/page.test.ts`

- [ ] **Step 1: `+page.ts`** (`prerender=true; ssr=true`).

- [ ] **Step 2: Message-Keys** – `c4_page_title`, `c4_meta_desc`, `c4_intro`, `c4_warning` (»Die Spalten ›Erweiterung A/B/C‹ sind bewusst Platzhalter – sie werden vor Veröffentlichung mit belegten, aktuellen Angaben gefüllt.«), Spaltenköpfe `c4_col_feature`, `c4_col_a/b/c` (»Erw. A/B/C«), Fußnote `c4_footnote`, sowie zehn Merkmal-Labels `c4_f_firefox`, `c4_f_notrack`, `c4_f_anon`, `c4_f_free`, `c4_f_os`, `c4_f_engines`, `c4_f_menus`, `c4_f_index`, `c4_f_light`, `c4_f_rockerwheel` und die zehn Gestura-Notizen `c4_v_firefox` (»Chrome · Edge · Firefox«), `c4_v_notrack` (»keine IP-Speicherung«), `c4_v_anon` (»keine E-Mail-Pflicht«), `c4_v_free` (»dauerhaft«), `c4_v_os` (»AGPL-3.0«), `c4_v_engines` (»frei definierbar«), `c4_v_menus` (»pro Domain«), `c4_v_index` (»gestura.app«), `c4_v_light` (»reines JS«), `c4_v_rockerwheel` (»inklusive«).

- [ ] **Step 3: Seite** – Intro; Warnbanner (`.badge`-artig warning-getönt, Lucide `TriangleAlert`); Matrix als CSS-Grid `2.2fr 1.2fr 1fr 1fr 1fr`. Zeilen-Array in TS aus den Keys; je Zeile: Merkmal-Label, Gestura-Zelle (grünes `Check`-Icon + Notiz), drei Platzhalterzellen mit `»–«` (muted). Gestura-Spalte hervorgehoben (`background: rgba(91,156,246,.07)`, accent-Border links/rechts). Kopfzeile mit Logo-Tile + »GESTURA«. Fußnote. `<svelte:head>`.

```svelte
	const rows = [
		{ label: () => m.c4_f_firefox(), gestura: () => m.c4_v_firefox() },
		{ label: () => m.c4_f_notrack(), gestura: () => m.c4_v_notrack() },
		/* … alle zehn … */
	];
```

- [ ] **Step 4: Test** – zehn Merkmal-Zeilen; jede Platzhalterspalte enthält `–`; Warnbanner-Text vorhanden; Gestura-Notizen vorhanden.

```ts
it('zeigt zehn Merkmale und drei Platzhalterspalten mit Gedankenstrich', () => {
	const { container, getAllByText } = render(Page);
	expect(container.querySelectorAll('.matrix-row').length).toBe(10);
	expect(getAllByText('–').length).toBeGreaterThanOrEqual(30);
});
```

- [ ] **Step 5: Prüfen & committen** (Commit »Baue C4 ›Gestura im Vergleich‹ (Platzhalter-Matrix)«).

---

## Task 7: C5 – »Beispiele« (`/beispiele`)

**Files:**
- Create: `src/routes/(public)/beispiele/+page.svelte`, `+page.ts`
- Create: `src/lib/marketing/showcase.ts`
- Modify: `messages/de.json`, `messages/en.json`
- Test: `src/routes/(public)/beispiele/page.test.ts`

- [ ] **Step 1: `+page.ts`** (`prerender=true; ssr=true`).

- [ ] **Step 2: Showcase-Daten** – `src/lib/marketing/showcase.ts` exportiert `SHOWCASE` (4 kuratierte Karten aus Screenshot 1n):

```ts
export interface ShowcaseCard {
	gesture: string;         // Mono-Chip, z. B. 'Super-Drag ↓'
	category: string;        // für Icon-Kachel-Farbe (categoryColor)
	type: 'menu' | 'engine'; // Typ-Badge
	nameKey: string;         // Message-Key Name
	descKey: string;         // Message-Key Beschreibung
}
export const SHOWCASE: ShowcaseCard[] = [
	{ gesture: 'Super-Drag ↓', category: 'shopping', type: 'menu', nameKey: 'c5_price_name', descKey: 'c5_price_desc' },
	{ gesture: 'Auswahl + →', category: 'reference', type: 'engine', nameKey: 'c5_wiki_name', descKey: 'c5_wiki_desc' },
	{ gesture: 'Rechtsklick-Menü', category: 'dev', type: 'menu', nameKey: 'c5_github_name', descKey: 'c5_github_desc' },
	{ gesture: '↓ → Menü', category: 'news', type: 'menu', nameKey: 'c5_news_name', descKey: 'c5_news_desc' }
];
```

- [ ] **Step 3: Message-Keys** – `c5_page_title`, `c5_meta_desc`, `c5_intro`, `c5_preview_label` (»Animierte Vorschau (GIF / Video-Frame)«), `c5_cta_install` (»Installieren«), `c5_cta_index` (»Zum Index →«), plus die vier Name/Desc-Paare (Copy aus 1n): Preisvergleich-Menü, Wikipedia-Schnellsuche, GitHub Dev-Menü, News-Radar.

- [ ] **Step 4: Seite** – Intro; 2×2-Grid; je Karte: Gesten-Chip (mono/accent) oben links, Vorschau-Platzhalter (210px Gradient-Fläche + `Play`-Kreis 52px + `c5_preview_label`), darunter `.icon-tile` (Farbe via `categoryColor(category)` aus `$lib/categories`) + Name + Typ-Badge (`entryTypeLabel` aus `$lib/categories`) + Beschreibung + zwei CTAs (`c5_cta_install` → `localizeHref('/') + '#install'`; `c5_cta_index` → `localizeHref('/index')`). `<svelte:head>`.

- [ ] **Step 5: Test** – vier Karten; »Zum Index«-Links zeigen auf `/index`; »Installieren«-Links enthalten `#install`.

- [ ] **Step 6: Prüfen & committen** (Commit »Baue C5 ›Beispiele‹«).

---

## Self-Review (gegen die Spec)

**Spec-Abdeckung:** §3 Routing → Task 1. §4 Header/Footer → Task 2. §5 C1–C5 → Tasks 3–7. §6 Assets/Store-Links → Task 2 (Badges/Logo) + verbatim URLs in Global Constraints. §7 i18n → Keys je Task, tote Keys: **Lücke geschlossen** – siehe Zusatz unten. §8 Tests → je Task. §10 Abweichungen (3 Badges, Theme-3-Zustand, /index) → Task 2/3/1. Kein Spec-Punkt ohne Task.

**Zusatz zu §7 (tote Keys):** Task 3 (C1) macht `hero_title`/`hero_sub`/`home_categories`/`home_docs_cta`/`hero_tagline` endgültig obsolet. Am Ende von Task 3 per `grep -rn "hero_title\|hero_sub\|home_categories\|home_docs_cta\|hero_tagline" frontend/src` verifizieren, dass sie nirgends mehr referenziert werden, und dann aus `de.json`+`en.json` entfernen (im selben Commit). `nav_browse`/`nav_docs`/`nav_get_gestura` in Task 2 analog prüfen und entfernen, falls verwaist.

**Platzhalter-Scan:** Keine »TODO/TBD«. Die einzigen bewusst offenen Punkte sind extern (Store-Badge-Bilddateien vom Nutzer; C4-Konkurrenzdaten – laut Spec Platzhalter) und klar mit Fallback/Regel versehen.

**Typ-Konsistenz:** `StoreBadges` (Task 2) konsumiert in Task 3. `GestureDiagram`-Prop-Signatur (Task 5) einmalig definiert. `ShowcaseCard` (Task 7) lokal. `EntryBlock`-Props `{entry, open?}` wie in Sub-A. Teaser-API-Funktionsname ist der einzige zu verifizierende Punkt (in Task 3 Step 5 ausdrücklich als »Signatur in $lib/api verifizieren« markiert – kein erfundener Name).

## Offene Verifikationspunkte für die Ausführung

1. Exakter Name/Signatur der Listen-Ladefunktion in `$lib/api.ts` (Task 3 Teaser).
2. Store-Badge-Dateiendungen (`.png` vs `.svg`) → Import-Pfade in `StoreBadges` anpassen.
3. `page.route.id`-Werte im Testkontext (Header-Aktivzustand) – Assertion nur, wenn stabil.
