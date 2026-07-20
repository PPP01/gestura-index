# gestura-index Backend

Symfony-JSON-API des Gestura-Index. Spec: `../docs/superpowers/specs/2026-07-20-index-backend-core-design.md`.

## Entwicklung

```bash
composer install                     # im Ordner backend/
php -S localhost:8000 -t public      # Dev-Server
php bin/phpunit                      # Tests (MariaDB gestura_index_test, dama-Rollback)
```

Lokale DB-Zugangsdaten liegen in `.env.local` / `.env.test.local` (nicht im Repo).

## Endpunkte (alle unter /api/v1)

| Methode | Pfad | Auth | Zweck |
| --- | --- | --- | --- |
| GET | /entries | – | Stöbern (q, site, category, tag, type, sort, page, perPage) |
| GET | /entries/{formatId} | – | Detail + freigegebene Versionen |
| GET | /entries/{formatId}/versions/{semver} | – | Format-JSON herunterladen |
| POST | /entries/updates | – | Update-Check (Liste id+version) |
| POST | /entries/{formatId}/install | – | Install-Ping nach bestätigtem Import |
| POST | /entries | optional Token | Einreichen (erzeugt ggf. Edit-Token) |
| PUT | /entries/{formatId} | Token | Neue Version / Metadaten |
| DELETE | /entries/{formatId} | Token | Soft-Delete |
| POST | /entries/{formatId}/report | – | Melden (fester Grund) |
| POST | /entries/{formatId}/screenshot | Token | Screenshot (WebP-Re-Encoding) |

## Moderation (bis Admin-Panel existiert)

`php bin/console index:queue | index:approve <formatId> | index:reject <formatId> | index:reports | index:resolve <id> --action=publish|delete | index:ban <submitterId> [--unban]`
