# Batterie-Konzept — Arbeitstitel „Batteriewächter“, Name: **DG65 Toolkit Batteriewächter**

Stand 07.10.2026 · Auftrag der EMS-Sitzung (Kickoff `Batterie-Kickoff.md`) · **Nur Konzept, kein Modul-Code vor Dietmars Freigabe.**

## 1. Kurzfassung

- **Empfehlung: ein eigenes, kleines DG65-Toolkit-Modul bauen**, aber mit bewusst anderem Schwerpunkt als im Briefing angenommen: nicht „Prognose zuerst“, sondern **Finden → Verstehen (Plausibilität, Alter) → Funkstille trennen → ruhig melden → Wechsel-Tagebuch**. Die Prognose kommt als zweite Stufe und sagt ehrlich „unbekannt“, wo die Daten sie nicht tragen.
- **Ehrliche Gegenseite:** Wer nur „Liste der leeren Batterien + E-Mail“ will, ist mit **Profile/Batterie Monitor (elueckel, 1.5)** ausreichend bedient. Ein Fork von BY_BatterieMonitor lohnt nicht (siehe 3).
- **Warum trotzdem bauen:** Die Live-Prüfung bei Dietmar zeigt Fälle, die weder das alte Modul noch elueckel erkennen können (Abschnitt 2): Widerspruch zweier Signale am selben Gerät (als Regel, bei Dietmar aktuell nicht live), drei Jahre alte Batteriewerte, Doppelzählung durch Sammelvariablen, Werte außerhalb des Wertebereichs, Spannung ohne Zelltyp.
- **Ehrliche Korrektur an der Briefing-Erwartung:** Die „Restlaufzeit aus dem Archiv“ ist bei Dietmars Geräten **für die meisten nicht möglich** (Z-Wave-Sensoren stehen bis kurz vor leer auf 100 %, das Archiv hat dort 15 Werte in der ganzen Laufzeit). Sie bleibt ein Ziel, ist aber nicht das Alleinstellungsmerkmal des ersten Schnitts.

## 2. Befund am Privat-Symcon (07.10.2026, rein lesend)

Zugriff über `ips-automation-Privat`, nur Lesen (die Abfragen liefen als Wegwerf-Auswertungsskripte, danach `cleanup_eval_scripts`: nichts übrig). Symcon 9.0, 4932 Variablen.

| System | Anzahl | Wie der Wert ankommt (live gesehen) |
|---|---|---|
| **Z-Wave** | 27 Geräte (Sensoren, Thermostate, Rauchmelder, Schloss) | je Gerät **zwei** Variablen: `BatteryVariable` (Integer, `~Battery.100`) und `BatteryLowVariable` (Bool, `~Battery`). Sensoren stehen auf **100 %**, Thermostate auf 46–89 %. |
| **CometWiFi** (eigenes Modul) | 12 Thermostate + 2 Räume | `Battery` (Integer, Prozent, z. B. 65), `BatteryLow` (Bool). Der Raum liefert zusätzlich `Battery` = „Schwächste Batterie“ → **Sammelwert**, würde bei naiver Suche doppelt zählen. |
| **Shelly** (Batteriegeräte) | 2 | `devicepower_0_battery_percent` (35 %) **und** `devicepower_0_battery_V` (4,7 V). 4,7 V passt nicht zu einer Knopfzelle: **Spannung allein verrät den Zelltyp nicht.** |
| **Froggit** | 4 Bodenfeuchte + Wetterstation | `~Battery.100` mit Stufenwerten (40/60/80) und **−20 %** (Sensor 2, seit 24.07.2025 nicht aktualisiert); Wetterstation `~Battery` (Bool). |
| **BotvacRobot** | 1 | `BATTERY`, `~Battery.100`, 73 %, zuletzt aktualisiert 13.11.2025. |
| **Zigbee2MQTT** | 8 Geräte | **keine** Batterievariable (nur Spannung 233 V → Netzgeräte, plus `linkquality`). Batterie-Fall hier **nicht live verifiziert**. |
| **Matter** | 17 Geräte | **keine** Batterievariable gefunden. Fall **nicht live verifiziert**. |
| **HomeMatic** | 0 | nicht vorhanden → LOW_BAT/OPERATING_VOLTAGE nur aus Dokumentation bekannt, **nicht verifiziert**. |

Konkrete Beobachtungen, aus denen das Konzept abgeleitet ist:

1. **Alter des Batteriewerts ≠ Lebenszeichen des Geräts.** Die Z-Wave-Thermostate melden ihren Batteriewert nur selten (letzte Aktualisierung 03/2023 bis 11/2024), senden aber täglich andere Werte (Sollwert aktuell, 07.10. 17:20). Das alte Modul zeigt „Letztes Var-Update 07.01.2024, OK“ — das sieht nach Funkstille aus und ist keine; umgekehrt ist der Wert drei Jahre alt und damit kaum belastbar. Das sind **zwei verschiedene Befunde**.
2. **Echte Funkstille gibt es:** „Sensor Heizung“ hat seit dem 21.09.2026 (16 Tage) keine einzige Variablenaktualisierung gemeldet.
3. **Widerspruch am selben Gerät (Korrektur 07.10.2026 abends):** Die erste Fassung dieses Konzepts nannte „Sensor Vorrat“ als Widerspruchsfall (altes Modul: LEER seit 19.09., Prozentvariable 100 %). Das war eine **veraltete Tabelle des alten Moduls** (6-Stunden-Takt): bei der Gegenprobe stand das Flag bereits wieder auf „in Ordnung“. Der Widerspruchs-Fall bleibt als Regel im Modul (ein Flag, das nach einem Batteriewechsel hängen bleibt, ist genau so ein Fall), ist aber bei Dietmar im Moment **nicht live zu sehen** — nur im Prüfstand.
4. **Archiv taugt kaum für die Prognose:** Die Z-Wave-Prozentvariable loggt nur Änderungen; über die gesamte Laufzeit stehen 15 Werte im Archiv (Stichprobe an einer Variablen). Bei 100-%-Sensoren gibt es gar keinen Verlauf. Bei den Thermostaten ist das Logging aus.
5. **Altes Modul zählt falsch:** BY_BatterieMonitor meldet „Aktoren gesamt: 18“, tatsächlich haben 27 Z-Wave-Geräte plus Comet/Shelly/Froggit/Botvac Batterien.
6. **Konfiguration der alten Instanz (#12613):** Push aktiv (WebFront-Instanz #58070), 6-Stunden-Intervall, keine Mail, kein eigenes Skript, Texte mit Platzhaltern `-§AKTORNAME-` usw. → Import möglich, aber klein.
7. **Fehltreffer-Gefahr:** Eine Namenssuche nach „Batterie“ liefert bei Dietmar über 60 Variablen, fast alle Heimspeicher/EMS/Wallbox (z. B. „Batterieladung“, „Bat.1 SOC“ mit `~Battery.100`). Heimspeicher müssen standardmäßig ausgeschlossen werden.

## 3. Bauen oder bestehendes Modul empfehlen?

| Option | Bewertung |
|---|---|
| **BY_BatterieMonitor (Bayaro)** | Tot (Repo 404, Forum seit 2021, Latin-1-`form.json`, Fatal Error um 00:00/06:00). Läuft bei Dietmar noch (Status 102), stört aber das Sammel-Update. **Entfernen**, nicht reparieren. |
| **symfork/BY_BatterieMonitor** | Fork mit 74 Commits, README: FHT/FS20/HMS/HomeMatic/Z-Wave, IPS ab 4.x, Version 1.3, **keine Lizenzangabe**. Ob Latin-1 und der `count()`-Fehler behoben sind, habe ich **nicht geprüft** (nicht ausgecheckt). Ohne Lizenz ist ein Weiterbau rechtlich unklar. → nicht empfohlen. |
| **Profile/Batterie Monitor (elueckel)**, 1.5 vom 11.06.2023, ab Symcon 6 | Aktuellstes brauchbares Store-Modul. Sucht Variablen per Profilwerten, HTML-Tabelle, E-Mail/App-Meldung, Ausschlüsse, Zeitstempel. Kann nach Beschreibung **nicht**: Gerätezusammenführung, Spannung, Plausibilität, Funkstille, Quittieren, Wechsel-Tagebuch, Prognose (nach Store-Seite, nicht ausprobiert). Letzte Version vor 3 Jahren. **Reicht für „leere Batterie melden“.** |
| **bumaas/BatteryCellMonitor** | Anderes Thema (Zellspannungen Heimspeicher). Nicht duplizieren. |
| **Home Assistant „Battery Notes“** (Referenz, kein Symcon) | Zeigt, was Nutzer erwarten: Batterietyp-Bibliothek je Gerätemodell, Wechseldatum, „nicht gemeldet“ als eigener Befund, Ereignisse. Beleg, dass die Kombination plattformübergreifend gefragt ist. |
| Suche nach Neuerem (07.10.2026) | Kein neueres Batterie-Modul gefunden. Einschränkung: Websuche und Store-Übersicht, kein vollständiger Durchlauf. |

**Entscheidung:** bauen, **klein starten**. Begründung: Dietmars reale Fälle (Abschnitt 2, Punkte 1–5) sind genau die, an denen die vorhandenen Module scheitern, und sie sind kein Sonderfall (jede Z-Wave-/Zigbee-Installation hat zwei Signale pro Gerät, Sammelwerte und alte Werte). Wenn Dietmar das Ziel enger fasst („nur melden, wenn leer“), ist die ehrliche Antwort: **elueckel installieren, BY entfernen, fertig.**

## 4. Bewertung aller Ideen

Skala: Nutzen/Aufwand/Risiko = niedrig · mittel · hoch. MVP = erster Schnitt. Belege: „live“ = bei Dietmar gesehen, „Doku“ = aus Herstellerdokumentation, „offen“ = nicht geprüft.

### 4.1 Finden statt pflegen

| Idee | Nutzen | Aufwand | Risiko | MVP | Anmerkung |
|---|---|---|---|---|---|
| Suche über Profile `~Battery`, `~Battery.100`, `~Battery.Reversed` | hoch | niedrig | niedrig | **ja** | live: Z-Wave, Comet, Shelly, Froggit, Botvac. Neue Darstellungen (Presentation) haben **leere** Profilfelder → zusätzlich `VariablePresentation` auswerten (SUITE Stolperstein 21). |
| Suche über Idents/Namen (`Battery*`, `LOWBAT`, `LOW_BAT`, `battery_low`, `battery_voltage` …) | hoch | niedrig | mittel | **ja** | nur als Vorschlag, gruppiert nach Gerät; Namenssuche erzeugt Fehltreffer (Beobachtung 7). |
| Gruppierung je Geräteinstanz (mehrere Signale, ein Gerät) | **hoch** | mittel | niedrig | **ja** | Grundlage der Widerspruchserkennung (Beobachtung 3). |
| Sammelwerte ausschließen („Schwächste Batterie“ im Raum) | hoch | niedrig | niedrig | **ja** | Erkennung: Instanz bündelt selbst Kind-Geräte; sonst manuell ausnehmen. |
| Heimspeicher/Fahrzeugakku ausschließen | hoch | niedrig | niedrig | **ja** | Standard: Instanzen von InverterHub, Tessie, EMS, ModBus-Heimspeicher werden nicht vorgeschlagen. |
| Instanztyp-Erkennung je System | mittel | mittel | mittel | später | erst bei echten Fehltreffern; Idents reichen im MVP. |
| Z2M `battery`/`battery_low`, Matter `BatPercentRemaining`, HomeMatic `LOW_BAT`/`OPERATING_VOLTAGE` | hoch | niedrig je System | **mittel** | **nur mit Vorbehalt** | bei Dietmar **nicht live prüfbar**. Im MVP als „laut Dokumentation erwartet, ungetestet“ kennzeichnen, Forum-Tester suchen. Matter-Skala (`BatPercentRemaining` in Halbprozent) vor Zusage prüfen. |
| KNX, EnOcean, LoRaWAN, Tuya, Netatmo, Nuki, USV | mittel | je niedrig | mittel | nein | nur über die allgemeine Suche, **keine eigene Zusage**; später nach Nutzerwunsch. |
| Trockenlauf „Was würde ich finden?“ | hoch | niedrig | niedrig | **ja** | Button zeigt Treffer, Gruppierung, ausgeschlossene Kandidaten mit Begründung; sichtbare Rückmeldung nach SUITE. |
| Manuell hinzufügen/ausnehmen | hoch | niedrig | niedrig | **ja** | |

### 4.2 Werte verstehen

| Idee | Nutzen | Aufwand | Risiko | MVP | Anmerkung |
|---|---|---|---|---|---|
| Einheitlicher Status: ok / schwach / leer / unbekannt (+ Prozent, wenn vorhanden) | hoch | niedrig | niedrig | **ja** | „unbekannt“ ist ein eigener Zustand, nie 0 (SUITE Stolperstein 15). |
| Plausibilität: Wert <0 oder >100, Wert springt, eingefroren | hoch | niedrig | niedrig | **ja** | live: Froggit −20 %. |
| **Widerspruchserkennung** (Flag „leer“, Prozent 100) | hoch | niedrig | niedrig | **ja** | live: Sensor Vorrat. |
| **Alter des Batteriewerts** getrennt anzeigen | **hoch** | niedrig | niedrig | **ja** | Schwelle je Gerätegruppe einstellbar (Z-Wave-Thermostat meldet monatlich, Funksensor täglich); Standard konservativ. |
| Spannung → Prozent über Entladekurven | mittel | **hoch** | **hoch** | **nein (Stufe 2)** | Kurven je Zelltyp (CR2032, CR2450, AAA/AA alkaline/NiMH/Lithium, LiPo, 9 V) sind Näherungen; Zelltyp ist aus der Spannung nicht ableitbar (live: Shelly 4,7 V). Zelltyp wählt der Nutzer; Kurven nur mit Quellenangabe und „Näherung“. |
| Zelltyp-Vorschlag aus Gerätemodell (Bibliothek à la Battery Notes) | mittel | hoch (Pflege) | mittel | nein (Stufe 3) | Bibliothek veraltet schnell; im MVP Zelltyp frei wählbar. |

### 4.3 Prognose und Lerneffekt

| Idee | Nutzen | Aufwand | Risiko | MVP | Anmerkung |
|---|---|---|---|---|---|
| Restlaufzeit aus Verlauf (robuste Regression, Konfidenz, „unbekannt“) | mittel (hoch bei fein auflösenden Geräten) | hoch | mittel | **nein (Stufe 2)** | Datenlage live schlecht (Beobachtung 4). Geeignet: Comet (Prozentstufen), Shelly (Spannung + %), Botvac. **Eigene Verlaufsliste im Modul** statt Archivabhängigkeit (Archivieren darf nicht erzwungen werden, SUITE 9k); Mindestdaten und Streuung bestimmen „unbekannt“. |
| Batteriewechsel erkennen (Wert springt) + **Batterietagebuch** | hoch | niedrig | niedrig | **ja** | Daten lassen sich **nicht nachträglich rekonstruieren** — deshalb früh mitschreiben, auch wenn die Auswertung erst später kommt. Erklärt auch Widersprüche wie Beobachtung 3. Manueller Eintrag „habe getauscht“ gleichwertig. |
| Lebensdauerstatistik je Gerät/Typ/Zelle | mittel | mittel | niedrig | nein (Stufe 2) | braucht Tagebuchdaten, die erst wachsen müssen. |
| Kälteeinfluss | niedrig-mittel | hoch | hoch | nein (Stufe 3) | braucht Temperaturverlauf je Gerät; plausibel, aber ohne Messdaten nur Behauptung. |
| Auffälligkeit gegenüber Gleichartigen (Median der Gruppe) | mittel | mittel | mittel | nein (Stufe 3) | braucht Gruppe ≥ 4 und Verlaufsdaten; erst nach Wochen sinnvoll. |

### 4.4 Funkstille und Gerätegesundheit

| Idee | Nutzen | Aufwand | Risiko | MVP | Anmerkung |
|---|---|---|---|---|---|
| **Lebenszeichen = jüngste Aktualisierung irgendeiner Variable der Geräteinstanz** | **hoch** | niedrig | mittel | **ja** | live bewährt (Thermostate täglich, Sensor Heizung 16 Tage still). Grenzfall: reine Ereignissender (Fensterkontakt, Rauchmelder) → großzügiger Standard je Gerätegruppe, pro Gerät einstellbar. Vor dem Bau prüfen: `VariableUpdated` (jede Meldung) vs. `VariableChanged` (nur Änderung). |
| Meldeintervalle je Gerät lernen statt fester Schwelle | hoch | mittel | mittel | nein (Stufe 2) | MVP: einstellbare Schwellen je Gerätegruppe. |
| RSSI/LQI als Zusatzgrund | niedrig-mittel | niedrig | niedrig | nein (Stufe 3) | live nur Z2M `linkquality` (Netzgeräte); Anzeige statt Regel. |

### 4.5 Planung und Haushalt

| Idee | Nutzen | Aufwand | Risiko | MVP | Anmerkung |
|---|---|---|---|---|---|
| Kritische Geräte (Rauchmelder, Schloss, Alarm) mit eigener Schwelle | hoch | niedrig | niedrig | **ja** | Einstellung je Gerät „kritisch“; keine Vorbelegung aus Dietmars Anlage (Rauchmelder/Schloss nur als Beispiel im Formular). |
| Gruppierung nach Raum/Etage | mittel | niedrig | niedrig | **ja (einfach)** | über die Objektbaum-Elternkette; Katasteramt (`STRUKT_GetStructure`) **optional hinter `function_exists`**, Vertrag vor Nutzung prüfen, nichts annehmen. |
| Einkaufsliste (4×CR2032, 7×AAA …) | mittel | mittel | niedrig | nein (Stufe 2) | braucht Zelltyp (manuell) und Prognose. |
| Tauschrunde (ähnliche Restlaufzeit bündeln) | mittel | mittel | niedrig | nein (Stufe 2) | braucht Prognose. |

### 4.6 Meldungen ohne Nerven

| Idee | Nutzen | Aufwand | Risiko | MVP | Anmerkung |
|---|---|---|---|---|---|
| Push WebFront/Kachel-Visualisierung | hoch | mittel | mittel | **ja** | **SUITE Stolperstein 22:** beide GUIDs suchen, `VISU_PostNotificationEx` (6 Parameter), TargetID, Titel ≤ 32 / Text ≤ 256 byte-sicher kürzen; Vorbild `WarnHub::discoverPushTargets()`. |
| E-Mail (SMTP-Instanz) | mittel | niedrig | niedrig | **ja** | |
| Erste Meldung → Erinnerung nach X Tagen → Wochenzusammenfassung → Ruhezeiten | **hoch** | mittel | niedrig | **ja** | Kern von „ohne Nerven“. Zustand je Gerät persistent, damit ein Neustart nichts doppelt meldet. |
| Quittieren („getauscht“, „außer Betrieb“), Zurückstellen (7 Tage), dauerhaft ausnehmen | hoch | mittel | niedrig | **ja** | Bedienung in Konsole **und** Kachel (Schaltflächen als `EnableAction`-Variablen, SUITE Stolperstein 10). |
| Eskalation (kritisch unbeachtet → zweiter Kanal) | mittel | niedrig | niedrig | nein (Stufe 2) | |
| Weitergabe an andere Meldeschnittstellen im Verbund (WarnHub) | offen | — | — | nein | nur über Vertrag nach Abstimmung. |

### 4.7 Anzeige

| Idee | Nutzen | Aufwand | Risiko | MVP | Anmerkung |
|---|---|---|---|---|---|
| Kachel: nach Dringlichkeit sortiert, Ampel, Filter, **kein eigener Titel** | hoch | mittel-hoch | mittel | **ja (schlank)** | Aufziehen zeigt nur Instanzvariablen → Kernzahlen (leer/schwach/Funkstille) als echte Variablen. Konfiguration nur in der Konsole. |
| Formular nach Verbund-Konvention | hoch | mittel | niedrig | **ja** | „Wozu dieses Modul?“ → „Neu“ (`NEWS_VERSIONS`) → „Doku & Hilfe“ → Fachpanels → Forum-Hinweis → „Über dieses Modul“. Statuszeile für jede automatische Verbindung, live in `GetConfigurationForm()`. |
| HTML-Tabelle als Variable (Ersatz für altes Modul) | mittel | niedrig | niedrig | **ja** | Dietmars alte Instanz liefert genau das (`~HTMLBox`) und ist vermutlich im WebFront verlinkt → Migration beachten. |

### 4.8 Verbund und Schnittstellen

| Idee | Nutzen | Aufwand | Risiko | MVP | Anmerkung |
|---|---|---|---|---|---|
| Lese-Vertrag `BWACH_GetState` (versioniert, additiv) | hoch | niedrig | niedrig | nur bei Bedarf eines Konsumenten | siehe 6. |
| Heimspeicher-Gesundheit (SOH, Zellen) | niedrig | hoch | mittel | **nein, bewusst** | EMS überwacht SOC, `BatteryCellMonitor` deckt Zellen; Vermischung mit Geräte-Batterien wäre Fehltreffer-Quelle. |
| Pförtner (`PFOE_CheckAccess`) für Konfiguration | niedrig | niedrig | niedrig | nein | optional hinter `function_exists`, nur auf Wunsch. |

### 4.9 Migration

| Idee | Nutzen | Aufwand | Risiko | MVP | Anmerkung |
|---|---|---|---|---|---|
| Import aus BY_BatterieMonitor (Texte, Push-Ziel, Intervall) | mittel | niedrig | mittel | **ja** (Einstellungen) | nur zusagen, was getestet ist; Gerätezahl wird abweichen (18 vs. 27), im Formular offen sagen. Hinweis „du kannst die alte Instanz entfernen“. |
| Import aus Profile/Batterie Monitor | niedrig | mittel | mittel | nein | bei Dietmar nicht installiert, nicht prüfbar; nur auf Nutzerwunsch. |

### 4.10 Qualität, Betrieb, Selbstschutz

| Idee | Nutzen | Aufwand | Risiko | MVP | Anmerkung |
|---|---|---|---|---|---|
| „Wer überwacht den Wächter?“ (eigener Lebenszeichen-Check, sichtbarer Status) | hoch | niedrig | niedrig | **ja** | Statuszeile „zuletzt geprüft HH:MM“; `lastSeenAt` im Vertrag (Muster ChargerHub/WPHub). |
| Ereignisgetrieben statt Dauer-Polling | hoch | mittel | mittel | **ja** | `RegisterMessage` auf Batterie-/Lebenszeichen-Variablen; Gesamtlauf nur täglich und bei Bedarf. Obergrenze der Nachrichtenanmeldungen bei hunderten Geräten vorab prüfen. |
| Fallen des Vorgängers: UTF-8 ohne BOM, kein `count()` auf null, fehlende Variablen/Instanzen | hoch | niedrig | niedrig | **ja** | Prüfskript im Prüfstand für alle `*.json`; `Read…()`-Rückgaben gecastet (SUITE 9c). |
| Prüfstand mit Szenarien/Mutationstests, Store-Checkliste, Migrationsvergleich | hoch | mittel | niedrig | **ja** | Szenarien in 7. |

### 4.11 Eigene Ergänzungen

| Idee | Nutzen | Aufwand | Risiko | MVP | Anmerkung |
|---|---|---|---|---|---|
| **Drei-Zeiten-Modell** (Alter des Batteriewerts, Lebenszeichen, Gerät) als Anzeigeprinzip | hoch | niedrig | niedrig | **ja** | aus Beobachtung 1. |
| **Datenqualität je Gerät** („Wert 3 Jahre alt“, „Skala unplausibel“) als Ampel neben dem Batteriestatus | hoch | niedrig | niedrig | **ja** | verhindert Scheinsicherheit („OK“ bei drei Jahre altem Wert). |
| „Gerät ist weg“ (nach X Tagen Funkstille als verwaist vorschlagen) | mittel | niedrig | niedrig | nein (Stufe 2) | |
| Ereignis „Batterie leer“ als Auslöser für eigene Skripte (keine Steuerung fremder Geräte) | mittel | niedrig | niedrig | nein (Stufe 2) | |

## 5. MVP-Vorschlag und Ausbaustufen

**Stufe 1 (MVP, „klar besser als das alte Modul“):**
1. Suche (Profile + Idents + Presentation-Fall), Gruppierung je Gerät, Sammel-/Heimspeicher-Ausschluss, Trockenlauf, manuell hinzufügen/ausnehmen.
2. Status ok/schwach/leer/unbekannt, Plausibilität, Widerspruchserkennung, **Drei-Zeiten-Modell** mit Datenqualität.
3. Funkstille als eigener Befund (Lebenszeichen), einstellbare Schwellen je Gerätegruppe.
4. Wechselerkennung + Batterietagebuch (eigene, schlanke Verlaufsliste im Modul).
5. Meldungen: Push (beide Visualisierungstypen) + E-Mail; erste Meldung → Erinnerung → Wochenzusammenfassung; Ruhezeiten; Quittieren/Zurückstellen/Ausnehmen; Kritisch-Markierung.
6. Anzeige: HTML-Tabellenvariable + schlanke Kachel + Kernzahlen als Variablen; Formular nach Verbund-Konvention.
7. `BWACH_GetState` (nach Abstimmung), eigener Lebenszeichen-Status, UTF-8-/Strict-Prüfskript, Einstellungs-Import aus BY_BatterieMonitor.

**Stufe 2:** Spannungskurven (Zelltyp manuell), Restlaufzeit-Prognose mit Konfidenz, Einkaufsliste, Tauschrunde, Meldeintervalle lernen, Eskalation, Lebensdauerstatistik, Verwaist-Vorschlag.
**Stufe 3:** Auffälligkeit gegenüber Gleichartigen, Kälteeinfluss, RSSI-Anzeige, Zelltyp-Bibliothek, weitere Systeme nach Nutzerwunsch.

Aufwand: Stufe 1 ist ein ordentliches Modul (Suche + Meldezustände + Kachel), Stufe 2 etwa nochmal so viel, Stufe 3 optional. Eine Zeitschätzung gebe ich erst nach der Freigabe.

## 0. Entscheidungen Dietmar (07.10.2026)

1. Bauen. 2. Name „Batteriewächter“ (Dietmar, 07.10.2026; „Appell“ verworfen) mit Anzeigename „DG65 Toolkit Batteriewächter“ (GitHub/Store/Forum-Suche ohne Treffer, `DG65/Batteriewaechter` frei). 3. Volles Programm (Stufe 1–3) von Anfang an. 4. **Keine Abstimmung mit WarnHub/Dashboard/EMS:** es gibt keine Verbindung, der Vertrag unten ist rein optional und wird erst gebaut, wenn ein Konsument ihn anfragt.

## 6. Vertrag `BWACH_GetState` (Entwurf, optional, erst bei Bedarf eines Konsumenten)

- Präfix `BWACH_`, Verbund-Konvention: Parameter explizit typisiert, keine Standardwerte, `contractVersion` `1.0` auf oberster Ebene, `lastSeenAt` des Moduls.
- Inhalt (additiv wachsend): Summen (`total`, `low`, `empty`, `silent`, `unknown`, `critical`) und eine Geräteliste je Eintrag `{id, name, room, status, percent|null, valueAgeDays, lastLifeSignAt, critical, acknowledgedUntil}`. Einheit/Skala jedes Feldes im Vertrag (`unit`), `null` = „keine Daten“ (nie 0).
- **Vor Festlegung abstimmen:** WarnHub (will es Batteriemeldungen als Quelle?), Dashboard (Kachelanzeige), EMS (Verbund-Gesundheit). Keine Annahmen über deren Verträge. Konsumenten rufen mit `function_exists()` und `try/catch (Throwable)` auf.

## 7. Prüfstand (geplant)

Szenarien: nur Flag / nur Prozent / Flag + Prozent widersprüchlich · Prozent −20 und 130 · eingefroren · Wert springt (Wechsel) · Sammelvariable (Raum) neben Einzelgeräten · Heimspeicher-Variable mit `~Battery.100` (muss ausgeschlossen werden) · Variable gelöscht/Instanz fehlt · Kernel-Neustart (`false` statt Typ) · Zeitumstellung (Wanduhr, SUITE Stolperstein 18) · 600 Geräte · Erinnerung/Ruhezeit/Neustart ohne Doppelmeldung · Push mit Umlauten/Emoji an der 32-Byte-Grenze · `form.json`/`locale.json` UTF-8 ohne BOM. Mutationstests an den Entscheidungsgrenzen (schwach/leer/Alter/Funkstille). Neuinstallations-Simulation (SUITE Store-Checkliste 12) vor jeder Veröffentlichung.

## 8. Name, Lizenz, Repo

- **Name (von Dietmar festgelegt): „Batteriewächter“**, Anzeigename „DG65 Toolkit Batteriewächter“. Der frühere Vorschlag „Appell“ ist verworfen.
  - **Kollisionsprüfung (07.10.2026, Websuche):** im Store, auf GitHub und im Forum kein Modul gleichen Namens gefunden; `DG65/Batteriewaechter` ist als Repo frei. Einschränkung: Websuche, nicht erschöpfend. Der Name ist beschreibend, im Store-Suchfeld also gut auffindbar; Aliase „Batterie“, „Batteriemonitor“.
- `library.json→name`: **„DG65 Toolkit Batteriewächter“** (ohne Zusatz, TOOLKIT.md), `module.json→aliases`: `Batteriewaechter`, `Batteriewächter`.
- Technischer Name ASCII: Klasse `Batteriewaechter`, Präfix `BWACH`, Ordner `Batteriewaechter`, Repo `DG65/Batteriewaechter` (TOOLKIT.md lässt das Muster frei; vor Anlage gegen die Alt-Namen-Liste in SUITE.md prüfen). Sobald ein Konsument `function_exists('BWACH_…')` nutzt, ist der Name eingefroren.
- Lizenz PolyForm Noncommercial 1.0.0 (kanonische `LICENSE` aus dem EMS-Repo). Branch-Strategie: zuerst nur `beta`, `main` erst nach Live-Bewährung. `vendor` in `module.json` leer (Software-Modul).

## 9. Risiken und offene Punkte

1. **Nicht live verifizierbar:** Z2M-/Matter-Batterien, HomeMatic, KNX, EnOcean, LoRaWAN, Tuya. Im MVP nur Zusagen aus Dokumentation → als „ungetestet“ kennzeichnen oder Forum-Tester einplanen.
2. **Lebenszeichen-Heuristik:** gut bei zyklisch sendenden Geräten, schwach bei reinen Ereignissendern. Gegenmaßnahme: großzügiger Standard, pro Gerät einstellbar, im Formular erklärt.
3. **Eigene Verlaufsliste:** Speicherort (Attribut vs. Variable) und Größe bei vielen Geräten noch zu entscheiden. Außerdem gehen Attribute beim Neu-Registrieren des Moduls verloren (SUITE Stolperstein 5) → Tagebuch muss das überstehen oder der Release-Hinweis muss davor warnen.
4. **Prognose** bleibt bei den meisten realen Geräten (100-%-Sensoren) vermutlich „unbekannt“. Kein Versprechen im Store-Text.
5. **Aufräumen bei Dietmar (nicht von mir erledigt):** BY_BatterieMonitor-Instanz #12613 stört das Sammel-Update; Entfernen auf Wunsch sofort oder nach dem MVP.

## 10. Entscheidungen, die Dietmar treffen sollte

1. **Bauen** (empfohlen, klein starten) **oder** elueckel installieren und BY entfernen (kostet nichts, löst aber die Fälle in Abschnitt 2 nicht)?
2. **Name** „Batteriewächter“: entschieden.
3. **MVP-Umfang** wie in Abschnitt 5 — insbesondere Batterietagebuch von Anfang an (Daten lassen sich nicht nachholen) und Prognose bewusst erst in Stufe 2.
4. **Vertrag:** entschieden, keine Abstimmung nötig (keine Verbindung zu anderen Modulen); optional bei konkretem Bedarf.

## 11. Stand Meilenstein 1 (07.10.2026)

Gebaut und im Prüfstand (`.tools/test-bwach.php`, 143 Prüfungen) sowie mit 32 Mutationstests (`.tools/mutation-bwach.php`, alle getötet) abgesichert: Suche, Gruppierung, Ausschlüsse, Status, Datenqualität, Funkstille, Kennzahlen, Tabellen, Formular. **Gegenprobe am Privat-Symcon (rein lesend, 07.10.2026 abends):** 45 Geräte gefunden, 5 Variablen begründet ausgeschlossen. Echte Treffer: ein Comet-Thermostat mit 0 % und 28 Tagen Funkstille, ein Comet-Thermostat mit 15 %, ein Shelly mit 18 %, Funkstille bei „Sensor Heizung“ (16 Tage) und beim Staubsauger (328 Tage), zehn Z-Wave-Thermostate mit 2–3 Jahre altem Batteriewert. Das alte Modul sah nur 18 Z-Wave-Geräte und keinen dieser Comet-/Shelly-Fälle.

Daraus entstanden zwei Regeln, die erst die Gegenprobe zeigte (jetzt im Prüfstand): (1) Eine Instanz mit Sammelwert (Comet-Raum) ist ein Sammelgerät, auch ihr „schwach“-Flag zählt nicht. (2) Mehrere Signale gleicher Art an einer Instanz (neun Bodenfeuchtesensoren an einer Wetterstation) sind mehrere Geräte.

**Noch nicht live am Modul selbst geprüft:** Die Logik lief per Wegwerfskript gegen das Privat-Symcon, das Modul als Instanz noch nicht (Installation über die Modulverwaltung steht aus, Repo noch nicht auf GitHub).

## 12. Stand Meilenstein 2 (08.10.2026)

Gebaut: Meldungen (Push an Kachel-Visualisierung und WebFront, E-Mail), Erinnerung, Ruhezeit, Eskalation, Wochenbericht, Quittieren, Batterietagebuch mit Wechselerkennung. Reine Logik in `BWACHMeldung.php`, im Prüfstand mit Zeitreihen abgesichert (246 Prüfungen, 64 Mutationstests). Zwei Fehler fielen erst beim Schreiben der Tests auf: ein Push-Titel mit Gerätename überschritt die 32-Byte-Grenze (jetzt steht der Name im Text), und ein fehlgeschlagener Wochenbericht galt als gesendet.

Lebenszeichen zählt nur noch Variablen ohne Aktion (Gegenprobe am Privat-Symcon: ausgebaute Thermostate bekamen täglich einen Sollwert von der Heizungssteuerung).

**Offen aus Stufe 2/3:** Spannungskurven, Prognose, Einkaufsliste, Tauschrunde, Lebensdauerstatistik, Gleichartigen-Vergleich, aktive Abfrage schlafender Geräte (Z-Wave: `ZW_RequestStatus` getestet, Wirkung nicht belegbar), Kachel. **Formular-Werte im `onClick`** (`$AckDevice`): laut Symcon-Dokumentation für benannte Felder im selben Bereich verfügbar, im Modul noch nicht an einer echten Instanz erprobt.

## 13. Stand Meilenstein 3 (08.10.2026)

Kachel gebaut (`module.html`, gleiche Instanz, `SetVisualizationType(1)`). Live-Test von 0.2.0 durch Dietmar am 08.10.2026: Instanz gefunden 48 Geräte, Quittieren per Formular funktioniert (`$AckDevice` kommt an), Push kommt an, E-Mail noch nicht geprüft. Dabei fiel auf: Tagebuch- und „Außer Betrieb“-Zeile blieben bis zum Neuöffnen alt (behoben), Dietmar fand die „Tabellen“ nicht (HTML-Variablen im Objektbaum, keine Kachel) — daher die echte Kachel. Kachel-JavaScript wird im Prüfstand unter Node mit einem Minimal-DOM geprüft und wurde im Browser in Desktop- und Handybreite angesehen; in der echten Kachel-Visualisierung noch nicht gesehen.

## 14. Stand Stufe 2 (08.10.2026, Version 0.4.0)

Gebaut: Zelltypen und Spannungskurven, Verlauf im Modul, Restlaufzeit-Prognose (Theil-Sen, Sicherheit hoch/mittel/gering/unbekannt), „bald leer“ als Meldung, Einkaufsliste, Tauschrunde, Lebensdauerstatistik, gelerntes Meldeverhalten, Verwaist-Vorschlag, Kachel-Ansichten. **Ehrliche Grenzen:** Die Entladekurven sind typische Näherungen, nicht gemessen und nicht herstellerspezifisch. Die Prognose ist bei Dietmars Geräten für die meisten (Z-Wave-Sensoren mit 100 %-Stufen) noch gar nicht möglich und entsteht erst mit Wochen an Verlauf; die Lebensdauerstatistik erst nach den ersten Wechseln. Bisher nur im Prüfstand mit simulierten Verläufen belegt, nicht an echten Langzeitdaten.

## 15. Stand Stufe 3 (08.10.2026, Version 0.5.0)

Gebaut: Gleichartigen-Vergleich, Funkqualität, Kälteeinfluss (mit Außentemperatur-Variable), Abfrage schlafender Z-Wave-Geräte (je Gerät, standardmäßig aus), Übernahme der Einstellungen aus BY_BatterieMonitor (gefüllt in die Maske, nicht gespeichert). **Bewusst nicht gebaut:** Zelltyp-Bibliothek nach Gerätemodell (keine belastbare Quelle; stattdessen Kandidaten aus der Spannung und manuelle Wahl), RSSI als Regel (nur Anzeige und Hinweis). **Gegenprobe Abfrage (Sensor Flur/Bad, 08.10.2026):** Beide Sensoren meldeten sich nach meiner Statusanfrage nach 1 h 45 min (Flur, passt zum 7200-s-Aufwachintervall) bzw. 48 min (Bad); ob die Anfrage das bewirkt hat oder das normale Aufwachen, ist nicht trennbar. Der Wächter behauptet deshalb nicht, dass die Abfrage hilft, sondern zeigt nur, ob das Gerät geantwortet hat.
