<?php
declare(strict_types=1);
namespace App\Service\WebAuthn;

use App\Entity\AdminUser;
use App\Entity\WebAuthnCredential;
use App\Exception\ApiProblem;
use App\Repository\WebAuthnCredentialRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deterministischer WebAuthn-Fake für die Test-Umgebung (kein echter
 * Authenticator, keine Krypto). Options-Methoden liefern konstantes JSON;
 * Registrierung/Assertion arbeiten allein über die im Client-JSON
 * übergebene Pseudo-Credential-ID (Feld `id`).
 */
final class FakeWebAuthnCeremony implements WebAuthnCeremony
{
    public function __construct(
        private readonly WebAuthnCredentialRepository $credentials,
        private readonly EntityManagerInterface $em,
    ) {}

    public function creationOptionsJson(AdminUser $user): string
    {
        return json_encode(['challenge' => 'fake-reg', 'rp' => ['id' => 'gestura.eu']], JSON_THROW_ON_ERROR);
    }

    public function verifyRegistration(AdminUser $user, string $clientJson, string $label): WebAuthnCredential
    {
        $data = json_decode($clientJson, true, 8, JSON_THROW_ON_ERROR);
        $credId = $data['id'] ?? throw new ApiProblem(400, 'Fake: missing id');
        $cred = new WebAuthnCredential($user, $credId, $clientJson, $label);
        $this->em->persist($cred);
        $this->em->flush();

        return $cred;
    }

    public function requestOptionsJson(?AdminUser $user): string
    {
        return json_encode(['challenge' => 'fake-assert'], JSON_THROW_ON_ERROR);
    }

    public function verifyAssertion(string $clientJson): AdminUser
    {
        $data = json_decode($clientJson, true, 8, JSON_THROW_ON_ERROR);
        $credId = $data['id'] ?? throw new ApiProblem(400, 'Fake: missing id');
        $cred = $this->credentials->findOneByCredentialId($credId)
            ?? throw new ApiProblem(401, 'Fake: unknown credential');
        $cred->lastUsedAt = new \DateTimeImmutable();
        $this->em->flush();

        return $cred->adminUser;
    }
}
