<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;
use App\Repository\WebAuthnCredentialRepository;
use App\Security\StepUpGuard;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CredentialRemoveController
{
    #[Route('/api/admin/credentials/{id}/remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(int $id, Security $security, WebAuthnCredentialRepository $repo, EntityManagerInterface $em, StepUpGuard $stepUp, AuditLogger $audit): Response
    {
        $stepUp->assertFresh();
        /** @var AdminUser $user */
        $user = $security->getUser();
        $cred = $repo->find($id);
        if ($cred === null || $cred->adminUser->id !== $user->id) {
            throw new ApiProblem(404, 'Credential not found');
        }
        if ($user->credentialCount() <= 2) {
            throw new ApiProblem(409, 'At least two passkeys required', ['backupRequired' => true]);
        }
        $audit->log($user, 'credential.remove', 'credential', (string) $cred->id);
        $em->remove($cred);
        $em->flush();
        return new Response('', 204);
    }
}
