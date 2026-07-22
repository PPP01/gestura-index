<?php
declare(strict_types=1);
namespace App\Service\WebAuthn;

use App\Entity\AdminUser;
use App\Entity\WebAuthnCredential;

/**
 * Kapselt die WebAuthn-Zeremonien (Options-Erzeugung + Attestation-/Assertion-
 * Prüfung). Wird in Tests durch einen deterministischen Fake ersetzt, damit
 * Admin-Funktionstests ohne echten Authenticator laufen.
 */
interface WebAuthnCeremony
{
    /** Erzeugt Creation-Options (Registrierung) als JSON; legt die Challenge in die Session. */
    public function creationOptionsJson(AdminUser $user): string;

    /** Prüft die Attestation-Antwort und persistiert ein neues WebAuthnCredential. */
    public function verifyRegistration(AdminUser $user, string $clientJson, string $label): WebAuthnCredential;

    /** Erzeugt Request-Options (Login) als JSON; legt die Challenge in die Session. */
    public function requestOptionsJson(?AdminUser $user): string;

    /** Prüft die Assertion-Antwort und liefert den zugehörigen AdminUser. */
    public function verifyAssertion(string $clientJson): AdminUser;
}
