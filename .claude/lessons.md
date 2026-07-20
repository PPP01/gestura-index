# Lessons — gestura-index

Geteiltes Projekt-Wissen. Regel: »Würde ein anderes Teammitglied in dieselbe Falle tappen?« → hier eintragen.

- **`backend/bin/phpunit` enthält einen manuellen `chdir(dirname(__DIR__))`-Fix**, damit `php backend/bin/phpunit` aus dem Repo-Root funktioniert (vanilla PHPUnit löst die Config nur über `getcwd()` auf). Die Datei ist **recipe-managed** — ein `composer recipes:update phpunit/phpunit` verwirft den Fix stillschweigend. Nach Recipe-Updates prüfen.
- **PHPUnit läuft mit `failOnDeprecation="true"`:** Die Suite kann »OK« drucken und trotzdem mit **Exit-Code 1** enden (»OK, but there were issues!«). Immer `php backend/bin/phpunit; echo $?` prüfen, nie nur den Text lesen. So aufgefallen: `imagedestroy()` ist seit PHP 8.5 deprecated.
- **Rate-Limiter in Tests:** Die echten Limits (5/h …) stehen in `config/packages/rate_limiter.yaml`; der `when@test`-Block setzt großzügige 1000er-Limits, damit Funktionstests nicht hineinlaufen. Die Drosselung selbst testet `RateLimitGuardTest` isoliert mit eigener Factory. Neue Limiter immer in beiden Blöcken ergänzen.
- **`RateLimiterFactory` als Typehint ist in Symfony 7.4 deprecated** — in Controllern/Services immer `RateLimiterFactoryInterface` typehinten (Parametername wie Limiter, z. B. `$submitLimiter`); Symfony registriert die Aliase automatisch, keine services.yaml-Binds nötig.
- **Hartes `$em->remove()` auf Entries mit DB-seitigem `ON DELETE CASCADE` reicht nicht:** Abhängige, bereits geladene Entities (z. B. `EntryVersion`) bleiben in der Doctrine-Identity-Map und lassen den nächsten `flush()` mit `ORMInvalidArgumentException` scheitern — abhängige Entities zusätzlich explizit per ORM entfernen.
- **Invariante der Statusmaschine:** Ein Entry mit Status `pending` hat nie eine gesetzte `currentVersion`; `published` impliziert `currentVersion !== null` (Guard in `ModerationService::publishEntry()`). Der Junk-Recycling-Pfad im Submit verlässt sich darauf.
