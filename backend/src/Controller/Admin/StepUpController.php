<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;
use App\Service\AdminSession;
use App\Service\WebAuthn\WebAuthnCeremony;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StepUpController
{
    #[Route('/api/admin/stepup', methods: ['POST'])]
    public function __invoke(Request $request, Security $security, WebAuthnCeremony $ceremony, AdminSession $session): Response
    {
        /** @var AdminUser $current */
        $current = $security->getUser();
        $verified = $ceremony->verifyAssertion($request->getContent());
        if ($verified->id !== $current->id) {
            throw new ApiProblem(403, 'Step-up credential does not match current user');
        }
        $session->markVerified();
        return new Response('', 204);
    }
}
