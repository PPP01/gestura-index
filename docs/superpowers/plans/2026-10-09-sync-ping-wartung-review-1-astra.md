**Trifft zu.** Der Plan enthält zwei Stellen, die den Client zu einer falschen Zustandsanzeige führen können. Geprüft gegen HEAD `7b7c08a`; Ping und Wartungs-Subscriber sind dort noch nicht implementiert, die beschriebenen Antworten ergeben sich aus dem geplanten Code zusammen mit dem vorhandenen Antwortpfad.

1. **Das voraussichtliche Wartungsende schaltet die Wartung ab.** Fundstelle: `docs/superpowers/plans/2026-10-09-sync-ping-wartung.md`, Entscheidung E2 (Zeile 32) und Task 4 (Zeilen 332–336).

   Eingabefall: Die Wartung dauert länger als angekündigt, die Flag-Datei existiert weiterhin mit `2026-10-09T12:00:00+00:00`; um 12:01 UTC kommt `GET /api/v1/sync/ping`. Der Limiter ist nicht erschöpft, `SELECT 1` funktioniert.

   Tatsächlich nach Plan: Der Subscriber ignoriert die Flag-Datei; Task 3 liefert `200 {"status":"ok","features":["sync-meta"]}`. Der Client startet den Assistenten während der fortdauernden Wartung. Bei gescheitertem DB-Check käme stattdessen `503 {"error":"unavailable"}` und damit die Anzeige „nicht erreichbar“.

   Erwartet: weiterhin `503 {"error":"maintenance"}` mit optionalem `until`. Der Vertrag bezeichnet `until` ausdrücklich als **voraussichtliches Ende**, nicht als Abschaltzeit (`exchange/2026-10-09-instruktion-sync-ping.md`, Zeilen 45–52). Das Überschreiten der Schätzung belegt kein Wartungsende.

2. **Die Slashvariante erzeugt eine Symfony-Weiterleitung.** Fundstelle: Plan, Task 3 (Zeile 199), zusammen mit `backend/public/.htaccess:34` und dem vorhandenen Symfony-Router: `backend/vendor/symfony/routing/Matcher/Dumper/CompiledUrlMatcherTrait.php:60`, `backend/vendor/symfony/framework-bundle/Routing/RedirectableCompiledUrlMatcher.php:24` sowie `backend/vendor/symfony/framework-bundle/Controller/RedirectController.php:115`.

   Eingabefall: `GET /api/v1/sync/ping/`, keine aktive Wartung, Dienst gesund.

   Tatsächlich nach Plan: Apache reicht den Request intern an Symfony weiter. Dessen Matcher korrigiert den abschließenden Slash mittels `301` auf `/api/v1/sync/ping`. Mit `redirect: 'error'` wird daraus für den Client ein Netzfehler und die Anzeige „nicht erreichbar“. Das Matcher-Verhalten wurde mit der geplanten Route und den installierten Symfony-Klassen ohne Kernelstart reproduziert.

   Erwartet: kein `3xx`; bei unterstützter Slashvariante `200` mit Ping-Body, andernfalls ein direktes `404`, das der Client als erreichbar behandelt. Die Apache-Ausnahme verhindert nur die Apache-Weiterleitung, nicht die des Routers.

Die übrige geprüfte Kette erklärt keine zusätzliche Fehlinterpretation für den vertraglichen GET: CORS (256) beantwortet OPTIONS vor Wartung (240) mit 204; eine gesetzte Wartungsantwort stoppt vor CSRF (200), Router und Limiter. `SyncProblem` bleibt durch `ProblemJsonSubscriber` bei der Vertragsform. Ein fehlgeschlagener DB-Check ergibt 503 unavailable, ein erschöpfter Ping-Limiter 429. Eine vorhandene, nicht lesbare Flag-Datei beziehungsweise nicht parsebarer Inhalt ergibt Wartung ohne `until`. Großschreibung bezeichnet andere, case-sensitive Pfade; HEAD hat regulär keinen Antwortbody und ist nicht der vereinbarte GET-Aufruf.

ENDE DES REVIEWS