# Changelog

Alle nennenswerten Änderungen an diesem Modul werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

## [0.1.0] - 2026-10-07

### Added
- Erstes Release (Meilenstein 1). Findet Batteriesignale automatisch: Variablenprofile `~Battery`, `~Battery.Reversed`, `~Battery.100`, typische Idents (Z-Wave, Comet, Shelly, Zigbee2MQTT, HomeMatic, Matter), Profile in neuen Darstellungen; Namenssuche nur auf Wunsch.
- Mehrere Signale eines Geräts (Prozent, „schwach“-Flag, Spannung) werden zu einem Gerät zusammengeführt; mehrere gleichartige Sensoren an einer Instanz werden getrennt geführt.
- Begründete Ausschlüsse: Energiespeicher-Module (Liste einstellbar), Sammelwerte und Sammelgeräte (Räume), Variablen ohne Geräteinstanz.
- Status ok/schwach/leer/unbekannt, Datenqualität (Widerspruch Flag/Prozent, unplausibler Wert, veralteter Batteriewert) und Funkstille als eigener Befund aus dem Lebenszeichen des Geräts.
- Geräte-Einstellungen: Gruppe „Ereignismelder“, „kritisch“, „ohne Altersprüfung“, „ausnehmen“; einstellbare Schwellen.
- Trockenlauf „Was würde gefunden?“, Kennzahlen-Variablen und zwei Tabellen-Variablen (Handlungsbedarf, Alle Geräte).
- Ereignisgesteuerte Bewertung der Batteriewerte, zeitgesteuerte Prüfung von Funkstille und Wertalter.
