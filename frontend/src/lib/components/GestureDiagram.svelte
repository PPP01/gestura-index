<script lang="ts">
	/**
	 * Selbstgebautes 150×80-SVG-Diagramm für eine Maus-Geste (C3 »Was sind
	 * Maus-Gesten«). Keine Fremdgrafik/Marken-Icons – nur eigene <path>/<rect>.
	 *
	 * kind='arrow': `path` ist eine einfache Polylinie ("M x y L x y …", nur
	 * gerade Segmente). Startpunkt-Kreis und Pfeilspitze werden HIER aus den
	 * Punkten der Polylinie berechnet (ein Algorithmus für alle Richtungen –
	 * kein Sonderfall je Karte).
	 * kind='rocker'/'wheel': feste Maus-Silhouette, `path` wird ignoriert.
	 */
	let {
		kind = 'arrow',
		path = '',
		label = ''
	}: { kind?: 'arrow' | 'rocker' | 'wheel'; path?: string; label?: string } = $props();

	type Point = { x: number; y: number };

	// Alle Zahlenpaare aus der Pfad-Definition einsammeln (Kommando-Buchstaben
	// M/L werden dabei einfach übersprungen – die Regex sucht nur Ziffern).
	function parsePoints(d: string): Point[] {
		const points: Point[] = [];
		const re = /(-?\d+(?:\.\d+)?)[ ,]+(-?\d+(?:\.\d+)?)/g;
		let match: RegExpExecArray | null;
		while ((match = re.exec(d))) {
			points.push({ x: parseFloat(match[1]), y: parseFloat(match[2]) });
		}
		return points;
	}

	const points = $derived(kind === 'arrow' ? parsePoints(path) : []);
	const start = $derived<Point>(points[0] ?? { x: 0, y: 0 });
	const tip = $derived<Point>(points.at(-1) ?? { x: 0, y: 0 });
	const before = $derived<Point>(points.length > 1 ? points[points.length - 2] : start);

	// Pfeilspitze = kleiner Winkel ("Dreieck" aus zwei Strichen, gleicher Stroke
	// wie der Schaft), am Ende der letzten Polylinien-Richtung ausgerichtet.
	const arrowHead = $derived.by(() => {
		const dx = tip.x - before.x;
		const dy = tip.y - before.y;
		const len = Math.hypot(dx, dy) || 1;
		const ux = dx / len;
		const uy = dy / len;
		const px = -uy;
		const py = ux;
		const size = 11;
		const spread = 6;
		const backX = tip.x - ux * size;
		const backY = tip.y - uy * size;
		const leftX = backX + px * spread;
		const leftY = backY + py * spread;
		const rightX = backX - px * spread;
		const rightY = backY - py * spread;
		return `M ${leftX} ${leftY} L ${tip.x} ${tip.y} L ${rightX} ${rightY}`;
	});
</script>

<svg viewBox="0 0 150 80" role="img" aria-label={label} class="gesture">
	{#if kind === 'arrow'}
		<path d={path} class="shaft" />
		<path d={arrowHead} class="head" />
		<circle cx={start.x} cy={start.y} r="6" class="dot" />
	{:else if kind === 'rocker'}
		<!-- Zwei Maustasten-Silhouetten (muted), jeweils mit accent-gefüllter
		     Taste oben; dazwischen ein muted Wechsel-Pfeil ("im Wechsel"). -->
		<rect x="43" y="12" width="24" height="48" rx="12" class="mouse-outline" />
		<path d="M43 26 L43 24 A12 12 0 0 1 55 12 A12 12 0 0 1 67 24 L67 26 Z" class="key-fill" />
		<rect x="83" y="12" width="24" height="48" rx="12" class="mouse-outline" />
		<path d="M83 26 L83 24 A12 12 0 0 1 95 12 A12 12 0 0 1 107 24 L107 26 Z" class="key-fill" />
		<path d="M71 46 L81 46" class="muted-shaft" />
		<path d="M75 40 L81 46 L75 52" class="muted-head" />
	{:else}
		<!-- Maus-Silhouette (muted) mit Rad (accent) + vertikalem Doppelpfeil
		     (accent) für "Rad drehen". -->
		<rect x="52" y="10" width="34" height="58" rx="17" class="mouse-outline" />
		<rect x="65" y="20" width="8" height="16" rx="4" class="key-fill" />
		<path d="M113 22 L113 58" class="shaft" />
		<path d="M107 28 L113 20 L119 28" class="head" />
		<path d="M107 52 L113 60 L119 52" class="head" />
	{/if}
</svg>

<style>
	.gesture {
		width: 100%;
		max-width: 150px;
		height: 80px;
		display: block;
		margin: 0 auto;
	}
	.shaft,
	.head {
		fill: none;
		stroke: var(--accent-color);
		stroke-width: 5;
		stroke-linecap: round;
		stroke-linejoin: round;
	}
	.dot {
		fill: var(--accent-color);
	}
	.mouse-outline {
		fill: none;
		stroke: var(--text-muted);
		stroke-width: 3;
	}
	.key-fill {
		fill: var(--accent-color);
		stroke: none;
	}
	.muted-shaft,
	.muted-head {
		fill: none;
		stroke: var(--text-muted);
		stroke-width: 4;
		stroke-linecap: round;
		stroke-linejoin: round;
	}
</style>
