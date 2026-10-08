# Wetterabruf und Kachelanzeige

Warum die Kachel so abruft und zeichnet, wie sie es tut, und was dabei verworfen wurde. Wie es im Einzelnen läuft, zeigen die Kommentare in `module.php` und `tests/README.md`.

## Abruf

- **Zwischenspeicher statt Abruf beim Öffnen.** Die letzte gültige Open-Meteo-Antwort steht mit Adresse und Zeit im Attribut `WeatherCache`. Das Öffnen (`GetVisualizationTile`) und Temperatur-Updates lesen nur daraus. Abgerufen wird vom Stundentimer; `ApplyChanges` und die Kachel-Anfrage `GetState` holen nur nach, wenn die Daten älter als 70 Minuten sind (`WEATHER_FRESH_SECONDS`) oder zu einem anderen Standort gehören. Bis 6 Stunden (`WEATHER_USABLE_SECONDS`) gelten alte Daten noch als anzeigbar. Nach einem Fehlschlag wartet die Kachel 5 Minuten (`WEATHER_RETRY_SECONDS`). Grund: Vorher konnte jedes Öffnen einen Netzabruf auslösen.
- **Stundentimer nicht bei jedem `ApplyChanges` neu setzen.** `SetTimerInterval` zählt ab dem Aufruf, auch beim gleichen Wert; bei häufigen Reloads käme der Timer nie ans Ziel. `ApplyChanges` setzt das Intervall nur, wenn es abweicht, und holt einen verhungerten Abruf über die 70-Minuten-Grenze nach. Plattform-Hintergrund: `plattform/timer.md`.
- **Kein Rückfall ohne Zertifikatsprüfung (verworfen).** Früher versuchte das Modul nach gescheitertem Abruf zuletzt `verify_peer=false`. Damit hätte jede Gegenstelle im Netz Wetterdaten unterschieben können. Jetzt bleibt ohne Antwort der letzte Stand aus `WeatherCache` stehen.
- **WMO-Codes:** Open-Meteo liefert Nieselregen als 51/53/55, das Modul ordnet genau diese zu. Ein Test stellt sicher, dass jeder WMO-Code für Tag und Nacht ein eigenes, vorhandenes Bild hat.

## Anzeige

- **FlipClock ohne eigenen Timer** (`autoStart: false`). Die Kachel stellt die Uhr selbst einmal je Sekunde (`syncClock`); der eingebaute Timer der FlipClock war ein zweiter, ein `requestAnimationFrame`-Kreislauf mit 60 Bildern je Sekunde. Gemessen am 28.09.2026 mit statischen Symbolen: Renderer und GPU 13 statt 60 ms je Sekunde (nicht am Code prüfbar).
- **Animierte Wettersymbole bleiben eine Option** (`UseAnimatedIcons`, Standard aus). Mit ihnen bestimmen die Symbole den Aufwand, gemessen rund 250 ms je Sekunde (28.09.2026, nicht am Code prüfbar). Eine laufende Animation in einer sichtbaren Kachel lässt die ganze Visu-Seite ständig neu zeichnen; deshalb gilt: keine Dauer-Animation ohne ausdrückliche Wahl des Nutzers.
- **Symbolstil: der Eigenschaftsname sagt das Gegenteil.** Die Eigenschaft heißt `UseOutlineIcons`, ein Haken wählt aber die gefüllten Symbole (Ordner `full`), so seit jeher. Der Name bleibt, damit bestehende Instanzen ihre Einstellung behalten; die Checkbox heißt „Filled icons“ bzw. „Gefüllte Symbole (statt Linien)“.
- **Icons nur `fa-light`.** Der Icon-Auflöser in `module.html` setzt jeden Stil auf `fa-light`, weil Symcons `/icons.js` nur diesen Schnitt zeichnet (Plattform: `plattform/kachel-visu.md`).

## Auslieferung

- **Eigener Hook statt Data-URIs.** Bilder, Symbole und FlipClock kommen über `/hook/wetterbilder/<ID>` mit Inhaltsversion in der Adresse: der Browser cacht sie dauerhaft (`immutable` nur für die aktuelle Version, sonst `no-cache`). Ohne registrierten Hook bleibt es bei Data-URIs.
- **Pfadangaben werden nicht durchgereicht.** Der Hook nimmt nur Namen, die zu festen Mustern passen (`BACKGROUND_NAME_PATTERN`, `ICON_PATH_PATTERN`, feste FlipClock-Dateien), und prüft per `realpath`, dass die Datei unter `assets/` liegt; `../` ergibt 404.
- **Hook nativ:** `RegisterHook` in `Create`, kein Zugriff auf das WebHook Control; ein Hook-Skript aus älteren Ständen wird entfernt.

---

Stand: geprüft gegen den Code am 08.10.2026
