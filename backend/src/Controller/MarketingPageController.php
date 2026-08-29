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
