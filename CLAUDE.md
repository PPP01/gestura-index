# gestura-index – Projekt-Instruktionen

Vollständiges Kontext-Briefing (was Gestura ist, was dieses Projekt ist, alle festgezurrten Entscheidungen, Hosting-Fakten): @docs/gestura-index-context.md

## Repo-Struktur

- `backend/` – Symfony 7.4 LTS JSON-API (reine API, kein Twig). Lokal: PHP 8.5.3, `composer`.
- `frontend/` – SvelteKit, **Svelte 5 mit Runes** (per `vite.config.ts` erzwungen), **TypeScript**, `adapter-static` (öffentliche Seiten prerendered, Admin später als client-only SPA via `fallback`).
- `schema/exchange-schema.json` – **Kopie** des Format-Vertrags aus dem Extension-Repo. Autoritative Quelle: `/mnt/c/Programme.alt/Gestura/js/exchange-schema.json`. Hier **nie direkt ändern** – bei Formatänderungen im Extension-Repo ändern und neu herüberkopieren.
- `deploy/` – Deploy-Skripte, CI-Konfiguration.

Lizenz: **AGPL-3.0-or-later** (Netzwerk-Copyleft-Pendant zur GPL 3 der Extension).

## Wichtige Referenzen (Extension-Repo, aus WSL)

- Design-Spec (freigegeben): `/mnt/c/Programme.alt/Gestura/docs/superpowers/specs/2026-07-19-menu-index-design.md`
- Referenz-Validator (autoritativ für Regeln jenseits des JSON-Schemas): `/mnt/c/Programme.alt/Gestura/js/menu-exchange.js`
- Phase-1-Plan (Muster für Planformat): `/mnt/c/Programme.alt/Gestura/docs/superpowers/plans/2026-07-19-menu-index-phase1.md`

## Nicht verhandelbare Prinzipien (Kurzform)

- Die Extension läuft vollständig ohne dieses Backend; hier entsteht nichts, wovon ein Extension-Feature abhängt.
- Alles anonym nutzbar; Konto (Phase 3) nur Komfort. Keine E-Mail-Pflicht, keine IP-Persistenz.
- Server validiert Einreichungen **identisch** zum Client: Aktions-Whitelist, nur `https:`-URLs, Größen-/Anzahllimits, SemVer – Regeln stehen im Schema (`x-gestura` + `description`).
- Einreichungen mit `transformCode` gehen **immer** in die Moderations-Warteschlange (Supply-Chain-Schutz), auch bei Trust-Level und Updates.

## Befehle

```bash
# Backend
composer --working-dir=backend install
php -S localhost:8000 -t backend/public          # Dev-Server
backend/bin/phpunit                               # Tests (sobald eingerichtet)

# Frontend
npm --prefix frontend run dev                     # Dev-Server
npm --prefix frontend run build                   # statischer Build → frontend/build/
npm --prefix frontend run check                   # svelte-check (TypeScript)
```

## Deployment (Zielumgebung, bestätigt)

Shared-Linux-Hosting mit SSH, MySQL, Composer 2.9.8. **PHP-CLI heißt dort `php85`**, nicht `php` – Deploy-Skripte müssen `php85` verwenden. Docroot der Index-Domain muss auf `backend/public/` zeigen; Frontend-Build wird ins Web-Root der Index-Domain geladen. Secrets ausschließlich in `.env.local` außerhalb des Repos.

## Arbeitsweise

Phase 2 startet mit **brainstorming → writing-plans → subagent-driven execution** (gleicher Ablauf wie Phase 1 in der Extension). Sprachen im Frontend/Admin: **en/de** von Anfang an.
