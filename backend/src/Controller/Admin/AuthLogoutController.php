<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Service\AdminSession;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class AuthLogoutController
{
    #[Route('/api/admin/auth/logout', methods: ['POST'])]
    public function __invoke(AdminSession $session, TokenStorageInterface $tokenStorage): Response
    {
        // Token-Storage muss VOR der Response geleert werden: Symfonys
        // ContextListener::onKernelResponse() schreibt sonst den noch aktiven
        // (authentifizierten) Token nach unserem session->invalidate() wieder
        // in die (neue) Session zurück, und der Logout wäre wirkungslos.
        $tokenStorage->setToken(null);
        $session->logout();
        return new Response('', 204);
    }
}
