# gestura-index – Projekt-Instruktionen

Vollständiges Kontext-Briefing (was Gestura ist, was dieses Projekt ist, alle festgezurrten Entscheidungen, Hosting-Fakten): @docs/gestura-index-context.md

Team-Gedächtnis (Fallen und Konventionen aus der Umsetzung): @.claude/lessons.md

## Repo-Struktur

- `backend/` – Symfony 7.4 LTS JSON-API (reine API, kein Twig). Lokal: PHP 8.5.3, `composer`.
- `frontend/` – SvelteKit, **Svelte 5 mit Runes** (per `vite.config.ts` erzwungen), **TypeScript**, `adapter-static` (öffentliche Seiten prerendered, Admin als client-only SPA via `fallback`).
- `schema/exchange-schema.json` – **Kopie** des Format-Vertrags aus dem Extension-Repo. Autoritative Quelle: `/mnt/c/Programme.alt/Gestura/js/exchange-schema.json`. Hier **nie direkt ändern** – bei Formatänderungen im Extension-Repo ändern und neu herüberkopieren. Achtung: die Datei wird zur **Laufzeit** von `ExchangeValidator` gelesen – eine neue Kopie ist eine Code-Änderung mit eigenem Testlauf.
- `deploy/` – Deploy-Skripte (versionierte Releases), Runbook.
- `exchange/` – Übergabekanal zum Extension-Repo: **lokaler Ordner, nicht im Repo** (gitignored). Was daraus dauerhaft gilt, steht in `docs/extension-austausch.md`.

Lizenz: **AGPL-3.0-or-later** (Netzwerk-Copyleft-Pendant zur GPL 3 der Extension).

**Design:** Die Website übernimmt das Design der Extension (Tokens, Hell/Dunkel, Karten, Lucide-Icons, Logo) – Regeln und übernommene Dateien: @docs/design-system.md. Einzige gewollte Abweichung: Max-Width-Shell (4K). Keine gestalterischen Alleingänge.

## Wichtige Referenzen (Extension-Repo, aus WSL)

- **Vertrag Extension ↔ Index (autoritativ, direkt lesen, nie kopieren):** `/mnt/c/Programme.alt/Gestura/docs/gestura-eu-api.md`
- **Austausch-Logbuch: `exchange/AUSTAUSCH.md`** (lokal, nicht im Repo) – vor Arbeit an der Extension-Schnittstelle zuerst hineinsehen, danach dort eine Zeile hinterlassen. Bei Mehrdeutigkeiten im Vertrag dort nachfragen, statt sie zu entscheiden. Repo-Fassung mit Stand und **offenen Rückfragen**: `docs/extension-austausch.md`.
- Design-Spec (freigegeben): `/mnt/c/Programme.alt/Gestura/docs/superpowers/specs/2026-07-19-menu-index-design.md`
- Referenz-Validator (autoritativ für Regeln jenseits des JSON-Schemas): `/mnt/c/Programme.alt/Gestura/js/menu-exchange.js`
- Phase-1-Plan (Muster für Planformat): `/mnt/c/Programme.alt/Gestura/docs/superpowers/plans/2026-07-19-menu-index-phase1.md`

## Nicht verhandelbare Prinzipien (Kurzform)

- Die Extension läuft vollständig ohne dieses Backend; hier entsteht nichts, wovon ein Extension-Feature abhängt.
- Alles anonym nutzbar; Konto (Phase 3) nur Komfort. Keine E-Mail-Pflicht, keine IP-Persistenz.
- Server validiert Einreichungen **identisch** zum Client: Aktions-Whitelist, nur `https:`-URLs, Größen-/Anzahllimits, SemVer – Regeln stehen im Schema (`x-gestura` + `description`).
- Einreichungen mit `transformCode` gehen **immer** in die Moderations-Warteschlange (Supply-Chain-Schutz), auch bei Trust-Level und Updates.
- **Locator-Sync (apiLevel 3):** Request-Bodies werden **nie** protokolliert – der Locator ist ein Bearer-Token und reist nur dort. Seine Form (`^[A-Za-z0-9_-]{43}$`) wird geprüft, **bevor** er etwas adressiert, und abgelegt wird er ausschließlich als SHA-256. Kein `3xx` auf `/api/v1/…`.

## Befehle

```bash
# Alles zusammen (Backend + Frontend, ein Befehl; Strg+C beendet beide)
./dev.sh                                          # Backend (Auto-Port ab 8000) + Vite → http://localhost:5173
npm run dev:all                                   # identisch, via Root-package.json (ruft ./dev.sh)

# Backend
composer --working-dir=backend install
php -S localhost:8000 -t backend/public          # Dev-Server (einzeln)
php backend/bin/phpunit                           # Tests (Exit-Code prüfen: echo $?)
                                                  # Achtung failOnDeprecation: »OK« kann trotzdem Exit 1 sein

# Frontend
npm --prefix frontend run dev                     # Dev-Server
npm --prefix frontend run build                   # statischer Build → frontend/build/
npm --prefix frontend run check                   # svelte-check (TypeScript)
npm --prefix frontend run test                    # vitest
```

## Deployment (Zielumgebung, bestätigt)

Shared-Linux-Hosting mit SSH, MySQL, Composer 2.9.8. **PHP-CLI heißt dort `php85`**, nicht `php` – Deploy-Skripte müssen `php85` verwenden. **Versionierte Releases:** `deploy/deploy.sh vX.Y.Z` deployt einen **annotierten** Git-Tag nach `releases/<tag>/`, geteilter Zustand liegt in `shared/`, `current` zeigt auf das aktive Release. Docroot **beider** Domains (`gestura.eu`, `api.gestura.eu`) ist `current/backend/public/` – der Frontend-Build liegt im Release in `backend/public/`, damit die Extension `https://gestura.eu/api/v1/updates` ohne Umleitung erreicht. `deploy/rollback.sh` schaltet zurück, `deploy/smoke.sh` prüft, `deploy/gc.sh` räumt auf. Secrets ausschließlich in `shared/.env.local`. Details: `deploy/README.md`.

## Stand

Phase 2 (Backend, öffentliche Website, Admin-SPA) und Phase 3 (anonyme Konten, Bewertungen, »Meine Daten«, Settings-Sync) sind gebaut. Der Extension-Vertrag ist auf **apiLevel 3** umgesetzt: `POST /api/v1/updates` und die vier `/api/v1/sync/*`-Endpunkte. Öffentlich antworten sie erst nach der manuellen Docroot-Umstellung beim Hoster – das ist der einzige verbliebene Release-Blocker (`deploy/README.md`).

## Arbeitsweise

**brainstorming → writing-plans → subagent-driven execution.** Pläne und Specs liegen unter `docs/superpowers/` und sind Momentaufnahmen ihrer Umsetzung – sie werden nachträglich nicht fortgeschrieben; der laufende Stand steht in `.claude/lessons.md` und in den READMEs. Sprachen im Frontend/Admin: **en/de**.
