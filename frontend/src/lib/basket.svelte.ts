import { browser } from '$app/environment';

const KEY = 'gestura-basket';

function loadInitial(): string[] {
	if (!browser) return [];
	try {
		const raw = localStorage.getItem(KEY);
		if (!raw) return [];
		const parsed = JSON.parse(raw);
		return Array.isArray(parsed) ? parsed.filter((x): x is string => typeof x === 'string') : [];
	} catch {
		return [];
	}
}

let ids = $state<string[]>(loadInitial());

function persist() {
	if (!browser) return;
	try {
		localStorage.setItem(KEY, JSON.stringify(ids));
	} catch {
		/* Speicher voll / privat – Auswahl bleibt für die Sitzung im State. */
	}
}

/** Persistenter Auswahl-Store (Menge von formatId). */
export const basket = {
	get ids(): string[] {
		return ids;
	},
	get count(): number {
		return ids.length;
	},
	has(id: string): boolean {
		return ids.includes(id);
	},
	toggle(id: string): void {
		ids = ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id];
		persist();
	},
	remove(id: string): void {
		ids = ids.filter((x) => x !== id);
		persist();
	},
	/**
	 * Entfernt mehrere IDs in EINEM Zug – für »Alle Menüs entfernen« und
	 * »Alle Suchmaschinen entfernen«. Einzeln in einer Schleife zu entfernen
	 * würde denselben Zustand mehrfach schreiben und neu rendern.
	 */
	removeMany(remove: Iterable<string>): void {
		const drop = new Set(remove);
		const next = ids.filter((id) => !drop.has(id));
		if (next.length === ids.length) return;
		ids = next;
		persist();
	},
	clear(): void {
		ids = [];
		persist();
	},
	/** Entfernt IDs, die nicht mehr im Katalog sind. */
	reconcile(valid: Set<string>): void {
		const next = ids.filter((id) => valid.has(id));
		if (next.length !== ids.length) {
			ids = next;
			persist();
		}
	}
};
