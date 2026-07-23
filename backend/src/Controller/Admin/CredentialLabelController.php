<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;
use App\Repository\WebAuthnCredentialRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CredentialLabelController
{
    #[Route('/api/admin/credentials/{id}', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function __invoke(int $id, Request $request, Security $security, WebAuthnCredentialRepository $repo, EntityManagerInterface $em, AuditLogger $audit): JsonResponse
    {
        /** @var AdminUser $user */
        $user = $security->getUser();
        $cred = $repo->find($id);
        if ($cred === null || $cred->adminUser->id !== $user->id) {
            throw new ApiProblem(404, 'Credential not found');
        }
        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'Invalid JSON body');
        }
        $label = $body['label'] ?? null;
        if (!is_string($label) || $label === '') {
            throw new ApiProblem(400, 'label is required');
        }

        // Umbenennung + Audit atomar: die zustandsändernde Aktion wird wie die
        // übrigen Passkey-Operationen im Audit-Log festgehalten.
        $em->wrapInTransaction(function () use ($cred, $label, $audit, $user): void {
            $cred->label = mb_substr($label, 0, 64);
            $audit->log($user, 'credential.label', 'credential', (string) $cred->id);
        });

        return new JsonResponse(['id' => $cred->id, 'label' => $cred->label]);
    }
}
