# TileVisu Wetter-Uhr-Kachel

Symcon-Kachel (HTML-SDK) mit FlipClock, Datum, aktuellem Wetter samt Hintergrundbild und 3-Tage-Vorhersage von Open-Meteo. Öffentliches Repo `da8ter/TileVisu-Weather-Clock-Tile`. Bedienung: `README.md`.

Projektwissen (Entscheidungen, Messwerte): **`docs/README.md`**. Betriebsdaten dieses Rechners (Zweige, Testsystem, Werkzeuge außerhalb des Repos) stehen in `CLAUDE.local.md` (nicht eingecheckt).

## Aufbau

- **`TileVisu-Weather-Clock-Tile/`**: einziges Modul, Klasse `TileVisuWeatherClockTile`, Präfix `TVWC`, `IPSModuleStrict`, ab Symcon 8.1.
- **`assets/`**: Wetterbilder (`wetterbilder/`, je WMO-Code Tag und Nacht), Wettersymbole (`icons/full` und `icons/outline`, Lizenz im Ordner), FlipClock.
- **Hook** `/hook/wetterbilder/<ID>`: natives `RegisterHook` in `Create`, Adressen mit Inhaltsversion und Token; ausgeliefert wird nur, was zu den festen Namensmustern passt und per `realpath` unter `assets/` liegt.
- **Wetterdaten** im Attribut `WeatherCache`; Öffnen, `GetState` und Temperatur-Updates rufen Open-Meteo nicht ab. Stundentimer `UpdateTimer`.
- **Nachrichtenfilter:** `VM_UPDATE` zählt nur mit `$Data[1] === true` (fehlt die Angabe, wird gesendet); Prüfwerte im Puffer `UpdateHashes`. Startzustand inline im Kacheldokument mit `JSON_HEX_TAG`.
- **Icon-Baustein** `symcon-icons-shared` (v3) im `<head>` von `module.html`: lädt Symcons `/icons.js` einmal ins Visu-Hauptfenster statt in jede Kachel. Wortgleiche Kopie in mehreren TileVisu-Repos, die Quelle liegt außerhalb des Repos: **nie von Hand ändern**. Der Icon-Auflöser erzwingt `fa-light`.

## Prüfen

```bash
php -l TileVisu-Weather-Clock-Tile/module.php
php tests/module_test.php     # ohne Netz: Open-Meteo ist eine Attrappe; Gegenprobe gegen einen älteren Commit, node --check der Kachel-Skripte
git diff --check
```

Umfang und die Handprobe nach dem Neuladen in einer Symcon-Testinstanz: `tests/README.md`.

## Regeln

- **Commits:** deutsche Botschaft, ein Thema je Commit, **ohne** Co-Authored-By-Zeile; Prüfungen vorher.
- **Nie** `git checkout`/`git restore` auf Dateien: die Arbeitskopie kann nicht committete Arbeit enthalten.
- **Push und Release nur auf Zuruf.** Release: `version`, `build` und `date` in `library.json` hochsetzen (`date` ist ein Unix-Zeitstempel); `compatibility.version` bleibt `8.1`.
- **Öffentliches Repo:** keine IP-Adressen, Ports, Instanz-IDs, Token, Pfade unter `/Users/`, keine Personendaten und keine echten Standorte – auch nicht in Tests und Kommentaren. FontAwesome Pro ist lizenziert: keine Font-Dateien, Kit-Kennungen oder Lizenzdaten.
- **Symcon-Standards:** `strict_types`, `IPSModuleStrict` mit vollen Typen, Darstellungen statt Variablenprofilen, Texte über `locale.json`, Nutzertexte sagen „Symcon“.
- **Netz nur mit Zertifikatsprüfung**, keine Abrufe im Öffnen-Pfad, keine Dauer-Animation ohne Nutzeroption (siehe `docs/`).

## Wissen

Gemeinsames Symcon-Plattformwissen (Hooks und Ausgabegrenze, Timer, Kacheln, Icons): https://github.com/da8ter/SymDo-Family-Organizer/tree/SymDo-Beta/docs/plattform – lokal `../List/docs/plattform/`. Symcon-Fragen am offiziellen Handbuch prüfen.
