# Tests

Ohne Symcon-Server und ohne Netz aus dem Repository-Verzeichnis ausführen:

```sh
php -l TileVisu-Weather-Clock-Tile/module.php
php -l tests/bootstrap.php
php -l tests/module_test.php
php tests/module_test.php
git diff --check
```

`tests/bootstrap.php` ist eine kleine SDK-Attrappe: `IPSModuleStrict` mit den typisierten Signaturen der Methoden, die das Modul überschreibt (und ein untypisiertes `IPSModule` für die Gegenprobe), dazu die Instanzmethoden und globalen Funktionen, die es aufruft. Open-Meteo ist eine zählende Attrappe (`Sys_GetURLContentEx`). Der Test startet sich ohne cURL, Sockets und URL-fopen neu: auch ein Fehler im Modul oder ein alter Stand erreicht nie einen Server. Jede Warnung wird zur Ausnahme. `HookProbeTile` macht `ProcessHookData` öffentlich wie Symcons `HookInstance` und fängt Kopfzeilen und Status ab.

`tests/module_test.php` prüft:

- Module Strict: typisierte Signaturen, keine `method_exists`-Weichen, kein eigenes `RegisterHook`, kein Zugriff aufs WebHook Control, Kernelstart abwarten, Property-Namen und Vorgaben wie vorher.
- Hook `/hook/wetterbilder/<ID>`: nativ in `Create`, versionierte Adressen für Hintergrund, eigenes Bild, Meteocons und FlipClock, Auslieferung mit Typ, Länge, `immutable` nur für die aktuelle Version, sonst `no-cache`, ETag/304, `nosniff`, 403 bei falschem Token oder ohne Hook, 404 für Unbekanntes und Pfadangaben (`../`), alte Adressen ohne Version, Ausgabegrenze, Rückfall auf Data-URIs ohne Hook.
- Zwischenspeicher: Öffnen, `GetState` und Temperatur-Updates ohne Abruf, Abruf nur bei veralteten Daten, neuem Standort oder vom Timer, Wartezeit nach einem Fehlschlag, Anfangszustand im Kacheldokument (auch mit `'`, `"`, `\`, Zeilenumbruch und `</script>` im Wert).
- Nachrichtenfilter und Stundentimer, Altlast Hook-Skript.
- Die Skripte von `module.html` und der erzeugten Kacheldokumente mit `node --check` (übersprungen ohne `node`).
- Gegenprobe gegen `2d14d26`: jedes Szenario läuft dort in einem eigenen Prozess (der alte `static`-Cache hielte sonst Daten über Aufrufe); genau die als behoben markierten Prüfungen fallen, die erhaltenen halten.

Temporäre Dateien landen in `sys_get_temp_dir()` (mit `TMPDIR=… php tests/module_test.php` umlenkbar) und werden entfernt.

Nicht abgedeckt ist die echte Symcon-Laufzeit. Nach `MC_ReloadModule` in einer Testinstanz prüfen: Bilder, Symbole und FlipClock erscheinen im Netzwerk-Tab als `/hook/wetterbilder/<ID>?…&v=…` mit 200, beim erneuten Öffnen aus dem Cache; das Öffnen der Kachel erzeugt keinen Open-Meteo-Abruf (Debug `OpenMeteo`).
