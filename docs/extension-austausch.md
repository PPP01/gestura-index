# Austausch mit dem Extension-Repo

Zwischen der Gestura-Extension und diesem Index läuft ein eigener Kanal: die Extension-Seite stellt Aufgaben, dieses Projekt meldet zurück, und Mehrdeutigkeiten im Vertrag werden dort **gefragt statt entschieden**. Dieses Dokument hält fest, was von diesem Kanal dauerhaft gilt – der Kanal selbst liegt außerhalb des Repos.

## Wo die Wahrheit steht

Vertrag, Format-Schema und Referenz-Validator werden **im Extension-Repo gepflegt** und von hier direkt gelesen, nicht kopiert:

| Datei (aus WSL) | Was |
|---|---|
| `/mnt/c/Programme.alt/Gestura/docs/gestura-eu-api.md` | der Vertrag Extension ↔ Index |
| `/mnt/c/Programme.alt/Gestura/js/exchange-schema.json` | das Format-Schema |
| `/mnt/c/Programme.alt/Gestura/js/menu-exchange.js` | der Referenz-Validator |

`docs/gestura-eu-api.md` in diesem Repo ist eine **Kopie zum Mitlesen**, markiert mit dem Commit, aus dem sie stammt. `schema/exchange-schema.json` ebenso. Beide werden hier nie direkt geändert: bei Formatänderungen im Extension-Repo ändern und neu herüberkopieren. Bei Abweichungen zwischen Vertrag und Index gilt die Datei im Extension-Repo.

## Der Kanal liegt lokal, nicht im Repo

Der Arbeitsordner `exchange/` ist in `.gitignore` eingetragen. Dort landen rohe Abzüge des Vertrags zu verschiedenen Ständen, Handover-Briefe, Rückmeldungen und Design-Material – teils Binärdateien, teils mit Windows-ADS-Artefakten (`…:Zone.Identifier`) aus dem WSL-Mount. Das ist Übergabeverkehr, kein Repo-Inhalt: ein Binärblob bliebe in einem öffentlichen Repo dauerhaft in der History, und die Abzüge veralten mit jeder Vertragsänderung, während die Autorität ohnehin woanders liegt.

Das Logbuch `exchange/AUSTAUSCH.md` führt beide Seiten fort und ist der Ort, an dem gefragt und zurückgemeldet wird. Wer an der Extension-Schnittstelle arbeitet, sieht vorher hinein und hinterlässt danach eine Zeile. Was daraus dauerhaft gilt, gehört hierher – dieses Dokument ist die Repo-Fassung, das Logbuch die Verkehrsgeschichte.

## Stand der Schnittstelle (5. September 2026)

Der Vertrag ist auf **apiLevel 3** vollständig umgesetzt: `POST /api/v1/updates` (Level 2) und die vier `/api/v1/sync/*`-Endpunkte (Level 3). Die Grenzen sind serverseitig durchgesetzt, die Aufbewahrung läuft über `index:sync:prune`, und die drei nicht verhandelbaren Punkte des Vertrags sind nachgewiesen: kein Request-Body im Log, Locator-Formprüfung vor jeder Adressierung, Ablage ausschließlich als SHA-256.

**Der einzige verbliebene Release-Blocker ist der manuelle Docroot-Schritt beim Hoster** (`deploy/README.md`). Bis er steht, antworten beide Endpunktgruppen nur lokal. R2 und R3 gehen nach Entscheidung des Eigentümers in **einer** Version heraus.

## Offene Rückfragen an die Extension-Seite

Beide sind so implementiert, wie dieses Projekt den Vertrag liest. Sie sind **gestellt, aber unbeantwortet** – wer hier etwas ändert, ändert eine Annahme, keine Vereinbarung.

- **Misst `size` den Base64-String oder die dekodierten Bytes?** Umgesetzt ist der **Base64-String**, weil »as transmitted« im Vertrag bei beiden Zahlen steht und übertragen wird der String ([SyncContract.php:40](../backend/src/Api/SyncContract.php#L40), [LocatorSyncRequest.php:119](../backend/src/Api/LocatorSyncRequest.php#L119)). Der Unterschied ist Faktor 4/3. Weil der Client die Grenze spiegelt, liefe eine abweichende Lesart genau an der Obergrenze auseinander: der Nutzer sähe vorab »passt« und bekäme ein 413.
- **Wie streng soll `apiLevel` im Request geprüft werden?** Umgesetzt ist nur »vorhanden und ganzzahlig«, sonst `bad-request` – kein Mindest- und kein Höchstwert, damit ein künftiger Level-4-Client nicht abgewiesen wird. Ist »unknown apiLevel« enger gemeint, fehlt die Liste der abzulehnenden Werte.

Ein dritter, gleichartiger Punkt hängt an einer fehlenden Angabe der Extension-Seite und ist dort beschrieben: die **Liste der eingebauten Engine-IDs**, ohne die sich `engineId`-Referenzen nicht als »eingebaut vs. unbekannt« klassifizieren lassen ([extension-bundle-import-todo.md](extension-bundle-import-todo.md)).

## Wenn etwas mehrdeutig ist

Nachfragen, nicht entscheiden. Der Vertrag wird auf der Extension-Seite gepflegt; eine stillschweigend andere Auslegung fällt erst auf, wenn Nutzerdaten daran hängen. Zurück erwartet wird jeweils eine Zeile im Logbuch, sobald etwas antwortet – mit Basis-URL und Stand.
