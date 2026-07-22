<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AdminUser;
use App\Enum\AdminRole;
use App\Exception\ApiProblem;
use App\Service\WebAuthn\BundleWebAuthnCeremony;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Deckt die REALE {@see BundleWebAuthnCeremony} ab (in Tests standardmäßig
 * per Alias durch {@see \App\Service\WebAuthn\FakeWebAuthnCeremony} ersetzt,
 * siehe `config/services.yaml`, `when@test`). Geholt wird die konkrete
 * Klasse direkt aus dem Test-Container, der auch private Services
 * herausgibt.
 *
 * Abgedeckt werden ausschließlich die Guard-/Fehlerzweige VOR dem Aufruf
 * der echten Krypto-Validatoren (attestationValidator->check() /
 * assertionValidator->check()) — dieser Kryptokern ist laut Spec §9 bewusst
 * gemockt (FakeWebAuthnCeremony) und ohne echten Authenticator nicht sinnvoll
 * unit-testbar. Siehe Testabdeckungs-Report für die Liste der bewusst
 * unabgedeckten Zeilen.
 */
final class BundleWebAuthnCeremonyTest extends KernelTestCase
{
    private BundleWebAuthnCeremony $ceremony;

    protected function setUp(): void
    {
        self::bootKernel();

        // Die Zeremonie liest/schreibt Challenges über AdminSession, die
        // ihrerseits über den RequestStack des Containers geht. Ohne einen
        // gepushten Request mit angehängter Session wirft
        // RequestStack::getSession() eine SessionNotFoundException.
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        self::getContainer()->get(RequestStack::class)->push($request);

        $this->ceremony = self::getContainer()->get(BundleWebAuthnCeremony::class);
    }

    private function makeUser(): AdminUser
    {
        return new AdminUser('Chef', 'chef@example.com', AdminRole::Admin);
    }

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Baut ein clientJson, das der Serializer zu einer vollständigen
     * AuthenticatorAssertionResponse deserialisiert (kein Signatur-Beweis
     * nötig, da der eigentliche check() in unseren Testfällen nie erreicht
     * wird — nur die Denormalizer-Strukturprüfung muss durchlaufen).
     */
    private function assertionShapedClientJson(string $rawIdBytes): string
    {
        $authenticatorData = random_bytes(32) . chr(0x01) . pack('N', 0); // rp_id_hash(32) + flags(UP) + signCount, keine attested credential data / extensions
        $clientData = json_encode(['type' => 'webauthn.get', 'challenge' => $this->b64url(random_bytes(16)), 'origin' => 'https://gestura.eu'], JSON_THROW_ON_ERROR);

        return json_encode([
            'id' => $this->b64url($rawIdBytes),
            'rawId' => base64_encode($rawIdBytes),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->b64url($clientData),
                'authenticatorData' => base64_encode($authenticatorData),
                'signature' => base64_encode(random_bytes(32)),
            ],
        ], JSON_THROW_ON_ERROR);
    }

    public function testVerifyRegistrationFailsWithoutChallenge(): void
    {
        try {
            $this->ceremony->verifyRegistration($this->makeUser(), '{}', 'Laptop');
            self::fail('Erwartete ApiProblem blieb aus');
        } catch (ApiProblem $e) {
            self::assertSame(400, $e->getStatusCode());
            self::assertSame('No registration challenge', $e->getMessage());
        }
    }

    public function testVerifyRegistrationFailsWithNonAttestationResponse(): void
    {
        $user = $this->makeUser();
        $this->ceremony->creationOptionsJson($user); // setzt die reg-Challenge

        // Ein assertion-förmiges clientJson deserialisiert zu einer
        // AuthenticatorAssertionResponse — keine AuthenticatorAttestationResponse.
        $clientJson = $this->assertionShapedClientJson(random_bytes(16));

        try {
            $this->ceremony->verifyRegistration($user, $clientJson, 'Laptop');
            self::fail('Erwartete ApiProblem blieb aus');
        } catch (ApiProblem $e) {
            self::assertSame(400, $e->getStatusCode());
            self::assertSame('Invalid attestation response', $e->getMessage());
        }
    }

    public function testVerifyAssertionFailsWithoutChallenge(): void
    {
        try {
            $this->ceremony->verifyAssertion('{}');
            self::fail('Erwartete ApiProblem blieb aus');
        } catch (ApiProblem $e) {
            self::assertSame(400, $e->getStatusCode());
            self::assertSame('No assertion challenge', $e->getMessage());
        }
    }

    public function testVerifyAssertionFailsWithUnknownCredential(): void
    {
        $this->ceremony->requestOptionsJson(null); // setzt die assert-Challenge
        $clientJson = $this->assertionShapedClientJson(random_bytes(16)); // keine passende WebAuthnCredential in der DB

        try {
            $this->ceremony->verifyAssertion($clientJson);
            self::fail('Erwartete ApiProblem blieb aus');
        } catch (ApiProblem $e) {
            self::assertSame(401, $e->getStatusCode());
            self::assertSame('Unknown credential', $e->getMessage());
        }
    }

    public function testCreationOptionsJsonReturnsNonEmptyOptionsForUserWithoutCredentials(): void
    {
        $json = $this->ceremony->creationOptionsJson($this->makeUser());

        self::assertNotSame('', $json);
        $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('challenge', $decoded);
    }

    public function testRequestOptionsJsonReturnsNonEmptyOptionsWithoutUser(): void
    {
        $json = $this->ceremony->requestOptionsJson(null);

        self::assertNotSame('', $json);
        $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('challenge', $decoded);
    }
}
