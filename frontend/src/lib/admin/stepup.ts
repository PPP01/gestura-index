import { AdminApiError, stepUpOptions, stepUpVerify, type AdminClientOpts } from './api';
import { performAssertion } from './webauthn';

/**
 * Führt `action` aus; bei einem Step-up-erforderlich-Fehler (403 mit
 * `stepUpRequired`) wird die WebAuthn-Ceremony durchgeführt und `action`
 * genau einmal erneut versucht. Alle anderen Fehler werden durchgereicht.
 */
export async function withStepUp<T>(action: () => Promise<T>, opts?: AdminClientOpts): Promise<T> {
	try {
		return await action();
	} catch (e) {
		if (!(e instanceof AdminApiError) || !e.stepUpRequired) throw e;
		const options = await stepUpOptions(opts);
		const assertion = await performAssertion(options);
		await stepUpVerify(assertion, opts);
		return action(); // einmaliger Retry
	}
}
