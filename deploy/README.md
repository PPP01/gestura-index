# deploy/

Deploy-Skripte und CI-Konfiguration für den gestura-index (entstehen in Phase 2).

Geplanter Ablauf (siehe Design-Spec, Abschnitt 7):

1. Frontend: `npm run build` → statische Dateien → Upload ins Web-Root der Index-Domain.
2. Backend: `composer install --no-dev -o` lokal/CI → SSH/rsync zum Server.
3. Migrationen auf dem Server: `php85 bin/console doctrine:migrations:migrate` (PHP-CLI heißt dort `php85`).

Zielumgebung: Shared-Linux-Hosting, SSH-Zugang, PHP 8.5.3, Composer 2.9.8, MySQL. Docroot der Index-Domain zeigt auf `backend/public/`. Secrets liegen in `.env.local` außerhalb des Repos.
