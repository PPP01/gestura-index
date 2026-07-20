# gestura-index

The optional sharing index for [Gestura](https://github.com/) — a service where Gestura users can browse, import, and submit gesture menus and custom search engines, and (later) sync their settings end-to-end encrypted across browsers.

**Gestura works fully without this service.** The index is a free, optional companion — no extension feature depends on it.

## Repository layout

| Directory | Contents |
| --- | --- |
| `backend/` | Symfony JSON API (`/api/v1`) — entries, versions, moderation, reports, accounts |
| `frontend/` | SvelteKit (Svelte 5) — public index website (prerendered, `adapter-static`) + admin SPA |
| `schema/` | Shared contract: JSON Schema of the Gestura exchange format (copied from the extension repo) |
| `deploy/` | Deployment scripts and CI configuration |
| `docs/` | Project documentation and context briefing |

## Principles

- **Usable without an account** — browsing, importing, submitting, update checks, and reporting all work anonymously. An account only adds convenience (management, ratings, sync), never obligations.
- **Data minimalism** — no e-mail requirement, no IP persistence, anonymous install counters, full data self-disclosure, immediate account deletion.
- **No push** — updates to imported content are only shown and applied on explicit user request.
- **End-to-end encryption** — everything private (settings sync) is zero-knowledge; the server only ever sees ciphertext.

## Development

Backend (PHP ≥ 8.2, Symfony):

```bash
cd backend
composer install
php -S localhost:8000 -t public   # or use the Symfony CLI
```

Frontend (Node ≥ 20, SvelteKit):

```bash
cd frontend
npm install
npm run dev
```

## Status

Phase 2 (index backend + website) is in preparation. Phase 1 — the exchange format, validator, and import/export UI — lives in the Gestura extension repository and is complete.

## License

[GNU AGPL-3.0-or-later](LICENSE) — the network-service counterpart to the GPL-3.0 license of the Gestura extension.
