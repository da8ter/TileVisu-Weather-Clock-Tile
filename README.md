# TileVisu Wetter Uhr

Die **TileVisu Wetter Uhr Kachel** erweitert IP-Symcon um eine HTML-Kachel, die aktuelle Wetterinformationen, eine dreitägige Vorhersage und eine FlipClock-Uhr kombiniert.

## Features
- **Wetter-Header**: Die Kachel zeigt Temperatur, Tagesname und Min-/Max-Werte basierend auf dem ersten Eintrag der Prognose.
- **Dynamische BWetterbilder**: Je nach Wetter wird ein passendes Hintergrundbild angezeigt.
- **FlipClock-Integration**: Uhrzeit und Datum erscheinen als FlipClock.

## Voraussetzungen
- **IP-Symcon** 7.1.
- **Wunderground/Weather.com Sync Modul** für Rohdaten der Vorhersage.
- Eine Temperaturvariable (String/Float) zur Anzeige der aktuellen Außentemperatur.

## Installation
- Modul aus dem Module-Store auswählen oder über die GitHub-URL `https://github.com/da8ter/TileVisu-Wetter-Uhr.git` hinzufügen.
- Instanz `TileVisuWeatherClockTile` im Objektbaum anlegen.
- Das Modul registriert automatisch den benötigten WebHook unter `/hook/wetterbilder/<InstanceID>`.

## Konfiguration
Im Formular stehen folgende Einstellungen zur Verfügung:
- **Temperature variable**: Symcon-Variable, deren formatierter Wert in der Kachel angezeigt wird.
- **Wunderground forecast variable (JSON raw data forecast)**: JSON-Ausgabe des Wunderground-Moduls; dient zur Befüllung der Vorhersage und zur Auswahl des Wetterbildes.

## Support & Dankeschön
- Spendenlinks (PayPal, Amazon Wunschliste) sind im Konfigurationsformular hinterlegt.

Viel Spaß mit der TileVisu Wetter Uhr!
