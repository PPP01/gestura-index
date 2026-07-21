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

/** Lokalisiertes Label einer Kategorie (Fallback: der Key selbst). */
export function categoryLabel(key: string): string {
	const fn = (m as Record<string, () => string>)[`cat_${key}`];
	return typeof fn === 'function' ? fn() : key;
}
