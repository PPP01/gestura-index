import { startRegistration, startAuthentication } from '@simplewebauthn/browser';

/**
 * Dünner Passthrough-Wrapper um `@simplewebauthn/browser`.
 *
 * `optionsJson` ist das Options-JSON, wie es die API (webauthn-lib,
 * Symfony-Backend) liefert – Standard-WebAuthn-L3-JSON-Form (challenge,
 * user.id, allowCredentials[].id als base64url). `@simplewebauthn/browser`
 * erwartet exakt dieses Format unter `optionsJSON`, daher keine
 * Feld-Normalisierung hier (siehe Task-Report für den offenen
 * Round-Trip-Verifikationspunkt).
 */
export async function performRegistration(optionsJson: unknown): Promise<unknown> {
	return startRegistration({ optionsJSON: optionsJson as never });
}

export async function performAssertion(optionsJson: unknown): Promise<unknown> {
	return startAuthentication({ optionsJSON: optionsJson as never });
}
