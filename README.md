# Mediapool Tools

Eine Sammlung nützlicher Werkzeuge zur Wartung und Bereinigung des REDAXO Medienpools.

## Funktionen

### 1. Unused Media Finder (Unbenutzte Medien)
Dieses Tool hilft dabei, den Medienpool aufzuräumen, indem es Dateien identifiziert, die in der Datenbank (Artikel, Meta-Infos, YForm, etc.) scheinbar keine Verwendung finden.

**Features:**
*   **Tiefen-Scan**: Durchsucht tabellenübergreifend alle relevanten Spalten nach Vorkommen von Dateinamen.
*   **Stapelverarbeitung**: Scannt auch große Datenbanken performant in kleinen Schritten (AJAX).
*   **Vorschau**: 
    *   Bilder und Videos direkt im Modal ansehen.
    *   PDFs und andere Dateien per Klick öffnen.
    *   Nicht-Bild-Dateien werden mit passenden Icons (FontAwesome) dargestellt.
*   **Aktionen**:
    *   **Verschieben**: Massenhaftes Verschieben unbenutzter Dateien in eine "Archiv"-Kategorie.
    *   **Löschen**: Löschen von unbenutzten Dateien.
    *   **Erzwungenes Löschen**: Mit der Option "Verwendung ignorieren" können auch Dateien gelöscht werden, bei denen REDAXO noch eine (evtl. fehlerhafte) Verknüpfung meldet.
    *   **Schützen**: Markieren Sie Dateien als "geschützt". Diese tauchen in zukünftigen Scans nicht mehr auf und sind systemweit vor manueller Löschung gesperrt.

### 2. Bulk Resize (Bildoptimierung)
Skaliert nachträglich Bilder im Medienpool, die definierte Maximalmaße überschreiten.

*   Ideal um "Altlasten" zu optimieren.
*   Grenzwerte für Breite/Höhe konfigurierbar.
*   Zeigt Einsparungspotential an.

## Installation

1.  Paket installieren und aktivieren.
2.  Die Tools finden sich im Hauptmenü unter "Mediapool Tools".

## Konfiguration

Standardwerte für den Bulk-Resize können in der `package.yml` angepasst werden (Default: 2000x2000px):

```yaml
config:
    image-max-width: 2000
    image-max-height: 2000
```

## Hinweise zur Sicherheit

*   **Backup**: Vor dem massenhaften Löschen von Dateien sollte **immer** ein Backup der Datenbank und der Dateien erstellt werden.
*   **Scan-Logik**: Der Scanner sucht nach dem reinen Dateinamen (z.B. `dateiname.jpg`) in der Datenbank. Wenn Dateien nur fest im PHP-Code oder in Templates referenziert sind (und nicht in der DB stehen), werden sie als "unbenutzt" erkannt. Nutzen Sie im Zweifel die "Schützen"-Funktion.
*   **Schutz**: Geschützte Dateien werden in einer eigenen Tabelle `rex_mediapool_tools_protected` gespeichert.

## Lizenz

Siehe LICENSE.md (MIT)

## Author

**Friends Of REDAXO**

* http://www.redaxo.org
* https://github.com/FriendsOfREDAXO
