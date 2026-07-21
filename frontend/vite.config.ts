import adapter from '@sveltejs/adapter-static';
import { sveltekit } from '@sveltejs/kit/vite';
import { defineConfig } from 'vitest/config';
import { paraglideVitePlugin } from '@inlang/paraglide-js';
import { svelteTesting } from '@testing-library/svelte/vite';

export default defineConfig({
	plugins: [
		sveltekit({
			compilerOptions: {
				// Force runes mode for the project, except for libraries. Can be removed in svelte 6.
				runes: ({ filename }) =>
					filename.split(/[/\\]/).includes('node_modules') ? undefined : true
			},

			// adapter-static: öffentliche Seiten werden prerendered (SEO), der Build ist
			// rein statisch und läuft auf klassischem Hosting ohne Node-SSR.
			// `fallback` liefert für nicht-prerenderte, dynamische Routen (Detailseiten,
			// Sprach-Weiche) eine SPA-Hülle aus.
			adapter: adapter({ fallback: '200.html' }),

			prerender: {
				// Header/Footer verlinken auf Routen, die erst in späteren SDD-Tasks entstehen:
				// – »/browse«: bleibt client-rendered (SPA-Fallback über adapter-static)
				// – »/docs«, »/about«, »/privacy«, »/imprint«: entstehen in späteren Tasks
				// Der Crawler würde bei echten 404-Fehlern auf diesen Pfaden den Build mit
				// harten Fehler abbrechen. Daher wird AUSSCHLIESSLICH ein 404-Status auf
				// diesen bekannten, noch fehlenden Zielen zur Warnung downgegradet.
				// WARNUNG: Diese Ausnahme MUSS in Task 13 (finale Integration) komplett
				// entfernt werden, sobald alle Routen existieren. Jeden anderen
				// HTTP-Fehler-Status (5xx, 3xx etc.) oder unerwartete Pfade führen zu
				// Buildabbruch – kein stiller Fehler-Schlucker.
				handleHttpError: ({ path, status, message }) => {
					const pendingRoutes = ['/browse', '/docs', '/about', '/privacy', '/imprint'];
					if (status === 404 && pendingRoutes.some((route) => path.endsWith(route))) {
						console.warn(`[prerender] ${message} — Route folgt in einem späteren Task.`);
						return;
					}
					throw new Error(message);
				}
			}
		}),

		// Paraglide: kompiliert die Übersetzungen (messages/*.json) zu tree-shakebaren
		// Funktionen nach src/lib/paraglide/. `url`-Strategie = Sprach-Präfix /en, /de.
		paraglideVitePlugin({
			project: './project.inlang',
			outdir: './src/lib/paraglide',
			strategy: ['url', 'cookie', 'preferredLanguage', 'baseLocale'],
			// Symmetrisches Präfix: auch die Basissprache (en) bekommt /en; die
			// nackte Wurzel / wird von der Sprach-Weiche auf /en bzw. /de geleitet.
			urlPatterns: [
				{
					pattern: '/:path(.*)?',
					localized: [
						['en', '/en/:path(.*)?'],
						['de', '/de/:path(.*)?']
					]
				}
			]
		}),

		// Sorgt dafür, dass Vitest Sveltes Browser-Build statt des SSR-Builds
		// auflöst (nur aktiv unter VITEST) – sonst schlägt render() aus
		// @testing-library/svelte mit "mount(...) is not available on the
		// server" fehl.
		svelteTesting()
	],
	test: {
		environment: 'jsdom',
		setupFiles: ['./vitest-setup.ts'],
		globals: true
	}
});
