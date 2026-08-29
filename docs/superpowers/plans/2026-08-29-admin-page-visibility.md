# Admin-Seiten-Sichtbarkeit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admin kann einzelne Marketing-Seiten (C2–C5) aktivieren/deaktivieren; deaktivierte Seiten liefern ein echtes HTTP 404 (auch für Suchmaschinen), aktive behalten ihr SEO-Prerendering.

**Architecture:** Neue `PageSetting`-Flags in MySQL, per Admin-API umschaltbar. Öffentliches Flag-API blendet die Nav aus. In Produktion bewacht ein Symfony-`MarketingPageController` die vier Clean-URL-Routen: aktiv ⇒ prerenderte HTML (200), deaktiviert ⇒ echtes 404. Im Dev sorgt ein `dev`-only `load`-Gate für dieselbe 404-Erfahrung.

**Tech Stack:** Symfony 7.4 (PHP 8.5, Doctrine/MySQL, phpunit), SvelteKit/Svelte 5 Runes + TypeScript (adapter-static, Paraglide, Vitest), Lucide.

**Spec:** `docs/superpowers/specs/2026-08-29-admin-page-visibility-design.md`

## Global Constraints

- Prosa/Kommentare/Commits Deutsch, Guillemets »…« (nie „…"/"…"), Halbgeviertstrich – (nie —), echte Umlaute ä/ö/ü/ß (nicht in Code-Bezeichnern). Commit-Message endet mit `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
- **Kein Push.** Merge am Ende lokal `--no-ff`.
- **Schaltbare Seiten (Whitelist, verbatim):** `was-ist-gestura`, `maus-gesten`, `vergleich`, `beispiele`. C1 (`/`) und `/index` NIE schaltbar.
- Lokales PHP-CLI heißt `php` (Deploy: `php85`). Backend-Tests: `php backend/bin/phpunit; echo $?` — Exit-Code prüfen (`failOnDeprecation=true`, »OK, but issues« ⇒ Exit 1).
- **Paraglide-Kompilat `frontend/src/lib/paraglide/messages.js` NIE committen** — nur `messages/de.json`+`en.json`.
- **Nie `vite dev` gleichzeitig mit `npm run check`/`test`.**
- Neue i18n-Keys immer in BEIDE `messages/de.json` UND `messages/en.json`.
- Admin-Mutationen: `AuditLogger::log($actor, <action>, <targetType>, <targetId>)`, in `wrapInTransaction`.
- Frontend-Abschluss-Gate je Task mit Frontend-Anteil: `npm --prefix frontend run check` (0/0) + `npm --prefix frontend run test -- --run` (grün). Backend-Abschluss: `php backend/bin/phpunit; echo $?` == 0.

## Dateistruktur

**Backend (neu):** `src/Entity/PageSetting.php`, `src/Repository/PageSettingRepository.php`, `src/PageVisibility/ToggleablePages.php`, `migrations/Version<ts>.php`, `src/Controller/Api/PageVisibilityController.php`, `src/Controller/Admin/AdminPageListController.php`, `src/Controller/Admin/AdminPageUpdateController.php`, `src/Controller/MarketingPageController.php`, `tests/fixtures/frontend-build/{de,en}/*.html`.
**Backend (geändert):** `config/packages/security.yaml`, `config/services.yaml`.
**Frontend (neu):** `src/routes/(public)/+error.svelte`, `src/routes/admin/pages/+page.svelte`, `src/routes/admin/pages/page.test.ts`.
**Frontend (geändert):** `src/lib/api.ts`, `src/lib/components/Header.svelte`, `src/routes/(public)/{was-ist-gestura,maus-gesten,vergleich,beispiele}/+page.ts`, `src/lib/admin/api.ts`, `src/lib/components/admin/Sidebar.svelte`, `messages/de.json`, `messages/en.json`.
**Docs:** `.claude/lessons.md`, `deploy/README.md`.

---

## Task 1: Backend — PageSetting-Entity, Repository, Whitelist, Migration

**Files:**
- Create: `backend/src/PageVisibility/ToggleablePages.php`, `backend/src/Entity/PageSetting.php`, `backend/src/Repository/PageSettingRepository.php`, `backend/migrations/Version<neuerTimestamp>.php`
- Test: `backend/tests/PageVisibility/PageSettingRepositoryTest.php`

**Interfaces:**
- Produces: `App\PageVisibility\ToggleablePages::KEYS` (`array<string>`), `::isValid(string): bool`. `App\Entity\PageSetting` (public props `?int $id`, `string $pageKey`, `bool $enabled`, `\DateTimeImmutable $updatedAt`, `?string $updatedBy`; Konstruktor `__construct(string $pageKey, bool $enabled = true)`). `PageSettingRepository::findAllIndexed(): array<string,bool>`, `::findByKey(string): ?PageSetting`.

- [ ] **Step 1: Whitelist-Klasse**

`backend/src/PageVisibility/ToggleablePages.php`:
```php
<?php

declare(strict_types=1);

namespace App\PageVisibility;

/**
 * Feste Whitelist der schaltbaren Marketing-Seiten (Slug = SvelteKit-Route
 * ohne Locale-Präfix). C1 (»/«) und der Katalog (»/index«) sind bewusst NICHT
 * enthalten – sie bleiben immer aktiv.
 */
final class ToggleablePages
{
    public const KEYS = ['was-ist-gestura', 'maus-gesten', 'vergleich', 'beispiele'];

    public static function isValid(string $key): bool
    {
        return \in_array($key, self::KEYS, true);
    }
}
```

- [ ] **Step 2: Entity**

`backend/src/Entity/PageSetting.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PageSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Sichtbarkeits-Flag einer schaltbaren Marketing-Seite. pageKey ist der
 * SvelteKit-Slug (z. B. »vergleich«). Fehlt eine Zeile, gilt die Seite als aktiv.
 */
#[ORM\Entity(repositoryClass: PageSettingRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_page_setting_key', columns: ['page_key'])]
class PageSetting
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 64)]
    public string $pageKey;

    #[ORM\Column]
    public bool $enabled = true;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    #[ORM\Column(length: 190, nullable: true)]
    public ?string $updatedBy = null;

    public function __construct(string $pageKey, bool $enabled = true)
    {
        $this->pageKey = $pageKey;
        $this->enabled = $enabled;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
```

- [ ] **Step 3: Repository**

`backend/src/Repository/PageSettingRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PageSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PageSetting>
 */
class PageSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PageSetting::class);
    }

    /** @return array<string,bool> pageKey => enabled */
    public function findAllIndexed(): array
    {
        $out = [];
        foreach ($this->findAll() as $ps) {
            $out[$ps->pageKey] = $ps->enabled;
        }

        return $out;
    }

    public function findByKey(string $key): ?PageSetting
    {
        return $this->findOneBy(['pageKey' => $key]);
    }
}
```

- [ ] **Step 4: Migration schreiben**

Neue Datei `backend/migrations/Version<aktuellerTimestampImFormatDerAnderen>.php` (Format wie bestehende Migrationen; Zeitstempel als aktuelles `YYYYMMDDHHMMSS`). `up()`:
```php
$this->addSql('CREATE TABLE page_setting (id INT AUTO_INCREMENT NOT NULL, page_key VARCHAR(64) NOT NULL, enabled TINYINT(1) NOT NULL, updated_at DATETIME NOT NULL, updated_by VARCHAR(190) DEFAULT NULL, UNIQUE INDEX uniq_page_setting_key (page_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
$now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
foreach (['was-ist-gestura', 'maus-gesten', 'vergleich', 'beispiele'] as $key) {
    $this->addSql('INSERT INTO page_setting (page_key, enabled, updated_at) VALUES (:k, 1, :now)', ['k' => $key, 'now' => $now]);
}
```
`down()`:
```php
$this->addSql('DROP TABLE page_setting');
```
`getDescription()`: `return 'Seiten-Sichtbarkeits-Flags (page_setting) für schaltbare Marketing-Seiten';`.

- [ ] **Step 5: Migration auf der Test-DB ausführen**

```bash
php backend/bin/console doctrine:migrations:migrate --no-interaction --env=test
```
Erwartet: Migration läuft durch (Tabelle + 4 Seed-Zeilen). Falls das Test-Schema anders erzeugt wird (z. B. via `doctrine:schema:create`), prüfe, wie die vorhandene Testsuite ihr Schema aufbaut, und richte dich danach (die Tabelle muss für die Tests existieren).

- [ ] **Step 6: Repository-Test**

`backend/tests/PageVisibility/PageSettingRepositoryTest.php` (KernelTestCase, EntityManager holen). Prüft: `findAllIndexed()` liefert die 4 Seeds als `true`; nach `enabled=false` auf `vergleich` spiegelt `findAllIndexed()['vergleich'] === false`; `findByKey('nope')` === null. Nutze das vorhandene KernelTest-Muster der Suite (z. B. ein bestehender Repository-/Service-Test als Vorlage; dama-Transaktions-Rollback greift).

- [ ] **Step 7: Test laufen lassen + committen**

```bash
php backend/bin/phpunit --filter PageSettingRepositoryTest; echo $?
git add backend/src/PageVisibility backend/src/Entity/PageSetting.php backend/src/Repository/PageSettingRepository.php backend/migrations backend/tests/PageVisibility
git commit  # »Ergänze PageSetting-Entity und Whitelist für Seiten-Sichtbarkeit«
```
Erwartet: Exit 0.

---

## Task 2: Backend — Öffentliches Flag-API + Admin GET/PATCH

**Files:**
- Create: `backend/src/Controller/Api/PageVisibilityController.php`, `backend/src/Controller/Admin/AdminPageListController.php`, `backend/src/Controller/Admin/AdminPageUpdateController.php`
- Modify: `backend/config/packages/security.yaml`
- Test: `backend/tests/Controller/PageVisibilityControllerTest.php`, `backend/tests/Controller/AdminPageControllerTest.php`

**Interfaces:**
- Consumes: `ToggleablePages::KEYS`/`::isValid`, `PageSettingRepository::findByKey`, `AuditLogger::log`, `App\Exception\ApiProblem`, `App\Entity\AdminUser` (public `->email`).
- Produces: `GET /api/v1/pages` → JSON `{slug:bool}` (alle 4 Keys). `GET /api/admin/pages` → JSON-Array `[{pageKey,enabled,updatedAt,updatedBy}]`. `PATCH /api/admin/pages/{key}` Body `{enabled:bool}` → 204.

- [ ] **Step 1: Öffentlicher Controller**

`backend/src/Controller/Api/PageVisibilityController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\PageVisibility\ToggleablePages;
use App\Repository\PageSettingRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Öffentliche, cookielose Sichtbarkeits-Map der schaltbaren Marketing-Seiten.
 * Liefert IMMER alle Whitelist-Keys (fehlende Zeile ⇒ aktiv). Kurz gecacht,
 * damit Admin-Toggles zeitnah greifen.
 */
final class PageVisibilityController
{
    #[Route('/api/v1/pages', methods: ['GET'])]
    public function __invoke(PageSettingRepository $repo): JsonResponse
    {
        $stored = $repo->findAllIndexed();
        $out = [];
        foreach (ToggleablePages::KEYS as $key) {
            $out[$key] = $stored[$key] ?? true;
        }

        $response = new JsonResponse($out);
        $response->setEtag(sha1((string) $response->getContent()));
        $response->setPublic();
        $response->setMaxAge(60);

        return $response;
    }
}
```

- [ ] **Step 2: Admin-List-Controller**

`backend/src/Controller/Admin/AdminPageListController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\PageVisibility\ToggleablePages;
use App\Repository\PageSettingRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liste aller schaltbaren Seiten mit ihrem aktuellen Sichtbarkeits-Status (für
 * die Admin-UI). Liefert alle Whitelist-Keys, auch ohne DB-Zeile (dann aktiv).
 */
final class AdminPageListController
{
    #[Route('/api/admin/pages', methods: ['GET'])]
    public function __invoke(PageSettingRepository $repo): JsonResponse
    {
        $byKey = [];
        foreach ($repo->findAll() as $ps) {
            $byKey[$ps->pageKey] = $ps;
        }

        $out = [];
        foreach (ToggleablePages::KEYS as $key) {
            $ps = $byKey[$key] ?? null;
            $out[] = [
                'pageKey' => $key,
                'enabled' => $ps?->enabled ?? true,
                'updatedAt' => $ps?->updatedAt->format(\DATE_ATOM),
                'updatedBy' => $ps?->updatedBy,
            ];
        }

        return new JsonResponse($out);
    }
}
```

- [ ] **Step 3: Admin-Update-Controller**

`backend/src/Controller/Admin/AdminPageUpdateController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Entity\PageSetting;
use App\Exception\ApiProblem;
use App\PageVisibility\ToggleablePages;
use App\Repository\PageSettingRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Schaltet eine Marketing-Seite an/aus. Nur Whitelist-Keys; unbekannter Key ⇒ 404.
 * Nicht destruktiv (kein Step-up/Backup-Gate), aber ROLE_ADMIN (security.yaml)
 * und CSRF-Header (AdminCsrfSubscriber) gelten. Upsert der Flag-Zeile + Audit.
 */
final class AdminPageUpdateController
{
    #[Route('/api/admin/pages/{key}', methods: ['PATCH'])]
    public function __invoke(
        string $key,
        Request $request,
        PageSettingRepository $repo,
        EntityManagerInterface $em,
        AuditLogger $audit,
        Security $security,
    ): Response {
        if (!ToggleablePages::isValid($key)) {
            throw new ApiProblem(404, 'Unknown page');
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !\array_key_exists('enabled', $data) || !\is_bool($data['enabled'])) {
            throw new ApiProblem(400, 'Field "enabled" (bool) is required');
        }
        $enabled = $data['enabled'];

        /** @var AdminUser $actor */
        $actor = $security->getUser();

        $em->wrapInTransaction(function () use ($repo, $em, $key, $enabled, $audit, $actor): void {
            $ps = $repo->findByKey($key);
            if (null === $ps) {
                $ps = new PageSetting($key, $enabled);
                $em->persist($ps);
            } else {
                $ps->enabled = $enabled;
            }
            $ps->updatedAt = new \DateTimeImmutable();
            $ps->updatedBy = $actor->email;

            $audit->log($actor, $enabled ? 'page.enable' : 'page.disable', 'page_setting', $key);
        });

        return new Response('', 204);
    }
}
```

- [ ] **Step 4: security.yaml — ROLE_ADMIN für `/api/admin/pages`**

In `backend/config/packages/security.yaml`, im `access_control`-Block **vor** der generischen Zeile `- { path: ^/api/admin, roles: ROLE_MODERATOR }` einfügen (Reihenfolge = first-match):
```yaml
        - { path: ^/api/admin/pages, roles: ROLE_ADMIN }
```
(Am besten direkt neben die anderen ROLE_ADMIN-Zeilen `^/api/admin/users` / `^/api/admin/audit`.)

- [ ] **Step 5: Test — öffentliches API**

`backend/tests/Controller/PageVisibilityControllerTest.php` (funktional, Vorlage: ein bestehender `Controller/Api/*Test` der Suite). Prüft: `GET /api/v1/pages` liefert 200 + JSON mit allen 4 Keys=`true`; nach `PageSetting('vergleich', false)` persistiert liefert es `vergleich=false`, übrige `true`. Kein Cookie/Auth nötig.

- [ ] **Step 6: Test — Admin GET/PATCH**

`backend/tests/Controller/AdminPageControllerTest.php`. Modelliere die Admin-Auth **exakt nach einem bestehenden Admin-Controller-Test, der ROLE_ADMIN + Session verlangt** (z. B. der Users- oder Audit-Controller-Test — grep `tests/` nach `/api/admin/users` bzw. `/api/admin/audit`; nutze deren Session-Seeding-Helper und den `X-Requested-With`-Header). Prüfe:
  - `GET /api/admin/pages` (als ROLE_ADMIN) → 200, 4 Einträge.
  - `PATCH /api/admin/pages/vergleich` `{enabled:false}` (ROLE_ADMIN + `X-Requested-With`) → 204; danach ist die Flag-Zeile `false` und ein `AuditLogEntry` mit Action `page.disable` existiert.
  - `PATCH /api/admin/pages/nope` → 404.
  - `PATCH` OHNE `X-Requested-With` → 403 (CSRF) — nur falls das vorhandene Test-Muster das mitprüft; sonst weglassen.

- [ ] **Step 7: Tests laufen lassen + committen**

```bash
php backend/bin/phpunit --filter 'PageVisibilityControllerTest|AdminPageControllerTest'; echo $?
git add backend/src/Controller backend/config/packages/security.yaml backend/tests/Controller
git commit  # »Ergänze Flag-API (öffentlich) und Admin-Umschalter für Seiten«
```
Erwartet: Exit 0.

---

## Task 3: Backend — MarketingPageController (echtes 404) + Config + .htaccess + Docs

**Files:**
- Create: `backend/src/Controller/MarketingPageController.php`, `backend/tests/Controller/MarketingPageControllerTest.php`, `backend/tests/fixtures/frontend-build/de/vergleich.html`, `backend/tests/fixtures/frontend-build/en/vergleich.html`
- Modify: `backend/config/services.yaml`, `backend/public/.htaccess`, `.claude/lessons.md`, `deploy/README.md`

**Interfaces:**
- Consumes: `PageSettingRepository::findByKey`, `ToggleablePages`.
- Produces: `GET /{locale}/{slug}` (locale `de|en`, slug = Whitelist) → aktiv+Datei ⇒ 200 text/html; deaktiviert oder Datei fehlt ⇒ 404 text/html (`X-Robots-Tag: noindex`).

- [ ] **Step 1: Config-Parameter für den Build-Pfad**

In `backend/config/services.yaml` unter `parameters:` ergänzen:
```yaml
    app.frontend_build_dir: '%kernel.project_dir%/../frontend/build'
```
Und für Tests einen `when@test`-Override auf das Fixture-Verzeichnis (im selben File, `when@test:`-Block, oder in `config/services_test.yaml`, je nachdem was das Projekt nutzt — grep nach `when@test` in `config/`):
```yaml
when@test:
    parameters:
        app.frontend_build_dir: '%kernel.project_dir%/tests/fixtures/frontend-build'
```

- [ ] **Step 2: Fixture-HTML anlegen**

`backend/tests/fixtures/frontend-build/de/vergleich.html`:
```html
<!doctype html><html lang="de"><head><title>Vergleich Fixture</title></head><body><main>FIXTURE_VERGLEICH_DE</main></body></html>
```
`backend/tests/fixtures/frontend-build/en/vergleich.html`:
```html
<!doctype html><html lang="en"><head><title>Comparison Fixture</title></head><body><main>FIXTURE_VERGLEICH_EN</main></body></html>
```

- [ ] **Step 3: Controller**

`backend/src/Controller/MarketingPageController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PageSettingRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Produktions-Wächter der schaltbaren Marketing-Seiten. Da die Docroot der
 * Index-Domain auf backend/public zeigt und die statischen Dateien
 * »<slug>.html« (nicht »<slug>«) heißen, fallen die Clean-URLs /de/<slug> an
 * Symfony. Aktiv ⇒ prerenderte HTML ausliefern (200, volle SEO-Wirkung);
 * deaktiviert ⇒ echtes 404. So sieht auch Google ein 404 statt eines Soft-404.
 * Im Dev rendert Vite die Seiten; dort greift stattdessen das dev-only
 * load-Gate im Frontend.
 */
final class MarketingPageController
{
    private const NOT_FOUND_HTML = '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>404 – Nicht gefunden</title><meta name="robots" content="noindex"><style>body{font-family:"Segoe UI",system-ui,sans-serif;background:#121216;color:#ececf1;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}main{text-align:center}a{color:#5b9cf6}</style></head><body><main><h1>404</h1><p>Diese Seite ist nicht verfügbar.</p><p><a href="/">Zur Startseite</a></p></main></body></html>';

    public function __construct(
        #[Autowire('%app.frontend_build_dir%')] private readonly string $buildDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        '/{locale}/{slug}',
        methods: ['GET'],
        requirements: ['locale' => 'de|en', 'slug' => 'was-ist-gestura|maus-gesten|vergleich|beispiele'],
        priority: -10,
    )]
    public function __invoke(string $locale, string $slug, PageSettingRepository $repo): Response
    {
        $enabled = $repo->findByKey($slug)?->enabled ?? true;
        if (!$enabled) {
            return $this->notFound();
        }

        $file = rtrim($this->buildDir, '/')."/{$locale}/{$slug}.html";
        if (!is_file($file)) {
            $this->logger->warning('Marketing-Build-Datei fehlt', ['file' => $file]);

            return $this->notFound();
        }

        return new Response((string) file_get_contents($file), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'public, max-age=60',
        ]);
    }

    private function notFound(): Response
    {
        return new Response(self::NOT_FOUND_HTML, 404, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
```
> `priority: -10` sorgt dafür, dass diese generisch aussehende 2-Segment-Route keine spezifischeren Routen verdeckt.

- [ ] **Step 4: Test**

`backend/tests/Controller/MarketingPageControllerTest.php` (funktional, Vorlage bestehender Api-Test). Prüft (Flags über persistierte `PageSetting` steuern):
  - `vergleich` aktiv ⇒ `GET /de/vergleich` → 200, Body enthält `FIXTURE_VERGLEICH_DE`; `GET /en/vergleich` → 200, `FIXTURE_VERGLEICH_EN`.
  - `vergleich` deaktiviert (`PageSetting('vergleich', false)`) ⇒ `GET /de/vergleich` → 404, `X-Robots-Tag: noindex`.
  - `GET /de/maus-gesten` (aktiv, aber KEINE Fixture-Datei) ⇒ 404 (kein 500).

- [ ] **Step 5: .htaccess-Regel + Lessons**

In `backend/public/.htaccess`, INNERHALB des `<IfModule mod_rewrite.c>`-Blocks, **vor** der Regel `RewriteCond %{REQUEST_FILENAME} -f` / `RewriteRule ^ - [L]`, einfügen:
```apache
    # Schaltbare Marketing-Seiten (Sub: Admin-Seiten-Sichtbarkeit) IMMER an
    # Symfony leiten – der MarketingPageController liefert 200 (aktiv) oder
    # echtes 404 (deaktiviert). Ohne diese Regel könnte ein Clean-URL-Rewrite
    # die statische HTML direkt ausliefern und das 404 umgehen.
    RewriteRule ^(de|en)/(was-ist-gestura|maus-gesten|vergleich|beispiele)/?$ index.php [L]
```
In `.claude/lessons.md` einen Eintrag ergänzen: `backend/public/.htaccess` ist recipe-managed; die Marketing-Interception-Regel nach einem `symfony/framework-bundle`-Recipe-Update erneut einfügen (Zweck: echtes 404 für deaktivierte Seiten).

- [ ] **Step 6: Deploy-Doku**

In `deploy/README.md` einen Abschnitt »Schaltbare Seiten« ergänzen: Der Frontend-Build wird ins Web-Root der Index-Domain geladen; der `MarketingPageController` liest die HTML aus `app.frontend_build_dir` (Prod: auf den deployten Build-Pfad setzen, z. B. via `.env.local`-Parameter oder services-Override). Die `.htaccess`-Interception-Regel muss vorhanden sein.

- [ ] **Step 7: Tests + committen**

```bash
php backend/bin/phpunit --filter MarketingPageControllerTest; echo $?
git add backend/src/Controller/MarketingPageController.php backend/config/services.yaml backend/public/.htaccess backend/tests/Controller/MarketingPageControllerTest.php backend/tests/fixtures .claude/lessons.md deploy/README.md
git commit  # »Bewache schaltbare Seiten in Symfony (echtes 404 wenn deaktiviert)«
```
Erwartet: Exit 0.

---

## Task 4: Frontend — öffentlicher API-Wrapper + Header-Nav-Ausblendung

**Files:**
- Modify: `frontend/src/lib/api.ts`, `frontend/src/lib/components/Header.svelte`
- Test: `frontend/src/lib/components/Header.test.ts` (erweitern)

**Interfaces:**
- Produces: `getPageVisibility(opts?): Promise<Record<string,boolean>>` in `$lib/api`.
- Consumes: `GET /api/v1/pages`.

- [ ] **Step 1: API-Wrapper**

In `frontend/src/lib/api.ts` (nach dem Muster der vorhandenen Fetch-Funktionen wie `listEntries` — dieselbe Basis-URL-Auflösung/`ClientOpts` verwenden) ergänzen:
```ts
export type PageVisibility = Record<string, boolean>;

/** Öffentliche Sichtbarkeits-Map der schaltbaren Marketing-Seiten. */
export async function getPageVisibility(opts: ClientOpts = {}): Promise<PageVisibility> {
	const res = await fetch(`${apiBase(opts)}/api/v1/pages`, { signal: opts.signal });
	if (!res.ok) {
		throw new Error(`GET /api/v1/pages -> ${res.status}`);
	}
	return (await res.json()) as PageVisibility;
}
```
> Verifiziere den echten Namen der Basis-URL-Helper-Funktion/Konstante in `api.ts` (im Plan `apiBase(opts)` genannt) und passe ihn an — nutze exakt das, was `listEntries` verwendet. Erfinde keine neue Basis-Logik.

- [ ] **Step 2: Header-Test erweitern (rot)**

In `frontend/src/lib/components/Header.test.ts` einen Test ergänzen: bei gemocktem `getPageVisibility` (`vi.mock('$lib/api', ...)`) mit `{ vergleich: false, ... }` erscheint nach dem Mount **kein** Nav-Link mit `href` auf `/vergleich`, aber die übrigen fünf schon. (Mount + `await tick()`/`findBy`, da die Ausblendung nach dem `onMount`-Fetch greift.)

- [ ] **Step 3: Header umbauen**

In `frontend/src/lib/components/Header.svelte`:
```svelte
	import { onMount } from 'svelte';
	import { getPageVisibility } from '$lib/api';

	// Slug je schaltbarer Nav-Route (Route-ID → Slug).
	const SLUG_BY_ID: Record<string, string> = {
		'/(public)/was-ist-gestura': 'was-ist-gestura',
		'/(public)/maus-gesten': 'maus-gesten',
		'/(public)/vergleich': 'vergleich',
		'/(public)/beispiele': 'beispiele'
	};

	let visibility = $state<Record<string, boolean>>({});
	onMount(async () => {
		try {
			visibility = await getPageVisibility();
		} catch {
			/* fail-open: bei Fehler alle Links zeigen; das echte Gating macht der Server */
		}
	});

	const visibleNav = $derived(
		nav.filter((item) => {
			const slug = SLUG_BY_ID[item.id];
			return slug === undefined || visibility[slug] !== false;
		})
	);
```
Und im Markup `{#each nav as item ...}` durch `{#each visibleNav as item ...}` ersetzen. Der bestehende Aktiv-/Accent-Zustand bleibt unverändert.

- [ ] **Step 4: Prüfen + committen**

```bash
npm --prefix frontend run check
npm --prefix frontend run test -- --run src/lib/components/Header.test.ts
git add frontend/src/lib/api.ts frontend/src/lib/components/Header.svelte frontend/src/lib/components/Header.test.ts
git commit  # »Blende deaktivierte Seiten aus der Marketing-Nav aus«
```
Erwartet: check 0/0, Tests grün.

---

## Task 5: Frontend — Dev-only 404-Gate auf C2–C5 + Fehlerseite

**Files:**
- Modify: `frontend/src/routes/(public)/was-ist-gestura/+page.ts`, `.../maus-gesten/+page.ts`, `.../vergleich/+page.ts`, `.../beispiele/+page.ts`
- Create: `frontend/src/routes/(public)/+error.svelte`
- Test: `frontend/src/routes/(public)/vergleich/page.test.ts` (erweitern oder neu `vergleich/load.test.ts`)

**Interfaces:**
- Consumes: `getPageVisibility` (Task 4), `dev` aus `$app/environment`, `error` aus `@sveltejs/kit`.

- [ ] **Step 1: `+page.ts` je Seite um dev-Gate erweitern**

Für jede der vier Seiten das `+page.ts` erweitern (Beispiel `vergleich`, Slug entsprechend anpassen):
```ts
import { dev } from '$app/environment';
import { error } from '@sveltejs/kit';
import { getPageVisibility } from '$lib/api';

// C4 »Gestura im Vergleich«: statisch prerendern (SEO), wie C1–C3.
export const prerender = true;
export const ssr = true;

// Nur im Dev (Vite rendert die Seite) prüfen wir das Flag und werfen ein echtes
// 404. Im Prod-Build ist dev=false ⇒ dieser Code tut nichts, die Seite wird
// normal prerendered und Symfony (MarketingPageController) übernimmt das Gating.
export const load = async () => {
	if (!dev) {
		return;
	}
	let vis: Record<string, boolean>;
	try {
		vis = await getPageVisibility();
	} catch {
		return; // fail-open im Dev
	}
	if (vis['vergleich'] === false) {
		error(404, 'Seite deaktiviert');
	}
};
```
Wichtig: Der `error(404)`-Aufruf steht AUSSERHALB des try/catch (sonst würde der geworfene SvelteKit-Fehler verschluckt). Slug je Datei: `was-ist-gestura`, `maus-gesten`, `vergleich`, `beispiele`.

- [ ] **Step 2: Fehlerseite**

`frontend/src/routes/(public)/+error.svelte`:
```svelte
<script lang="ts">
	import { page } from '$app/state';
	import { localizeHref } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
</script>

<svelte:head><title>{page.status} · Gestura</title><meta name="robots" content="noindex" /></svelte:head>

<section class="error-page">
	<h1>{page.status}</h1>
	<p>{page.error?.message ?? m.error_not_found()}</p>
	<a class="btn btn-primary" href={localizeHref('/')}>{m.error_home()}</a>
</section>

<style>
	.error-page {
		text-align: center;
		padding: 80px 0;
	}
	.error-page h1 {
		font-size: 48px;
		margin: 0 0 8px;
	}
	.error-page p {
		color: var(--text-secondary);
		margin: 0 0 20px;
	}
</style>
```
i18n-Keys `error_not_found` (de »Diese Seite ist nicht verfügbar.« / en »This page is not available.«) und `error_home` (de »Zur Startseite« / en »Back to home«) in BEIDE JSON.

- [ ] **Step 3: Test (dev-Gate)**

`frontend/src/routes/(public)/vergleich/page.test.ts` erweitern (oder neue `load.test.ts`): mit `vi.mock('$app/environment', () => ({ dev: true, ... }))` und gemocktem `getPageVisibility`:
  - `vis.vergleich === false` ⇒ `load()` wirft (SvelteKit-`error` mit `status: 404`). Prüfe via `await expect(load(...)).rejects.toMatchObject({ status: 404 })`.
  - `vis.vergleich === true` ⇒ `load()` resolved ohne Fehler.
> Falls `$app/environment` schwer zu mocken ist, teste die Gate-Logik alternativ, indem du eine kleine reine Hilfsfunktion extrahierst (`assertPageEnabled(vis, 'vergleich')`), die den `error(404)` wirft, und diese testest; die `+page.ts` ruft sie im `if (dev)`-Zweig auf. Erfinde keinen Test, der nichts prüft.

- [ ] **Step 4: Prüfen + committen**

```bash
npm --prefix frontend run check
npm --prefix frontend run test -- --run
git add "frontend/src/routes/(public)"
git commit  # »Ergänze Dev-404-Gate und Fehlerseite für schaltbare Seiten«
```
Erwartet: check 0/0, Tests grün.

---

## Task 6: Frontend — Admin-Sektion »Seiten«

**Files:**
- Create: `frontend/src/routes/admin/pages/+page.svelte`, `frontend/src/routes/admin/pages/page.test.ts`
- Modify: `frontend/src/lib/admin/api.ts`, `frontend/src/lib/components/admin/Sidebar.svelte`, `frontend/messages/de.json`, `frontend/messages/en.json`

**Interfaces:**
- Consumes: `adminFetch` (aus `$lib/admin/api`).
- Produces: `pages()`, `setPageEnabled(key, enabled)` in `$lib/admin/api`; Route `/admin/pages`.

- [ ] **Step 1: Admin-API-Wrapper**

In `frontend/src/lib/admin/api.ts` ergänzen (Muster wie `reports`/`resolveReport`):
```ts
export interface AdminPageSetting {
	pageKey: string;
	enabled: boolean;
	updatedAt: string | null;
	updatedBy: string | null;
}

export const pages = (o?: AdminClientOpts) => adminFetch<AdminPageSetting[]>('/api/admin/pages', {}, o);

export const setPageEnabled = (key: string, enabled: boolean, o?: AdminClientOpts) =>
	adminFetch<void>(`/api/admin/pages/${key}`, { method: 'PATCH', body: JSON.stringify({ enabled }) }, o);
```

- [ ] **Step 2: i18n-Keys**

In BEIDE `messages/*.json`:
```jsonc
// de.json
"admin_nav_pages": "Seiten",
"admin_pages_title": "Seiten-Sichtbarkeit",
"admin_pages_hint": "Deaktivierte Seiten liefern ein echtes 404 – auch für Suchmaschinen.",
"admin_pages_load_error": "Seiten konnten nicht geladen werden.",
"admin_pages_active": "Aktiv",
"admin_pages_inactive": "Deaktiviert",
"admin_page_was_ist_gestura": "Was ist Gestura",
"admin_page_maus_gesten": "Maus-Gesten",
"admin_page_vergleich": "Vergleich",
"admin_page_beispiele": "Beispiele"
```
```jsonc
// en.json
"admin_nav_pages": "Pages",
"admin_pages_title": "Page visibility",
"admin_pages_hint": "Disabled pages return a real 404 — for search engines too.",
"admin_pages_load_error": "Failed to load pages.",
"admin_pages_active": "Active",
"admin_pages_inactive": "Disabled",
"admin_page_was_ist_gestura": "What is Gestura",
"admin_page_maus_gesten": "Mouse gestures",
"admin_page_vergleich": "Comparison",
"admin_page_beispiele": "Examples"
```

- [ ] **Step 3: Sidebar-Eintrag**

In `frontend/src/lib/components/admin/Sidebar.svelte`: Import um ein Icon ergänzen (z. B. `FileText` aus `@lucide/svelte` — verifiziere, dass der Name existiert; sonst `Files`/`LayoutTemplate`) und `navItems` ergänzen:
```ts
		{ href: '/admin/pages', label: m.admin_nav_pages, Icon: FileText, adminOnly: true },
```
(vor `account` einsortieren).

- [ ] **Step 4: Admin-Seite**

`frontend/src/routes/admin/pages/+page.svelte` (Muster wie `admin/reports/+page.svelte`: `onMount`-Load, `$state`, Toggle → PATCH → Refetch):
```svelte
<script lang="ts">
	import { onMount } from 'svelte';
	import { pages, setPageEnabled, type AdminPageSetting } from '$lib/admin/api';
	import { m } from '$lib/paraglide/messages.js';

	let items = $state<AdminPageSetting[]>([]);
	let loading = $state(true);
	let loadError = $state(false);
	let busy = $state<string | null>(null);

	const DISPLAY: Record<string, () => string> = {
		'was-ist-gestura': () => m.admin_page_was_ist_gestura(),
		'maus-gesten': () => m.admin_page_maus_gesten(),
		vergleich: () => m.admin_page_vergleich(),
		beispiele: () => m.admin_page_beispiele()
	};

	async function reload() {
		loading = true;
		loadError = false;
		try {
			items = await pages();
		} catch {
			loadError = true;
		} finally {
			loading = false;
		}
	}

	async function toggle(item: AdminPageSetting) {
		busy = item.pageKey;
		try {
			await setPageEnabled(item.pageKey, !item.enabled);
			await reload();
		} catch {
			loadError = true;
		} finally {
			busy = null;
		}
	}

	onMount(reload);
</script>

<h1>{m.admin_pages_title()}</h1>
<p class="hint">{m.admin_pages_hint()}</p>

{#if loading}
	<p>…</p>
{:else if loadError}
	<p class="error">{m.admin_pages_load_error()}</p>
{:else}
	<ul class="page-list">
		{#each items as item (item.pageKey)}
			<li class="card">
				<span class="name">{DISPLAY[item.pageKey]?.() ?? item.pageKey}</span>
				<button
					class="btn"
					class:btn-primary={item.enabled}
					disabled={busy === item.pageKey}
					onclick={() => toggle(item)}
				>
					{item.enabled ? m.admin_pages_active() : m.admin_pages_inactive()}
				</button>
			</li>
		{/each}
	</ul>
{/if}

<style>
	.hint { color: var(--text-secondary); }
	.page-list { list-style: none; padding: 0; display: flex; flex-direction: column; gap: 10px; }
	.page-list li { display: flex; align-items: center; justify-content: space-between; }
	.error { color: var(--danger-color); }
</style>
```

- [ ] **Step 5: Test**

`frontend/src/routes/admin/pages/page.test.ts` (Muster wie `admin/reports`-Test): `vi.mock('$lib/admin/api')` mit `pages` → 4 Einträge (`vergleich` disabled), Render zeigt vier Zeilen; Klick auf den `vergleich`-Button ruft `setPageEnabled('vergleich', true)` und danach erneut `pages()` (Refetch). Prüfe die Aufrufe via `vi.mocked(...)`.

- [ ] **Step 6: Prüfen + committen**

```bash
npm --prefix frontend run check
npm --prefix frontend run test -- --run
git add "frontend/src/routes/admin/pages" frontend/src/lib/admin/api.ts frontend/src/lib/components/admin/Sidebar.svelte frontend/messages/de.json frontend/messages/en.json
git commit  # »Ergänze Admin-Sektion ›Seiten‹ zum Umschalten der Sichtbarkeit«
```
Erwartet: check 0/0, Tests grün.

---

## Self-Review (gegen die Spec)

**Spec-Abdeckung:** §3.1 Entity/Repo/Migration → T1. §3.2 öffentliches API → T2. §3.3 Admin GET/PATCH + security.yaml + Audit → T2. §3.4 MarketingPageController → T3. §3.5 .htaccess → T3. §4.1 Nav → T4. §4.2 Dev-Gate → T5. §4.3 Fehlerseite → T5. §5 Admin-UI → T6. §6 Deploy-Doku → T3. §7 Tests → je Task. Keine Lücke.

**Platzhalter-Scan:** Keine »TODO/TBD«. Zwei bewusst als »Implementer verifiziert« markierte Punkte (Basis-URL-Helper-Name in `api.ts`; `$app/environment`-Mock-Strategie / Extraktion einer Hilfsfunktion) mit klarer Anweisung, nichts zu erfinden.

**Typ-Konsistenz:** `PageSetting`-Props, `findByKey`/`findAllIndexed`, `ToggleablePages::KEYS/isValid`, `getPageVisibility(): Record<string,boolean>`, `pages()/setPageEnabled` — durchgängig gleich benannt und zwischen Tasks konsistent verwendet. Slug-Whitelist überall identisch (`was-ist-gestura|maus-gesten|vergleich|beispiele`).

## Offene Verifikationspunkte für die Ausführung

1. Wie die Testsuite ihr DB-Schema aufbaut (Migration vs. schema:create) — T1 Step 5.
2. Vorlage-Admin-Test mit ROLE_ADMIN-Session-Seeding — T2 Step 6 (grep in `backend/tests`).
3. `when@test`-Parameter-Override-Mechanismus des Projekts — T3 Step 1.
4. Basis-URL-Helper in `frontend/src/lib/api.ts` — T4 Step 1.
5. Existenz des Lucide-Icons `FileText` — T6 Step 3.
