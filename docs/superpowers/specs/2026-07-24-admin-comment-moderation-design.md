# Admin-Kommentar-Moderation – Design-Spec

> Folgeblock zu Phase-3 Sub-Projekt D (Sterne-Bewertungen, gemergt). D lieferte die Kommentar-Moderation bewusst **nur als CLI** (`index:comments:queue|approve|reject`). Dieses Teil-Sub-Projekt (»Teil 1« des Blocks »Admin-API + Frontend«) schließt die Lücke: Admin-API-Endpunkte + Admin-SPA-UI, 1:1 gespiegelt an der bestehenden Versions-Moderation. **Teil 2** (öffentliche, nur-lesende Rating-Anzeige + Reviews auf der Detailseite) folgt als eigener Zyklus.

## 1. Ziel

Ein Admin kann wartende Bewertungs-Kommentare (`commentStatus = pending`) über die bestehende Admin-SPA sehen, freigeben und ablehnen – ohne die CLI. Die Freigabe macht den Kommentar öffentlich (in `/reviews`); die Ablehnung blendet nur den Text aus, der Stern bleibt im Aggregat (aus D). Keine neue Domänenlogik – nur die Admin-HTTP-Schicht (Gates, Audit, Locking) + UI über den vorhandenen `ModerationService::approveComment/rejectComment`.

## 2. Nicht-Ziele

- **Keine öffentliche Rating-Anzeige** – das ist Teil 2 (eigener Spec/Plan/Zyklus).
- **Keine web-basierte Rating-Abgabe / kein End-Nutzer-Konto-Login auf der Website** – Konten (`gacc_`) bleiben extension-only (bestätigte Scope-Entscheidung).
- **Keine neue Domänenlogik** – `ModerationService::approveComment/rejectComment` (D) bleiben unverändert; hier entsteht nur die Admin-Schicht darum.
- Keine Erweiterung des Report-Mechanismus, keine Massen-Moderation.

## 3. Backend – Admin-API (spiegelt Versions-Moderation)

Alle Endpunkte liegen unter `^/api/admin` (bestehende Cookie-Firewall, `AdminSessionAuthenticator`, Idle-Timeout, `AdminUserChecker`); state-changing Requests verlangen den Header `X-Requested-With: XMLHttpRequest` (`AdminCsrfSubscriber`). Kein neuer Firewall-/Security-Code.

| Methode | Pfad | Muster / Gates |
| --- | --- | --- |
| `GET` | `/api/admin/comments` | `CommentQueueController` – listet wartende Kommentare via `RatingRepository::pendingComments()`: je `{id, formatId, stars, comment, createdAt}`. Ungeschützt-lesend (wie `QueueController`). |
| `POST` | `/api/admin/comments/{id}/approve` | `CommentApproveController` – spiegelt `VersionApproveController`: `wrapInTransaction` + `$em->lock($rating, PESSIMISTIC_WRITE)` + `refresh` + `ModerationService::approveComment` (Status-Guard als Sentinel abfangen → `409` NACH Commit) + `AuditLogger::log($actor, 'comment.approve', 'comment', (string) $id)`. **Kein** Step-up. |
| `POST` | `/api/admin/comments/{id}/reject` | `CommentRejectController` – spiegelt `VersionRejectController`: `BackupPasskeyGate::assertEnough($actor)` + `StepUpGuard::assertFresh()` **vor** der Transaktion, dann Lock + `rejectComment` + Sentinel-409 + `AuditLogger::log($actor, 'comment.reject', 'comment', (string) $id)`. |

- **Locking-Aggregat = die `Rating`-Zeile** (`$em->lock($rating, PESSIMISTIC_WRITE)` + `$em->refresh($rating)`): serialisiert paralleles Approve/Reject auf demselben Kommentar. Anders als bei Versionen gibt es keinen `currentVersion`-Zeiger zu schützen – nur den `commentStatus`-Übergang.
- **Sentinel-409-Muster (aus lessons.md):** Der Status-Guard (`nur pending`) aus `ModerationService` wird **innerhalb** der Closure gefangen und als Konfliktmeldung **zurückgegeben**; das `ApiProblem(409)` wirft der Controller erst **nach** dem (dann leeren) Commit – ein Throw aus `wrapInTransaction` würde den EntityManager schließen.
- `404`, wenn kein `Rating` mit der `id` existiert.

## 4. Frontend – Admin-SPA

- **`lib/admin/api.ts`:** drei dünne `adminFetch`-Wrapper analog zu `approveVersion`/`rejectVersion`:
  - `commentQueue()` → `GET /api/admin/comments` (Typ `PendingComment[]` mit `{id, formatId, stars, comment, createdAt}`).
  - `approveComment(id)` → `POST /api/admin/comments/{id}/approve`.
  - `rejectComment(id)` → `POST /api/admin/comments/{id}/reject`.
- **Queue-Seite (`/admin/queue`):** neue Sektion **»Kommentare«** neben den bestehenden Sektionen »Einträge« und »Versionen«. Je Kommentar: Eintrags-`formatId` (Link zur Admin-Eintragsdetailseite), Sterne, Kommentartext, Datum, plus **Freigeben**- und **Ablehnen**-Buttons.
  - **Freigeben** ruft `approveComment(id)` direkt.
  - **Ablehnen** läuft über `withStepUp(() => rejectComment(id))` – identisches Muster wie Version-Reject, inkl. `backup_required`-Fehlerbehandlung (Meldung »mind. 2 Passkeys nötig«).
  - Nach erfolgreicher Aktion wird die Queue neu geladen (wie bei Einträgen/Versionen).
- **i18n:** neue Strings in `messages/en.json` + `messages/de.json` (Sektions-Überschrift, Button-Labels, Fehlermeldungen) im bestehenden `admin_queue_*`-Namensschema.

## 5. Sicherheit

- Auth/CSRF/Idle-Timeout über die bestehende `^/api/admin`-Firewall – nichts Neues.
- **Approve** ist niedrig-geschützt (nur Audit + Lock), **Reject** trägt beide Gates (`BackupPasskeyGate` + frisches Step-up) – 1:1 wie Versions-/Eintrags-Reject (bestätigte Entscheidung). Reject ist reversibel im Sinne des Sterns (nur der Text wird ausgeblendet), wird aber wie die übrigen destruktiven Moderationsaktionen behandelt.
- Jede Mutation schreibt einen Audit-Eintrag (`comment.approve` / `comment.reject`) in derselben Transaktion wie die fachliche Änderung (Audit-Atomarität, lessons.md).

## 6. Tests

**Backend (Funktionstests, Muster aus den bestehenden Admin-Moderations-Tests, z. B. `ReviewFindingsTest`/Queue-Tests):**
- `GET /api/admin/comments` listet nur `pending`-Kommentare (approved/rejected/kommentarlos ausgeschlossen); ohne Admin-Session → 401.
- `approve` setzt `commentStatus = approved`, schreibt Audit `comment.approve`, `204`; zweiter Approve auf denselben (nun approved) Kommentar → `409` (Guard), EntityManager bleibt nutzbar (Sentinel-Muster).
- `reject` ohne frisches Step-up → **403** (`StepUpGuard`, `stepUpRequired`); mit weniger als 2 Passkeys → **409** (`BackupPasskeyGate`, `backupRequired`); im grünen Pfad (`loginWithCredentials` mit 2 Passkeys ⇒ frische Session) `commentStatus = rejected`, **Stern unverändert**, Audit `comment.reject`, `204`.
- `404` für unbekannte `id`.

**Frontend (Vitest, Muster der bestehenden `queue.test.ts`/`api.test.ts`):**
- `lib/admin/api.test.ts`: `commentQueue`/`approveComment`/`rejectComment` treffen die richtigen URLs/Methoden.
- Queue-Page-Test: Kommentar-Sektion rendert wartende Kommentare; Freigeben ruft `approveComment`; Ablehnen läuft über `withStepUp`; nach Aktion Reload.

## 7. Extension-/Vertrag-Hinweis

Nichts extern nötig – dieses Teilprojekt ist rein betreiberseitig (Admin). Die Rating-Abgabe (Extension) und die öffentliche Anzeige (Teil 2) sind davon unabhängig.
