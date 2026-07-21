# Sub-Projekt 4a: Admin-Backend (Auth + Admin-API + Audit-Log) – Design-Spec

> Teil von Phase 2. Reihenfolge: Backend-Kern (SP1 ✓) → Deployment (SP2 ✓) → öffentliches Frontend (SP3 ✓) → **Admin-Backend (SP4a, dieses Dokument)** → Admin-SPA (SP4b).

## 1. Ziel

Die serverseitige Grundlage für ein Web-Admin-Panel: WebAuthn-basierte Anmeldung (Passkey), Rollen und Nutzerverwaltung, HTTP-Endpunkte, die die bereits vorhandene Moderationslogik (`ModerationService`) und Abfragen bereitstellen, sowie ein Audit-Log. SP4a ist eigenständig über Funktionstests gegen die Endpunkte prüfbar – ohne dass die SPA (SP4b) existiert.

## 2. Nicht-Ziele

- Keine Svelte-SPA (das ist SP4b).
- Keine Änderungen an den öffentlichen Endpunkten oder am öffentlichen Frontend.
- Kein Passwort-Login (ausschließlich Passkey), kein separater zweiter Faktor beim Login (siehe Auth-Modell).
- Keine Phase-3-Themen (Konten für Einreicher, Settings-Sync, Bewertungen).

## 3. Auth-Modell (festgezurrt)

- **Passkey als starke Auth:** WebAuthn mit erzwungener User-Verification (UV=required) zählt als Zwei-Komponenten-Authentisierung (Besitz + Biometrie/PIN). **Kein** separater zweiter Faktor (kein TOTP) beim Login.
- **Step-up vor destruktiven Aktionen:** Ban, Ablehnen, Nutzer deaktivieren/einladen u. Ä. verlangen eine **frische** Passkey-Bestätigung (letzte erfolgreiche Verifikation < 5 min; sonst erneute Assertion nötig).
- **RP-ID `gestura.eu`:** Die WebAuthn-Ceremony läuft im Browser auf `gestura.eu` (dort wird SP4b ausgeliefert); die registrierbare Domain `gestura.eu` deckt auch `api.gestura.eu` ab. Die API validiert gegen RP-ID `gestura.eu`.
- **Session:** Nach erfolgreichem Login setzt die API ein **httpOnly-Session-Cookie** (`Domain=.gestura.eu`, `Secure`, `SameSite=Strict`, Idle-TTL 30 min). Nicht per JavaScript lesbar (XSS-fest). Refresh bei Aktivität; nach Ablauf erneuter Passkey-Login.
- **Bibliothek:** `web-auth/webauthn-symfony-bundle` (Standard für Symfony) für Registrierungs- und Assertion-Ceremonies.
- **Backup-Passkey-Pflicht (≥ 2):** Jeder Admin-Account soll **zwei** registrierte Passkeys haben (z. B. Rechner + Handy), damit ein verlorenes/defektes Gerät nicht aussperrt. **Der Login braucht immer nur einen** beliebigen davon – nie beide gleichzeitig. Durchsetzung (bestätigt): Der Account ist nach dem **ersten** Passkey nutzbar (kein Aussperren beim Setup), aber ein zweiter wird **vor destruktiven Aktionen erzwungen** (siehe unten); ein Passkey lässt sich **nie unter 2** entfernen (sobald zwei existieren).

## 4. Rollen

Zwei Rollen (Enum `AdminRole`):
- **`admin`** – volle Rechte: Moderation **und** Nutzerverwaltung (einladen/deaktivieren), Ban/Unban, Audit-Log-Einsicht.
- **`moderator`** – nur Moderation: Warteschlange, Einträge/Versionen freigeben/ablehnen, Meldungen bearbeiten. Kein Zugriff auf Nutzerverwaltung, Ban oder Audit-Log.

Rollenprüfung serverseitig pro Endpunkt (Symfony Security Voter oder `#[IsGranted]`).

## 5. Datenmodell (neue Doctrine-Entities)

- **`AdminUser`** – `id`, `displayName`, `email` (für Einladung/Kontakt; nur Betreiber-Daten, nicht öffentlich), `role` (`AdminRole`), `status` (`AdminUserStatus`: `invited` | `active` | `disabled`), `createdAt`, `lastLoginAt` (nullable). E-Mail eindeutig.
- **`WebAuthnCredential`** – ManyToOne auf `AdminUser`; `credentialId` (binär/Base64URL), `publicKey`, `signatureCounter`, `aaguid`, `label`, `createdAt`, `lastUsedAt`. Ein Nutzer kann mehrere Passkeys haben (Geräteverlust-Vorsorge).
- **`AdminInvite`** – Einladung: `tokenHash` (nur **Hash** des Einmal-Tokens, Muster wie Edit-Token/Argon2id), `adminUser` (der einzuladende, `invited`-Account), `role`, `createdBy` (AdminUser, nullable für CLI-Bootstrap), `expiresAt`, `usedAt` (nullable). Klartext-Token nie gespeichert; steckt nur im Einladungslink.
- **`AuditLogEntry`** – `actor` (AdminUser, nullable für System/CLI), `action` (String, z. B. `entry.approve`, `version.reject`, `report.resolve`, `submitter.ban`, `user.invite`, `user.disable`, `auth.login`), `targetType`/`targetId` (nullable), `createdAt`, `detail` (JSON, nullable). Unveränderlich (nur Insert).

Enums: `AdminRole` (`admin`, `moderator`), `AdminUserStatus` (`invited`, `active`, `disabled`).

## 6. Provisionierung & Einladungs-Flow (E-Mail)

- **Bootstrap (erster Admin):** CLI `index:admin:create <displayName> <email> --role=admin` legt einen `invited` Admin an und **verschickt die Einladungs-E-Mail** (und druckt den Link zusätzlich in die Konsole als Fallback).
- **Einladung durch Admins:** `POST /api/admin/users` (Rolle `admin`) mit `{ displayName, email, role }` → legt `invited`-Account + `AdminInvite` an und **versendet eine Einladungs-E-Mail** mit einmaligem, ablaufendem Link (Standard-Ablauf z. B. 72 h).
- **Registrierung:** Invitee öffnet den Link auf `gestura.eu` → WebAuthn-Registrierung (erster Passkey) → Account wird `active`, Invite als `usedAt` markiert. Direkt danach fordert das Panel den **zweiten (Backup-)Passkey** an – ein Handy geht bequem per Cross-Device/QR vom selben Rechner, alternativ ein zweites Gerät/Hardware-Key.
- **Recovery bei Passkey-Verlust:** Ein anderer Admin lädt denselben Nutzer erneut ein (neuer Passkey wird registriert); für den einzigen/ersten Admin dient die CLI (`index:admin:create` bzw. ein Re-Invite-Befehl) als Wiederherstellung.
- **E-Mail-Versand:** Symfony Mailer; `MAILER_DSN` (SMTP des Hosters) ausschließlich in `.env.local` auf dem Server, nie im Repo. Einladungs-E-Mail schlicht und auf Deutsch (Betreiberkontext), mit dem Link und Ablaufhinweis.

## 7. Admin-Endpunkte (`/api/admin/*`)

Dünne Controller über `ModerationService` + Repository-Abfragen; jede zustandsändernde Aktion schreibt einen `AuditLogEntry`. problem+json wie in der öffentlichen API.

**Auth/Session:**
- `POST /api/admin/auth/options` – Assertion-Optionen (Challenge) für den Login.
- `POST /api/admin/auth/login` – Assertion prüfen → Session-Cookie setzen.
- `POST /api/admin/auth/logout` – Session beenden.
- `GET /api/admin/auth/me` – aktueller Nutzer + Rolle (für die SPA).
- `POST /api/admin/register/options` + `POST /api/admin/register` – Passkey-Registrierung via gültigem Einladungs-Token.
- `POST /api/admin/stepup/options` + `POST /api/admin/stepup` – frische Assertion für Step-up.

**Passkey-Verwaltung (eigener Account, eingeloggt):**
- `GET /api/admin/credentials` – eigene Passkeys auflisten (Label, angelegt/zuletzt genutzt).
- `POST /api/admin/credentials/options` + `POST /api/admin/credentials` – weiteren Passkey zum eigenen Account hinzufügen (z. B. Handy/zweiter Rechner; Cross-Device unterstützt).
- `PATCH /api/admin/credentials/{id}` – Label ändern.
- `POST /api/admin/credentials/{id}/remove` – Passkey entfernen; **wird mit 409 abgelehnt, wenn danach < 2 übrig blieben** (Backup-Pflicht), und ist Step-up-geschützt.

**Moderation (admin + moderator):**
- `GET /api/admin/queue` – offene Einträge/Versionen (pending), transformCode-Einreichungen hervorgehoben.
- `GET /api/admin/entries/{id}` – Detail inkl. Versionen und Meldungen.
- `POST /api/admin/entries/{id}/approve` · `/reject` (reject = Step-up).
- `POST /api/admin/versions/{id}/approve` · `/reject` (reject = Step-up).
- `GET /api/admin/reports` – offene Meldungen.
- `POST /api/admin/reports/{id}/resolve` – mit `{ publish: bool }`.
- `POST /api/admin/submitters/{id}/ban` · `/unban` (ban = Step-up).

**Verwaltung (nur admin):**
- `GET /api/admin/users` · `POST /api/admin/users` (einladen) · `POST /api/admin/users/{id}/disable` (Step-up) · `POST /api/admin/users/{id}/reinvite`.
- `GET /api/admin/audit` – Audit-Log (paginierbar).

## 8. Sicherheit

- Eigener Firewall-Block in `security.yaml` für `^/api/admin` (außer den unauthentifizierten Auth-/Register-Endpunkten); Access-Control per Rolle.
- **Credentialed CORS nur für `/api/admin`:** feste Origin `https://gestura.eu`, `Access-Control-Allow-Credentials: true`. Getrennt vom `*`-CORS der öffentlichen API (der bestehende `CorsSubscriber` unterscheidet Pfad-Präfix).
- **Rate-Limiter:** Login, Registrierung und Einladung je eigener Limiter (mit großzügigem `when@test`-Block wie gehabt).
- Step-up-Guard prüft die Frische der letzten Verifikation serverseitig (nicht clientseitig manipulierbar).
- **Backup-Passkey-Gate:** Destruktive Aktionen (Ban/Unban, Ablehnen von Eintrag/Version, Nutzer einladen/deaktivieren) werden serverseitig mit 409 abgelehnt, solange der handelnde Account **weniger als 2** registrierte Passkeys hat. Die Anzahl wird aus den `WebAuthnCredential` abgeleitet (kein separates Flag).
- CSRF: Da SameSite=Strict + eigener CORS-Origin, ist CSRF stark eingedämmt; zusätzlich für zustandsändernde Cookie-Requests ein CSRF-Token oder das Erzwingen eines Custom-Headers (z. B. `X-Requested-With`), den Cross-Site-Formulare nicht setzen können.

## 9. Tests

- WebAuthn-Ceremonies in Tests **gemockt** (kein echter Authenticator): Registrierung, Login, Step-up-Frische.
- Rollen-Guards: Moderator wird auf Verwaltungs-/Ban-/Audit-Endpunkten mit 403 abgewiesen.
- Jede Moderationsaktion end-to-end über HTTP (approve/reject Entry+Version, resolve Report, ban/unban) inkl. **Audit-Log-Schreibung**.
- Einladungs-Flow: Invite anlegen → E-Mail via Symfony **Test-Transport** asserten → Registrierung mit gültigem/abgelaufenem/verbrauchtem Token.
- Session: Zugriff ohne Cookie → 401; abgelaufene Session → 401; Step-up nötig, wenn Verifikation zu alt.
- Backup-Passkey-Pflicht: zweiten Passkey hinzufügen; destruktive Aktion mit nur 1 Passkey → 409; Entfernen bis auf 2 ok, das Löschen unter 2 → 409; Login mit einem beliebigen der Passkeys.
- PHPUnit läuft weiter mit `failOnDeprecation="true"` (Exit-Code prüfen).

## 10. Deployment-Notizen

- Neue Doctrine-Migrationen (AdminUser, WebAuthnCredential, AdminInvite, AuditLogEntry).
- `security.yaml` neu; WebAuthn-Bundle-Konfiguration (RP-ID/-Name).
- Serverseitig in `.env.local`: `MAILER_DSN` (SMTP des Hosters), ggf. `WEBAUTHN_RP_ID=gestura.eu`. Deploy weiterhin via `deploy/deploy.sh` (Migrationen laufen dort schon).
- Bootstrap-Admin nach dem Deploy einmalig per CLI anlegen.
