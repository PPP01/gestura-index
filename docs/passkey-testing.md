# Passkey-Anmeldung lokal testen (Runbook)

Ziel: die WebAuthn-/Passkey-Ceremonies (Registrierung, Login, Step-up, Backup-Passkey) **einmal echt** durchfahren – bisher liefen sie nur gegen Mocks (`FakeWebAuthnCeremony` im Backend, gemocktes `@simplewebauthn/browser` im Frontend). Der Test validiert den bisher ungeprüften Round-Trip `webauthn-lib ↔ @simplewebauthn/browser` und den Session-Cookie-Fluss.

> **Smoke-Test-Stand:** Die Backend-Optionen-Erzeugung ist bereits bestätigt – `POST /api/admin/auth/options` liefert mit `WEBAUTHN_RP_ID=localhost` valides L3-JSON (`challenge`, `rpId:localhost`, `userVerification:required`, `application/json`). Was dieser Browser-Test ergänzt, ist die **Response-Validierung** (Attestation/Assertion durch den echten Authenticator).

## Warum lokal Single-Origin

WebAuthn braucht Secure-Context + Origin-Match: `navigator.credentials` läuft nur auf `https://` **oder** `http://localhost`, und die RP-ID muss zur Origin passen (`gestura.eu` matcht `localhost` nicht → lokal `WEBAUTHN_RP_ID=localhost`). Der Vite-Dev-Proxy (`/api → localhost:$BACKEND_PORT`, in `frontend/vite.config.ts`) sorgt dafür, dass SPA **und** API unter der einen Origin `http://localhost:5173` laufen – damit entfällt jede CORS-/Cross-Origin-Cookie-Frage (der produktiv hartkodierte CORS-Origin `https://gestura.eu` ist lokal schlicht irrelevant, weil same-origin).

## Einmalige lokale Konfiguration (bereits gesetzt, gitignored)

- `backend/.env.local`: `WEBAUTHN_RP_ID=localhost` (Override; `SESSION_COOKIE_DOMAIN` bleibt leer → host-only-Cookie auf localhost). `MAILER_DSN` bleibt `null://null` – der Bootstrap-Befehl druckt den Invite-Token in die Konsole.
- `frontend/.env.local`: `PUBLIC_API_BASE=http://localhost:5173` – so stellt der API-Client same-origin-Requests, die der Proxy ans Backend weiterreicht.
- `frontend/vite.config.ts`: `server.proxy` `'/api' → 'http://localhost:${BACKEND_PORT ?? 8000}'` (nur für `vite dev`, ohne Wirkung auf den Build). **Der Port ist nicht mehr fest:** `dev.sh` sucht ab 8000 den ersten freien und reicht ihn als `BACKEND_PORT` an beide Seiten durch – eine Stellschraube, immer konsistent. Wer einen festen Port braucht: `BACKEND_PORT=8000 ./dev.sh`.

Voraussetzung: lokale MariaDB mit der Datenbank `gestura_index` inkl. der Admin-Tabellen (die Migrationen wurden dort angewandt – Stand bestätigt: `admin_user` existiert).

## Ablauf

### 1. Server starten

Der bequeme Weg – startet Backend **und** Vite mit konsistentem Port:

```bash
./dev.sh
```

Einzeln geht es weiterhin (dann muss der Vite-Proxy denselben Port sehen):

```bash
BACKEND_PORT=8000 ./dev.sh
# oder ganz von Hand:
php -S localhost:8000 -t backend/public backend/public/index.php
```

Der Front-Controller als Router-Skript sorgt für sauberes Routing beim PHP-Built-in-Server. Port **8000** ist der Projekt-Default (siehe CLAUDE.md); der Vite-Proxy zeigt darauf. Ist 8000 belegt, beide Stellen (hier + `frontend/vite.config.ts` `server.proxy`) auf denselben freien Port angleichen.

### Zugang von Windows (WSL2, Zugriff nur über die WSL-IP)

Der Browser läuft auf Windows, die Dienste in WSL. `http://<WSL-IP>` taugt NICHT für WebAuthn (kein Secure Context, IP ist keine gültige RP-ID). Lösung: ein Windows→WSL-Portproxy, damit `http://localhost:5173` auf Windows die WSL-Instanz erreicht (Vite bindet via `server.host: true` auf `0.0.0.0`):

```powershell
# einmalig in einer Admin-PowerShell auf Windows:
netsh interface portproxy add v4tov4 listenaddress=127.0.0.1 listenport=5173 connectaddress=<WSL-IP> connectport=5173
```

Danach im Windows-Browser `http://localhost:5173/…` öffnen → Secure Context + RP-ID `localhost`. Entfernen mit `netsh interface portproxy delete v4tov4 listenaddress=127.0.0.1 listenport=5173`.

### 2. Frontend-Dev-Server starten

```bash
npm --prefix frontend run dev      # → http://localhost:5173
```

### 3. Virtuellen Authenticator aktivieren (kein Hardware-Key nötig)

Chrome/Edge → DevTools (F12) → Menü »⋮« → **More tools → WebAuthn** → *Enable virtual authenticator environment* → **Add**:

- Protocol `ctap2`, Transport `internal` (Platform),
- *Supports resident keys* ✓, *Supports user verification* ✓,
- *Automatic presence simulation* ✓.

Registrierte Passkeys erscheinen danach in diesem Tab; hier lassen sich auch mehrere Authenticators/Credentials verwalten.

### 4. Bootstrap-Admin anlegen

```bash
php backend/bin/console index:admin:create "Test Admin" test@local --role=admin
```

Aus der Konsole den Fallback-Link nehmen und den Host durch den Dev-Server ersetzen, z. B.:
`http://localhost:5173/de/admin/register?token=gsta_…`
(Der gedruckte Link zeigt aus historischen Gründen auf `https://gestura.eu` – nur der `?token=…`-Teil zählt.)

### 5. Registrieren → Login

1. Register-Seite öffnen (Schritt 4) → »Passkey anlegen«: der virtuelle Authenticator legt den Passkey an → Konto wird aktiv → Weiterleitung zur Anmeldung.
2. Login-Seite → »Mit Passkey anmelden«: Assertion mit dem virtuellen Authenticator → Session-Cookie gesetzt → Weiterleitung ins Dashboard (`/admin/queue`).
3. Reload/Deep-Link testen: Seite neu laden → Shell (Sidebar/Topbar) bleibt (Guard lädt die Session); direktes Öffnen einer geschützten Route ohne Session → Redirect zur Anmeldung.

### 6. Backup-Passkey + Step-up

1. »Mein Konto« → »Passkey hinzufügen« → zweiter Passkey (im WebAuthn-Tab ggf. einen zweiten Authenticator/Credential zulassen). Das Backup-Banner verschwindet, sobald ≥ 2 Passkeys da sind.
2. Eine destruktive Aktion auslösen (z. B. in der Queue »Ablehnen«): der Server verlangt Step-up → es läuft automatisch eine frische Assertion (virtueller Authenticator) → die Aktion wird wiederholt und ausgeführt.
3. Passkey entfernen: Entfernen bis auf 2 ist ok; das Löschen unter 2 wird mit einem Hinweis (409) abgelehnt.

### 7. Logout

Logout → ein Folge-Request/`/me` ergibt 401 → Redirect zur Anmeldung (die Session ist serverseitig beendet, das Token aus dem `TokenStorage` geleert).

## Was der Test bestätigen soll

- Registrierung: `@simplewebauthn` `startRegistration(optionsJSON)` akzeptiert das `webauthn-lib`-Creation-Options-JSON, und der `AuthenticatorAttestationResponseValidator` akzeptiert die zurückgegebene Attestation (der eigentliche Round-Trip).
- Login/Step-up: analog für `startAuthentication` + `AuthenticatorAssertionResponseValidator`.
- Sollte der Round-Trip an einem Feldnamen scheitern, gehört die Normalisierung in den Wrapper `frontend/src/lib/admin/webauthn.ts` – **nicht** ins Backend (der Server validiert identisch zur Extension).

## Produktion

Lokal-only: `WEBAUTHN_RP_ID=localhost`, `PUBLIC_API_BASE=http://localhost:5173` und der Vite-Proxy sind reine Dev-Hilfen (Env-Dateien gitignored). Produktiv bleibt RP-ID `gestura.eu`, credentialed CORS auf `https://gestura.eu`, Cookie `Domain=.gestura.eu` – siehe `deploy/README.md`.
