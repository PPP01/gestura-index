# Review: Sync-Ping und Wartungsmodus

Geprüft gegen HEAD `7b7c08a`, die Instruktion, den obersten Logbuch-Eintrag sowie `CLAUDE.md` und `.claude/lessons.md`.

## Kritisch

Keine Findings.

## Hoch

### H1 – Der neue Smoke-Check macht Rollbacks auf vorhandene Alt-Releases scheinbar fehlerhaft

- **Fundstelle:** Plan E1 und E8, Task 10; [deploy/rollback.sh](../../../../deploy/rollback.sh) Zeilen 50–61.
- **Beschreibung:** `rollback.sh` darf auf jedes frühere vollständige Release zurückschalten und ruft anschließend stets das aktuelle `deploy/smoke.sh` auf. Dieses soll nun zwingend `GET /api/v1/sync/ping` mit `200` verlangen. Jedes vor diesem Feature erzeugte Release antwortet dort mit `404`. Der Rollback ist dann bereits vollzogen, wird aber zwangsläufig als fehlgeschlagen gemeldet. Zudem kann ein in `shared/state/` liegendes Flag auf einem solchen Alt-Release nicht greifen: dort fehlen Symlink, Subscriber und Kommandos. E1 behauptet zu weitgehend, der Zustand überlebe auch einen Rollback.
- **Konsequenz:** Ein Notfall-Rollback erhält einen irreführenden Fehlerstatus; bei Rückkehr zu einem Vorfeature-Release entfällt zugleich der erwartete Wartungsschutz.
- **Vorschlag:** Eine explizite Kompatibilitätsstrategie planen: Beim Rollback auf Releases ohne Ping `404` als Legacy-Fall zulassen oder einen Release-Featuremarker auswerten. E1 auf Rollbacks zu Releases mit diesem Feature beschränken und das Verhalten beim Alt-Release im Runbook dokumentieren (oder solche Rollbacks bewusst sperren).

## Mittel

### M1 – Die behauptete ISO-8601-Validierung ist nicht strikt

- **Fundstelle:** Plan Task 4, Zeilen 352–367; Task 5, Zeilen 459–469.
- **Beschreibung:** `strtotime()` akzeptiert auch natürliche und mehrdeutige Ausdrücke wie `tomorrow`; es validiert weder das versprochene ISO-8601/ATOM-Format noch einen Offset strikt. Der Subscriber behandelt denselben Inhalt ebenfalls als gültiges `until`, statt ihn – wie E7 für ungültige Inhalte sagt – als Wartung ohne `until` zu behandeln.
- **Konsequenz:** Die Operator-Schnittstelle ist nicht reproduzierbar und kann still von Server-Zeitzone und Interpretationsregeln abhängen.
- **Vorschlag:** Einen gemeinsamen, strikten ATOM-Parser verwenden (`DateTimeImmutable::createFromFormat()` mit Fehlerprüfung und Format-Roundtrip) und ihn im Kommando wie im Subscriber einsetzen. Tests für `tomorrow`, fehlenden Offset und unmögliche Daten ergänzen.

### M2 – Der entscheidende DB-Ausfallpfad des Pings wird nicht getestet

- **Fundstelle:** Plan Task 6, Zeilen 651–652; Task 8, Zeilen 968–995.
- **Beschreibung:** Task 8 prüft nur, ob `SyncProblem::unavailable()` grundsätzlich die richtige Response baut. Er prüft nicht den maßgeblichen `try/catch` um `Connection::executeQuery('SELECT 1')` im neuen Controller. Damit belegt der Test nicht die Behauptung, ein DB-Fehler werde als `503 {"error":"unavailable"}` statt als `500 application/problem+json` ausgeliefert.
- **Konsequenz:** Eine fehlerhafte Exception-Behandlung im Controller bliebe unentdeckt und verletzte genau den zentralen Ping-Vertrag für einen DB-Ausfall.
- **Vorschlag:** Die DB-Probe hinter eine kleine injizierbare Abstraktion ziehen und den geworfenen DBAL-Fehler unit-testen; alternativ den Controller mit einer testbaren Connection-Abstraktion testen.

### M3 – Der Test behauptet fälschlich, keinen Verbrauch des Ping-Limits nachzuweisen

- **Fundstelle:** Plan Task 7, Zeilen 821–837; Task 2, Zeilen 134–144.
- **Beschreibung:** Im Testmodus ist `sync_ping` auf 1000/h gesetzt. Der Test sendet während Wartung nur zehn Requests und erwartet danach einen erfolgreichen Ping. Selbst wenn der Subscriber falsch hinter dem Controller liefe und alle zehn Requests Tokens verbrauchten, blieben 990 Tokens und der abschließende Request wäre weiterhin `200`.
- **Konsequenz:** Die wichtige Invariante aus E3 (Wartung verbraucht kein Limit-Token) ist nicht abgesichert.
- **Vorschlag:** Den Limiter vor Aktivierung der Wartung bis auf das letzte Token erschöpfen, dann Wartungsrequests senden, Flag entfernen und den einen erfolgreichen Ping prüfen; oder hierfür einen isolierten Limiter mit kleinem Limit verwenden.

### M4 – Die zugesagte Abschlussmeldung an die Extension fehlt im Plan

- **Fundstelle:** Instruktion Zeilen 74–80; `exchange/AUSTAUSCH.md`, oberster Eintrag, insbesondere die Zusage, nach Livegang zu melden; Plan Tasks 1–11.
- **Beschreibung:** Die Instruktion verlangt die Meldung, sobald der Ping auf `https://gestura.eu` antwortet. Die Zusage wiederholt dies. Kein Task enthält einen nach erfolgreichem Deploy auszuführenden Logbuch-/Handoff-Schritt; Task 11 dokumentiert nur den Betrieb.
- **Konsequenz:** Eine sichtbare Vertragsänderung kann technisch live gehen, ohne dass die vereinbarte Gegenstellen-Kommunikation erfolgt.
- **Vorschlag:** Einen abschließenden, bewusst nicht automatisierten Task nach erfolgreichem Live-Smoke ergänzen, einschließlich des vorgesehenen Logbuch-Wortlauts und der Bedingung, erst nach bestätigtem `200` auszuführen.

### M5 – Der Deploy-Schutz für den neuen shared-Zustand hat keine Regressionstest-Abdeckung

- **Fundstelle:** Plan Task 9, Zeilen 1013–1040; [deploy/tests/gc-test.sh](../../../../deploy/tests/gc-test.sh) Zeilen 16–40 und 98–102.
- **Beschreibung:** Der Plan erweitert das dauerhafte Layout um `shared/state/` und behauptet, es überstehe Deploy und Garbage Collection. Der vorhandene Shell-Test modelliert und prüft aber nur `.env.local`, `media` und `log`; Task 9 führt ihn nicht aus und passt ihn nicht an.
- **Konsequenz:** Ein späteres Ändern an Symlinks oder GC kann die Flag-Datei entfernen, ohne dass die dokumentierte E1-Invariante auffällt.
- **Vorschlag:** `deploy/tests/gc-test.sh` um `shared/state/sync-maintenance` und den `backend/var/state`-Symlink erweitern, deren Erhalt nach GC prüfen und diesen Shell-Test als Task-9-Testschritt aufführen. Ergänzend `bash -n deploy/deploy.sh` ausführen.

## Niedrig

### N1 – Normalisierung von `until` im Subscriber bleibt ungetestet

- **Fundstelle:** Plan Task 7, Zeilen 731–744; E7.
- **Beschreibung:** Der Test mit einem zukünftigen Wert prüft nur das Vorhandensein von `until`, nicht dessen exakten, nach UTC normalisierten ATOM-Wert. Der Factory-Test in Task 8 übergibt bereits einen UTC-String und prüft deshalb `SyncMaintenanceSubscriber::parseUntil()` nicht.
- **Konsequenz:** Eine Regression bei Offset-Umrechnung oder Antwortwert wäre trotz bestandenem Test möglich.
- **Vorschlag:** Eine Flag-Datei mit etwa `+02:00` setzen und exakt den erwarteten `+00:00`-Wert im Body sowie `Retry-After` prüfen.

### N2 – Der Plan erklärt die bestehende Sync-Smoke-Lücke zu früh für geschlossen

- **Fundstelle:** Plan E8 und Task 10, insbesondere Zeilen 1072 und 1080; [deploy/README.md](../../../../deploy/README.md) Zeile 11.
- **Beschreibung:** Ein Ping prüft Erreichbarkeit, Datenbank und Features, aber weder einen Locator-Request noch die bestehenden `/sync/list`, `/sync/get`, `/sync/state`, `/sync/meta` und `/sync/delete`-Vertragswege. Er schließt daher nicht die bisher dokumentierte Lücke, dass die Sync-Endpunkte insgesamt nicht gesmokt werden.
- **Konsequenz:** Das Entfernen des Hinweises kann Betreiber glauben lassen, die vollständige Locator-Sync-Oberfläche sei nach einem Deploy abgedeckt.
- **Vorschlag:** Den Hinweis präzisieren: Ping wird jetzt geprüft; die funktionalen Locator-Sync-Wege bleiben bewusst außerhalb des Smoke-Checks und werden durch PHPUnit bzw. bei Bedarf separat geprüft.

### N3 – Kleine Abweichung zwischen Entscheidung und vorgeschlagenem Code

- **Fundstelle:** E1, Zeile 28; Task 4, Zeilen 325–330.
- **Beschreibung:** E1 sagt `is_file()`, der Subscriber verwendet `file_exists()`. Eine gleichnamige Directory oder ein anderer nicht lesbarer Nicht-Datei-Eintrag aktiviert dadurch Wartung ohne `until`.
- **Konsequenz:** Gering, aber die spezifizierte Semantik und der Code stimmen nicht überein.
- **Vorschlag:** Entweder `is_file()` verwenden oder die Entscheidung auf die tatsächlich gewünschte Existenz-Semantik korrigieren; Datei-Lesefehler nicht still mit `@` unterdrücken, sondern kontrolliert als Wartung ohne `until` behandeln oder protokollieren.

## Gesamturteil

**Umsetzbar nach Korrekturen.** Die zentrale Architektur passt zum vorhandenen Symfony-Code: Prioritäten sind korrekt (`CorsSubscriber` 256, Wartung 240, `AdminCsrfSubscriber` 200), die Limiter-Injektion folgt dem vorhandenen `syncV1Limiter`-Muster, und Parameter-/Testpfad sind grundsätzlich stimmig. Vor Umsetzung müssen jedoch mindestens die Rollback-Kompatibilität, strikte `until`-Verarbeitung und die irreführenden beziehungsweise fehlenden Tests korrigiert werden.

ENDE DES REVIEWS
