<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;
use App\Security\StepUpGuard;
use App\Service\AuditLogger;
use App\Service\WebAuthn\WebAuthnCeremony;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CredentialAddController
{
    #[Route('/api/admin/credentials', methods: ['POST'])]
    public function __invoke(Request $request, Security $security, WebAuthnCeremony $ceremony, StepUpGuard $stepUp, AuditLogger $audit, EntityManagerInterface $em): JsonResponse
    {
        $stepUp->assertFresh();

        /** @var AdminUser $user */
        $user = $security->getUser();
        try {
            $body = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'Invalid JSON body');
        }
        $label = is_string($body['label'] ?? null) && $body['label'] !== '' ? $body['label'] : 'Passkey';

        // Neues Credential + Audit atomar: der Audit-Eintrag entsteht nie ohne
        // das zugehörige Credential (und umgekehrt).
        $cred = $em->wrapInTransaction(function () use ($ceremony, $user, $request, $label, $audit) {
            $c = $ceremony->verifyRegistration($user, $request->getContent(), mb_substr($label, 0, 64));
            $audit->log($user, 'credential.add', 'credential', (string) $c->id);
            return $c;
        });

        return new JsonResponse(['id' => $cred->id, 'label' => $cred->label], 201);
    }
}
