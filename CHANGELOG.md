# Changelog

Alle nennenswerten Änderungen an diesem Modul werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

## [0.2.0] - 2026-10-08

### Added
- **Meldungen ohne Nerven** (Meilenstein 2, standardmäßig AUS bis „Meldungen aktiv“): Gemeldet werden „Batterie leer“, „Batterie schwach“ und „Funkstille“. Erste Meldung, Erinnerung nach N Tagen (kritische Geräte früher), Ruhezeit (wird nachgeholt, kritische Geräte dürfen durchbrechen), Wochenbericht. Mehrere Befunde eines Laufs kommen als EINE Nachricht. Wackelnde Werte melden nicht jedes Mal neu (24 Stunden Karenz).
- **Zustellung** per Push an Kachel-Visualisierung (`VISU_PostNotificationEx`) und klassisches WebFront (`WFC_PushNotification`), Titel/Text byte-sicher gekürzt, optional auf ausgewählte Ziele beschränkt, sowie per E-Mail (SMTP-Instanz). Testmeldung mit Ergebnis. Schlägt die Zustellung auf allen Wegen fehl, wird die Meldung nicht als gemeldet verbucht und im Meldungslog vermerkt.
- **Eskalation** für kritische Geräte, die niemand beachtet, über eigene Wege.
- **Quittieren**: „Habe ich getauscht“ (3 Tage Wartezeit), „Erinnere mich später“, „Gerät ist außer Betrieb“ (Liste, wieder aufnehmbar).
- **Batterietagebuch**: Batteriewechsel werden erkannt (Prozentwert springt hoch, „schwach“-Flag wird zurückgesetzt) oder von Hand eingetragen; Tabelle als Variable. Zustandsspeicher in versteckten Variablen, damit sie das Neu-Registrieren des Moduls überstehen.

## [0.1.0] - 2026-10-07

### Added
- Erstes Release (Meilenstein 1). Findet Batteriesignale automatisch: Variablenprofile `~Battery`, `~Battery.Reversed`, `~Battery.100`, typische Idents (Z-Wave, Comet, Shelly, Zigbee2MQTT, HomeMatic, Matter), Profile in neuen Darstellungen; Namenssuche nur auf Wunsch.
- Mehrere Signale eines Geräts (Prozent, „schwach“-Flag, Spannung) werden zu einem Gerät zusammengeführt; mehrere gleichartige Sensoren an einer Instanz werden getrennt geführt.
- Begründete Ausschlüsse: Energiespeicher-Module (Liste einstellbar), Sammelwerte und Sammelgeräte (Räume), Variablen ohne Geräteinstanz.
- Status ok/schwach/leer/unbekannt, Datenqualität (Widerspruch Flag/Prozent, unplausibler Wert, veralteter Batteriewert) und Funkstille als eigener Befund aus dem Lebenszeichen des Geräts.
- Geräte-Einstellungen: Gruppe „Ereignismelder“, „kritisch“, „ohne Altersprüfung“, „ausnehmen“; einstellbare Schwellen.
- Trockenlauf „Was würde gefunden?“, Kennzahlen-Variablen und zwei Tabellen-Variablen (Handlungsbedarf, Alle Geräte).
- Ereignisgesteuerte Bewertung der Batteriewerte, zeitgesteuerte Prüfung von Funkstille und Wertalter.
