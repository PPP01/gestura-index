import { env } from '$env/dynamic/public';

/** Basis-URL der Index-API; über PUBLIC_API_BASE überschreibbar. */
export const API_BASE = env.PUBLIC_API_BASE || 'https://api.gestura.eu';

export type EntryType = 'menu' | 'engine';
export type ReportReason = 'spam' | 'broken_links' | 'misleading' | 'legal';

/** Ein Eintrag in der Listen-/Kartenansicht (Serializer::toListItem). */
export interface EntryListItem {
	formatId: string;
	type: EntryType;
	name: string;
	description: string | null;
	categories: string[];
	tags: string[];
	domains: string[];
	installCount: number;
	currentVersion: string | null;
	deprecated: boolean;
	successorFormatId: string | null;
	screenshotUrl: string | null;
	updatedAt: string;
}

/** Eine Version eines Eintrags (Serializer::toDetail.versions[]). */
export interface VersionInfo {
	semver: string;
	changelog: string | null;
	hasTransformCode: boolean;
	submittedAt: string;
}

/** Detailansicht: Listenfelder plus Versionsliste. */
export interface EntryDetail extends EntryListItem {
	versions: VersionInfo[];
}

export interface EntryListResponse {
	items: EntryListItem[];
	page: number;
	perPage: number;
	total: number;
}

/** Query-Parameter für listEntries; nur gesetzte Felder landen in der URL. */
export interface EntryQuery {
	q?: string;
	type?: EntryType;
	category?: string;
	tag?: string;
	site?: string;
	sort?: string;
	page?: number;
	perPage?: number;
}

export interface ReportInput {
	reason: ReportReason;
	comment?: string;
}

type Fetch = typeof fetch;

export interface ClientOpts {
	fetch?: Fetch;
	baseUrl?: string;
}

/** Normalisierter API-Fehler (aus application/problem+json bzw. Netzwerkfehler). */
export class ApiError extends Error {
	constructor(
		public status: number,
		public title: string,
		public detail: string | null
	) {
		super(title);
		this.name = 'ApiError';
	}
}

/** Setzt die API-Basis vor eine relativ gelieferte Screenshot-URL. */
export function absoluteScreenshotUrl(url: string | null, baseUrl: string = API_BASE): string | null {
	return url ? baseUrl + url : null;
}

/** Baut die Download-URL einer konkreten Version (auch als Import-URL nutzbar). */
export function downloadVersionUrl(formatId: string, semver: string, baseUrl: string = API_BASE): string {
	return `${baseUrl}/api/v1/entries/${encodeURIComponent(formatId)}/versions/${encodeURIComponent(semver)}`;
}

async function toApiError(res: Response): Promise<ApiError> {
	let title = res.statusText || 'Request failed';
	let detail: string | null = null;
	try {
		const body = await res.json();
		if (typeof body?.title === 'string') title = body.title;
		if (typeof body?.detail === 'string') detail = body.detail;
	} catch {
		/* kein JSON-Body – Fallback auf statusText */
	}
	return new ApiError(res.status, title, detail);
}

async function request(path: string, init: RequestInit, opts: ClientOpts): Promise<Response> {
	const f = opts.fetch ?? fetch;
	const base = opts.baseUrl ?? API_BASE;
	let res: Response;
	try {
		res = await f(base + path, init);
	} catch (e) {
		throw new ApiError(0, 'Network error', e instanceof Error ? e.message : null);
	}
	if (!res.ok) throw await toApiError(res);
	return res;
}

function mapItem(item: EntryListItem, base: string): EntryListItem {
	return { ...item, screenshotUrl: absoluteScreenshotUrl(item.screenshotUrl, base) };
}

export async function listEntries(query: EntryQuery, opts: ClientOpts = {}): Promise<EntryListResponse> {
	const params = new URLSearchParams();
	for (const [key, value] of Object.entries(query)) {
		if (value !== undefined && value !== null && value !== '') params.set(key, String(value));
	}
	const qs = params.toString();
	const res = await request(`/api/v1/entries${qs ? `?${qs}` : ''}`, { method: 'GET' }, opts);
	const data = (await res.json()) as EntryListResponse;
	const base = opts.baseUrl ?? API_BASE;
	return { ...data, items: data.items.map((i) => mapItem(i, base)) };
}

export async function getEntry(formatId: string, opts: ClientOpts = {}): Promise<EntryDetail> {
	const res = await request(
		`/api/v1/entries/${encodeURIComponent(formatId)}`,
		{ method: 'GET' },
		opts
	);
	const data = (await res.json()) as EntryDetail;
	const base = opts.baseUrl ?? API_BASE;
	return { ...mapItem(data, base), versions: data.versions };
}

export async function downloadVersion(
	formatId: string,
	semver: string,
	opts: ClientOpts = {}
): Promise<unknown> {
	const res = await request(
		`/api/v1/entries/${encodeURIComponent(formatId)}/versions/${encodeURIComponent(semver)}`,
		{ method: 'GET' },
		opts
	);
	return res.json();
}

export async function pingInstall(formatId: string, opts: ClientOpts = {}): Promise<void> {
	await request(`/api/v1/entries/${encodeURIComponent(formatId)}/install`, { method: 'POST' }, opts);
}

export async function reportEntry(
	formatId: string,
	input: ReportInput,
	opts: ClientOpts = {}
): Promise<void> {
	await request(
		`/api/v1/entries/${encodeURIComponent(formatId)}/report`,
		{
			method: 'POST',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify(input)
		},
		opts
	);
}
