# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

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
