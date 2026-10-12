# Mediapool Tools

Eine Sammlung nützlicher Werkzeuge zur Wartung und Bereinigung des REDAXO Medienpools.

*   **Unused Media Finder**: Finden und Löschen unbenutzter Dateien.
*   **Duplicate Finder**: Finden und Zusammenführen doppelter Dateien.
*   **Bulk Resize**: Nachträgliche Massen-Skalierung von Bildern.

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
*   **Bildbibliotheken in dieser Reihenfolge:** **libvips** (Programm `vipsthumbnail`, Paket `libvips-tools` – schnell und sparsam, auch bei sehr großen Fotos) → **ImageMagick** (PHP-Extension `imagick`, sonst Programm `convert`) → **GD** als letzte Lösung. Scheitert ein Weg, wird der nächste versucht; die Seite zeigt, was verfügbar ist.
*   Hochformat-Fotos mit EXIF-Drehung bleiben in allen drei Wegen richtig herum.

### 3. Duplicate Finder (Doppelte Dateien)
Findet identische Dateien im Medienpool basierend auf Dateigröße und Inhalt (MD5-Hash).

**Features:**
*   **Exakte Analyse**: Vergleicht Dateien nicht nur visuell, sondern binär auf Gleichheit.
*   **Merge (Zusammenführen)**: Wählen Sie die Datei aus, die Sie behalten möchten. Alle Duplikate werden gelöscht, und **sämtliche Verweise in der Datenbank** werden automatisch auf die verbleibende Datei umgebogen.
    *   Der Scanner durchsucht dabei, ähnlich wie der "Unused Media Finder", alle Tabellen nach Vorkommen des Dateinamens.
*   **Sicher**: Cache wird nach dem Vorgang automatisch geleert, damit Änderungen sofort sichtbar sind.
*   **Übersichtlich**: Gruppierte Darstellung aller Duplikate inkl. Vorschau und Link zum Medienpool.

### 4. Defekte Dateien
Findet Dateien im Medienpool, die kaputt oder falsch benannt sind – solche Dateien werden falsch ausgegeben und lassen z. B. die KI-Bildbeschreibung (mediaplace) mit „Bad Request“ scheitern.

**Erkennt:**
*   **Datei fehlt**: Eintrag im Medienpool, aber keine Datei auf dem Server.
*   **Leer**: Datei mit 0 Byte (fehlgeschlagener Upload).
*   **Falsche Endung**: Inhalt passt nicht zur Endung, z. B. ein PDF als `.jpg` oder ein PNG als `.jpg` – mit Vorschlag für die richtige Endung.
*   **Beschädigt**: Bild lässt sich nicht lesen oder ist abgeschnitten (JPEG ohne Endmarke, PNG ohne IEND). Handyfotos mit angehängten Daten hinter der Endmarke (z. B. Samsung „SEFT“, Bewegungsfotos) werden per Probe-Dekodierung erkannt und nicht als defekt gemeldet.
*   **Angaben veraltet**: Größe, Typ oder Abmessungen im Medienpool weichen von der Datei ab.
*   **Verwaiste Dateien**: im Medienordner ohne Eintrag im Medienpool (nur als Hinweis, ohne Aktion).

**Aktionen je Datei:**
*   **Endung korrigieren**: Datei wird unter dem richtigen Namen angelegt, **alle Verweise in der Datenbank** werden Tabelle für Tabelle umgestellt (wie beim Duplicate Finder), danach Eintrag umbenannt und alte Datei entfernt.
*   **Angaben korrigieren**: Größe, Typ und Abmessungen aus der Datei übernehmen.
*   **Löschen**: nur, wenn die Datei nirgends verwendet wird (Prüfung des Medienpools).

Die Prüfung läuft in Etappen und hält auch große Medienpools aus; die Liste zeigt die schwersten Befunde zuerst (höchstens 300 Zeilen). Recht: `mediapool_tools[broken_media]`.

## Installation

1.  Paket installieren und aktivieren.
2.  Die Tools finden sich im Hauptmenü unter "Tools" im Medienpool.

## Konfiguration

Die Einstellungen für den Bulk-Resize (z.B. maximale Breite/Höhe) sowie Grenzwerte für die Analyse können bequem über die **Einstellungen**-Seite im AddOn konfiguriert werden.

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
