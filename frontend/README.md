# gestura-index Frontend

SvelteKit mit **Svelte 5 (Runes)** und **TypeScript**. Zwei Teile in einer App:

- die **öffentliche Website** – prerendered über `adapter-static`, damit sie SEO-fähig ist;
- das **Admin-Panel** – client-only SPA gegen die API, ausgeliefert über den `fallback` (`200.html`).

Die App konsumiert ausschließlich die JSON-API aus `../backend/`; eigene Serverlogik gibt es nicht.

## Entwicklung

```bash
npm install
npm run dev            # nur das Frontend (Port 5173)
../dev.sh              # Backend + Frontend zusammen – der übliche Weg
```

`dev.sh` sucht sich ab 8000 den ersten freien Backend-Port und reicht ihn als `BACKEND_PORT` an den Vite-Proxy weiter (`vite.config.ts` → `server.proxy['/api']`). Beides läuft damit unter der einen Origin `http://localhost:5173` – das ist die Voraussetzung für die Passkey-Tests (WebAuthn verlangt Origin-Match) und erspart jede CORS-Frage.

> Aus einem **Windows-Browser** heraus `http://127.0.0.1:5173` statt `localhost` verwenden: `localhost` löst dort zuerst nach `::1` auf, der Portproxy zur WSL hängt aber nur an IPv4. Das sieht wie ein toter Dienst aus, ist aber nur die Namensauflösung.

```bash
npm run build          # statischer Build → build/
npm run preview        # Build lokal ansehen
npm run check          # svelte-check (TypeScript)
npm run test           # vitest
```

## Sprachen (Paraglide)

Nachrichten liegen als JSON in `messages/en.json` und `messages/de.json`; kompiliert wird nach `src/lib/paraglide/`.

**Das Kompilat ist gitignored und darf nicht committet werden.** `dev` und `build` erledigen es über das Vite-Plugin, `test`/`check`/`prepare` rufen `paraglide-js compile` vorab auf. Nach dem Ergänzen neuer Keys also nur die beiden JSON-Quellen committen.

> Ein `paraglide compile` **neben einem laufenden Dev-Server** kann dessen Modulgraph entwerten – die Seiten antworten dann mit 500 und der Server verlangt Dateien, die es im neuen Kompilat nicht mehr gibt. Heilung: Dev-Server neu starten. Ein `touch` auf die Message-JSONs genügt nicht.

## Design

Die Website übernimmt das Design der Extension. Regeln und die Liste der übernommenen Dateien: [`../docs/design-system.md`](../docs/design-system.md).

Zwei davon sind **Kopien aus dem Extension-Repo** und werden hier nicht weiterentwickelt, sondern bei Änderungen neu herübergeholt:

- `src/lib/styles/gestura-common.css` ← `css/common.css`
- `src/lib/menu-icons.ts` ← `js/menu-icons.js` (die 50 Icon-Namen sind ein *Daten*-Vertrag des Austauschformats, kein Styling-Detail)

Einzige gewollte Abweichung vom Extension-Design ist die Max-Width-Shell für sehr breite Bildschirme.

## Übergabe an die Extension

Der Knopf »An Gestura senden« holt das Bundle selbst über `POST /api/v1/bundle` und reicht es **inline** per DOM-Event `gestura:import` weiter – `detail` ist dabei ein **String**, kein Objekt. Die Extension fetcht auf diesem Weg nichts. Die Rückmeldung kommt als `gestura:import-result`.

Der Rückkanal-Listener hängt bewusst am Lebenszyklus der Komponente, **nicht** am Sendevorgang: zwischen Klick und Rückmeldung steht der Nutzer im Import-Dialog der Extension, und das dauert regelmäßig länger als der 15-Sekunden-Hinweis. Details: [`../docs/extension-bundle-import-todo.md`](../docs/extension-bundle-import-todo.md).
