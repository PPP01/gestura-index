import type { Component } from 'svelte';
import {
	Code,
	ShoppingCart,
	Video,
	Newspaper,
	Users,
	CheckSquare,
	Search,
	BookOpen,
	Clapperboard,
	Tag
} from '@lucide/svelte';
import { m } from '$lib/paraglide/messages.js';

/** Die festen Kategorie-Keys – identisch zum Backend-Enum, feste Reihenfolge. */
export const CATEGORIES = [
	'dev',
	'shopping',
	'video',
	'news',
	'social',
	'productivity',
	'search',
	'reference',
	'entertainment',
	'other'
] as const;

const ICONS: Record<string, Component> = {
	dev: Code,
	shopping: ShoppingCart,
	video: Video,
	news: Newspaper,
	social: Users,
	productivity: CheckSquare,
	search: Search,
	reference: BookOpen,
	entertainment: Clapperboard,
	other: Tag
};

/** Lucide-Icon-Komponente für eine Kategorie (Fallback: Tag). */
export function categoryIcon(key: string): Component {
	return ICONS[key] ?? Tag;
}

const COLORS: Record<string, string> = {
	dev: '#8b5cf6',
	shopping: '#e6a117',
	video: '#ef5350',
	news: '#fb8c4e',
	social: '#ec4899',
	productivity: '#4caf50',
	search: '#5b9cf6',
	reference: '#2bb8a8',
	entertainment: '#d4b106',
	other: '#8a8a93'
};

/** Kategorie-Akzentfarbe (Icon-Kachel/Badge); Fallback: Akzent. */
export function categoryColor(key: string): string {
	return COLORS[key] ?? '#5b9cf6';
}

/** Lokalisiertes Label einer Kategorie (Fallback: der Key selbst). */
export function categoryLabel(key: string): string {
	const fn = (m as unknown as Record<string, () => string>)[`cat_${key}`];
	return typeof fn === 'function' ? fn() : key;
}
