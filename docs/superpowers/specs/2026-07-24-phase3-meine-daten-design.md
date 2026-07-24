# Phase 3 – Sub-Projekt E: »Meine Daten« (Selbstauskunft) – Design-Spec

> Baut auf A (Konto-Fundament ✓), F (Settings-Sync ✓) und C (Edit-Token-Migration ✓) auf. Phase-3-Stand: **A ✓ → F ✓ → C ✓ → E (dieses Dokument)**; D (Sterne-Bewertungen) folgt unabhängig danach.

## 1. Ziel

Das festgezurrte Prinzip »vollständige Selbstauskunft + sofortiges Löschrecht« in einem einzigen, maschinenlesbaren Endpunkt einlösen: `GET /api/account/data` liefert dem Konto-Inhaber alles, was der Server über sein Konto weiß – Konto-Metadaten, Sync-Blobs (**inklusive Chiffrat**), sowie die verknüpften Submitter mit ihren Einträgen als Referenzliste. Eigenständig über Funktionstests prüfbar, ohne Extension-Änderungen und ohne Web-Frontend.

## 2. Nicht-Ziele

- **Kein Web-Frontend**; keine Extension-Umsetzung (Client-Verhalten ist Vertrag, §7).
- **Keine neue Lösch-Semantik.** Das Löschrecht existiert bereits vollständig: `DELETE /api/account` (A) löscht das Konto, kaskadiert die Sync-Blobs (F, `ON DELETE CASCADE`) und setzt verknüpfte Submitter auf anonym (C, `ON DELETE SET NULL`). E fügt hier nichts hinzu (YAGNI); der Vertrag §7 beschreibt beides zusammen als »Meine Daten«-Ansicht.
- **Kein alternatives Exportformat** (kein CSV, kein Datei-Download-Header) – nur die JSON-Antwort.
- **Keine Entry-Payloads.** Einträge sind öffentlicher Index-Inhalt und über die Browse-API frei abrufbar; die Selbstauskunft nennt sie nur als Referenz (§4).
- D (Bewertungen) unberührt – bei dessen Umsetzung wird der `account`-Block hier um Bewertungen ergänzt (§4, Kopplungshinweis).

## 3. Endpunkt

| Methode | Pfad | Verhalten |
| --- | --- | --- |
| `GET` | `/api/account/data` | Bearer-authentifiziert über den bestehenden `AccountResolver`. `200` mit vollständiger Selbstauskunft (§4). `401` ohne/mit ungültigem Token. |

- **Auth:** ausschließlich `AccountResolver` – liefert Authentifizierung, `account_auth_ip`-DoS-Schutz und konstante-Zeit-Verifikation. **Kein** neuer Rate-Limiter (rein lesend, deckt `account_auth_ip` ab).
- **`lastSeenAt`-Berührung (bewusste Reihenfolge):** `AccountResolver::resolve()` überschreibt bei `touchLastSeen = true` (Default) das `lastSeenAt` **und flusht, bevor es das Konto zurückgibt** – ein normaler Aufruf würde also immer »jetzt« zurückliefern und das Feld in der Selbstauskunft nutzlos machen. Deshalb: Der Controller ruft `requireAccount($request, touchLastSeen: false)` (auflösen **ohne** Berührung), der Assembler liest den **echten vorherigen** `lastSeenAt`-Wert, und **erst danach** berührt der Controller explizit (`$account->lastSeenAt = new \DateTimeImmutable(); $em->flush();`), damit der Abruf – wie jeder Konto-Request – als Aktivität fürs Prune-Fenster zählt. Die zwei Berührungszeilen sind bewusst inline (dieselben wie im Resolver); eine DRY-Extraktion auf `AccountResolver::touch()` ist zulässig, aber nicht gefordert.
- Fehler als `application/problem+json` (Projektstandard).

## 4. Antwortstruktur

```json
{
  "account": {
    "createdAt": "2026-01-15T10:30:00+00:00",
    "lastSeenAt": "2026-07-20T14:22:00+00:00"
  },
  "sync": {
    "settings": { "version": 3, "updatedAt": "2026-07-20T14:22:00+00:00", "size": 1234, "ciphertext": "…" },
    "menus":    { "version": 1, "updatedAt": "2026-07-18T09:00:00+00:00", "size": 5678, "ciphertext": "…" }
  },
  "submitters": [
    {
      "tokenSelector": "a1b2c3d4e5f6a7b8",
      "approvedCount": 3,
      "banned": false,
      "createdAt": "2026-02-01T12:00:00+00:00",
      "entries": [
        {
          "formatId": "com.example.shop",
          "type": "menu",
          "status": "published",
          "createdAt": "2026-02-01T12:05:00+00:00",
          "versions": [
            { "semver": "1.0.0", "status": "approved" },
            { "semver": "1.1.0", "status": "pending" }
          ]
        }
      ]
    }
  ]
}
```

**Block `account`:** `createdAt`, `lastSeenAt` (Stand vor der Berührung, §3). **Kein** `tokenSelector`, **kein** `tokenHash` – das Konto identifiziert sich bereits über das Bearer-Token; der gespeicherte Hash ist abgeleitetes Geheimnis-Material, das dem Inhaber nichts nützt und nur die Angriffsfläche vergrößert. *(D-Kopplung: bei Umsetzung von D werden hier die abgegebenen Sterne-Bewertungen ergänzt.)*

**Block `sync`:** Objekt, dessen Schlüssel die belegten Collections sind (`settings`|`menus`|`engines`). Je Collection `{version, updatedAt, size, ciphertext}` – identisch zur F-Übersicht (`GET /api/account/sync`), **zusätzlich das `ciphertext`** (die Selbstauskunft enthält bewusst die Rohdaten). Leeres Konto ⇒ `{}` (als `(object)` serialisiert, F-Muster gegen `[]`-Fehlserialisierung). `size` = Byte-Länge des `ciphertext`.

**Block `submitters`:** Liste aller mit dem Konto verknüpften Submitter (`submitter.account === Konto`, aus C). Je Submitter:
- `tokenSelector` (öffentliche Token-Hälfte; identifiziert das Edit-Token eindeutig, **kein** Geheimnis), `approvedCount`, `banned`, `createdAt`.
- **kein** `tokenHash` (Geheimnis-Material, s. o.).
- `entries`: Liste der Einträge dieses Submitters – je Eintrag `{formatId, type, status, createdAt, versions}`; `versions` = alle `EntryVersion` als `{semver, status}`. **Keine** Payloads.
- Leeres Konto ⇒ `[]`.

## 5. Implementierung

- **Neuer Controller** `AccountDataController` (analog zu den Sync-Controllern in `src/Controller/Api/`), eine `__invoke`-Action; Route `GET /api/account/data`. Löst das Konto per `AccountResolver` auf und delegiert an den Assembler; hält keine Baulogik.
- **Neuer Assembler-Service** `AccountDataAssembler` (`src/Service/`), der aus dem `Account` das Array baut – hält den Controller dünn und ist ohne HTTP-Schicht testbar. Nutzt bestehende Repositories (alle Entities haben **public properties**, keine Getter):
  - `SyncBlobRepository::findBy(['account' => $account])` (F) für die Blobs.
  - `SubmitterRepository::findBy(['account' => $account])` (C) für die verknüpften Submitter.
  - **Wichtig – keine navigierbaren Inversen:** `Entry→Submitter` und `EntryVersion→Entry` sind **unidirektionale** `ManyToOne`-Beziehungen; `Submitter` hat **kein** `entries`-Collection, `Entry` hat **kein** `versions`-Collection. Die Einträge daher per `EntryRepository::findBy(['submitter' => $submitter])`, die Versionen per `EntryVersionRepository::findBy(['entry' => $entry])` laden.
  - **Enums:** `Entry.type` (`EntryType`), `Entry.status` (`EntryStatus`), `EntryVersion.status` (`VersionStatus`) werden per `->value` zum String serialisiert.
  - **N+1:** pro Submitter eine Entry-Query, pro Entry eine Version-Query. Die Datenmengen pro Konto sind klein (persönliche Einreichungen) – akzeptabel; keine vorzeitige Optimierung (YAGNI). Falls ein Fetch-Join gewünscht ist, gehört er in eine Repository-Methode, nicht in den Assembler.
- **Datumsformat:** ISO-8601 mit Offset (`DateTimeInterface::ATOM`), projektweit einheitlich (`createdAt`, `lastSeenAt`, `updatedAt`).
- Keine neue Entity, keine Migration, kein neuer Rate-Limiter.

## 6. Sicherheit / Grenzen

- Reiner Lesezugriff; keine Mutation (außer der `lastSeenAt`-Berührung durch den Resolver, wie jeder Konto-Request).
- **Konto-Isolation:** Die Selbstauskunft enthält ausschließlich Daten des authentifizierten Kontos – alle Queries filtern hart auf `account = <aufgelöstes Konto>`. Ein Test beweist, dass Blobs/Submitter eines Fremdkontos **nicht** erscheinen.
- **Kein `tokenHash`** in der Ausgabe (weder Konto noch Submitter) – explizite Negativ-Assertion im Test.
- Keine IP-Persistenz (nur der Resolver-Limiter-Cache). `account_auth_ip` bremst Enumeration/DoS.
- Performance unkritisch: drei Konto-gebundene Queries auf kleine Datenmengen.

## 7. Extension-Vertrag (hier dokumentiert, Umsetzung im Extension-Repo)

- Die Extension bietet im Konto-Bereich »Meine Daten« an: ruft `GET /api/account/data` ab und zeigt die Selbstauskunft menschenlesbar (Konto-Alter, letzter Zugriff, synchronisierte Collections mit Größe/Version, eigene Einreichungen mit Status).
- Der Roh-JSON ist zugleich der **Export**: die Extension bietet »Als Datei speichern« des unveränderten Response-Bodys an (das Chiffrat ist enthalten; zum Entschlüsseln braucht es den lokal gehaltenen Sync-Key aus F §6 – ohne ihn bleibt das Chiffrat opak, konsistent zum Zero-Knowledge-Prinzip).
- **Löschen** verweist auf das bestehende `DELETE /api/account` (A) – die Extension zeigt beide Aktionen (»Meine Daten ansehen/exportieren« und »Konto löschen«) in einer Ansicht; serverseitig sind es zwei Endpunkte, keine neue Lösch-Logik in E.

## 8. Tests

Muster aus `ApiTestCase` (Limiter-Cache-Reset), `AccountTest::createAccount()`-Idiom für Token, F-/C-Test-Idiome für Blobs und gemigrierte Submitter.

- **Auth:** ohne Token → `401`; mit ungültigem/gefälschtem Token → `401`.
- **Leeres Konto:** frisch angelegtes Konto → `200`, `account` mit beiden Zeitstempeln, `sync` == `{}` (nicht `[]`), `submitters` == `[]`.
- **Vollprofil (Kernnachweis):** Konto + zwei Sync-Blobs (verschiedene Collections) + ein per Claim (C) verknüpfter Submitter mit einem Eintrag und zwei Versionen. Assertion aller Felder exakt: Blob-`version`/`size`/`ciphertext` stimmen mit dem Geschriebenen überein; Submitter-`tokenSelector`/`approvedCount`/`banned`; Eintrag-`formatId`/`type`/`status`; beide Versionen mit korrektem `semver`/`status`.
- **Konto-Isolation:** Ein zweites Konto mit eigenem Blob + Submitter existiert; die Selbstauskunft von Konto A enthält **nichts** von Konto B (weder Blob noch Submitter).
- **Kein `tokenHash`:** explizite Assertion, dass weder im `account`- noch in den `submitters`-Blöcken ein `tokenHash`/Hash-Feld auftaucht.
- **`ciphertext` enthalten:** im Gegensatz zur F-Übersicht (`GET /sync`) trägt jeder `sync`-Eintrag hier das `ciphertext`-Feld (Assertion auf Anwesenheit + korrekten Wert).
- **`lastSeenAt`-Berührung (Reihenfolge):** Zwei aufeinanderfolgende Abrufe – der zweite Abruf meldet als `lastSeenAt` den Zeitpunkt, den der **erste** Abruf gesetzt hat (nicht »jetzt« des zweiten). Beweist zugleich, dass der Endpunkt als Aktivität zählt (Berührung erfolgt) **und** den vorherigen Wert meldet (Reihenfolge stimmt).
