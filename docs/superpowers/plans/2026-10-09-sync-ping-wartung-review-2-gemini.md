# Unabhängiges Review: Sync-Ping und Wartungsmodus für `/api/v1/sync/*`

**Reviewgegenstand:** `docs/superpowers/plans/2026-10-09-sync-ping-wartung.md` (12 Tasks, Entscheidungen E1–E9)  
**Prüfstand:** Git HEAD `7b7c08a`  
**Referenzen:** `exchange/2026-10-09-instruktion-sync-ping.md` (inkl. Nachtrag), `exchange/AUSTAUSCH.md`, `CLAUDE.md`, `.claude/lessons.md`  
**Datum:** 2026-10-09  

---

## Findings

### Kritisch

*Keine Befunde.* Der Plan enthält keine strukturellen Blocker oder Architekturfehler, die die Ausführung grundlegend verhindern würden.

---

### Hoch

*Keine Befunde.* Die Kernmechanismen (Event-Subscriber-Prioritäten, Routen mit und ohne Trailing Slash, Symfony-Autowiring, Rate-Limiting, DBAL-Mocking und Deploy-Logik) sind technisch solide und konsistent zum bestehenden Codebase durchdacht.

---

### Mittel

#### M-1: Task 12 scheitert an `.gitignore` – `exchange/` ist nicht im Repository
- **Fundstelle:** Plan Task 12 (Zeilen 1602–1630) im Vergleich zu `.gitignore` (Zeile 33: `/exchange/`) und `CLAUDE.md` (Zeile 13)
- **Beschreibung:** Task 12 instruiert den Entwickler, die Datei `exchange/AUSTAUSCH.md` zu bearbeiten und das Ergebnis mit der Commit-Nachricht `Melde Ping-Livegang im Austausch-Logbuch` zu committen. Der gesamte Ordner `exchange/` ist jedoch im Repository per `.gitignore` ignoriert (`CLAUDE.md`: »`exchange/` – Übergabekanal zum Extension-Repo: lokaler Ordner, nicht im Repo (gitignored). Was daraus dauerhaft gilt, steht in `docs/extension-austausch.md`«; siehe auch Commit `6570562`).
- **Konsequenz:** Nach der Bearbeitung von `exchange/AUSTAUSCH.md` meldet `git status` einen sauberen Arbeitsbaum (»nothing to commit, working tree clean«). Ein externer Entwickler kann den vorgeschriebenen Commit nicht ausführen oder greift fälschlicherweise zu `git add -f`, was ein gitignoriertes Verzeichnis ins Repository einbrächte.
- **Vorschlag:** Task 12 muss klarstellen, dass der lokale Übergabekanal `exchange/AUSTAUSCH.md` zwar gepflegt wird, der Git-Commit sich jedoch auf die im Repo getrackte Datei `docs/extension-austausch.md` beziehen muss (oder die Commit-Anweisung für die lokale Datei entfällt und nur `docs/extension-austausch.md` committet wird).

#### M-2: Systematische typografische Verstöße (fehlende Anführungszeichen »…« und transkribierte Umlaute)
- **Fundstelle:** Gesamter Plan (u. a. Zeilen 23, 29, 45, 59, 109, 130, 267, 536, 545, 1190, 1452, 1605, 1676)
- **Beschreibung:** Laut Projektvorgabe soll deutscher Text mit echten Umlauten (ä ö ü ß), deutschen Anführungszeichen »…« und dem Halbgeviertstrich (–) formatiert sein.
  1. Im gesamten Dokument wird kein einziges deutsches Anführungszeichen »…« verwendet. Stattdessen werden ASCII-Quotes (`"ok"`), Backticks (`` `nicht erreichbar` ``) oder ASCII-Spitzklammern (`>>%s<<` in `MaintenanceOnCommand.php` Zeilen 536 und 545) genutzt.
  2. Es treten gehäuft transkribierte Umlaute im Fließtext und Code-Kommentaren auf: `erfuellt` (Z. 23), `zusaetzlicher` (Z. 29), `uebrigen` (Z. 59), `eingeschraenkt` (Z. 1676), `"ok" heisst` (Z. 267).
  3. In Task 9 (Z. 1452) ersetzt der Plan in `deploy/tests/gc-test.sh` das im Repo-Code korrekt stehende Wort `beschädigt` durch `beschaedigt` (`echo "FEHLT $CASE (shared/ wurde durch rm -rf eines Releases beschaedigt)"`).
  4. Grammatik-/Tippfehler: `unmöglich Daten` statt `unmögliche Daten` (Z. 109, 130, 1190).
  5. Im Text werden mehrfach ASCII-Pfeile (`->`, `<-`) anstelle von echten Pfeilen (`→`, `←`) verwendet (z. B. Z. 45, Z. 130, Z. 1605).
- **Konsequenz:** Verletzung der im Projekt geltenden typografischen Konventionen.
- **Vorschlag:** Typografie im gesamten Plan glätten: Guillemets »…« setzen, Umlaute ausschreiben, `beschaedigt` in Task 9 auf `beschädigt` korrigieren und ASCII-Pfeile im Fließtext durch `→`/`←` ersetzen.

---

### Niedrig

#### N-1: Unlesbare Flag-Datei erzeugt PHP-Warning bei `file_get_contents()` ohne `@`
- **Fundstelle:** Task 4, `SyncMaintenanceSubscriber.php` (Zeilen 412–416)
- **Beschreibung:** Der Plan fängt Lesefehler per `try { $content = file_get_contents($this->syncMaintenanceFile); } catch (\Throwable) { $content = false; }` ab, mit dem Vermerk »kein @-Suppressor«. Standard-PHP wirft bei `file_get_contents()` jedoch keine `\Throwable`, sondern emittiert ein `E_WARNING` und gibt `false` zurück. Falls kein Exception-konvertierender ErrorHandler greift, wird eine unschöne Warning ins Log geschrieben.
- **Konsequenz:** Mögliche Warning-Logs bei Dateizugriffsfehlern während der Anfragebearbeitung.
- **Vorschlag:** Vor dem Lesen `is_readable($this->syncMaintenanceFile)` prüfen oder gezielt mit `error_clear_last()` arbeiten.

#### N-2: Verwendung von `@unlink` in `MaintenanceOffCommand`
- **Fundstelle:** Task 5, `MaintenanceOffCommand.php` (Zeile 629)
- **Beschreibung:** In `MaintenanceOffCommand` steht `if (!@unlink($this->syncMaintenanceFile))`. Dies widerspricht der in `.claude/lessons.md` festgehaltenen Lesson (»deleteFileAt() loggt unlink-Fehler, statt sie mit @ zu verschlucken«).
- **Konsequenz:** Schlägt das Löschen fehl (z. B. fehlende Dateirechte), erfährt der Operator via `$io->error()` nicht die zugrunde liegende Systemursache.
- **Vorschlag:** Auf `@` verzichten oder die Fehlermeldung mit `error_get_last()['message']` anreichern.

#### N-3: Stilabweichung in der Vorlage für `AUSTAUSCH.md`
- **Fundstelle:** Task 12 (Zeile 1605)
- **Beschreibung:** Die vorgeschlagene Überschrift lautet `## <DATUM> . <- Index . Ping live`. Alle bisherigen Einträge in `exchange/AUSTAUSCH.md` folgen der Notation `## 2026-10-09 · ← Index · Ping live` mit Mittelpunkt (`·`) und typografischem Pfeil (`←`).
- **Konsequenz:** Stilbruch in der Chronik des Austausch-Logbuchs.
- **Vorschlag:** Vorlage auf `## <DATUM> · ← Index · Ping live` anpassen.

#### N-4: Kein explizites `Cache-Control: no-store` auf der Wartungsantwort (503)
- **Fundstelle:** Task 1, `SyncProblem::maintenance()` (Zeilen 159–173) und Task 4 (`SyncMaintenanceSubscriber.php`)
- **Beschreibung:** Während `LocatorSyncPingController` bei `200` explizit `Cache-Control: no-store` setzt, liefert `SyncProblem::maintenance()` bei `503` den Header `Retry-After`, aber kein explizites `Cache-Control: no-store`. Zwar cachen RFC-konforme Proxys 503-Antworten in der Regel nicht ohne explizite Freigabe, der Ping-Endpunkt verlangt laut Instruktion jedoch striktes `no-store`.
- **Konsequenz:** Sehr geringes Restrisiko, dass aggressive Caches/Proxys eine 503-Antwort zwischenspeichern.
- **Vorschlag:** In `SyncProblem::maintenance()` den Header `'Cache-Control' => 'no-store'` in `$headers` ergänzen.

#### N-5: Fehlender Testfall für Fehlschlag bei `index:maintenance:off`
- **Fundstelle:** Task 7, `MaintenanceCommandTest.php` (Zeilen 1212–1233)
- **Beschreibung:** Getestet werden das erfolgreiche Löschen und der idempotente Aufruf bei bereits fehlender Flag-Datei. Der Fehlerpfad (`unlink` scheitert $\rightarrow$ `Command::FAILURE`) wird nicht getestet.
- **Konsequenz:** Minimale Testlücke für einen unwahrscheinlichen Fehlerfall.
- **Vorschlag:** Entweder per Mock/Dateirechte absichern oder bewusst als nicht-kritisch dokumentieren.

---

## Gesamturteil

**Umsetzbar nach Korrekturen**

### Begründung
Der Umsetzungsplan ist fachlich und technisch exzellent ausgearbeitet:
1. **Hohe Präzision am Code:** Alle Klassen-, Service-, Parameter- und Dateinamen sowie Konstruktorsignaturen stimmen exakt mit dem Repository-Stand überein.
2. **Saubere Symfony-Integration:** Die Event-Subscriber-Prioritäten (CORS 256 $\rightarrow$ Wartung 240 $\rightarrow$ CSRF 200 $\rightarrow$ Router 32) sind mathematisch und funktional exakt gewählt. OPTIONS-Preflights bleiben unberührt `204`. Trailing Slashes werden über zwei explizit benannte Routen (`/ping` und `/ping/`) ohne 301-Umleitung bedient, was den Vertrag bezüglich `redirect: 'error'` lückenlos einhält.
3. **Belastbare Teststrategie:** Alle geforderten Invarianten (Rate-Limiting in Wartung, UTC-Normierung, DB-Ausfall via DBAL-Mock, Smoke-Warnung bei Wartung, Smoke-Toleranz bei Rollback via `SMOKE_LEGACY=1`) sind methodisch sauber und mit hoher Assertions-Schärfe getestet.
4. **Deploy-Konsistenz:** Die Symlink-Strategie (`var/state` $\rightarrow$ `shared/state`) übersteht Deploys und Rollbacks, und `gc-test.sh` sichert die Beständigkeit ab.

Vor der Umsetzung müssen lediglich die gitignorierten Pfade in Task 12 (Commit von `docs/extension-austausch.md` statt `exchange/AUSTAUSCH.md`) klargestellt und die typografischen Mängel behoben werden. Danach kann der Plan direkt und ohne Kontextverlust abgearbeitet werden.

ENDE DES REVIEWS
