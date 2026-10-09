**Trifft zu.** Zwei Stellen führen zu einer falschen Einordnung durch den Client.

1. **Die beiden Ping-Routen verhindern die Slash-Umleitung nicht.**

   **Fundstelle:** `docs/superpowers/plans/2026-10-09-sync-ping-wartung.md`, E9 und Task 3, Zeilen 277–278. Der tatsächlich verwendete Symfony-Matcher bricht bereits beim Slash-Konflikt mit der ersten Route ab (`backend/vendor/symfony/routing/Matcher/Dumper/CompiledUrlMatcherTrait.php:105–107`), bevor er die zweite Route prüft. Anschließend wählt er den Redirect-Controller.

   **Eingabefall:** `GET /api/v1/sync/ping/`, Wartung ausgeschaltet, Datenbank erreichbar und Ping-Limit nicht erschöpft.

   **Tatsächlich:** `301` mit `Location: https://gestura.eu/api/v1/sync/ping`. Die Extension behandelt dies wegen `redirect: 'error'` als Netzwerkfehler und meldet „nicht erreichbar“. Die API-Regel in `backend/public/.htaccess` verhindert diese nachgelagerte Symfony-Umleitung nicht. Auch `HEAD` trifft denselben Redirect-Pfad.

   **Erwartet:** `200 application/json` mit `{"status":"ok","features":["sync-meta"]}` ohne Umleitung, wie für beide Pfade im Plan zugesagt. Reproduziert mit dem unveränderten Controller-Code aus Task 3, dem installierten Attribut-Loader, kompilierten Matcher und Redirect-Controller, ausschließlich im Speicher.

2. **Bestimmter ungültiger Flag-Inhalt erzeugt 500 statt Wartung.**

   **Fundstelle:** Derselbe Plan, Task 1, Zeilen 120–123, und Task 4, Zeilen 412–420. `DateTimeImmutable::createFromFormat()` kann einen `ValueError` werfen; der Subscriber fängt nur Fehler beim Dateilesen ab, nicht beim anschließenden Parsen.

   **Eingabefall:** `GET /api/v1/sync/ping` bei vorhandener Flag-Datei mit dem Inhalt `2030-01-01T12:00:00+00:00\0junk`, wobei `\0` ein tatsächliches eingebettetes Nullbyte bezeichnet.

   **Tatsächlich:** Der ungefangene `ValueError` wird durch `backend/src/EventSubscriber/ProblemJsonSubscriber.php:57–71` zu `500 application/problem+json` mit `{"type":"about:blank","title":"Internal Server Error","status":500}`. Die Extension meldet „nicht erreichbar“ statt Wartung. Parserfehler und Fehlerantwort wurden mit dem Parser aus dem Plan und dem vorhandenen Subscriber im Speicher reproduziert.

   **Erwartet:** `503 application/json` mit `{"error":"maintenance"}` ohne `until`, weil die vorhandene Flag-Datei Wartung aktiviert und ungültiger Inhalt laut Plan lediglich die Zeitangabe entfallen lässt.

ENDE DES REVIEWS