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
