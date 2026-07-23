<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AdminUser;
use App\Enum\AdminRole;
use App\Service\WebAuthn\BundleWebAuthnCeremony;
use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use Doctrine\ORM\EntityManagerInterface;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Testet den ECHTEN Krypto-Erfolgsweg der {@see BundleWebAuthnCeremony}:
 * eine vollständige Registrierung (Attestation) gefolgt von einem Login
 * (Assertion) mit kryptographisch korrekter Signatur.
 *
 * Der Test baut einen minimalen Software-Authenticator (EC P-256), erzeugt
 * binärkorrekte WebAuthn-Antworten (authenticatorData, clientDataJSON,
 * attestationObject) und führt sie durch die ECHTEN Validatoren
 * ({@see \Webauthn\AuthenticatorAttestationResponseValidator::check()},
 * {@see \Webauthn\AuthenticatorAssertionResponseValidator::check()}) –
 * NICHT durch den {@see \App\Service\WebAuthn\FakeWebAuthnCeremony}.
 *
 * Durchlaufene Ceremony-Steps (Attestation):
 *   CheckClientDataCollectorType, CheckChallenge, CheckOrigin,
 *   CheckTopOrigin, CheckRelyingPartyIdIdHash, CheckUserWasPresent,
 *   CheckUserVerification, CheckBackupBitsAreConsistent, CheckAlgorithm,
 *   CheckExtensions, CheckAttestationFormatIsKnownAndValid,
 *   CheckHasAttestedCredentialData, CheckMetadataStatement, CheckCredentialId.
 *
 * Durchlaufene Ceremony-Steps (Assertion):
 *   CheckAllowedCredentialList, CheckUserHandle, CheckClientDataCollectorType,
 *   CheckChallenge, CheckOrigin, CheckTopOrigin, CheckRelyingPartyIdIdHash,
 *   CheckUserWasPresent, CheckUserVerification, CheckBackupBitsAreConsistent,
 *   CheckExtensions, CheckSignature (ECDSA ES256), CheckCounter.
 */
final class RealWebAuthnCryptoCeremonyTest extends KernelTestCase
{
    private BundleWebAuthnCeremony $ceremony;
    private EntityManagerInterface $em;

    /** @var \OpenSSLAsymmetricKey EC P-256 Schlüsselpaar */
    private \OpenSSLAsymmetricKey $privateKey;

    /** 32-Byte raw x-Koordinate des öffentlichen Schlüssels */
    private string $publicX;

    /** 32-Byte raw y-Koordinate des öffentlichen Schlüssels */
    private string $publicY;

    /** Zufällige Credential-ID (32 Byte) */
    private string $credentialId;

    private const RP_ID = 'gestura.eu';
    private const ORIGIN = 'https://gestura.eu';

    /** 16 Null-Bytes AAGUID (Privacy-Platzhalter, keine Metadata-Prüfung) */
    private const AAGUID = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";

    protected function setUp(): void
    {
        self::bootKernel();

        // Session in den RequestStack einsetzen (BundleWebAuthnCeremony
        // speichert/liest Challenges über AdminSession → RequestStack).
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        self::getContainer()->get(RequestStack::class)->push($request);

        // Echte BundleWebAuthnCeremony holen – NICHT der when@test-Fake-Alias,
        // sondern die konkrete Klasse (als public markiert in services.yaml).
        $this->ceremony = self::getContainer()->get(BundleWebAuthnCeremony::class);
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        // EC P-256 Schlüsselpaar erzeugen
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        self::assertNotFalse($key, 'EC-Schlüsselerzeugung fehlgeschlagen');
        $this->privateKey = $key;

        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertArrayHasKey('ec', $details);

        // x/y auf exakt 32 Byte normieren (P-256 Koordinatenlänge).
        // OpenSSL kann führende Null-Bytes weglassen oder ein führendes
        // Vorzeichenbyte ergänzen — beides wird hier korrigiert.
        $this->publicX = self::normalizeCoordinate($details['ec']['x'], 32);
        $this->publicY = self::normalizeCoordinate($details['ec']['y'], 32);

        $this->credentialId = random_bytes(32);
    }

    /**
     * Vollständiger Happy-Path: Registrierung (Attestation) + Login (Assertion)
     * mit echtem Krypto-Durchlauf.
     */
    public function testFullRegistrationAndAssertionThroughRealValidators(): void
    {
        // --- AdminUser persistieren (verifyRegistration braucht eine DB-ID) ---
        $user = new AdminUser('Crypto-Tester', 'crypto-tester@example.com', AdminRole::Admin);
        $this->em->persist($user);
        $this->em->flush();
        self::assertNotNull($user->id, 'AdminUser braucht eine DB-generierte ID');

        // ===================================================================
        // PHASE 1: REGISTRIERUNG (Attestation)
        // ===================================================================

        // 1a. CreationOptions holen — speichert die Challenge in der Session
        $optionsJson = $this->ceremony->creationOptionsJson($user);
        $optionsDecoded = json_decode($optionsJson, true, 8, JSON_THROW_ON_ERROR);
        $challengeB64url = $optionsDecoded['challenge'];

        // 1b. clientDataJSON bauen (wie der Browser es erzeugt)
        $clientDataReg = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $challengeB64url,
            'origin' => self::ORIGIN,
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        // 1c. authenticatorData bauen (mit attestedCredentialData)
        //     Flags: UP(0x01) | UV(0x04) | AT(0x40) = 0x45
        $authDataReg = $this->buildAuthenticatorData(
            flags: 0x45,
            signCount: 0,
            includeCredentialData: true,
        );

        // 1d. attestationObject (CBOR): fmt=»none«, leeres attStmt, authData
        $attestationObject = $this->buildAttestationObject($authDataReg);

        // 1e. Vollständiges Client-JSON (Struktur wie navigator.credentials.create())
        $clientJson = $this->buildAttestationClientJson($clientDataReg, $attestationObject);

        // 1f. verifyRegistration() — durchläuft attestationValidator->check()
        //     mit allen 14 CeremonySteps (siehe Klassen-Docblock).
        $credential = $this->ceremony->verifyRegistration($user, $clientJson, 'Software-Authenticator');

        self::assertNotNull($credential->id, 'Credential muss in der DB persistiert sein');
        self::assertSame($user, $credential->adminUser);
        self::assertSame('Software-Authenticator', $credential->label);

        // ===================================================================
        // PHASE 2: LOGIN (Assertion)
        // ===================================================================

        // 2a. RequestOptions holen — speichert die Challenge in der Session
        $requestJson = $this->ceremony->requestOptionsJson($user);
        $requestDecoded = json_decode($requestJson, true, 8, JSON_THROW_ON_ERROR);
        $assertChallengeB64url = $requestDecoded['challenge'];

        // 2b. clientDataJSON für Assertion
        $clientDataAssert = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $assertChallengeB64url,
            'origin' => self::ORIGIN,
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        // 2c. authenticatorData für Assertion (OHNE attestedCredentialData)
        //     Flags: UP(0x01) | UV(0x04) = 0x05; signCount: 1 (> 0 aus Registrierung)
        $authDataAssert = $this->buildAuthenticatorData(
            flags: 0x05,
            signCount: 1,
            includeCredentialData: false,
        );

        // 2d. ECDSA-Signatur über authenticatorData || sha256(clientDataJSON)
        $dataToSign = $authDataAssert . hash('sha256', $clientDataAssert, true);
        $signature = '';
        $signOk = openssl_sign($dataToSign, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        self::assertTrue($signOk, 'ECDSA-Signatur konnte nicht erzeugt werden');

        // 2e. Vollständiges Client-JSON (Struktur wie navigator.credentials.get())
        $clientJsonAssert = $this->buildAssertionClientJson(
            $clientDataAssert,
            $authDataAssert,
            $signature,
        );

        // 2f. verifyAssertion() — durchläuft assertionValidator->check()
        //     mit allen 13 CeremonySteps inkl. CheckSignature (ES256).
        $returnedUser = $this->ceremony->verifyAssertion($clientJsonAssert);

        self::assertSame(
            $user->id,
            $returnedUser->id,
            'Assertion muss den korrekten AdminUser zurückgeben',
        );
    }

    // ===================================================================
    // Hilfsmethoden: Software-Authenticator
    // ===================================================================

    /**
     * Baut den binären authenticatorData-Block nach WebAuthn-Spec.
     *
     * Layout: rpIdHash(32) || flags(1) || signCount(4)
     *   [|| aaguid(16) || credIdLen(2) || credId(N) || coseKey(CBOR)]
     */
    private function buildAuthenticatorData(
        int $flags,
        int $signCount,
        bool $includeCredentialData,
    ): string {
        $data = hash('sha256', self::RP_ID, true)   // rpIdHash
            . chr($flags)                            // flags
            . pack('N', $signCount);                 // signCount (uint32 big-endian)

        if ($includeCredentialData) {
            $data .= self::AAGUID;                            // aaguid (16 Byte)
            $data .= pack('n', strlen($this->credentialId));  // credIdLen (uint16 BE)
            $data .= $this->credentialId;                     // credentialId
            $data .= $this->buildCosePublicKey();             // COSE-Key (CBOR)
        }

        return $data;
    }

    /**
     * COSE EC2/ES256 Public Key als CBOR-Bytes.
     *
     * Map-Struktur:
     *   1 (kty): 2 (EC2)
     *   3 (alg): -7 (ES256)
     *  -1 (crv): 1 (P-256)
     *  -2 (x):   32 Byte
     *  -3 (y):   32 Byte
     */
    private function buildCosePublicKey(): string
    {
        $map = MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($this->publicX))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($this->publicY));

        return (string) $map;
    }

    /**
     * CBOR-kodiertes AttestationObject (fmt »none«, leeres attStmt).
     *
     * Map-Struktur:
     *   "fmt":     "none"
     *   "attStmt": {} (leere Map)
     *   "authData": <binary>
     */
    private function buildAttestationObject(string $authData): string
    {
        $map = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        return (string) $map;
    }

    /**
     * JSON-Objekt für die Attestation-Antwort
     * (nachgebildete Struktur von navigator.credentials.create()).
     */
    private function buildAttestationClientJson(string $clientDataJsonRaw, string $attestationObjectRaw): string
    {
        $credIdB64url = Base64UrlSafe::encodeUnpadded($this->credentialId);

        return json_encode([
            'id' => $credIdB64url,
            'rawId' => $credIdB64url,
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientDataJsonRaw),
                'attestationObject' => Base64UrlSafe::encodeUnpadded($attestationObjectRaw),
                'transports' => ['internal'],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * JSON-Objekt für die Assertion-Antwort
     * (nachgebildete Struktur von navigator.credentials.get()).
     */
    private function buildAssertionClientJson(
        string $clientDataJsonRaw,
        string $authDataRaw,
        string $signatureDer,
    ): string {
        $credIdB64url = Base64UrlSafe::encodeUnpadded($this->credentialId);

        return json_encode([
            'id' => $credIdB64url,
            'rawId' => $credIdB64url,
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientDataJsonRaw),
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authDataRaw),
                'signature' => Base64UrlSafe::encodeUnpadded($signatureDer),
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Normiert eine EC-Koordinate auf exakt $length Bytes.
     *
     * OpenSSL kann führende Null-Bytes weglassen (Koordinate < 2^248)
     * oder ein führendes 0x00-Byte ergänzen (ASN.1-Vorzeichenbit).
     */
    private static function normalizeCoordinate(string $raw, int $length): string
    {
        $len = strlen($raw);

        if ($len > $length) {
            // Führendes 0x00-Vorzeichenbyte abschneiden
            return substr($raw, $len - $length);
        }

        if ($len < $length) {
            // Führende Null-Bytes auffüllen
            return str_pad($raw, $length, "\x00", STR_PAD_LEFT);
        }

        return $raw;
    }
}
