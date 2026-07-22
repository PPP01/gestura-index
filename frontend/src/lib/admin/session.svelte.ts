import { me, AdminApiError, type MeResponse, type AdminClientOpts } from './api';

/** Runes-Store für den eingeloggten Admin/Moderator. */
class Session {
	user = $state<MeResponse | null>(null);

	get isAdmin() {
		return this.user?.role === 'admin';
	}

	get needsBackup() {
		return (this.user?.credentialCount ?? 0) < 2;
	}

	set(u: MeResponse | null) {
		this.user = u;
	}

	clear() {
		this.user = null;
	}

	async load(opts?: AdminClientOpts): Promise<MeResponse | null> {
		try {
			this.user = await me(opts);
			return this.user;
		} catch (e) {
			if (e instanceof AdminApiError && e.status === 401) {
				this.user = null;
				return null;
			}
			throw e;
		}
	}
}

export const session = new Session();
