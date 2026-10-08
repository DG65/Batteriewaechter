# DG65 Toolkit Batteriewächter

Überwacht die Batterien der Geräte im Haus (Funksensoren, Thermostate, Fenster- und Rauchmelder …) für IP-Symcon. Findet die Batteriewerte selbst, führt mehrere Signale eines Geräts zusammen und sagt ehrlich dazu, **wie verlässlich** der Wert ist.

> Status: **0.5.1, Beta** (Stufe 1 bis 3 des Konzepts gebaut). Was an echten Langzeitdaten noch fehlt, steht unter „Ehrliche Grenzen“.

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

## Zelltyp, Prognose, Einkauf

- **Zelltyp je Gerät** unter „Geräte-Einstellungen“ (z. B. CR2032, 3× AAA). Die Liste enthält schon jedes erkannte Gerät; es genügt, Zelltyp und Anzahl Zellen zu wählen und „Übernehmen“ zu klicken. Damit rechnet der Wächter Spannungen in einen Ladezustand um und zählt die Einkaufsliste. Die Umrechnung ist eine **Näherung** mit typischen Entladekurven je Zellchemie, keine Datenblattwerte eines Herstellers, und wird immer als „aus Spannung berechnet“ gekennzeichnet. Aus der Spannung allein lässt sich der Zelltyp nicht sicher erkennen (3 V: Knopfzelle oder zwei Alkali-Zellen); der Wächter nennt nur Kandidaten.
- **Prognose:** Er schreibt den Verlauf jedes Geräts im Modul selbst mit und schätzt die Restlaufzeit („reicht noch etwa 23 Tage, mittlere Sicherheit“). Das braucht mindestens 4 Messpunkte über 14 Tage seit dem letzten Batteriewechsel und einen Ladezustand, der sich in feinen Schritten ändert. Viele Geräte melden nur grobe Stufen oder sehr selten; dort steht ehrlich „Restlaufzeit unbekannt“ mit Grund. „Batterie bald leer“ wird nur bei hoher oder mittlerer Sicherheit gemeldet.
- **Einkaufsliste und Tauschrunde:** „4× CR2032, 7× AAA“ für die nächsten 30 Tage (einstellbar), die Tauschrunde nach Ort gebündelt mit Vorschlag, bis wann. Geräte ohne Zelltyp stehen gesondert dabei.
- **Lebensdauer:** Aus den erfassten Batteriewechseln (Tagebuch) entsteht je Gerät, Zelltyp und System die Zeit zwischen zwei Wechseln. Das braucht pro Gerät mindestens zwei Wechsel und wächst erst mit der Zeit.
- **Gelerntes Meldeverhalten und „vermutlich ausgebaut“:** Meldet ein Gerät sehr regelmäßig, erkennt der Wächter Funkstille früher als mit der festen Schwelle (nie später). Ein Gerät, das seit über 60 Tagen still ist, wird als „vermutlich ausgebaut“ vorgeschlagen.

## Auffällige Geräte, Funkqualität, Kälte, Abfrage

- **Vergleich mit Gleichartigen:** Entlädt ein Gerät mindestens doppelt so schnell wie der Median seiner Gruppe (gleiches System, gleicher Zelltyp, mindestens 4 Geräte mit bekannter Entladerate), markiert der Wächter es und nennt mögliche Ursachen (defektes Gerät, schlechte Funkverbindung, Dauersenden). Das gibt es nur in den Tabellen, der Kachel und dem Wochenbericht, nicht als Push.
- **Funkqualität:** Wo ein Gerät sie liefert (z. B. Zigbee `linkquality`, RSSI), zeigt der Wächter sie an und stuft sie ein. Die Skalen sind je System verschieden; unbekannte werden nur angezeigt.
- **Kälteeinfluss:** Mit einer Außentemperatur-Variable (Formular „Prognose und Einkauf“) wertet der Wächter aus, ob sich die Batterien bei Kälte schneller entladen. Das braucht mindestens 3 Geräte mit je 3 Zeitabschnitten bei Kälte und bei Wärme, also frühestens nach einem Winter; bis dahin steht „noch nicht genug Daten“.
- **Abfrage schlafender Z-Wave-Geräte:** Je Gerät einzuschalten (Spalte „Abfragen“), standardmäßig aus. Ist der Batteriewert älter als eingestellt, schickt der Wächter höchstens alle 7 Tage eine Statusanfrage und zeigt, ob das Gerät geantwortet hat. Ein schlafendes Gerät antwortet erst beim nächsten Aufwachen; bis dahin liegt die Anfrage in der Warteschlange der Z-Wave-Instanz. Ob eine Abfrage den Batteriewert schneller zurückbringt als das normale Aufwachen, ist an echten Geräten nicht belegt.
- **Übernahme aus BY_BatterieMonitor:** Im Panel „Meldungen“ lassen sich Push-, E-Mail- und SMTP-Einstellungen einer alten Instanz in die Maske übernehmen. Gespeichert wird erst mit „Übernehmen“.

## Ehrliche Grenzen

- Die **Entladekurven** sind typische Näherungen, nicht gemessen und nicht herstellerspezifisch.
- Die **Prognose** braucht Wochen Verlauf und Geräte, die den Ladezustand fein genug melden. Z-Wave-Sensoren, die bis kurz vor leer „100 %“ zeigen, bekommen keine; dort hilft nur „leer/schwach“ und die Funkstille-Erkennung.
- **Lebensdauer, Kälteeinfluss und der Gleichartigen-Vergleich** brauchen Daten, die erst über Monate entstehen. Im Prüfstand sind sie mit simulierten Verläufen belegt, nicht mit Langzeitdaten echter Geräte.
- Eine **Zelltyp-Bibliothek nach Gerätemodell** gibt es bewusst nicht: Ohne eine belastbare, gepflegte Quelle würde sie Modelle raten. Der Wächter nennt Kandidaten aus der Spannung, der Zelltyp wird je Gerät von Hand gewählt.
- Zigbee2MQTT, Matter und HomeMatic sind nach Dokumentation angebunden, aber nicht an echten Batteriegeräten geprüft.

## Kachel

Die Instanz lässt sich in der Kachel-Visualisierung als Kachel hinzufügen. Sie zeigt die Geräte nach Dringlichkeit; die Filterzeile oben blendet nur ein, was gerade wichtig ist (Handlungsbedarf, leer, schwach, Funkstille, Daten prüfen), „📓 Tagebuch“ zeigt die erfassten Batteriewechsel. Antippen einer Zeile klappt sie auf: Gründe, Alter des Batteriewerts, Lebenszeichen und die Schaltflächen zum Quittieren (abschaltbar unter „Zustand“). Die Kachel hat keinen eigenen Titel, den liefert der Instanzname. Zusätzlich liegen „Handlungsbedarf“, „Alle Geräte“ und „Batterietagebuch“ als HTML-Variablen unter der Instanz im Objektbaum.

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

`BWACH_Search`, `BWACH_Check`, `BWACH_Preview`, `BWACH_SendTest`, `BWACH_UnretireAll` (je `<InstanzID>`), `BWACH_Acknowledge(<InstanzID>, '<Schlüssel>', 'getauscht'|'zurueckgestellt'|'ausser_betrieb')`, `BWACH_Unretire(<InstanzID>, '<Schlüssel>')` — alle liefern einen Text. Die Schlüssel der Geräte nennt „📋 Was würde gefunden?“; einfacher ist die Auswahl im Formular.

## Prüfstand

```
php .tools/test-bwach.php        # Prüfstand ohne Symcon
php .tools/mutation-bwach.php    # Mutationstests
php .tools/check-standalone.php  # keine ungesicherten Fremdaufrufe
```

Das Konzept mit allen betrachteten Ideen und Ausbaustufen steht in `.docs/Batterie-Konzept.md`.

## Lizenz

PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei, gewerblich nur mit gesonderter Lizenz. Siehe `LICENSE`.
