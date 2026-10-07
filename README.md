# DG65 Toolkit Batteriewächter

Überwacht die Batterien der Geräte im Haus (Funksensoren, Thermostate, Fenster- und Rauchmelder …) für IP-Symcon. Findet die Batteriewerte selbst, führt mehrere Signale eines Geräts zusammen und sagt ehrlich dazu, **wie verlässlich** der Wert ist.

> Status: **0.1.0, Beta** (Meilenstein 1). Meldungen (Push/E-Mail), Batterietagebuch, Kachel und Prognose folgen in den nächsten Versionen.

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

`BWACH_Search(<InstanzID>)`, `BWACH_Check(<InstanzID>)`, `BWACH_Preview(<InstanzID>)` — alle liefern einen Text.

## Prüfstand

```
php .tools/test-bwach.php        # Prüfstand ohne Symcon
php .tools/mutation-bwach.php    # Mutationstests
php .tools/check-standalone.php  # keine ungesicherten Fremdaufrufe
```

Das Konzept mit allen betrachteten Ideen und Ausbaustufen steht in `.docs/Batterie-Konzept.md`.

## Lizenz

PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei, gewerblich nur mit gesonderter Lizenz. Siehe `LICENSE`.
