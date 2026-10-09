<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Api\SyncContract;
use App\Exception\SyncProblem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Wartungsmodus für alle /api/v1/sync/-Endpunkte (inkl. Ping).
 *
 * Greift NUR auf /api/v1/sync/ – nicht auf /api/v1/updates, nicht auf
 * /api/account/sync/*, nicht auf die öffentliche Website. OPTIONS-Preflights
 * bleiben 204 (CorsSubscriber, Priorität 256, läuft vorher).
 *
 * Kein DB-Zugriff: die häufigste Ursache für den Wartungsmodus ist eine
 * DB-Migration; der Subscriber darf dabei nicht selbst scheitern.
 *
 * Gesteuert über eine Flag-Datei, deren Pfad aus dem Container-Parameter
 * app.sync_maintenance_file kommt (var/state/sync-maintenance, auf dem
 * Server per Symlink in shared/state/). Inhalt:
 * optional eine ISO-8601-Zeile mit dem voraussichtlichen Ende (until).
 * Leerer oder ungültiger Inhalt = Wartung ohne until. Abgelaufenes until =
 * Wartung bleibt aktiv, aber ohne until in der Antwort (E2).
 */
final class SyncMaintenanceSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly string $syncMaintenanceFile,
    ) {}

    /**
     * Priorität 240: nach CorsSubscriber (256, OPTIONS → 204) und vor
     * AdminCsrfSubscriber (200), vor dem Router (32) und vor den Controllern
     * (Rate-Limiter). So verbraucht eine Wartungsanfrage kein Limit-Token.
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 240]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Nur /api/v1/sync/ – exakter Präfix, kein Regex.
        if (!str_starts_with($request->getPathInfo(), '/api/v1/sync/')) {
            return;
        }

        // OPTIONS-Preflights sind schon von CorsSubscriber beantwortet
        // (stopPropagation), aber defensiv: falls doch einer durchkommt,
        // nicht mit 503 antworten.
        if ($request->getMethod() === 'OPTIONS') {
            return;
        }

        if (!is_file($this->syncMaintenanceFile)) {
            return;
        }

        // Lesefehler kontrolliert behandeln: Datei vorhanden, aber nicht
        // lesbar → Wartung ohne until. is_readable() statt @-Suppressor:
        // Symfonys ErrorHandler konvertiert die E_WARNING von
        // file_get_contents() in eine ErrorException, die das try/catch
        // zwar fangen würde, aber einen unnötigen Stacktrace loggt.
        // Die Vorprüfung vermeidet den Fehlerpfad im Normalfall; das
        // try/catch bleibt als Sicherheitsnetz für Rennbedingungen
        // (Datei zwischen is_readable und file_get_contents gelöscht).
        if (!is_readable($this->syncMaintenanceFile)) {
            $content = false;
        } else {
            try {
                $content = file_get_contents($this->syncMaintenanceFile);
            } catch (\Throwable) {
                $content = false;
            }
        }

        $until = ($content !== false)
            ? SyncContract::parseAtomTimestamp($content)
            : null;

        // E2: Abgelaufenes until beendet die Wartung NICHT. Der Server
        // antwortet weiterhin 503, aber ohne until und ohne Retry-After.
        if ($until !== null && $until <= new \DateTimeImmutable()) {
            $until = null;
        }

        $problem = SyncProblem::maintenance($until);
        $event->setResponse($problem->toApiResponse());
    }
}
