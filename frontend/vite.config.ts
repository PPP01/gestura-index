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
			adapter: adapter({ fallback: '200.html' })
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
