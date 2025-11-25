# TileVisu Wetter Uhr

Die **TileVisu Wetter Uhr Kachel** erweitert IP-Symcon um eine HTML-Kachel, die aktuelle Wetterinformationen, eine dreitägige Vorhersage und eine FlipClock-Uhr kombiniert.

## Features
- **Wetter-Header**: Die Kachel zeigt Temperatur, Tagesname und Min-/Max-Werte basierend auf dem ersten Eintrag der Prognose.
- **Dynamische Wetterbilder**: Je nach Wetter wird ein passendes Hintergrundbild angezeigt.
- **FlipClock-Integration**: Uhrzeit und Datum erscheinen als FlipClock.

## Voraussetzungen
- **IP-Symcon** 7.1.
- **Open-Meteo API**: Standortauswahl im Formular, die Service-URL wird automatisch erzeugt (kein API‑Key erforderlich).
- Optional: Eine Temperaturvariable (String/Float) zur Anzeige der aktuellen Außentemperatur. Falls nicht gesetzt, wird die aktuelle Temperatur aus der Open-Meteo API verwendet.

## Installation
- Modul aus dem Module-Store auswählen oder über die GitHub-URL `https://github.com/da8ter/TileVisu-Wetter-Uhr.git` hinzufügen.
- Instanz `TileVisuWeatherClockTile` im Objektbaum anlegen.
- Das Modul registriert automatisch den benötigten WebHook unter `/hook/wetterbilder/<InstanceID>`.

## Konfiguration
Im Formular stehen folgende Einstellungen zur Verfügung:
- **Temperature variable**: Symcon-Variable, deren formatierter Wert in der Kachel angezeigt wird.
- **Location**: Standortauswahl (Latitude/Longitude werden automatisch in die Open‑Meteo‑URL eingesetzt). Wenn kein Standort gewählt ist, wird als Fallback der Nordpol (90.0, 0.0) verwendet.
- **Show weather**: Schaltet die komplette Wetteranzeige (Hintergrundbild, Temperatur, Vorhersage) ein/aus. Wenn deaktiviert, werden keine Wetterdaten mehr abgefragt/gesendet.
- **Custom media image**: Optionales Medienobjekt (Bild), das als Hintergrund angezeigt wird. Ist gesetzt, wird dieses Bild anstelle der dynamischen Wetterbilder verwendet (Temperatur/Vorhersage bleiben aktiv, solange „Show weather“ eingeschaltet ist).
- **Show clock**: Blendt die FlipClock-Uhr ein/aus.
- **Show date**: Blendet das Datum ein/aus.
- **Show seconds**: Blendet Sekunden in der FlipClock ein.
- **Forecast width (%)**: Breite der Vorhersage in Prozent.
- **Clock width (%)**: Breite der Uhr in Prozent.
- **Date font size (px, 0 = auto)**: Feste Schriftgröße des Datums in Pixeln. 0 = Automatik.
- **Date size factor (1–5)**: Faktor für die automatische Datumsschrift.

- **Write weather data to variable**: Wenn aktiviert, legt das Modul eine String-Variable `OpenMeteoRaw` an und schreibt dort den letzten Open-Meteo JSON-Response (Wetterdaten) hinein. Wird die Option deaktiviert, wird die Variable automatisch wieder gelöscht.

Hinweis: Wenn „Show weather“ deaktiviert ist, bleibt der Hintergrund leer (kein Fehlerhinweis), und es werden keine Temperatur-/Vorhersage-Updates übertragen. Wenn ein „Custom media image“ (Bild) gesetzt ist, wird dieses genutzt, sobald die Wetteranzeige aktiv ist.

## Funktionsweise (Kurz)
- **Uhrbreite** (`Clock width (%)`): Die FlipClock wird auf die eingestellte Fensterbreite skaliert. Nach dem Resize wird die Schriftgröße der Uhr so gesetzt, dass die Zielbreite exakt getroffen wird.
- **Datum**: Wenn `Date font size (px) = 0`, wird die Datumsschrift automatisch aus der Uhrschrift berechnet. Das Datum bleibt einzeilig.
- **Temperatur + Forecast**: Der Bereich links wächst/ schrumpft horizontal bis maximal zur im Backend eingestellten Breite und bis kurz vor die Uhr.


Viel Spaß mit der TileVisu Wetter Uhr!
