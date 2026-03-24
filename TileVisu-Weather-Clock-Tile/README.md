# TileVisuWeatherClockTile

## Funktionsumfang

- Zeigt eine **FlipClock** (Uhrzeit mit optionalen Sekunden) und das aktuelle **Datum** als HTML-Kachel in der TileVisu an.
- Lädt über die **Open-Meteo API** automatisch aktuelle Wetterdaten (Temperatur, WMO-Code, Tag/Nacht) und eine **3-Tage-Vorhersage**.
- Wählt anhand des Wetters ein passendes **Hintergrundbild** (Tag/Nacht) aus und zeigt es mit Crossfade an.
- Unterstützt ein optionales **benutzerdefiniertes Hintergrundbild** (Medienobjekt).
- Bilder und Assets werden über einen automatisch registrierten **WebHook** ausgeliefert – keine externen CDNs nötig.
- Alle Texte sind über `locale.json` in **Deutsch** und **Englisch** lokalisiert.

## Voraussetzungen

- IP-Symcon ab Version **7.1**
- Internetzugang für die Open-Meteo API (kein API-Key erforderlich)

## Installation

1. Bibliothek über den Module Store oder die folgende URL hinzufügen:
   ```
   https://github.com/da8ter/TileVisu-Weather-Clock-Tile.git
   ```
2. Im Objektbaum eine neue Instanz vom Typ **TileVisuWeatherClockTile** anlegen.
3. Standort im Konfigurationsformular auswählen.

Der benötigte WebHook (`/hook/wetterbilder/<InstanceID>`) wird automatisch registriert.

## Konfiguration

| Eigenschaft | Typ | Standard | Beschreibung |
| ----------- | --- | -------- | ------------ |
| **Temperatur-Variable** | SelectVariable | – | Symcon-Variable (Integer/Float) zur Anzeige der aktuellen Außentemperatur. Wenn leer, wird der Open-Meteo-Wert verwendet. |
| **Standort** | SelectLocation | – | Standort (Latitude/Longitude) für die Open-Meteo-Abfrage. Fallback: 90.0 / 0.0. |
| **Wetter** | CheckBox | ✔ | Wetteranzeige (Hintergrundbild, Temperatur, Vorhersage) ein-/ausschalten. |
| **Aktuell und Vorhersage** | CheckBox | ✔ | 3-Tage-Vorhersage ein-/ausblenden. |
| **Animierte Icons** | CheckBox | ✘ | Animierte Wettericons verwenden. |
| **Icon-Style: Linie/Fläche** | CheckBox | ✔ | Outline- oder Full-Icons verwenden. |
| **Eigenes Hintergrundbild** | SelectMedia | – | Optionales Medienobjekt als Hintergrundbild (ersetzt das dynamische Wetterbild). |
| **Uhr** | CheckBox | ✔ | FlipClock-Uhr ein-/ausblenden. |
| **Datum** | CheckBox | ✔ | Datum ein-/ausblenden. |
| **Sekunden anzeigen** | CheckBox | ✘ | Sekundenanzeige in der FlipClock. |
| **Breite Vorhersage (%)** | NumberSpinner | 25 | Breite des Vorhersagebereichs in % der Fensterbreite (5–100). |
| **Breite Uhr (%)** | NumberSpinner | 70 | Breite der Uhr in % der Fensterbreite (5–100). |
| **Vertikale Position der Uhr (%)** | NumberSpinner | 50 | Vertikale Position der Uhr (20–70 %). |
| **Schriftgröße Datum (px, 0 = auto)** | NumberSpinner | 0 | Feste Datumsschriftgröße in px. 0 = automatisch aus Uhrgröße berechnet. |
| **Größenfaktor Datum (1–5)** | NumberSpinner | 3 | Faktor für die automatische Datumsschrift relativ zur Uhrgröße. |
| **Wetterdaten in Variable schreiben** | CheckBox | ✘ | Legt eine String-Variable `OpenMeteoRaw` an und schreibt den letzten Open-Meteo JSON-Response hinein. |
| **Bild-URL in Variable schreiben** | CheckBox | ✘ | Legt eine String-Variable `CurrentImageUrl` an und schreibt die aktuelle Bild-URL hinein. |

### Hinweise

- Ist **Wetter** deaktiviert, bleibt der Hintergrund leer und es werden keine Wetterdaten abgefragt.
- Ist ein **Eigenes Hintergrundbild** gesetzt, wird dieses als Hintergrund verwendet – Temperatur und Vorhersage bleiben aktiv, solange **Wetter** eingeschaltet ist.

## Exportierte PHP-Befehle

| Befehl | Beschreibung |
| ------ | ------------ |
| `TVWC_UpdateNow(int $InstanceID)` | Aktualisiert Wetterbild, Temperatur und Vorhersage sofort (entspricht dem stündlichen Timer). |

## Variablen

| Ident | Typ | Beschreibung |
| ----- | --- | ------------ |
| `OpenMeteoRaw` | String | Letzter Open-Meteo JSON-Response (nur wenn *Wetterdaten in Variable schreiben* aktiv). |
| `CurrentImageUrl` | String | Aktuelle Bild-URL (nur wenn *Bild-URL in Variable schreiben* aktiv). |

## Technische Details

- **Uhrzeit-Synchronisation**: Die FlipClock wird clientseitig jede Sekunde anhand der Systemzeit (`new Date()`) aktualisiert, um Drift durch Browser-Tab-Throttling zu vermeiden.
- **Wetter-Update**: Alle 60 Minuten per Timer (`UpdateTimer`).
- **WebHook**: Bilder und FlipClock-Assets werden tokengesichert über `/hook/wetterbilder/<InstanceID>` ausgeliefert.
