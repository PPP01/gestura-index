# Phase 3 – Sub-Projekt A: End-Nutzer-Konto-Fundament – Design-Spec

> Erstes Sub-Projekt von Phase 3. Phase 3 zerfällt in mehrere unabhängige Bausteine: **A. Konto-Fundament (dieses Dokument)** → B. Geräte-Transfer (hier als Vertrag gelöst) → C. Edit-Token-Migration → D. Sterne-Bewertungen → E. »Meine Daten« → F. E2E-Settings-Sync. A ist die Wurzel: Identität + Auth-Realm, auf der die anderen aufbauen.

## 1. Ziel

Ein leichtgewichtiges, anonymes **End-Nutzer-Konto** als Fundament für spätere Phase-3-Features (v. a. Settings-Sync). Das Konto wird aus der Gestura-Extension heraus **komplett ohne Nutzerdaten** angelegt, ist über ein Bearer-Geheimnis (»Konto-Token«) nutzbar und über den bestehenden Extension-Export/Import auf weitere Geräte übertragbar. Eigenständig über Funktionstests gegen die Endpunkte prüfbar – ohne Extension-Änderungen und ohne Web-Frontend.

## 2. Nicht-Ziele (explizit, gegen Scope-Creep)

- **Kein Inhalte-Sync/Merge** (Suchmaschinen/Menüs zusammenführen inkl. Tombstones) – das ist Sub-Projekt **F**. Siehe §10 für die dokumentierte Vorgabe an F.
- **Kein Passkey-Upgrade** und **keine Token-Rotation** – bewusst später (Rotation ist an die Sync-Schlüsselverwaltung in F gekoppelt).
- **Keine** Submission-Besitz-Verknüpfung/Migration (C), Bewertungen (D), »Meine Daten« (E).
- **Kein Web-Frontend** in diesem Spec (evtl. später). **Keine** Extension-Umsetzung – die Extension-Seite ist nur als **Vertrag** dokumentiert (§8); Umsetzung erfolgt im separaten Extension-Repo.
- Kein Live-Geräte-Kopplungs-Subsystem (ephemerer Relay/SAS/`PairingSession`) – durch die Backup-Export-Entscheidung (§8) entfallen.

## 3. Festgezurrte Prinzipien (Bezug)

- **Strikte Trennung Admin ↔ End-Nutzer:** eigener Entity-Typ (**nicht** `AdminUser`), keine Rolle/kein Admin-Flag, keine Verknüpfung der Identitäten, eigener cookieloser Auth-Realm. End-Nutzer-Sessions sind der `^/api/admin`-Firewall strukturell nicht präsentierbar.
- **Datensparsamkeit:** keine E-Mail, kein Username, keine IP-Persistenz, sofortiges Löschrecht.
- **Ausschließlich Passkey / keine Passwörter:** Das 256-Bit-Bearer-Token ist ein Capability-Token (Maschinen-Geheimnis, nie vom Menschen getippt/gemerkt), kein schwaches Passwort – konsistent mit dem bestehenden Edit-Token.
- **Zero-Knowledge-Vorbereitung:** Der Server speichert nur den Argon2id-Hash des Token-Verifiers, nie das Klartext-Geheimnis – Voraussetzung, damit F (Sync) zero-knowledge bleiben kann.

## 4. Datenmodell (neue Doctrine-Entity)

**`Account`** (eigenständig, keine Verknüpfung zu `AdminUser`):

| Feld | Typ | Zweck |
| --- | --- | --- |
| `id` | interne PK | nie nach außen exponiert |
| `selector` | string(16), unique-indiziert | Klartext-Lookup-Teil des Tokens (O(1)-Suche) |
| `verifierHash` | string | Argon2id-Hash des Verifiers (Klartext-Verifier nie gespeichert) |
| `createdAt` | datetime_immutable | Erstellzeitpunkt |
| `lastSeenAt` | datetime_immutable | grob, nur fürs Aufräumen inaktiver Konten (§7) |

Kein Username, keine E-Mail, kein Passkey (Upgrade später). Zustände: nur *existiert* / *gelöscht*, kein `pending`/`invited` (Self-Service ist sofort aktiv).

## 5. Auth-Realm (cookielos, bearer)

Spiegelt das bewährte Selector/Verifier-Muster (`EditTokenService`/`InviteTokenService` + `SubmitterResolver`).

- **`AccountTokenService`** – erzeugt Token im Format `gacc_<selector16hex>_<verifier43base64url>` (neuer, distinkter Präfix **`gacc_`** neben `gsti_`/`gsta_`); persistiert nur Selector + Argon2id(Verifier).
- **`AccountResolver`** – parst das Token, Selector-Lookup, **konstante-Zeit**-Argon2id-Verifikation gegen einen Dummy-Hash (Timing-Oracle-Schutz), aktualisiert `lastSeenAt`. **Per-IP-Limit vor** der teuren Argon2id-Verifikation (DoS-Schutz), analog `token_auth_ip`.
- Endpunkte unter eigenem Namespace `/api/account/...`; Authentifizierung per `Authorization: Bearer gacc_…`. **Cookielos & stateless** – funktioniert für die Extension (Header) und ist strukturell getrennt von der Cookie-Admin-Firewall und der anonymen öffentlichen API. Keine Subdomain nötig.

## 6. Endpunkte (`/api/account/*`)

| Methode | Pfad | Auth | Zweck |
| --- | --- | --- | --- |
| `POST` | `/api/account` | keine | Anonymes Konto anlegen; gibt das Token `gacc_…` **einmalig** im Response zurück (nie wieder abrufbar). Kein Request-Body. Per-IP-Limit `account_create`. |
| `GET` | `/api/account/me` | Bearer | Token-Gültigkeit prüfen; liefert minimal `{ "createdAt": … }`, sonst `401`. Die Extension erkennt so ein gelöschtes/ungültiges Token. |
| `DELETE` | `/api/account` | Bearer | **Sofortiges Löschen** (festgezurrtes Löschrecht); entfernt die Konto-Zeile, `204`. Idempotent gegenüber bereits gelöschten Token (dann `401` mangels gültiger Auth). |

Fehler als `application/problem+json` (bestehendes `ProblemJsonSubscriber`-Muster).

## 7. Aufräumen inaktiver Konten

Konsolen-Command **`index:account:prune`**: löscht Konten, deren `lastSeenAt` älter als eine konfigurierbare Frist ist (Default z. B. 12 Monate). Datensparsamkeit. **Kopplung zu F:** Sobald Sync-Blobs existieren, müssen sie beim Prune mitgelöscht werden – hier notiert, dort umgesetzt.

## 8. Geräte-Transfer & Export-Vertrag (Extension-Repo, hier dokumentiert)

Der Gerätewechsel nutzt den **bestehenden Extension-Export/Import (Phase 1)** statt eines eigenen Kopplungs-Subsystems.

- **Zwei getrennte Export-Arten:**
  - **Teilen/Einreichen-Export** (einzelnes Menü/Engine): enthält **NIEMALS** das Konto-Token – nur öffentliche Inhalte.
  - **Backup/Migrations-Export** (eigene Komplettsicherung): enthält das Token, **deutlich als sensibel markiert** (»diese Datei kann dein Konto steuern«).
- **Identität beim Import:** Ein Backup-Import bringt das Token aufs neue Gerät → dasselbe Konto. Ein evtl. vorheriges (anonymes) Konto des Zielgeräts wird dadurch verwaist und später vom Prune-Command aufgeräumt. Ein Konto »gewinnt«.
- **Inhalte** (bereits vorhandene Menüs/Engines auf dem Zielgerät) werden **nicht** hier zusammengeführt – das ist der Sync-Merge in F (§10).
- Der Backup-Export dient zugleich als **Backup/Recovery** (Token-Verlust = Kontoverlust; die Datei ist das nutzergehaltene Recovery-Artefakt).

## 9. Sicherheit

- **`account_create`** – Per-IP-Limit gegen Massen-Erstellung (Vorschlag: 10 / Stunde, sliding_window); in `rate_limiter.yaml`, großzügig (1000) in `when@test`/`when@dev`.
- **`account_auth_ip`** – Per-IP-Limit **vor** der Argon2id-Verifikation (DoS). Da selbst eine Sicherheitskontrolle → im `when@test`-Block **echtes** Limit (Tests mit distinkten Client-IPs, analog `report_per_entry`).
- **Konstante-Zeit-Verifikation** via Dummy-Hash (Timing-Oracle), wie `SubmitterResolver`.
- **Keine IP-Persistenz** (nur Limiter-Cache). Token **einmalig** bei Erstellung ausgegeben, danach serverseitig nicht mehr rekonstruierbar (nur Hash gespeichert).
- **`gacc_`-Guard bei Einreichungen (Verteidigung in der Tiefe):** Enthält eingereichter Inhalt (submit/update) **irgendwo** einen `gacc_`-Muster-String (z. B. versehentlich in `name`/`description`/`url`), wird die Einreichung **abgelehnt** – ein Konto-Token darf nie über den öffentlichen Index austreten. Ergänzt die ohnehin strikte Schema-Validierung.

## 10. Nicht in diesem Spec – Vorgabe an F (Settings-Sync)

Der laufende Inhalte-Abgleich ist F. Dokumentierte Vorgabe, damit A dafür trägt:

- **Anker:** Alle Geräte mit demselben Konto-Token sind dasselbe Konto → der natürliche Sync-Anker.
- **Merge-Strategie (Skizze):** je Eintrag über die format-nativen Felder – gleiche `id` → höhere SemVer-`version` gewinnt (Gleichstand: Nutzer entscheidet / lokale Kopie behalten); unterschiedliche `id` → Vereinigung (beide behalten). **Löschungen** brauchen **Tombstones** (»gelöscht« vs. »nie existiert« ist sonst nicht unterscheidbar) – Kernentscheidung für F.
- Baut auf der Phase-1-Import-UX auf (»Standard ersetzen« vs. »neu hinzufügen«), verallgemeinert zu automatischem, wiederholbarem Abgleich.
- Zero-Knowledge bleibt möglich, weil der Server nie das Token-Klartext-Geheimnis kennt (§3).

## 11. Tests

Muster aus `ApiTestCase` (Limiter-Cache-Reset in `setUp`), `InMemoryStorage` für isolierte Limiter-Tests.

- Konto anlegen → `201` + wohlgeformtes `gacc_`-Token.
- `me` mit gültigem Token → `200` + `createdAt`; mit ungültigem/gelöschtem → `401`.
- `delete` → `204`, danach ist dasselbe Token ungültig.
- `account_create`-Limit greift (Massen-Erstellung).
- `account_auth_ip`-Limit greift **vor** Argon2id (isoliert, echtes Limit, distinkte Client-IPs).
- Konstante-Zeit-Pfad: unbekannter Selector löst dennoch Dummy-Verifikation aus (kein Timing-Oracle) – wie `SubmitterResolverTest`.
- `gacc_`-Submission-Guard: Einreichung mit `gacc_`-String irgendwo → abgelehnt.
- `index:account:prune` löscht nur hinreichend alte Konten.
