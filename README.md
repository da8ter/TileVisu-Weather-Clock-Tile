# TileVisu Wetter Uhr

Die **TileVisu Wetter Uhr** erweitert IP-Symcon um eine HTML-Kachel, die aktuelle Wetterinformationen, eine dreitägige Vorhersage und eine FlipClock-Uhr kombiniert. Das Repository stellt das Modul `TileVisuWeatherHeaderTile` bereit.

## Features
- **Wetter-Header**: Die Kachel zeigt Temperatur, Tagesname und Min-/Max-Werte basierend auf dem ersten Eintrag der Prognose (`TileVisu-Weather-Header-Tile/module.html`).
- **Dynamische Bildauswahl**: Wetterbilder werden über einen eigenen WebHook ausgeliefert, der anhand des Wunderground-Codes das passende Motiv bestimmt (`TileVisu-Weather-Header-Tile/module.php` → `sendImageUpdate()`).
- **Automatische Skalierung**: Das Temperatur-/Vorhersage-Panel passt seine Größe automatisch an und bleibt dabei am unteren linken Rand verankert (`module.html`, Funktion `scaleLeftStack()`).
- **FlipClock-Integration**: Uhrzeit und Datum erscheinen über FlipClock-Assets, die bei Bedarf direkt aus dem Modul geladen werden (`ensureFlipClockAssetsFromUrl()` in `module.html`).

## Voraussetzungen
- **IP-Symcon** mit HTML-SDK-Unterstützung.
- **Wunderground/Weather.com Sync Modul** für Rohdaten der Vorhersage und Temperaturvariablen (siehe Hinweis in `TileVisu-Weather-Header-Tile/form.json`).
- Eine Temperaturvariable (String/Float) und eine Vorhersagevariable (JSON), die vom Wunderground-Modul aktualisiert werden.

## Installation
- Modul aus dem Module-Store auswählen oder über die GitHub-URL `https://github.com/da8ter/TileVisu-Wetter-Uhr.git` hinzufügen.
- Instanz `TileVisuWeatherHeaderTile` im Objektbaum anlegen.
- Das Modul registriert automatisch den benötigten WebHook unter `/hook/wetterbilder/<InstanceID>`.

## Konfiguration
Im Formular (`form.json`) stehen folgende Einstellungen zur Verfügung:
- **Temperature variable**: Symcon-Variable, deren formatierter Wert in der Kachel angezeigt wird.
- **Wunderground forecast variable (JSON raw data forecast)**: JSON-Ausgabe des Wunderground-Moduls; dient zur Befüllung der Vorhersage und zur Auswahl des Wetterbildes.
- Hinweis- und Spendensektion mit Verlinkungen, falls Unterstützung gewünscht ist.

## Datenfluss und Aktualisierung
- `MessageSink()` in `module.php` reagiert auf `VM_UPDATE`-Ereignisse der konfigurierten Temperatur- und Vorhersagevariablen.
- Ein Update der **Vorhersagevariable** löst `sendForecastUpdate()`, `sendImageUpdate()` und `sendTemperatureUpdate()` aus. Dadurch werden Wetterdaten, Bild und Temperatur synchron gehalten.
- Ein Update der **Temperaturvariable** ruft `sendTemperatureUpdate()` auf.
- Das Frontend empfängt die Daten via `UpdateVisualizationValue` und rendert sie in `module.html` (siehe `handleMessage()`).

## Bereitstellung der Wetterbilder
- Das Modul erzeugt (falls nicht vorhanden) ein WebHook-Skript (`ensureHookScript()`), das Bilder aus `TileVisu-Weather-Header-Tile/assets/wetterbilder/` als Data-URI ausliefert.
- Über die URL `/hook/wetterbilder/<InstanceID>?name=<slug>` werden die Assets vom Frontend geladen; Cache-Busting erfolgt mittels Zeitstempel (`module.html` → `setImage()`).

## Fehlerbehebung
- **WebHook Control fehlt**: In `RegisterHook()` wird eine Warnung geloggt (`LogMessage()`), falls kein WebHook-Control-Modul gefunden wird. In diesem Fall das Standard-WebHook-Modul installieren/aktivieren.
- **Keine Vorhersage sichtbar**: Prüfen, ob die Wunderground-JSON-Variable gültige Daten liefert und dem Modul zugeordnet ist.
- **Keine Temperaturanzeige**: Sicherstellen, dass die Temperaturvariable einen formatierten Wert besitzt (`GetValueFormatted`).

## Support & Dankeschön
- Spendenlinks (PayPal, Amazon Wunschliste) sind im Konfigurationsformular hinterlegt.
- Bei Fehlermeldungen bitte Log-Auszüge sowie die Konfiguration der Symcon-Variablen bereitstellen.

Viel Spaß mit der TileVisu Wetter Uhr!