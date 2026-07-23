<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Security\StepUpGuard;
use App\Service\WebAuthn\WebAuthnCeremony;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class CredentialAddOptionsController
{
    #[Route('/api/admin/credentials/options', methods: ['POST'])]
    public function __invoke(Security $security, WebAuthnCeremony $ceremony, StepUpGuard $stepUp): JsonResponse
    {
        // Step-up bereits hier verlangen (nicht erst beim Absenden), damit der
        // Client eine frische Bestätigung einholt, BEVOR die u. U. langsame
        // Attestation-Zeremonie (z. B. QR-/Hybrid-Flow aufs Handy) startet. Sonst
        // liefe das 5-Minuten-Fenster (StepUpGuard::MAX_AGE) während des QR-Tanzes
        // ab und der Passkey würde erst beim POST verworfen.
        $stepUp->assertFresh();

        /** @var AdminUser $user */
        $user = $security->getUser();
        return JsonResponse::fromJsonString($ceremony->creationOptionsJson($user));
    }
}
