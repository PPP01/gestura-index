# Phase 3 – Sub-Projekt F: E2E-Settings-Sync – Design-Spec

> Baut direkt auf Sub-Projekt A (End-Nutzer-Konto-Fundament, gemergt) auf. Reihenfolge Phase 3: **A ✓ → F (dieses Dokument)**; C (Edit-Token-Migration), D (Bewertungen), E (»Meine Daten«) folgen unabhängig.

## 1. Ziel

Zero-Knowledge-Synchronisierung der Gestura-Extension-Konfiguration über das anonyme End-Nutzer-Konto (A): Der Server speichert ausschließlich **clientseitig verschlüsselte, opake Blobs** mit optimistischer Nebenläufigkeitskontrolle. Verschlüsselung, Entschlüsselung und der komplette Inhalte-Merge laufen **clientseitig** in der Extension. Eigenständig über Funktionstests gegen die Endpunkte prüfbar – ohne Extension-Änderungen und ohne Web-Frontend.

## 2. Nicht-Ziele

- **Keine Extension-Umsetzung** – Krypto- und Merge-Vertrag (§6, §7) werden hier nur dokumentiert; Umsetzung erfolgt im separaten Extension-Repo.
- **Kein Web-Frontend.** **Keine Schlüsselrotation** (später, zusammen mit Token-Rotation). **Keine Blob-Historie** (nur aktueller Stand + Versionszähler). **Kein serverseitiger Merge** (unmöglich by design – Server sieht nur Chiffrat).
- Sub-Projekte C/D/E bleiben unberührt.

## 3. Architektur-Prämisse (aus dem Zero-Knowledge-Prinzip)

Der Server **kann nicht mergen** – er sieht nur Chiffrat. Daraus folgt die Arbeitsteilung:

- **Backend = dummer Blob-Store:** speichern, ausliefern, Versionszähler führen, Konflikte per 409 melden. Kein Entschlüsseln, keine Struktur-Kenntnis, keine Envelope-Validierung (bewusst: kaputtes Chiffrat ist ein Client-Problem).
- **Extension = gesamte Intelligenz:** Schlüssel halten, ver-/entschlüsseln, mergen (id+version+Tombstones), Konflikt-Retry.
- Sync-Zyklus des Clients: `GET` → entschlüsseln → lokal mergen → verschlüsseln → `PUT` mit `baseVersion`; bei `409` Zyklus wiederholen (Merge ist idempotent, Wiederholung konvergiert).

## 4. Datenmodell (neue Doctrine-Entity)

**`SyncBlob`:**

| Feld | Typ | Zweck |
| --- | --- | --- |
| `id` | interne PK | – |
| `account` | ManyToOne auf `Account`, FK **`ON DELETE CASCADE`** (DB-seitig) | Besitzer. Kaskade zwingend: `index:account:prune` löscht per DQL-Bulk-DELETE an der ORM vorbei (dokumentierter Vorbehalt aus A – hiermit aufgelöst) |
| `collection` | string(16) | benannter Slot; Whitelist `settings` \| `menus` \| `engines`; `UNIQUE(account_id, collection)` |
| `ciphertext` | LONGTEXT | opakes Chiffrat (base64-Envelope, §6) – max. 256 KB |
| `version` | int | monoton steigender Zähler für optimistisches Locking |
| `updatedAt` | datetime_immutable | einzige Metadaten-Zeitspur |

## 5. Endpunkte (`/api/account/sync/*`)

Alle bearer-authentifiziert über den bestehenden `AccountResolver` (liefert Auth, `account_auth_ip`-DoS-Schutz und konstante-Zeit-Verifikation). Fehler als `application/problem+json`.

| Methode | Pfad | Verhalten |
| --- | --- | --- |
| `GET` | `/api/account/sync` | Übersicht ohne Chiffrat: je belegter Collection `{version, updatedAt, size}` – billiger Änderungs-Poll |
| `GET` | `/api/account/sync/{collection}` | `200 {"version": n, "ciphertext": "…"}`; `404` wenn Slot leer |
| `PUT` | `/api/account/sync/{collection}` | Body `{"baseVersion": n, "ciphertext": "…"}`. `baseVersion` == Server-Stand (bzw. `0` bei leerem Slot) → speichern, `version + 1`, `200 {"version": n+1}`. Sonst **`409 {"version": <aktuell>}`**, Blob unverändert |
| `DELETE` | `/api/account/sync/{collection}` | Slot leeren, `204`; `404` wenn bereits leer. Teil des Löschrechts |

Validierung: unbekannte Collection → `400`; `ciphertext` > 256 KB → `413` (vor jedem DB-Schreiben); `baseVersion` fehlt / keine Ganzzahl ≥ 0 → `400`; `ciphertext` fehlt / kein String → `400`.

Nebenläufigkeit: Der Vergleich `baseVersion` ↔ gespeicherte `version` und das Inkrement laufen atomar (Transaktion; bei Bedarf pessimistische Sperre auf der Blob-Zeile analog zu den Moderations-Locks), damit zwei parallele PUTs nicht beide »gewinnen«.

## 6. Krypto-Vertrag (Extension-Repo, hier dokumentiert)

- **Sync-Key:** Die Extension erzeugt einmalig einen zufälligen 256-Bit-Key (`crypto.getRandomValues`), gespeichert neben dem Konto-Token. Er wird **nie** an den Server gesendet – deshalb echtes Zero-Knowledge auch gegenüber dem Betreiber (das Konto-Token allein genügt NICHT: der Server empfängt es bei jeder Anfrage im Authorization-Header und könnte einen daraus abgeleiteten Schlüssel mitschneiden).
- **Backup-Export:** Der Sync-Key wandert wie das Token in den Backup/Migrations-Export (sensibel markiert). Gerät 2 kann nach Import sofort entschlüsseln. Verlust von Token+Key ohne Backup ⇒ Sync-Daten unlesbar – Preis von Zero-Knowledge, in der Extension-UI klar zu kommunizieren.
- **Verschlüsselung:** WebCrypto (buildfrei, kein Zusatz-Lib), **AES-256-GCM**, frischer zufälliger 12-Byte-IV **pro Upload**. Envelope als `ciphertext`-String: `{"v": 1, "alg": "A256GCM", "iv": "<base64>", "data": "<base64>"}`. Unbekanntes `v` ⇒ Client bricht mit klarer Meldung ab (Format-Evolution ohne Rätselraten).
- **AAD:** `collection` + Konto-Selector gehen als Additional Authenticated Data in GCM ein – ein Blob lässt sich nicht unbemerkt in einen anderen Slot oder ein anderes Konto kopieren (Swap-Schutz).

## 7. Merge-Vertrag (Extension-Repo, hier dokumentiert – konkretisiert §10 aus A)

- **`menus` / `engines`** (Listen): gleiche `id` → höhere SemVer-`version` gewinnt (Gleichstand: lokale Kopie behalten, kein Dialog im Automatik-Sync); unterschiedliche `id` → Vereinigung (beide behalten).
- **Tombstones:** Löschen erzeugt `{id, deletedAt}` statt stillem Entfernen. Ein Tombstone schlägt jede Version desselben Eintrags, solange er lebt. Aufbewahrung **90 Tage**, danach räumt der Client ihn aus. Dokumentierter Kompromiss: ein Gerät, das länger als 90 Tage offline war, re-importiert einen gelöschten Eintrag schlimmstenfalls – dafür wächst die Löschliste nicht unbegrenzt.
- **`settings`** (ein Objekt, keine Listen): **Last-Writer-Wins pro Sync** anhand eines `modifiedAt`-Zeitstempels im Klartext-Payload (liegt im Chiffrat, nicht serverseitig). Feld-Level-Merge wäre bei dem kleinen Settings-Objekt Overengineering.

## 8. Sicherheit / Grenzen (Backend)

- **`sync_write`**-Rate-Limiter (nur PUT/DELETE): 120/h pro IP, sliding_window; großzügig (1000) in `when@test`/`when@dev` – Missbrauchsbremse (Storage-/Write-Amplification), keine Sicherheitskontrolle im engeren Sinn.
- 256-KB-Limit serverseitig erzwungen (413). Rationale: die Extension-Daten liegen heute komplett in `chrome.storage.sync` (100-KB-Quota) – 256 KB verschlüsselt+base64 ist großzügig, verhindert aber Missbrauch als Datei-Hoster.
- Collection-Whitelist hart (kein Free-Form-Storage).
- Keine IP-Persistenz (nur Limiter-Cache). Server behandelt `ciphertext` als opaken String.

## 9. Tests

Muster aus `ApiTestCase` (Limiter-Cache-Reset), `AccountTest::createAccount()`-Idiom für Token.

- Roundtrip: PUT (baseVersion 0) → 200 version 1 → GET liefert Chiffrat+version → PUT (baseVersion 1) → version 2.
- Konflikt: PUT mit veralteter baseVersion → **409** mit aktueller version; Blob unverändert (GET beweist alten Inhalt).
- Übersicht: GET `/sync` listet `{version, updatedAt, size}` je belegter Collection, ohne Chiffrat; leer bei neuem Konto.
- Leerer Slot → 404 (GET und DELETE); unbekannte Collection → 400; Chiffrat > 256 KB → 413; fehlende/ungültige Felder → 400; ohne/mit ungültigem Token → 401.
- **Kaskaden-Regression (wichtigster Test):** `DELETE /api/account` **und** `index:account:prune` entfernen zugehörige SyncBlobs mit – deckt den Bulk-DELETE-Vorbehalt aus A ab.
- `sync_write`-Limiter isoliert (InMemoryStorage-Muster).
