# Review: Sync-Ping und Wartungsmodus

Geprüft gegen HEAD `7b7c08a`. Die angeforderten Review-Dateien wurden nicht
gelesen.

## Kritisch

Keine Befunde.

## Hoch

Keine Befunde.

## Mittel

### Smoke-Check akzeptiert ungültige Antworten

- **Fundstelle:** Plan, Task 10, Zeilen 1491–1494 (`deploy/smoke.sh`).
- **Beschreibung:** Der 200-Zweig sucht nur unabhängig nach den Zeichenfolgen
  `"status":"ok"` und `"features"`; der 503-Zweig nach `"maintenance"`.
  Damit gilt etwa ein semantisch falscher 200-Body als gesund. Ebenso wird
  `{"error":"unavailable","message":"maintenance"}` als geplante
  Wartung und damit als bloße Warnung akzeptiert.
- **Konsequenz:** Der Deploy kann erfolgreich enden beziehungsweise nur warnen,
  obwohl der Ping-Vertrag oder die Datenbankprüfung defekt ist.
- **Vorschlag:** Den JSON-Body mit dem auf dem Ziel ohnehin vorhandenen
  `php85` parsen und für 200 `status === "ok"` sowie ein Array `features`
  verlangen. Für die Wartungswarnung explizit `error === "maintenance"`
  prüfen. Einen negativen Smoke-Test oder zumindest einen isolierten Test der
  Body-Prüfung ergänzen.

### Der neue strikte Parser ist nicht direkt und nicht vollständig abgesichert

- **Fundstelle:** Plan, E7 und Task 1, Zeilen 63–65 sowie 104–140; Tests in
  Task 7, Zeilen 985–1010 und 1161–1198.
- **Beschreibung:** Für `SyncContract::parseAtomTimestamp()` gibt es keinen
  eigenen Test. Die indirekten Tests decken weder den ausdrücklich zugesagten
  `Z`-Offset noch Whitespace/angehängten Müll oder die erfolgreiche Annahme
  eines Nicht-UTC-Offsets ab. Außerdem ist die Behauptung, die Methode ersetze
  bisherige `strtotime()`-Aufrufe, nicht mit HEAD vereinbar: Unter `backend/`
  existiert kein solcher Aufruf.
- **Konsequenz:** Eine Änderung des Parsers kann zentrale Vertragsfälle
  unbemerkt beschädigen; ein Entwickler bekommt zudem eine falsche Aussage
  über den zu migrierenden Bestand.
- **Vorschlag:** Einen datengetriebenen `SyncContractTest` ergänzen: gültig
  sind `+00:00`, `Z` und ein anderer Offset; ungültig sind leer, fehlender
  Offset, unmögliches Datum, natürliche Sprache und angehängte Daten. Die
  Formulierung zu `strtotime()` durch »wird von Kommando und Subscriber
  verwendet« ersetzen.

### Der in Task 12 geforderte Commit ist unmöglich

- **Fundstelle:** Plan, Task 12, Zeilen 1598–1629.
- **Beschreibung:** Task 12 verlangt einen Eintrag in `exchange/AUSTAUSCH.md`
  und liefert dafür ein Commit-Subject. `exchange/` ist jedoch laut
  `.gitignore:33` vollständig ignoriert; `CLAUDE.md:13` bezeichnet den Ordner
  ausdrücklich als lokalen, nicht versionierten Übergabekanal.
- **Konsequenz:** Ein Entwickler, der den Plan strikt taskweise inklusive
  Commit abarbeitet, kann den letzten Commit nicht erzeugen. Der Plan ist an
  dieser Stelle nicht reproduzierbar.
- **Vorschlag:** Den Commit-Abschnitt von Task 12 entfernen und ausdrücklich
  sagen, dass der Logbuch-Eintrag lokal und uncommittet bleibt. Falls ein
  versionierter Nachweis gewünscht ist, dafür eine nicht ignorierte Datei und
  einen eigenen Auftrag bestimmen.

## Niedrig

### Wartung wird nicht über alle vorhandenen Sync-Endpunkte getestet

- **Fundstelle:** Plan, Task 7, Zeilen 920–932.
- **Beschreibung:** Der Funktionstest prüft neben Ping nur `POST /sync/list`.
  Im Bestand gibt es außerdem `PUT /sync/state` sowie `POST /sync/get`,
  `/sync/meta` und `/sync/delete`.
- **Konsequenz:** Die Präfix-Implementierung macht das geplante Verhalten
  wahrscheinlich, aber die behauptete Abdeckung »jeder `/sync/*`-Endpunkt«
  wird nicht als Regressionstest bewiesen.
- **Vorschlag:** Für die restlichen Routen eine kleine Datenprovider-Matrix mit
  gültigen Minimalanfragen und der Erwartung `503 maintenance` ergänzen.

### Typografie entspricht wiederholt nicht der Vorgabe

- **Fundstelle:** Plan, unter anderem Zeilen 23, 29, 45, 59, 130, 267, 1452,
  1676.
- **Beschreibung:** Wiederholt stehen ASCII-Umschreibungen wie `erfuellt`,
  `zusaetzlicher`, `uebrigen`, `heisst`, `beschaedigt` und `eingeschraenkt`;
  außerdem erscheint `->` im deutschen Fließtext statt des Halbgeviertstrichs.
- **Konsequenz:** Der Plan verfehlt die ausdrücklich verlangte deutsche
  Typografie sichtbar und wiederkehrend.
- **Vorschlag:** Durch »erfüllt«, »zusätzlicher«, »übrigen«, »heißt«, »beschädigt«
  und »eingeschränkt« ersetzen; im Fließtext »–« verwenden. Die in PHP- und
  Shell-Code notwendigen ASCII-Zeichen bleiben davon unberührt.

## Gesamturteil

**Umsetzbar nach Korrekturen.** Die Kernimplementierung passt zum Bestand:
Subscriber-Prioritäten (CORS 256, Admin-CSRF 200), Autowiring des benannten
Rate-Limiters, die DBAL-Connection in Version 4.4.3, Test-Cache-Reset,
Route-Import, Symlink-Layout sowie die Legacy-Behandlung beim Rollback sind
konsistent. Die drei mittleren Befunde müssen vor einer taskweisen Übergabe
korrigiert werden, damit Deploy-Prüfung, Parser-Vertrag und Abschluss-Task
verlässlich ausführbar sind.

ENDE DES REVIEWS
