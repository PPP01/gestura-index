# Phase 3 – Sub-Projekt D: Sterne-Bewertungen – Design-Spec

> Baut auf A (Konto-Fundament ✓), C (Edit-Token-Migration ✓, liefert Konto↔Submitter-Trust) und E (»Meine Daten« ✓, wird hier erweitert) auf. Phase-3-Stand: **A ✓ → F ✓ → C ✓ → E ✓ → D (dieses Dokument)**. Danach folgt als eigener Block **Admin-API + Frontend** (Rating-Anzeige/-Abgabe, Moderations-UI) – in D bewusst noch nicht enthalten.

## 1. Ziel

Angemeldete Konten können veröffentlichte Einträge mit **1–5 Sternen** und optional einem **kurzen Freitext-Kommentar** bewerten – genau eine Bewertung pro Konto und Eintrag. Der **Stern** fließt sofort in ein öffentliches Aggregat (Ø + Anzahl); der **Kommentar** durchläuft eine **Hybrid-Moderation nach Vertrauen** (vertrauenswürdige Konten sofort, neue in die Warteschlange). Eigenständig über Funktionstests prüfbar, ohne Extension-Änderungen und ohne Frontend.

## 2. Nicht-Ziele

- **Kein Frontend**, **keine Extension-Umsetzung** – nur Backend-API + dokumentierter Vertrag (§8). Rating-/Moderations-UI und **Admin-API** sind der unmittelbar folgende, separate Block.
- **Keine anonymen Bewertungen** – Sterne ausschließlich mit Konto (festgezurrter Beschluss). Anonyme Nutzung bleibt bei den aggregierten Install-Zählern.
- **Keine denormalisierten Zähler** auf `Entry` – Aggregate werden lesend berechnet (§4), um Drift bei Konto-/Eintrag-Löschung (inkl. `index:account:prune`-Bulk-DELETE) strukturell auszuschließen.
- **Kein Per-Kommentar-`Report`** – `Report` bleibt eintragsbezogen; Missbrauch nach Freigabe behandelt der Admin per Reject (§3). Eine feinere Meldelogik ist späteres Scope.
- Keine Bewertungs-Historie (nur aktueller Stand), keine mehrsprachigen Kommentare (ein String).

## 3. Datenmodell + Moderation

**Neue Entity `Rating`:**

| Feld | Typ | Zweck |
| --- | --- | --- |
| `id` | interne PK | – |
| `account` | ManyToOne → `Account`, FK **`ON DELETE CASCADE`** (DB-seitig) | Autor. Kaskade zwingend – `index:account:prune` löscht per DQL-Bulk-DELETE an der ORM vorbei (Muster aus F/A). |
| `entry` | ManyToOne → `Entry`, FK **`ON DELETE CASCADE`** (DB-seitig) | bewerteter Eintrag |
| `stars` | int (1–5) | Bewertung – zählt **immer sofort** ins Aggregat |
| `comment` | text, **nullable**, max. **500** Zeichen | optionaler Kurzkommentar |
| `commentStatus` | enum `CommentStatus` (`pending` \| `approved` \| `rejected`) | nur relevant wenn `comment !== null`; ohne Kommentar konventionell `approved` (nichts zu moderieren) |
| `createdAt` / `updatedAt` | datetime_immutable | – |

- **`UNIQUE(account_id, entry_id)`** – genau eine Bewertung pro Konto & Eintrag; ein zweites PUT aktualisiert dieselbe Zeile (Upsert).
- Neues Enum **`CommentStatus`** (eigenes Enum, nicht `VersionStatus` – semantisch getrennt).
- Neue Migration (`rating`-Tabelle, beide FKs `ON DELETE CASCADE`, Unique-Constraint).

**Hybrid-Moderation nach Vertrauen (nur der Kommentar):**

- Beim Schreiben/Ändern eines Kommentars entscheidet die aus C bekannte Trust-Aggregation: `SubmitterRepository::sumApprovedCountForAccount($account) >= SubmissionService::TRUST_THRESHOLD` (=3, wiederverwendet) ⇒ `commentStatus = approved` (sofort sichtbar); sonst `pending` (Warteschlange).
- **Der Stern zählt in beiden Fällen sofort** ins Aggregat; nur die Sichtbarkeit des Textes hängt an der Moderation.
- Wird ein Kommentar per Update **geändert**, wird der Status neu bewertet (geänderter Text durchläuft die Moderation erneut). Wird ein Kommentar auf `null` gesetzt, entfällt jede Moderation.
- **Admin-Aktionen:** `ModerationService::approveComment(Rating)` / `rejectComment(Rating)` (Guard: nur `pending` freigeb-/ablehnbar; Status-Wechsel + `flush()`, Muster wie `approveVersion`/`rejectVersion`). `rejectComment` blendet nur den **Text** aus (`commentStatus = rejected`); der **Stern bleibt** im Aggregat. `ModerationService` bekommt dafür eine `RatingRepository`-Abhängigkeit.
- **CLI** (analog zu den bestehenden Moderations-Commands `index:queue`/`index:approve`/…): `index:comments:queue` (listet Ratings mit `commentStatus = pending`), `index:comments:approve <id>`, `index:comments:reject <id>`. **Keine Admin-API in D** – konsistent damit, dass heute nur `ReportController` Admin-API ist und der Rest über CLI läuft; die Admin-API kommt im Folgeblock.

## 4. Aggregation (lesend, kein N+1)

- **`RatingRepository::aggregatesFor(int[] $entryIds): array<int, array{average: float, count: int}>`** – **eine** gruppierte Query (`SELECT entry_id, AVG(stars), COUNT(*) … WHERE entry_id IN (:ids) GROUP BY entry_id`) für eine ganze Listenseite bzw. den Detailabruf. Fehlende IDs ⇒ `{average: null, count: 0}` (kein Eintrag in der Map).
- **`EntrySerializer::toListItem(Entry, ?array $rating = null)`** und **`toDetail(Entry, array $versions, ?array $rating = null)`** bekommen einen optionalen `rating`-Parameter. Default `null` ⇒ Feld `rating: {"average": null, "count": 0}`. Bestehende Aufrufer (`AdminEntryDetailController`) bleiben dadurch unverändert lauffähig.
- `EntryListController` / `EntryDetailController` bestimmen die IDs der Ergebnisseite, holen die Aggregate in einer Query und reichen sie pro Eintrag an den Serializer. `average` wird auf **1 Nachkommastelle** gerundet ausgeliefert (`round($avg, 1)`), `count` ist die Gesamtzahl der Bewertungen (unabhängig vom Kommentar-Status). Ohne Bewertungen: `average: null`.
- **Immer korrekt, keine Drift:** Löscht eine Kaskade Rating-Zeilen (Eintrag- oder Konto-Löschung, prune), verschwinden sie aus der Aggregat-Query automatisch.

## 5. Endpunkte

| Methode | Pfad | Auth | Verhalten |
| --- | --- | --- | --- |
| `PUT` | `/api/v1/entries/{formatId}/rating` | Konto (`gacc_`) | Upsert eigener Bewertung. Body `{"stars": 1–5, "comment"?: string≤500}`. `200` mit `{stars, comment, commentStatus}`. Idempotent (2. PUT aktualisiert dieselbe Zeile). |
| `GET` | `/api/v1/entries/{formatId}/rating` | Konto | eigene Bewertung `{stars, comment, commentStatus, createdAt}` oder `404` |
| `DELETE` | `/api/v1/entries/{formatId}/rating` | Konto | eigene Bewertung entfernen, `204`; `404` wenn keine vorhanden |
| `GET` | `/api/v1/entries/{formatId}/reviews` | öffentlich | paginierte Liste **freigegebener** Kommentare: je Eintrag `{stars, comment, createdAt}`, **anonym** (kein Autor). Nur Ratings mit `comment !== null` und `commentStatus = approved`. |

- Auth über den bestehenden `AccountResolver` (Bearer `gacc_`, `account_auth_ip`-Schutz, konstante Zeit).
- **Guards bei PUT:** Eintrag nicht `published` → `404`; **eigener Eintrag** (`entry.submitter?->account === Konto`) → `403`; gesperrtes Konto-Bündel (`SubmitterRepository::hasBannedForAccount`) → `403`; ungültiges JSON / `stars` fehlt / keine Ganzzahl 1–5 → `400`; `comment` kein String / > 500 Zeichen → `400`; ungültiges/fehlendes Token → `401`. (Statuscode `400` für Validierung – projektweite Konvention, das Repo nutzt kein 422.)
- **Neuer Rate-Limiter `rating_write`** (nur PUT/DELETE): moderate Grenze pro IP, sliding_window; großzügig (1000) in `when@test`/`when@dev`. Missbrauchsbremse, keine Sicherheitskontrolle im engeren Sinn.
- `reviews` ist öffentlich cachebar (ETag/max-age wie Liste/Detail), Paginierung analog `EntryListController` (`page`/`perPage ≤ 50`).

## 6. Selbst-Rating-Sperre (Anti-Gaming)

- Ein Konto darf Einträge seiner **verknüpften Submitter** (aus C) nicht bewerten: PUT auf einen Eintrag mit `entry.submitter->account === Konto` ⇒ `403`.
- **Best-Effort, dokumentiert:** Hat das Konto einen Eintrag **anonym** (Submitter nicht mit dem Konto verknüpft) eingereicht, lässt sich die Urheberschaft nicht zuordnen – die Sperre greift dann nicht. Das ist der bewusste Preis der Datensparsamkeit und im Vertrag (§8) vermerkt.

## 7. Kopplung an E (»Meine Daten«)

`AccountDataAssembler` (aus E) bekommt einen neuen Block **`ratings`**: je eigener Bewertung `{formatId, stars, comment, commentStatus, createdAt}` – über `RatingRepository::findBy(['account' => $account])`. Erfüllt den in der E-Spec vermerkten D-Kopplungshinweis. Leeres Konto ⇒ `[]`. Kein Autor-Fremddatenleck (nur eigene Ratings des authentifizierten Kontos).

## 8. Extension-Vertrag (hier dokumentiert, Umsetzung im Extension-/Frontend-Repo)

- Auf einer Eintrags-Detailseite zeigt der Client Ø-Sterne + Anzahl (aus `rating` in Liste/Detail) sowie – aufklappbar – die freigegebenen Kommentare (`/reviews`).
- Ein angemeldetes Konto kann seine Bewertung abgeben/ändern/entfernen (PUT/GET/DELETE). Nach dem Absenden eines Kommentars kommuniziert der Client den `commentStatus` (`pending` ⇒ »wird geprüft«).
- **Kommentar-PII-Warnung:** Kommentare sind öffentlich und anonym gelistet – der Client weist beim Verfassen darauf hin, keine personenbezogenen Daten einzutragen.
- Selbst-Rating ist serverseitig gesperrt (§6); der Client blendet die Abgabe für eigene Einträge idealerweise aus.

## 9. Sicherheit / Grenzen

- Nur mit Konto bewertbar; Selbst-Rating gesperrt (§6). Gesperrtes Konto-Bündel kann nicht bewerten (§5).
- Reviews anonym – keine Konto-Identität öffentlich. Kommentar-Text ist nutzergeneriert; PII-Warnung im Vertrag.
- Kaskaden: Rating-Zeilen verschwinden bei Eintrag- **und** Konto-Löschung (inkl. `index:account:prune`) via DB-`ON DELETE CASCADE`; Aggregate stimmen automatisch (lesend, §4).
- Keine IP-Persistenz (nur Limiter-Cache). `comment` ≤ 500 Zeichen serverseitig erzwungen (400).

## 10. Tests

Muster aus `ApiTestCase` (Limiter-Cache-Reset, `createPublishedEntry`, `createSubmitterWithToken`), `AccountTest::createAccount()`-Idiom für Konto-Token, `AccountClaimTest`-Idiom für konto-verknüpfte Submitter.

- **Rating-Upsert:** PUT (stars) → `200`, GET liefert es; zweites PUT ändert Sterne (kein zweiter Datensatz, Aggregat konsistent); DELETE → `204`, danach GET `404`.
- **Aggregat:** zwei Konten bewerten denselben Eintrag → Liste/Detail liefern korrekten `average`/`count`; ein Eintrag ohne Bewertungen → `{average: null, count: 0}`.
- **Selbst-Rating:** Konto bewertet Eintrag seines verknüpften Submitters → `403`.
- **Ban-Bündel:** gesperrtes Konto-Bündel → PUT `403`.
- **Unveröffentlicht:** PUT auf nicht-`published` Eintrag → `404`.
- **Trust-Hybrid:** vertrauenswürdiges Konto (aggregierter approvedCount ≥ Schwelle) → Kommentar sofort `approved` und in `/reviews`; neues Konto → Kommentar `pending`, **nicht** in `/reviews`, aber **Stern zählt** im Aggregat.
- **Reviews:** listet nur `approved`-Kommentare, anonym (kein Autor-Feld); Paginierung; Ratings ohne Kommentar erscheinen nicht in `/reviews`.
- **Validierung:** `stars` 0/6/„x" → `400`; Kommentar > 500 Zeichen → `400`; fehlendes/ungültiges Token → `401`.
- **Kaskaden-Regression:** Eintrag-Löschung **und** Konto-Löschung (Endpoint + `index:account:prune`) ⇒ Rating-Zeilen weg, Aggregat des Eintrags fällt auf `{null, 0}`.
- **E-Kopplung:** `GET /api/account/data` enthält den `ratings`-Block mit den eigenen Bewertungen inkl. `commentStatus`.
- **CLI-Moderation:** `index:comments:queue` listet Pending; `index:comments:approve`/`reject` wechseln den Status (Reject blendet Text aus, Stern bleibt); Guard: nur Pending moderierbar.
