# DG65 Toolkit Batteriewächter

Überwacht die Batterien der Geräte im Haus (Funksensoren, Thermostate, Fenster- und Rauchmelder …) für IP-Symcon. Findet die Batteriewerte selbst, führt mehrere Signale eines Geräts zusammen und sagt ehrlich dazu, **wie verlässlich** der Wert ist.

> Status: **0.2.0, Beta** (Meilenstein 2). Kachel, Prognose, Spannungskurven und Statistik folgen in den nächsten Versionen.

## Was es anders macht

- **Drei Zeiten statt einer:** Alter des Batteriewerts, Lebenszeichen des Geräts und das Gerät selbst. Ein Gerät, das seit Wochen nichts sendet (Funkstille), ist etwas anderes als eine schwache Batterie, und ein „OK“ aus dem Jahr 2023 ist kein OK.
- **Datenqualität sichtbar:** Widerspruch zwischen „schwach“-Flag und Prozentwert, Werte außerhalb von 0–100 %, veraltete Batteriewerte.
- **Finden statt pflegen:** Variablenprofile (`~Battery`, `~Battery.Reversed`, `~Battery.100`) und typische Bezeichner; Trockenlauf zeigt vorher, was gefunden würde und warum etwas ausgeschlossen wird.
- **Keine Heimspeicher-Fehltreffer:** Energiespeicher, Fahrzeugakkus, Sammelwerte („Schwächste Batterie“ eines Raums) und Skriptvariablen werden begründet ausgeschlossen.

## Einrichten

1. Modul über die Modulverwaltung hinzufügen: `https://github.com/DG65/Batteriewaechter` (Branch `beta`).
2. Instanz „Batteriewächter“ anlegen.
3. „🔎 Jetzt neu suchen“, dann „📋 Was würde gefunden?“ prüfen.
4. Ereignismelder (Fenster-/Rauchmelder), kritische Geräte und Geräte ohne Altersprüfung unter „Geräte-Einstellungen“ eintragen.
5. Die Variablen „Handlungsbedarf“ und „Alle Geräte“ (HTML-Tabellen) per Verknüpfung ins WebFront legen.

## Meldungen (standardmäßig aus)

Unter „🔔 Meldungen“ einschalten. Gemeldet werden „Batterie leer“, „Batterie schwach“ und „Funkstille“; zweifelhafte Daten (veraltet, Widerspruch) stehen nur im Wochenbericht und in den Tabellen.

- **Nicht bei jeder Prüfung:** erste Meldung, dann Erinnerung nach N Tagen (kritische Geräte alle 2 Tage), danach Ruhe. Ein neuer, schlimmerer Befund ist wieder eine erste Meldung.
- **Gebündelt:** mehrere Befunde eines Laufs = eine Nachricht (fünf Zeilen, dann „… und N weitere“).
- **Ruhezeit** (Standard 22–7 Uhr): Meldungen werden nachgeholt, nicht verworfen; kritische Geräte dürfen sie durchbrechen.
- **Eskalation** nur für kritische Geräte: nach 24 Stunden ohne Quittung eine zweite Meldung über die Eskalationswege.
- **Wochenbericht** (Standard Montag 8 Uhr) mit allen Geräten mit Befund.
- **Zustellung:** Push an alle gefundenen Kachel-Visualisierungen und WebFront-Instanzen (oder eine Auswahl) und/oder E-Mail über eine SMTP-Instanz. „📨 Testmeldung senden“ zeigt, was ankam.

## Quittieren und Batterietagebuch

Unter „✅ Quittieren und Batterietagebuch“ Gerät und Aktion wählen: **Habe ich getauscht** (kommt mit Datum ins Tagebuch, 3 Tage Wartezeit bis das Gerät den neuen Stand meldet), **Erinnere mich später** oder **Gerät ist außer Betrieb** (wird nicht mehr überwacht, jederzeit wieder aufnehmbar). Das Tagebuch erkennt Wechsel auch selbst: der Prozentwert steigt um mindestens 25 Punkte (einstellbar) oder das „schwach“-Flag wird zurückgesetzt. Die Tabelle liegt als Variable „Batterietagebuch“ unter der Instanz.

## Unterstützte Systeme

| System | Erkennung | Stand |
|---|---|---|
| Z-Wave (`BatteryVariable`, `BatteryLowVariable`), Symcon-Profile `~Battery*` | Profil, Ident | an einer echten Anlage geprüft |
| CometWiFi, Shelly (Prozent und Spannung), Froggit, Botvac | Profil, Ident | an einer echten Anlage geprüft |
| Zigbee2MQTT (`battery`, `battery_low`), HomeMatic (`LOWBAT`, `LOW_BAT`, `OPERATING_VOLTAGE`), Matter (`BatPercentRemaining`, Halbprozent) | Ident | **nach Dokumentation, nicht an echtem Gerät geprüft** — Rückmeldungen willkommen |

Spannungswerte werden angezeigt, aber noch nicht in Prozent umgerechnet (die Auswertung nach Zelltyp ist für eine spätere Version vorgesehen), weil die Spannung allein den Zelltyp nicht verrät.

## Bedeutung der Status

| Anzeige | Bedeutung |
|---|---|
| ✅ ok / ⚠️ schwach / 🪫 leer | aus Prozentwert (Schwellen einstellbar) oder aus dem „schwach“-Flag des Geräts |
| ❔ unbekannt | nichts Auswertbares (z. B. nur Spannung oder unplausibler Wert) |
| 🔇 Funkstille | seit mehr als N Tagen keine Aktualisierung irgendeiner Variable des Geräts |
| „Wert veraltet“ | der Batteriewert wurde seit mehr als N Tagen nicht gemeldet; manche Geräte melden ihn nur alle paar Monate (dann „Ohne Altersprüfung“) |
| „Widerspruch“ | Flag und Prozentwert sagen Verschiedenes; es gilt das neuere Signal, bei kritischen Geräten das schlechtere |

## Skripte

`BWACH_Search`, `BWACH_Check`, `BWACH_Preview`, `BWACH_SendTest`, `BWACH_UnretireAll` (je `<InstanzID>`), `BWACH_Acknowledge(<InstanzID>, '<Schlüssel>', 'getauscht'|'zurueckgestellt'|'ausser_betrieb')`, `BWACH_Unretire(<InstanzID>, '<Schlüssel>')` — alle liefern einen Text. Die Schlüssel der Geräte nennt „📋 Was würde gefunden?“ nicht; einfacher ist die Auswahl im Formular.

## Prüfstand

```
php .tools/test-bwach.php        # Prüfstand ohne Symcon
php .tools/mutation-bwach.php    # Mutationstests
php .tools/check-standalone.php  # keine ungesicherten Fremdaufrufe
```

Das Konzept mit allen betrachteten Ideen und Ausbaustufen steht in `.docs/Batterie-Konzept.md`.

## Lizenz

PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei, gewerblich nur mit gesonderter Lizenz. Siehe `LICENSE`.
