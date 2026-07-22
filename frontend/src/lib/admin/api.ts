import { env } from '$env/dynamic/public';

/** Basis-URL der Admin-API; über PUBLIC_API_BASE überschreibbar. */
export const ADMIN_API_BASE = env.PUBLIC_API_BASE || 'https://api.gestura.eu';

export type AdminRole = 'admin' | 'moderator';
export type AdminUserStatus = 'invited' | 'active' | 'disabled';
export type EntryType = 'menu' | 'engine';
export type ReportReason = 'spam' | 'broken_links' | 'misleading' | 'legal';

/** GET /auth/me. */
export interface MeResponse {
	email: string;
	displayName: string;
	role: AdminRole;
	credentialCount: number;
	stepUpFresh: boolean;
}

/** Element von GET /credentials. */
export interface Credential {
	id: number;
	label: string;
	createdAt: string;
	lastUsedAt: string | null;
}

export interface QueueEntry {
	id: number;
	formatId: string;
	type: EntryType;
	createdAt: string;
}

export interface QueueVersion {
	id: number;
	entryId: number;
	formatId: string;
	semver: string;
	hasTransformCode: boolean;
	submittedAt: string;
}

/** GET /queue. */
export interface QueueResponse {
	entries: QueueEntry[];
	versions: QueueVersion[];
}

export interface EntryVersionAdmin {
	semver: string;
	changelog: string | null;
	hasTransformCode: boolean;
	submittedAt: string;
}

/** Eingebettete offene Meldung in GET /entries/{id}. */
export interface OpenReport {
	id: number;
	reason: ReportReason;
	comment: string | null;
	createdAt: string;
}

/** GET /entries/{id} — EntrySerializer::toDetail plus Admin-Zusatzfelder. */
export interface EntryDetailAdmin {
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
	versions: EntryVersionAdmin[];
	submitterId: number;
	submitterBanned: boolean;
	openReports: OpenReport[];
}

/** Element von GET /reports (nur offene Meldungen). */
export interface Report {
	id: number;
	entryId: number;
	formatId: string;
	submitterId: number;
	submitterBanned: boolean;
	reason: ReportReason;
	comment: string | null;
	createdAt: string;
}

/** Element von GET /users. */
export interface AdminUser {
	id: number;
	displayName: string;
	email: string;
	role: AdminRole;
	status: AdminUserStatus;
	createdAt: string;
	lastLoginAt: string | null;
}

export interface AuditItem {
	id: number;
	actor: string | null;
	action: string;
	targetType: string;
	targetId: string;
	detail: unknown;
	createdAt: string;
}

/** GET /audit. */
export interface AuditResponse {
	items: AuditItem[];
	page: number;
	perPage: number;
}

export interface AdminClientOpts {
	fetch?: typeof fetch;
	baseUrl?: string;
}

/** Normalisierter Admin-API-Fehler (aus application/problem+json bzw. Netzwerkfehler). */
export class AdminApiError extends Error {
	constructor(
		public status: number,
		public title: string,
		public detail: string | null,
		public stepUpRequired = false,
		public backupRequired = false,
		public retryAfter: number | null = null
	) {
		super(title);
		this.name = 'AdminApiError';
	}
}

async function toError(res: Response): Promise<AdminApiError> {
	let title = res.statusText || 'Error';
	let detail: string | null = null;
	let stepUpRequired = false;
	let backupRequired = false;
	let retryAfter: number | null = null;
	try {
		const body = await res.json();
		if (typeof body?.title === 'string') title = body.title;
		if (typeof body?.detail === 'string') detail = body.detail;
		stepUpRequired = body?.stepUpRequired === true;
		backupRequired = body?.backupRequired === true;
		retryAfter = typeof body?.retryAfter === 'number' ? body.retryAfter : null;
	} catch {
		/* kein JSON-Body – Fallback auf statusText */
	}
	return new AdminApiError(res.status, title, detail, stepUpRequired, backupRequired, retryAfter);
}

/** Zentrale Fetch-Funktion für die Admin-API: credentials + X-Requested-With, problem+json→Flags. */
export async function adminFetch<T>(
	path: string,
	init: RequestInit = {},
	opts: AdminClientOpts = {}
): Promise<T> {
	const f = opts.fetch ?? fetch;
	const base = opts.baseUrl ?? ADMIN_API_BASE;
	let res: Response;
	try {
		res = await f(`${base}${path}`, {
			...init,
			credentials: 'include',
			headers: {
				'Content-Type': 'application/json',
				'X-Requested-With': 'XMLHttpRequest',
				...(init.headers ?? {})
			}
		});
	} catch (e) {
		throw new AdminApiError(0, 'Network error', e instanceof Error ? e.message : null);
	}
	if (!res.ok) throw await toError(res);
	if (res.status === 204) return undefined as T;
	const text = await res.text();
	return (text ? JSON.parse(text) : undefined) as T;
}

// --- Auth ---

export const authOptions = (o?: AdminClientOpts) =>
	adminFetch<unknown>('/api/admin/auth/options', { method: 'POST' }, o);

export const authLogin = (assertion: unknown, o?: AdminClientOpts) =>
	adminFetch<void>('/api/admin/auth/login', { method: 'POST', body: JSON.stringify(assertion) }, o);

export const authLogout = (o?: AdminClientOpts) =>
	adminFetch<void>('/api/admin/auth/logout', { method: 'POST' }, o);

export const me = (o?: AdminClientOpts) => adminFetch<MeResponse>('/api/admin/auth/me', {}, o);

// --- Register ---

export const registerOptions = (token: string, o?: AdminClientOpts) =>
	adminFetch<unknown>(
		'/api/admin/register/options',
		{ method: 'POST', body: JSON.stringify({ token }) },
		o
	);

export const register = (token: string, attestation: unknown, o?: AdminClientOpts) =>
	adminFetch<void>(
		'/api/admin/register',
		{ method: 'POST', body: JSON.stringify({ token, ...(attestation as Record<string, unknown>) }) },
		o
	);

// --- Step-up ---

export const stepUpOptions = (o?: AdminClientOpts) =>
	adminFetch<unknown>('/api/admin/stepup/options', { method: 'POST' }, o);

export const stepUpVerify = (assertion: unknown, o?: AdminClientOpts) =>
	adminFetch<void>('/api/admin/stepup', { method: 'POST', body: JSON.stringify(assertion) }, o);

// --- Credentials ---

export const listCredentials = (o?: AdminClientOpts) =>
	adminFetch<Credential[]>('/api/admin/credentials', {}, o);

export const addCredentialOptions = (o?: AdminClientOpts) =>
	adminFetch<unknown>('/api/admin/credentials/options', { method: 'POST' }, o);

export const addCredential = (attestation: unknown, label: string, o?: AdminClientOpts) =>
	adminFetch<{ id: number; label: string }>(
		'/api/admin/credentials',
		{
			method: 'POST',
			body: JSON.stringify({ ...(attestation as Record<string, unknown>), label })
		},
		o
	);

export const renameCredential = (id: number, label: string, o?: AdminClientOpts) =>
	adminFetch<{ id: number; label: string }>(
		`/api/admin/credentials/${id}`,
		{ method: 'PATCH', body: JSON.stringify({ label }) },
		o
	);

export const removeCredential = (id: number, o?: AdminClientOpts) =>
	adminFetch<void>(`/api/admin/credentials/${id}/remove`, { method: 'POST' }, o);

// --- Moderation ---

export const queue = (o?: AdminClientOpts) => adminFetch<QueueResponse>('/api/admin/queue', {}, o);

export const entryDetail = (id: number, o?: AdminClientOpts) =>
	adminFetch<EntryDetailAdmin>(`/api/admin/entries/${id}`, {}, o);

export const approveEntry = (id: number, o?: AdminClientOpts) =>
	adminFetch<void>(`/api/admin/entries/${id}/approve`, { method: 'POST' }, o);

export const rejectEntry = (id: number, o?: AdminClientOpts) =>
	adminFetch<void>(`/api/admin/entries/${id}/reject`, { method: 'POST' }, o);

export const approveVersion = (id: number, o?: AdminClientOpts) =>
	adminFetch<void>(`/api/admin/versions/${id}/approve`, { method: 'POST' }, o);

export const rejectVersion = (id: number, o?: AdminClientOpts) =>
	adminFetch<void>(`/api/admin/versions/${id}/reject`, { method: 'POST' }, o);

export const reports = (o?: AdminClientOpts) => adminFetch<Report[]>('/api/admin/reports', {}, o);

export const resolveReport = (id: number, publish: boolean, o?: AdminClientOpts) =>
	adminFetch<void>(
		`/api/admin/reports/${id}/resolve`,
		{ method: 'POST', body: JSON.stringify({ publish }) },
		o
	);

export const banSubmitter = (id: number, o?: AdminClientOpts) =>
	adminFetch<void>(`/api/admin/submitters/${id}/ban`, { method: 'POST' }, o);

export const unbanSubmitter = (id: number, o?: AdminClientOpts) =>
	adminFetch<void>(`/api/admin/submitters/${id}/unban`, { method: 'POST' }, o);

// --- Users ---

export const listUsers = async (o?: AdminClientOpts): Promise<AdminUser[]> => {
	const res = await adminFetch<{ users: AdminUser[] }>('/api/admin/users', {}, o);
	return res.users;
};

export interface InviteUserInput {
	displayName: string;
	email: string;
	role: AdminRole;
}

export const inviteUser = async (input: InviteUserInput, o?: AdminClientOpts): Promise<AdminUser> => {
	const res = await adminFetch<{ id: number; email: string; status: AdminUserStatus }>(
		'/api/admin/users',
		{ method: 'POST', body: JSON.stringify(input) },
		o
	);
	// Der Server liefert beim Invite nur id/email/status zurück; die übrigen
	// Felder sind aus dem Request bereits bekannt (createdAt kommt erst mit dem
	// nächsten listUsers()-Reload).
	return {
		id: res.id,
		displayName: input.displayName,
		email: res.email,
		role: input.role,
		status: res.status,
		createdAt: '',
		lastLoginAt: null
	};
};

export const disableUser = (id: number, o?: AdminClientOpts) =>
	adminFetch<void>(`/api/admin/users/${id}/disable`, { method: 'POST' }, o);

export const enableUser = (id: number, o?: AdminClientOpts) =>
	adminFetch<void>(`/api/admin/users/${id}/enable`, { method: 'POST' }, o);

export const reinviteUser = (id: number, o?: AdminClientOpts) =>
	adminFetch<void>(`/api/admin/users/${id}/reinvite`, { method: 'POST' }, o);

// --- Audit ---

export const audit = (page: number, perPage: number, o?: AdminClientOpts) =>
	adminFetch<AuditResponse>(`/api/admin/audit?page=${page}&perPage=${perPage}`, {}, o);
