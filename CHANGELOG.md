# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

## [1.2.0] - 2026-10-12

### Hinzugefügt
- **Defekte Dateien** (Medienpool → Tools): findet fehlende, leere, falsch benannte (Inhalt passt nicht zur Endung) und beschädigte bzw. abgeschnittene Dateien, veraltete Angaben (Größe/Typ/Abmessungen) und verwaiste Dateien im Medienordner.
    - Aktionen: Endung korrigieren (inkl. Umstellen aller Verweise in der Datenbank), Angaben korrigieren, Löschen (nur unbenutzte).
    - Handyfotos mit Zusatzdaten hinter der JPEG-Endmarke (Samsung „SEFT“, Bewegungsfotos) gelten nicht als defekt.
    - Läuft in Etappen, API mit CSRF-Schutz, neues Recht `mediapool_tools[broken_media]`.

### Behoben
- **Bulk-Resize ohne Imagick (GD) drehte Hochformat-Fotos quer** ([#6](https://github.com/FriendsOfREDAXO/mediapool_tools/issues/6)): Die EXIF-Orientation (1–8) wird jetzt vor dem Verkleinern auf die Pixel angewendet, da GD sie ignoriert und ohne EXIF speichert. Außerdem prüft die „muss verkleinert werden?“-Abfrage bei gedrehten Fotos die sichtbaren (vertauschten) Maße – auch im Imagick-Pfad.

## [1.0.0] - 2026-01-30

### Hinzugefügt
- **Initial Release** des Addons.
- **Unused Media Finder**:
    - Algorithmus zum Scannen der gesamten Datenbankstruktur nach Dateinamen.
    - UI mit Tabelle, Vorschau-Icons und Filtern.
    - Modal-Vorschau für Bilder und Videos.
    - Batch-Processing via AJAX für Performance bei großen Datenmengen.
    - Feature: "Als geschützt markieren" (blendet Dateien aus und schützt sie global vor Löschung).
    - Feature: "Erzwungenes Löschen" (Force Delete) für hartnäckige Dateien.
    - Feature: Medien in andere Kategorien verschieben.
    - Globaler Extension-Point-Hook (`MEDIA_IS_IN_USE`) für geschützte Dateien.
- **Bulk Resize**:
    - Übernahme der Funktionalität aus dem Uploader-Addon.
    - Skalierung von Bildern auf Maximalwerte.
