# gestura-index

The optional sharing index for the **Gestura** browser extension — a service where Gestura users can browse, import, and submit gesture menus and custom search engines, and sync their settings end-to-end encrypted across browsers.

**Gestura works fully without this service.** The index is a free, optional companion — no extension feature depends on it.

## Repository layout

| Directory | Contents |
| --- | --- |
| `backend/` | Symfony 7.4 JSON API — entries, versions, moderation, reports, accounts, admin, extension contract endpoints |
| `frontend/` | SvelteKit (Svelte 5, runes, TypeScript) — public index website (prerendered, `adapter-static`) + admin SPA |
| `schema/` | Shared contract: JSON Schema of the Gestura exchange format — **a copy** of `js/exchange-schema.json` from the extension repo, never edited here |
| `deploy/` | Deployment scripts (versioned releases, rollback, smoke test, GC) and the deployment runbook |
| `docs/` | Project documentation, design system, and the copy of the extension ↔ index contract |
| `exchange/` | Hand-off channel with the extension repository — see below |

## The extension contract

The extension and the index talk over a versioned contract. It is maintained **in the extension repository** and read from there directly, never copied into code:

```text
/mnt/c/Programme.alt/Gestura/docs/gestura-eu-api.md   the contract (authoritative)
/mnt/c/Programme.alt/Gestura/js/exchange-schema.json  the format schema
/mnt/c/Programme.alt/Gestura/js/menu-exchange.js      the reference validator
```

`exchange/AUSTAUSCH.md` is the shared log book between the two repositories: read it before touching anything on the extension boundary, and leave a line there afterwards. `docs/gestura-eu-api.md` is a convenience copy of the contract, marked with the commit it was taken from.

The index implements **apiLevel 3**: the update check (`POST /api/v1/updates`) and the four anonymous, locator-addressed sync endpoints (`/api/v1/sync/*`).

## Principles

- **Usable without an account** — browsing, importing, submitting, update checks, settings sync, and reporting all work anonymously. An account only adds convenience (management, ratings), never obligations.
- **Data minimalism** — no e-mail requirement, no IP persistence, anonymous install counters, full data self-disclosure, immediate account deletion. Sync locators are stored only as a SHA-256 hash.
- **No push** — updates to imported content are only shown and applied on explicit user request.
- **End-to-end encryption** — everything private (settings sync) is zero-knowledge; the server only ever sees ciphertext and cannot decrypt, merge, or inspect it.
- **The server validates exactly like the client** — action whitelist, `https:`-only URLs, size and count limits, SemVer. Submissions carrying `transformCode` always go to the moderation queue.

## Development

Requires PHP ≥ 8.2 (developed on 8.5) with `ext-gd`, `ext-pdo_mysql`; Node ≥ 20; MariaDB/MySQL.

```bash
# Everything at once — backend (auto-picks a free port from 8000) + Vite. Ctrl+C stops both.
./dev.sh                             # → http://localhost:5173

composer --working-dir=backend install
npm --prefix frontend install

php backend/bin/phpunit; echo $?     # backend tests — always check the exit code
npm --prefix frontend run test       # frontend tests
npm --prefix frontend run check      # svelte-check (TypeScript)
npm --prefix frontend run build      # static build → frontend/build/
```

> The test suite runs with `failOnDeprecation="true"`: it can print `OK` and still exit with code 1 (“OK, but there were issues!”). Read the exit code, not the text.

Local database credentials live in `backend/.env.local` / `.env.test.local` (not in the repo).

## Deployment

Shared Linux hosting with SSH, MySQL, Composer — **the PHP CLI there is `php85`, not `php`**. `deploy/deploy.sh vX.Y.Z` deploys an annotated git tag to `releases/<tag>/`; shared state lives in `shared/`, and `current` points at the active release. Both domains (`gestura.eu`, `api.gestura.eu`) serve from the same docroot, `current/backend/public/`. Details and the runbook: [`deploy/README.md`](deploy/README.md).

## Status

- **Phase 1** (exchange format, validator, import/export UI) — complete, lives in the extension repository.
- **Phase 2** (index backend + public website + admin SPA) — complete.
- **Phase 3** (anonymous accounts, ratings, “my data”, settings sync) — complete.
- **Extension contract R2/R3** — implemented and tested; the endpoints answer locally. Going public still needs one manual step at the hoster (the shared docroot).

## License

[GNU AGPL-3.0-or-later](LICENSE) — the network-service counterpart to the GPL-3.0 license of the Gestura extension.
