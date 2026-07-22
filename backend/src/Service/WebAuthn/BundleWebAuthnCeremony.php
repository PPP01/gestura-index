<?php
declare(strict_types=1);
namespace App\Service\WebAuthn;

use App\Entity\AdminUser;
use App\Entity\WebAuthnCredential;
use App\Exception\ApiProblem;
use App\Repository\WebAuthnCredentialRepository;
use App\Service\AdminSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\Bundle\Service\PublicKeyCredentialCreationOptionsFactory;
use Webauthn\Bundle\Service\PublicKeyCredentialRequestOptionsFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Echte WebAuthn-Zeremonie über die web-auth/webauthn-lib-Services (5.3.x).
 *
 * Ruft die Attestation-/Assertion-Validatoren direkt auf. Die dem Client
 * ausgelieferte Challenge wird in der Admin-Session zwischengespeichert und
 * bei der Verifikation exakt in die neu gebauten Options zurückgesetzt (die
 * Options-Factory erzeugt bei jedem Aufruf eine frische Zufalls-Challenge).
 */
final class BundleWebAuthnCeremony implements WebAuthnCeremony
{
    private const PROFILE = 'admin';

    private readonly SerializerInterface $serializer;

    public function __construct(
        private readonly PublicKeyCredentialCreationOptionsFactory $creationFactory,
        private readonly PublicKeyCredentialRequestOptionsFactory $requestFactory,
        private readonly AuthenticatorAttestationResponseValidator $attestationValidator,
        private readonly AuthenticatorAssertionResponseValidator $assertionValidator,
        WebauthnSerializerFactory $serializerFactory,
        private readonly WebAuthnCredentialRepository $credentials,
        private readonly AdminSession $session,
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(default:default_rp_id:WEBAUTHN_RP_ID)%')]
        private readonly string $rpId,
    ) {
        $this->serializer = $serializerFactory->create();
    }

    public function creationOptionsJson(AdminUser $user): string
    {
        $options = $this->creationFactory->create(
            self::PROFILE,
            $this->userEntity($user),
            $this->descriptorsFor($user),
        );
        $this->session->putChallenge('reg', base64_encode($options->challenge));

        return $this->serializer->serialize($options, 'json');
    }

    public function verifyRegistration(AdminUser $user, string $clientJson, string $label): WebAuthnCredential
    {
        $challenge = $this->session->takeChallenge('reg')
            ?? throw new ApiProblem(400, 'No registration challenge');
        $options = $this->rebuildCreationOptions($user, base64_decode($challenge));

        $pkc = $this->serializer->deserialize($clientJson, PublicKeyCredential::class, 'json');
        if (!$pkc->response instanceof AuthenticatorAttestationResponse) {
            throw new ApiProblem(400, 'Invalid attestation response');
        }

        try {
            $record = $this->attestationValidator->check($pkc->response, $options, $this->rpId);
        } catch (\Throwable) {
            throw new ApiProblem(400, 'Attestation verification failed');
        }

        $cred = new WebAuthnCredential(
            $user,
            $this->b64url($record->publicKeyCredentialId),
            $this->serializer->serialize($record, 'json'),
            $label,
        );
        $cred->aaguid = $record->aaguid->__toString();
        $this->em->persist($cred);
        $this->em->flush();

        return $cred;
    }

    public function requestOptionsJson(?AdminUser $user): string
    {
        $allow = $user !== null ? $this->descriptorsFor($user) : [];
        $options = $this->requestFactory->create(self::PROFILE, $allow);
        $this->session->putChallenge('assert', base64_encode($options->challenge));

        return $this->serializer->serialize($options, 'json');
    }

    public function verifyAssertion(string $clientJson): AdminUser
    {
        $challenge = $this->session->takeChallenge('assert')
            ?? throw new ApiProblem(400, 'No assertion challenge');

        $pkc = $this->serializer->deserialize($clientJson, PublicKeyCredential::class, 'json');
        if (!$pkc->response instanceof AuthenticatorAssertionResponse) {
            throw new ApiProblem(400, 'Invalid assertion response');
        }

        $cred = $this->credentials->findOneByCredentialId($this->b64url($pkc->rawId))
            ?? throw new ApiProblem(401, 'Unknown credential');
        $record = $this->deserializeRecord($cred->source);
        $options = $this->rebuildRequestOptions(base64_decode($challenge));

        try {
            $updated = $this->assertionValidator->check(
                $record,
                $pkc->response,
                $options,
                $this->rpId,
                (string) $cred->adminUser->id,
            );
        } catch (\Throwable) {
            throw new ApiProblem(401, 'Assertion verification failed');
        }

        $cred->source = $this->serializer->serialize($updated, 'json');
        $cred->lastUsedAt = new \DateTimeImmutable();
        $this->em->flush();

        return $cred->adminUser;
    }

    private function userEntity(AdminUser $user): PublicKeyCredentialUserEntity
    {
        return PublicKeyCredentialUserEntity::create($user->email, (string) $user->id, $user->displayName);
    }

    /** @return list<\Webauthn\PublicKeyCredentialDescriptor> */
    private function descriptorsFor(AdminUser $user): array
    {
        $out = [];
        foreach ($user->credentials as $c) {
            $out[] = $this->deserializeRecord($c->source)->getPublicKeyCredentialDescriptor();
        }

        return $out;
    }

    private function deserializeRecord(string $json): CredentialRecord
    {
        return $this->serializer->deserialize($json, CredentialRecord::class, 'json');
    }

    private function rebuildCreationOptions(AdminUser $user, string $challenge): PublicKeyCredentialCreationOptions
    {
        $options = $this->creationFactory->create(self::PROFILE, $this->userEntity($user), []);
        $options->challenge = $challenge;

        return $options;
    }

    private function rebuildRequestOptions(string $challenge): PublicKeyCredentialRequestOptions
    {
        $options = $this->requestFactory->create(self::PROFILE, []);
        $options->challenge = $challenge;

        return $options;
    }

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
