# Phase 3 – Sub-Projekt C: Edit-Token-Migration – Design-Spec

> Baut auf Sub-Projekt A (Konto-Fundament, gemergt) auf. Phase-3-Stand: **A ✓ → F ✓ → C (dieses Dokument)**; D (Bewertungen) und E (»Meine Daten«) folgen unabhängig. E profitiert direkt von C (Konto kennt danach seine Einreichungen).

## 1. Ziel

Bestehende anonyme Einreichungen (Submitter mit `gsti_`-Edit-Token) lassen sich in das End-Nutzer-Konto (`gacc_`) überführen, und das Konto wird zum vollwertigen Einreicher-Ersatz: **(1) Claim** verknüpft alte Edit-Tokens mit dem Konto, **(2) Verwalten** (Update/Delete/Screenshot) funktioniert danach mit dem Konto-Token, **(3) Neu-Einreichen** funktioniert direkt mit dem Konto-Token – inklusive Weiterwirken des verdienten Vertrauens. Eigenständig über Funktionstests prüfbar, ohne Extension-Änderungen und ohne Web-Frontend.

## 2. Nicht-Ziele

- Kein Web-Frontend; keine Extension-Umsetzung (Client-Verhalten ist Vertrag, §8).
- Kein Unclaim-/Entzug-Endpunkt (später, falls nötig – Konto-Löschung deckt den Rückweg ab, §4).
- Kein Trust-Umbau auf Konto-Ebene (`approvedCount` bleibt pro Submitter; Aggregation nur lesend, §6).
- D (Bewertungen) und E (»Meine Daten«) unberührt.

## 3. Datenmodell

**`Submitter`-Erweiterung** (einzige Schema-Änderung):

| Feld | Typ | Zweck |
| --- | --- | --- |
| `account` | ManyToOne auf `Account`, **nullable**, FK **`ON DELETE SET NULL`**, Index | Zugehöriges Konto oder `null` (klassischer anonymer Submitter) |

- Ein Konto bündelt beliebig viele Submitter; ein Submitter gehört maximal einem Konto.
- **`ON DELETE SET NULL`:** Konto-Löschung (Löschrecht, inkl. `index:account:prune`-Bulk-DELETE) lässt Submitter samt Einträgen als anonyme Edit-Token-Submitter zurück – nichts geht verloren, die Einträge bleiben über das (weiterhin gültige) Edit-Token verwaltbar.
- Entries, Moderationszählung (`approvedCount`-Inkrement) und `banned` bleiben unverändert am Submitter.

## 4. Claim (`POST /api/account/claims`)

Besitzbeweis **beider** Geheimnisse in einem Request: `Authorization: Bearer gacc_…` (Konto) + Body `{"editToken": "gsti_…"}`.

- Die Edit-Token-Prüfung durchläuft **dieselbe Schutzkette wie `SubmitterResolver`**: `token_auth_ip`-Limit VOR der Argon2id-Verifikation, konstante Zeit via Dummy-Hash, `token_auth`-Limit pro IP+Selector. Keine neuen Limiter.
- Ergebnisse:
  - `200 {"entries": n}` – verknüpft; `n` = Anzahl der Einträge des Submitters. **Idempotent:** erneuter Claim durch dasselbe Konto → ebenfalls `200`.
  - `409` – Submitter gehört bereits einem **anderen** Konto.
  - `403` – Submitter ist gesperrt (ein gesperrter Ruf lässt sich nicht in ein Konto einbringen).
  - `401` – ungültiges Konto- oder Edit-Token; `400` – fehlender/formal ungültiger Body.
- Das Edit-Token **bleibt nach dem Claim gültig** (beide Wege parallel; die Extension kann es später selbst aufräumen).

## 5. Verwalten mit dem Konto-Token

`SubmitterResolver::requireOwner()` (genutzt von Update/Delete/Screenshot) akzeptiert zusätzlich zum `gsti_`-Header einen **`gacc_`-Header**:

- Präfix-Weiche im Authorization-Header: `gsti_` → bestehende Edit-Token-Logik (unverändert, inkl. konstanter Zeit); `gacc_` → Konto via bestehendem `AccountResolver` auflösen, Eigentum = `entry->submitter->account` ist genau dieses Konto.
- Fremder Eintrag → `403`; Ban-Regeln siehe §7.

## 6. Neu-Einreichen mit dem Konto-Token + Trust-Aggregation

- Submit mit `gacc_`-Header hängt den Entry an einen **konto-verknüpften Submitter** – deterministisch: der **älteste nicht gesperrte** verknüpfte Submitter wird wiederverwendet (keine Marker-Spalte nötig); nur wenn das Konto **keinen** hat, wird lazy einer angelegt – **mit** generiertem Edit-Token, das einmalig in der Response mitkommt (Rückfall-Credential, konsistent zum SET-NULL-Verhalten in §3). `approvedCount` wächst am jeweils genutzten Submitter normal.
- **Trust-Aggregation:** Für die Entscheidung »sofort publizieren vs. Warteschlange« zählt bei Konto-Einreichungen die **Summe der `approvedCount` aller nicht gesperrten Submitter des Kontos** (eine Repository-Query). Migrierter Ruf wirkt sofort; die Moderations-Zählung selbst bleibt pro Submitter.
- Der Supply-Chain-Schutz bleibt unberührt: Einreichungen mit `transformCode` gehen **immer** in die Warteschlange, unabhängig vom Trust.

## 7. Ban-Semantik (Bündel-Wirkung)

Ist **irgendein** mit dem Konto verknüpfter Submitter gesperrt, sind **Konto-Einreichungen und konto-basiertes Verwalten blockiert** (`403`) – wer Trust aggregiert, aggregiert auch Sperren; eine Sperre gegen ein Token wirkt gegen das ganze Bündel. Die Edit-Token-Pfade behalten ihre bestehende Per-Submitter-Ban-Prüfung.

## 8. Extension-Vertrag (hier dokumentiert, Umsetzung im Extension-Repo)

- Die Extension bietet nach Konto-Anlage an, gespeicherte Edit-Tokens per Claim zu überführen (pro Token ein Request).
- Nach erfolgreichem Claim kann sie fürs Verwalten das Konto-Token verwenden; das Edit-Token behält sie als Rückfall (oder räumt es bewusst auf).
- Beim Konto-Submit liefert der Server einmalig ein Edit-Token als Rückfall-Credential mit – die Extension speichert es wie bisherige Edit-Tokens (Backup-Export-Regeln aus A §8 gelten).

## 9. Tests

- **Claim:** happy path (`200`, korrektes `entries`-Count); Idempotenz (zweiter Claim dasselbe Konto → `200`); fremdes Konto → `409`; gesperrter Submitter → `403`; ungültiges Edit-Token → `401`; fehlender Body → `400`.
- **Verwalten:** Update/Delete eines verknüpften Entry per `gacc_` → Erfolg; fremder Entry per `gacc_` → `403`; klassischer `gsti_`-Weg unverändert (Regression).
- **Konto-Submit:** ohne verknüpften Submitter wird einer angelegt (Response enthält `editToken`); beim zweiten Submit wird er wiederverwendet (kein weiterer Submitter, kein weiteres Token); hat das Konto bereits einen gemigrierten Submitter, wird DIESER genutzt (kein neuer, kein Token in der Response).
- **Trust-Aggregation:** Konto mit migriertem Submitter über der Schwelle ⇒ Konto-Submit publiziert sofort; unter der Schwelle ⇒ Warteschlange; `transformCode` ⇒ immer Warteschlange.
- **Ban-Bündel:** ein gesperrter verknüpfter Submitter blockiert Konto-Submit und konto-basiertes Verwalten (`403`).
- **Kaskaden-Regression:** Konto-Löschung (Endpoint **und** `index:account:prune`) ⇒ `account`-FK der Submitter wird `NULL`, Entries bleiben, Edit-Token funktioniert weiter.
