# Changelog

Alle nennenswerten Änderungen an diesem Modul werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

## [0.18.5] - 2026-10-25

### Added
- **`BWACH_DeviceListJson(<InstanzID>, true|false)`** (Forum: tägliche Übersicht und Warnungen per Telegram, ohne die Auswertung nachzubauen): Die ausgewerteten Geräte als JSON, mit Kopf (Modulversion, Stand, ob schon gesucht wurde) und je Gerät Schlüssel, Name, Ort, System, Status, Prozent, Spannung, Funkstatus, nicht erreichbar (und seit wann), kritisch, Dringlichkeit, Befund samt allen Gründen, Alter von Wert und Lebenszeichen, Zelltyp. `true` liefert nur die Geräte mit Handlungsbedarf. Die Funktion rechnet bei jedem Aufruf neu und speichert nichts; die Felder bleiben stabil und werden nur erweitert. Sie ist für eigene Skripte gedacht, keine Schnittstelle zu anderen Modulen.

## [0.18.4] - 2026-10-25

### Added
- **Ortsfilter in der Kachel** (Forum): Vor „🛒 Einkauf“ steht eine Auswahl „📍 Alle Orte“ mit den Orten der überwachten Geräte (Kategorie, „Ort (eigen)“ oder Regel „Ort setzen“), sortiert, mit „(ohne Ort)“ zuletzt. Der Ort wirkt zusammen mit den Statusfiltern: Die Zähler an den Filterchips zählen nur die Geräte des gewählten Orts, die Liste und die Suche ebenso. Die Auswahl bleibt im Browser gemerkt; ein Ort, den es nicht mehr gibt, gilt als „alle Orte“. Die Auswahl erscheint erst ab zwei Orten. Einkauf, Statistik und Tagebuch zeigen weiter alle Orte, der Filter ist dort abgeblendet.

## [0.18.3] - 2026-10-24

### Fixed
- **UNREACH zählte als Lebenszeichen** (Forum: Zweit-Wetterstation, Batterie leer): Meldete die CCU „UNREACH = true“ erneut, sah der Wächter ein Lebenszeichen, obwohl es nur eine weitere Offline-Meldung war. `UNREACH`, `STICKY_UNREACH` und die in den Geräte-Einstellungen gewählte Erreichbarkeits-Variable zählen nie mehr als Lebenszeichen.

### Changed
- **Nicht erreichbar vor letztem Batteriestand:** Ist ein Gerät in Funkstille oder nicht erreichbar und sein letzter Batteriestand „leer“ oder „schwach“, steht zuerst „Nicht erreichbar“ bzw. „Funkstille“ und danach „Letzte Batteriemeldung: schwach (…), vor … Tagen, Batterie möglicherweise leer“ (bei „leer“: „vermutlich leer“). Das gilt für Kachel, Tabellen und Meldungen. In der Kachel zeigt das Gerät dann 🔇 statt ⚠️ oder 🪫. Zähler und Sortierung bleiben unverändert.

## [0.18.2] - 2026-10-24

### Added
- **Zelltypen C (Baby) und D (Mono)**, jeweils Alkali (1,5 V) und als NiMH-Akku (1,2 V) (Forum: Alarmsirene HomeMatic HM-Sec-Sir-WM, Akkubetrieb). Die Umrechnung von Spannung in Prozent nutzt dieselben typischen Kurven wie bei AA und AAA (Alkali bzw. NiMH), die Einkaufsliste führt sie als „C (Baby)“, „D (Mono)“, „C (Baby, Akku)“ und „D (Mono, Akku)“. Nennt ein Gerät als Ersatz „LR14“ oder „LR20“, wird der Zelltyp daraus erkannt.

## [0.18.1] - 2026-10-19

### Added
- **Stufenanzeige aus Darstellungen** (Forum: Blink-Kameras zeigten die Stufe 3 „Gut“ als „3 % leer“): Die Stufen einer Batterievariable liest der Wächter jetzt auch aus den Intervallen einer Symcon-Darstellung (ohne Prozentanzeige), nicht nur aus Profilen. Eine Stufe, die „Unbekannt“ heißt, ergibt den Status „unbekannt“ mit eigenem Grund und keinen Batteriestand.

### Changed
- **Ausdrücklich gewählte Erreichbarkeits-Variable geht in beide Richtungen vor** (Forum): „nicht erreichbar“ zählt nach der Wartezeit auch bei „Ohne Funkstille-Prüfung“, „erreichbar“ gilt sofort als aktiv, auch wenn das Gerät lange nichts sendet. Das automatisch erkannte `UNREACH` bleibt wie in 0.18.0 (verstärkt nur).

## [0.18.0] - 2026-10-19

### Added
- **Erreichbarkeit** (Forum: HomeMatic-Geräte standen auf „aktiv“, obwohl `UNREACH` „nicht erreichbar“ meldete): Meldet ein Gerät sich nicht erreichbar, zählt das als Funkstille, auch wenn sich irgendeine Variable des Geräts kürzlich gemeldet hat. Eine Variable mit dem Ident `UNREACH` wertet der Wächter automatisch aus (an der Instanz, ihren Geschwistern und zusammengeführten HomeMatic-Kanälen). In den Geräte-Einstellungen lässt sich je Gerät eine eigene Erreichbarkeits-Variable wählen (z. B. eine entprellte), mit der Option „umkehren“. Neue Einstellung „nicht erreichbar zählt als Funkstille nach“ (Standard 30 Minuten, 0 = aus), damit kurzes Flackern nicht zählt. „Ohne Funkstille-Prüfung“ gilt auch hier. Neue Spalten: `ReachVar`, `ReachInv`; neue Einstellung `UnreachMinutes`.

## [0.17.1] - 2026-10-19

### Fixed
- **Flackerndes „Batterie schwach“ löste Wechsel aus** (Forum, Homematic an der Spannungsgrenze): Bei aus der Spannung berechneten Werten zählt das Zurücksetzen des Schwach-Flags nur noch als Wechsel, wenn der Ladezustand zugleich auf mindestens 80 % und um mindestens 40 Punkte steigt, wie schon beim Prozentsprung.

## [0.17.0] - 2026-10-17

### Added
- **Ort selbst bestimmen** (Forum: Der Ort kam bisher nur aus dem Namen der Kategorie und passte nicht immer): „Geräte-Einstellungen“ haben die Spalte „Ort (eigen)“ (je Gerät und je Sensor), die Gruppen-Regeln die Spalte „Ort setzen“, mit der sich viele Geräte auf einmal einem Ort zuordnen lassen. Vorrang: eigener Ort am Gerät, dann Ort aus der Regel, dann die Kategorie. Der Ort gilt in der Kachel (Anzeige, Sortierung „Nach Ort“), in den Tabellen, in Tauschrunde und Einkaufsliste, in der CSV und in der Spalte „Ort“ der Geräte-Einstellungen. Die Regel nach „Ort (Kategorie)“ sucht weiter in der Kategorie, damit eine Regel, die einen Ort setzt, ihre eigene Bedingung nicht verändert. Neue Spalten: `PlaceOwn` (Geräte) und `PlaceSet` (Regeln).

## [0.16.8] - 2026-10-16

### Changed
- **„Rückgängig“ bleibt nicht mehr tagelang stehen** (Forum: Die Schaltfläche war seit Tagen da, obwohl nichts zurückzunehmen war): Sie verfällt nach 24 Stunden statt nach 7 Tagen, lässt sich mit dem neuen „✕“ daneben ausblenden, ohne etwas zurückzunehmen, und verschwindet, sobald ein Wechsel von Hand nachgetragen oder die erkannten Wechsel gelöscht werden (sie gälte sonst für einen Stand, den es nicht mehr gibt).

## [0.16.7] - 2026-10-15

### Fixed
- **Stufenanzeige wurde als Prozentwert gelesen** (Forum, Netatmo): Das Netatmo-Modul meldet die Batterie als Integer `Battery` mit dem Profil `Netatmo.Battery` in sechs Stufen (0 = empty … 5 = max, Profil ohne Wertebereich 0 bis 0, aber mit benannten Stufen). Der Wächter las die 5 als „5 %“ und meldete jedes Gerät als leer. Jetzt gilt: Eine über den Ident erkannte Integer-Variable mit eigenem Profil (nicht `~…`) und 3 bis 10 benannten Stufen ist eine Stufenanzeige. Die Stufen werden gleichmäßig auf 0 bis 100 % verteilt (bei sechs Stufen 0, 20, … 100), die unterste Stufe gilt als leer, die zweitunterste (ab vier Stufen) als schwach, der Rest als in Ordnung. Die Gründe nennen die Stufe („Stufe 5 von 5“). Gilt für jedes Modul, das die Batterie so meldet. Quelle des Befunds: Quelltext des Moduls (demel42/IPSymconNetatmoWeather), nicht an einem Netatmo-Gerät geprüft.

## [0.16.6] - 2026-10-15

### Fixed
- **Spannungsrauschen galt als Batteriewechsel** (Forum, HomeMatic): Bei Geräten, die nur eine Spannung melden, rechnet der Wächter den Ladezustand aus der Spannung. Eine Schwankung von 0,1 V bewegt die Alkali-Kurve im mittleren Bereich um bis zu 25 Prozentpunkte (1,3 V = 40 %, 1,4 V = 65 %), und das war die Schwelle für einen erkannten Wechsel: Tagebuch, „Gewechselte Batterien“, Lebensdauer und Prognose-Vergleich füllten sich mit falschen Einträgen („Prozentwert sprang von 40 % auf 65 %“). Jetzt gilt ein Wechsel bei aus der Spannung berechneten Werten nur, wenn der neue Wert mindestens 80 % beträgt und der Sprung mindestens 40 Prozentpunkte. Ein zurückgesetztes „schwach“-Flag und gemessene Prozentwerte sind unverändert.

### Added
- **„Automatisch erkannte Wechsel löschen“** (Panel „Quittieren und Batterietagebuch“, mit Rückfrage): Löscht die als „erkannt“ markierten Einträge und setzt den Prognose-Vergleich zurück. Von Hand eingetragene und nachgetragene Wechsel bleiben. Für das, was frühere Versionen fälschlich erkannt haben (`BWACH_DeleteDetectedChanges`).
- **Gemessene Spannung wird gezeigt:** Im aufgeklappten Gerät der Kachel („⚡ Gemessene Spannung 1,2 V (daraus berechnet: …)“) und in der Spalte „Batteriestand“ der Geräte-Einstellungen („40 % · 1,2 V“).

## [0.16.5] - 2026-10-13

### Changed
- **Formular-Aufbau mit Kontrollkästchen statt Auswahlliste** (Anregung von Dietmar: schneller, und man sieht die Wahl aller Bereiche auf einen Blick): Die Liste hat die Spalten „Aufgeklappt“, „Zugeklappt“ und „🙈 Ausgeblendet“ zum Anhaken. Kein Haken heißt „Standard“. Sind mehrere Haken gesetzt, gilt „Ausgeblendet“ vor „Zugeklappt“ vor „Aufgeklappt“, und die Liste zeigt nach dem Öffnen nur den geltenden. Eine bisher gespeicherte Auswahl wird weiter gelesen.

## [0.16.4] - 2026-10-13

### Added
- **Bereiche ausblenden** im Panel „🪟 Formular: Aufbau“ (Anregung aus dem Toolkit): Die Spalte „Beim Öffnen“ hat die vierte Auswahl „🙈 Ausgeblendet“ für die Fachpanels (nicht für Zweck, Dokumentation, Erste Schritte, Rückmeldungen und Lizenz). Ein ausgeblendetes Panel bleibt im Formular (nur nicht sichtbar), seine Einstellungen bleiben beim „Übernehmen“ erhalten und wirken weiter. Die Kopfzeile des Aufbau-Panels nennt die Zahl („2 ausgeblendet“), eine Zeile darin die Namen. „Auf Standard zurücksetzen“ holt alles zurück.

## [0.16.3] - 2026-10-13

### Added
- **Spalte „Nr.“ in den Gruppen-Regeln** (Forum): Sie nummeriert die Regeln von oben und passt zu „Regel N“ in der Spalte „Gilt“. Die Nummer folgt der gespeicherten Reihenfolge; wer Zeilen verschiebt, sieht sie nach „Übernehmen“ neu.
- Der Hilfetext der Regeln nennt das Kriterium „Sensor“.

## [0.16.2] - 2026-10-13

### Added
- **Gruppen-Regel nach „Sensor enthält“** (Forum: Froggit-Sensoren mit gleichem Instanz-, Ort- und Systemnamen, nur der Sensor unterscheidet sich): Neues Kriterium neben „Ort“, „System“ und „Name“. Es sucht im Namen der Batterie-Variable (z. B. „soilbatt1“), nicht im Namen der Instanz, und trifft nur bei Instanzen mit mehreren Sensoren.

### Fixed
- In „Geräte-Einstellungen“ zeigte „Gilt“ bei Sensor-Zeilen keine Gruppen-Regel, die auf den Sensor passt, und die Spalte „Treffer“ zählte je Instanz statt je Sensor. Beides folgt jetzt der Auswertung.

## [0.16.1] - 2026-10-13

### Fixed
- **Z-Wave-Sensoren standen fälschlich in „Funkstille“** (Befund an der eigenen Anlage: „Sensor Windfang“ und „Sensor Wohnzimmer“ meldeten täglich, der Wächter sagte „seit 7 Tagen kein Lebenszeichen“). Ursache: Variablen mit einer Aktion (Sollwert, Schalter) zählen nicht als Lebenszeichen, weil Symcon sie oft selbst schreibt. Der Wächter hielt dabei auch `VariableCustomAction = 1` für eine Aktion, das aber „keine Aktion“ heißt (so setzt das Z-Wave-Modul seine Sensorwerte). Damit galten fast alle Messwerte solcher Geräte als „von Symcon geschrieben“, und nur die Batterie (alle paar Tage) blieb als Lebenszeichen. Jetzt gilt: Benutzerdefinierte Aktion 1 = keine Aktion, größer als 1 = Skript oder Instanz, 0 = Standardaktion der Variable. Betroffen waren alle Geräte, deren Messwerte so gesetzt sind, nicht nur Z-Wave.

## [0.16.0] - 2026-10-13

### Added
- **Einstellungen je Sensor bei Instanzen mit mehreren Sensoren** (Forum: 19 Froggit-Sensoren an wenigen Gateways): Hängen mehrere Batterie-Variablen an einer Instanz, führt der Wächter sie schon als eigene Geräte, die Geräteeinstellungen kannten aber nur die Instanz. Jetzt bekommt jeder Sensor eine eigene Zeile in „Geräte-Einstellungen“ (Spalte „Sensor“) mit eigenem Zelltyp, eigener Zellenzahl, „kritisch“, „Ohne Altersprüfung“, „Ohne Funkstille-Prüfung“, Abfragen, Funkstille-Schwelle und Anzeigename. Der „Batteriestand“ ist der des Sensors, nicht mehr das Minimum der Instanz. Eine Zeile für die **Instanz** gilt wie bisher für alle ihre Sensoren, solange ein Sensor keine eigene Zeile hat; eine neue Sensor-Zeile übernimmt die Einstellung der Instanz, das erste Speichern ändert also nichts. Neue Spalte: `Sensor` (Variablen-ID, gespeichert) und `Sensor` als Anzeige.

## [0.15.1] - 2026-10-12

### Changed
- **Logging über `$this->LogMessage`** statt `IPS_LogMessage` (Hinweis aus dem Review von Symcon). Gilt für die sechs Meldungen bei Zustellfehlern von Push und E-Mail und bei nicht angenommenen Abfragen; sie erscheinen jetzt mit der Instanz als Absender und der Art „Warnung“.

## [0.15.0] - 2026-10-12

### Added
- **Eigene Zelltypen** (Forum: „kann man fehlende Batterien selbst nachtragen?“): Im Panel „Weitere Variablen“ trägt man in der Liste „Eigene Zelltypen“ einen Batterietyp ein, der in der Auswahl fehlt: Name, Einkaufsbezeichnung (leer = Name) und optional „Spannung voll“ und „Spannung leer“ je Zelle. Der Typ steht danach bei „Zelltyp“ in „Geräte-Einstellungen“, „Gruppen“ und „Vorrat“ zur Wahl (mit dem Zusatz „(eigener Typ)“) und kommt in die Einkaufsliste. Mit beiden Spannungen rechnet der Wächter linear dazwischen einen Ladezustand aus (eine grobe Näherung); ohne sie dient der Typ nur der Einkaufsliste. Ein eigener Typ überschreibt nie einen eingebauten. Wird ein Typ umbenannt oder gelöscht, fallen Geräte mit diesem Typ auf „unbekannt“ zurück („Zelltyp offen“). Neue Einstellung: `OwnCells`.

### Fixed
- **Spaltenüberschrift „Zelltyp (eigen)“ saß in der falschen Liste:** Seit 0.12.5 stand sie bei „Vorrat“ statt in „Geräte-Einstellungen“ (dort stand nur „Zellen (eigen)“). Jetzt gehört „Zelltyp (eigen)“ in die Geräteliste, „Vorrat“ heißt wieder „Zelltyp“.

## [0.14.3] - 2026-10-12

### Added
- **Weitere Zelltypen** (Forum: „Ich suche LR44“): LR44 / AG13 (Alkali-Knopfzelle), SR44 (Silberoxid), CR2025, CR2016, CR1632, CR1220, CR2477, CR2 und A23 (12-V-Batterie). Wählbar in „Geräte-Einstellungen“ und „Gruppen“, in der Einkaufsliste mit ihrer Bezeichnung, und Matter-Geräte, die einen dieser Typen als Ersatz nennen, werden erkannt. Die Kurven für die Umrechnung aus der Spannung sind Näherungen wie bei den übrigen Typen; bei SR44 ist die Umrechnung besonders grob, weil Silberoxid die Spannung lange flach hält.

## [0.14.2] - 2026-10-12

### Changed
- **„Erste Schritte“ prüft jetzt, ob die Kachel eingebunden ist:** Das Modul liest das Startobjekt der Kachel-Visualisierung (Einstellung `BaseID`) und sucht die Instanz oder eine Verknüpfung darauf darunter. Gefunden: ✅ „Die Kachel ist in der Kachel-Visualisierung eingebunden“. Nicht gefunden: nur ein Hinweis (die Kachel ist optional, der Punkt wird nie „offen“). Gibt es keine Kachel-Visualisierung, steht ein neutraler Hinweis. Vorher stand dort immer ein Hinweis ohne Haken, weil ich es nicht prüfen konnte; das ging aber doch.
- Der Matter-Hinweis in „Erste Schritte“ sagt „bei 1 Gerät gibt es“ statt „bei 1 Geräten“.

## [0.14.1] - 2026-10-12

### Added
- **Statistik „Gewechselte Batterien“** (Forum: „da werden manche erschrecken“): In der Kachel unter „Statistik“ und in der Tabelle „Lebensdauer und Entladung“ steht je Zeitraum (letzte 30 Tage, letzte 12 Monate, insgesamt), wie viele Wechsel an wie vielen Geräten stattfanden und wie viele Batterien welchen Typs das waren (eine Batterie je Zelle: 2× AAA bei zwei Zellen). Wechsel von Geräten ohne bekannten Zelltyp oder ohne Eintrag (nicht mehr überwacht) werden als „Zelltyp unbekannt“ gezählt, nicht geraten. Der Zelltyp ist der heutige des Geräts; das Tagebuch hält ihn nicht fest.

## [0.14.0] - 2026-10-12

### Added
- **„Ohne Funkstille-Prüfung“** je Gerät und je Gruppen-Regel (Forum: „nur melden, wenn die Batterie wirklich schwach ist“): Das Gerät darf beliebig lange schweigen, nur der Batteriestand und die Flags zählen. Zusammen mit „Ohne Altersprüfung“ bleibt für selten funkende Sensoren nur eine Meldung bei schwacher oder leerer Batterie. „Gilt“ nennt es. Ohne jedes Lebenszeichen bleibt die Funk-Spalte „unbekannt“.
- **Letzter Wechsel am Gerät** (Forum: Stromfresser entlarven): Im aufgeklappten Gerät der Kachel steht „🔄 Letzter Wechsel: 12.10.2026 (vor 3 Tagen) · die Batterie davor hielt 14 Monate“, sobald das Tagebuch einen Wechsel kennt. Neue Sortierung „Zuletzt gewechselt“ in der Kachel und neue Anzeigespalte „Letzter Wechsel“ (sortierbar) in „Geräte-Einstellungen“.

### Changed
- „Gilt“ in „Geräte-Einstellungen“ nennt „außer Betrieb“ (und „ohne Funkstille-Prüfung“). Außer Betrieb gesetzte Geräte bleiben dort stehen, damit ihre Einstellungen nicht verloren gehen, waren aber nicht erkennbar.
- Neue Einstellungen in den Listen: `NoStill`.

## [0.13.1] - 2026-10-11

### Added
- **Zelltyp „LTO-Akku (Lithium-Titanat, fest eingebaut, 2,4 V)“** (Forum, Tempest-Wetterstation): Die Kurve folgt den dokumentierten Schwellen eines solchen Geräts (voll etwa 2,8 V, Sparmodus ab 2,455 V, „niedrig“ bei 2,355 V). Sie ist deshalb absichtlich strenger als die allgemeine Entladekurve einer LTO-Zelle, damit sie rechtzeitig warnt. Eine Näherung, wie alle Kurven; der Wert steht „aus Spannung berechnet“.

### Fixed
- **„Battery“ mit einem Spannungsprofil ist eine Spannung** (Tempest: `Battery`, Float, Profil `Tempest.Volt.3`): Sie wurde als Prozentwert gelesen („2,64 %“, leer). Jetzt gilt sie als Spannung, der Zelltyp rechnet sie um.
- **„Battery_Status“ mit eigener Aufzählung ist kein Prozentwert** (Tempest: `Tempest_battery_status`): Ein Statuscode wurde als „0 %“, leer gewertet. Variablen der Art `…Status` oder `…State` mit einem eigenen Profil (nicht `~…`) erkennt der Wächter nicht mehr als Prozent.

## [0.13.0] - 2026-10-11

Anlass: ein Blick auf andere Systeme (Home Assistant mit „Battery Notes“, openHAB, ioBroker, Homey), was sie können und uns fehlte.

### Added
- **„Variablen ignorieren“** (Panel „Weitere Variablen“): Einzelne Variablen lassen sich aus der Erkennung nehmen, ohne das ganze Gerät auszunehmen. Anlass: eine USV, bei der der Wächter die Last statt des Ladezustands nahm. Die ignorierten stehen im Trockenlauf unter „Ausgeschlossen“. Ein Haken sitzt nach „Übernehmen“ (neue Suche).
- **Text-Zustand als Batterie** (Panel „Weitere Variablen“, Art „Text: schwach, wenn er enthält …“ mit Spalte „Text“): Für Variablen, die den Zustand als Wort melden („ok“, „low“, „critical“). Steht eines der eingetragenen Wörter (Komma = oder) im Wert, gilt die Batterie als schwach. Nur von Hand, der Wächter errät keine Texte.
- **„Erste Meldung erst, wenn der Befund so lange anliegt“** (Panel „Meldungen“, Minuten, Standard 0 = sofort wie bisher): Filtert einzelne Ausreißer. Kritische Geräte melden immer sofort, Erinnerungen und Eskalation sind nicht betroffen. Ein Befund, der verschwand und wieder auftaucht, beginnt von vorn.

### Changed
- **Last in Prozent ist kein Ladezustand:** Eine Variable, die nur über das Profil `~Battery.100` erkannt wurde und deren Ident mit `Load`, `Last`, `Auslastung` oder `Usage` beginnt (USV: `Load`, `LoadPct`), zählt nicht als Batterie und steht unter „Ausgeschlossen“. Eine von Hand zugeordnete Variable bleibt immer.
- Neue Einstellungen: `IgnoredVariables`, `ConfirmMinutes`.

## [0.12.11] - 2026-10-11

### Changed
- **HomeMatic: Geräte ohne Batterie (Netzteil) werden bei der Erkennung ausgeschlossen** (Forum, mehrere Nutzer): Eine `LOWBAT`- oder `OPERATING_VOLTAGE`-Variable, die „Nie“ einen Wert erhalten hat, ignoriert der Wächter. Bleibt an einem Gerät kein Signal übrig (z. B. ein 4fach-Schalter am Netzteil, eine Dose mit HmIP), erscheint es nicht mehr, und der Trockenlauf nennt den Grund unter „Ausgeschlossen“. Ein Batteriegerät behält sein `LOWBAT` am Funktionskanal; ein „Nie“ am Wartungskanal stört dort nicht. Bewusst nur für HomeMatic: Bei Z-Wave und Matter ist „noch nie gemeldet“ der Fall, den die Abfrage klären soll. Ein frisch angelerntes Batteriegerät erscheint nach dem ersten Telegramm und „Jetzt neu suchen“. Von Hand zugeordnete Variablen bleiben immer.

### Added
- **Funkstille-Schwelle je Gerät und je Gruppen-Regel** (Forum): Neue Spalte „Funkstille nach (Tage)“ in „Geräte-Einstellungen“ und in den Gruppen-Regeln. 0 oder leer heißt Standard. Der Wert gilt für das Gerät vor den allgemeinen Schwellen für Standard und Ereignismelder und vor der gelernten Schwelle; eine eigene Einstellung am Gerät geht der Regel vor. „Gilt“ nennt ihn („Funkstille nach 300 Tagen“), der Grund bei einer Funkstille sagt „für dieses Gerät eingestellt“. Anlass: Selten sendende Sensoren ließen sich nur durch eine globale Schwelle aus „Handlungsbedarf“ nehmen.
- **Nuki:** `BatteryCritical`, `DoorSensorBatteryCritical` und `KeypadBatteryCritical` (True = kritisch) gelten als „schwach“-Flag, `BatteryChargeState` (MQTT) als Prozentwert.

## [0.12.10] - 2026-10-11

### Fixed
- **Gruppen-Regeln hatten kein „Ohne Altersprüfung“** (Forum: Es ließ sich nur je Gerät einstellen, in der Regel fehlte es). Die Regel hat jetzt die Spalte „Ohne Altersprüfung“. „Gilt“ in den Geräte-Einstellungen nennt sie („ohne Altersprüfung“), auch wenn sie vom Gerät selbst kommt. Eine eigene Einstellung am Gerät bleibt, auch wenn die Regel sie nicht setzt.

## [0.12.9] - 2026-10-11

### Added
- **Variable „Handlungsbedarf (Anzahl)“** (Wunsch aus dem Forum, für Hinweise in IPSView und eigene Skripte): Anzahl der Geräte, die in der Tabelle „Handlungsbedarf“ stehen, also leer, schwach, Funkstille, Daten prüfen, unbekannt oder bald leer. Größer als 0 heißt: etwas ist zu tun. Bisher gab es nur die Zahlen je Befund.

## [0.12.8] - 2026-10-11

### Changed
- **Netzspannung gilt nicht als Batteriespannung** (Forum: Messsteckdosen mit `VOLTAGE` und `OPERATING_VOLTAGE`): Eine Spannung zwischen 30 und 400 V (z. B. 232 V) ist keine Batterie. Sie steht unter „Ausgeschlossen“ („Spannung 232,2 V ist Netzspannung, keine Batteriespannung“) und in der Diagnose. Zellen und Blöcke bleiben unter 30 V, ab 400 gilt ein Wert wie bisher als Millivolt (Matter, 3000 = 3 V). Eine von Hand zugeordnete Spannung wird nie ausgeschlossen. Früher wurde ein Wert über 100 durch 1000 geteilt; das gilt jetzt erst ab 400.
- **120 % und mehr bis 120 %:** Die Gründe nennen den gemeldeten Wert („Gerät meldet 120 %, gewertet als voll (100 %)“), damit die Anzeige von 100 % nichts verschweigt.

## [0.12.7] - 2026-10-11

### Changed
- Der Matter-Hinweis bei Geräten ohne Endpunkt 0 nennt die von Symcon angekündigte Besserung: Der Matter Konfigurator soll den Endpunkt 0 mit Version 9.1 anbieten, bis dahin geht es nur von Hand.

## [0.12.6] - 2026-10-11

### Added
- **Panel „🪟 Formular: Aufbau“:** Für jedes Fachpanel lässt sich festlegen, ob es beim Öffnen des Formulars aufgeklappt oder zugeklappt ist („Standard“ heißt: wie das Modul es vorsieht), und an welcher Position es steht (Zahl; bei gleicher Zahl bleibt die Standardreihenfolge). Anlass: Symcon merkt sich das Auf- und Zuklappen nicht selbst. Die Panels oben (Zweck, Neu, Dokumentation, Erste Schritte) und unten (Rückmeldungen, Über dieses Modul) bleiben fest. „Auf Standard zurücksetzen“ (`BWACH_ResetFormLayout`) setzt alles zurück. Eine kaputte oder unvollständige Einstellung fällt auf den Standard zurück. Neue Einstellung: `FormLayout`.

## [0.12.5] - 2026-10-11

### Changed
- **„Geräte-Einstellungen“ steht jetzt gleich nach „Gefundene Geräte“** (Rückmeldung aus dem Forum: das meistbenutzte Panel musste bei jedem Öffnen gesucht werden; Symcon merkt sich nicht, was aufgeklappt war).
- **„Außer Betrieb“ lässt sich einzeln zurücknehmen** (Hinweis aus dem Forum: „Alle aufnehmen“ kann viel Arbeit zunichtemachen). Neu: Auswahl „Gerät außer Betrieb“ mit „Dieses Gerät wieder aufnehmen“. „Alle … wieder aufnehmen“ steht nur noch hinter einer Rückfrage.
- Die Spalten heißen „Zelltyp (eigen)“ und „Zellen (eigen)“: Sie zeigen die Einstellung des Geräts selbst, was am Ende zählt (auch aus einer Gruppen-Regel), steht in „Gilt“. Der Hilfetext sagt es jetzt.

## [0.12.4] - 2026-10-11

### Added
- **„Rückgängig“ für die letzte Quittierung** (Wunsch aus dem Forum, versehentlich gedrückt): In der Kachel erscheint unten „↩️ Rückgängig: …“ mit dem Namen der letzten Quittierung, im Formular unter „Quittieren“ die Schaltfläche „Letzte Quittierung rückgängig“, für Skripte `BWACH_UndoAcknowledge`. Es nimmt „Habe ich getauscht“ (inklusive Tagebucheintrag), „Erinnere mich später“ und „Außer Betrieb“ zurück. „Alles getauscht“ eines Ortes zählt als eine Quittierung. Es gilt nur für die jeweils letzte, bis zu 7 Tage lang. Das Rückgängig braucht die Quittier-Berechtigung der Kachel.
- **USV-Ladung über NUT wird erkannt** (Ident `battery_charge`/`DP_battery_charge`, Prozent). Die Spannung der USV wurde schon erkannt, die Ladung nicht (Diagnose aus dem Forum).

## [0.12.3] - 2026-10-11

### Changed
- **Nur noch ein Eintrag in „Instanz hinzufügen“:** Das Modul war unter fünf Alias-Namen aufgelistet, die alle dasselbe Modul meinten und einen neuen Nutzer ratlos machten. Es bleibt „DG65-Toolkit Batteriewächter“; die Suche nach „Batterie“ findet es weiterhin.
- **Prozentwerte bis 120 % gelten als voll** (Rückmeldung aus dem Forum: Froggit meldet frisch 120 %). Erst darüber ist der Wert „unplausibel“.

## [0.12.2] - 2026-10-11

### Fixed
- **Kachel war auf hellem Untergrund kaum lesbar** (Rückmeldung eines Nutzers aus dem Forum, IPSView auf iPhone und Android: weiße Schrift auf hellgrauem Grund). Die Kachel hatte keinen eigenen Hintergrund, sondern setzte helle Schrift voraus und verließ sich auf Variablen des Hosts, die es dort nicht gibt. Jetzt hat sie einen eigenen Hintergrund und eigene Farben (dunkel, wie bisher auf PC und iPad). Neu: Einstellung **„Helle Darstellung der Kachel“** unter „Zustand“ (hellgrau mit dunkler Schrift). Eine automatische Erkennung des Themes gibt Symcon einer Kachel nicht.
- **Reine „schwach“-Flags gelten nicht mehr als „veraltet“** (HomeMatic `LOWBAT` und andere Ereignis-Flags ändern sich nur bei Ereignissen, nach Rückmeldung eines Nutzers landeten solche Geräte dauerhaft in „Handlungsbedarf“). Die Altersprüfung gilt nur noch für Prozent und Spannung; ob ein Gerät lebt, sagt die Funkstille.
- „Diagnose fürs Forum“ nannte unter „nicht erkannt“ auch die eigenen Zähler und Tabellen des Wächters (`Empty`, `Low`, `TableDiary`).

### Added
- **Objekt-IDs in der Kachel:** Im aufgeklappten Gerät steht eine Zeile „🆔 Instanz #… · Prozent #… · „schwach“-Flag #…“ (bei zusammengefassten HomeMatic-Kanälen alle Instanzen). Wunsch aus dem Forum, hilft bei der Fehlersuche.
- **Boolean „Battery“ mit Beschriftungen der Darstellung wird erkannt** (X-Sense und ähnliche): Ident `battery`, `battery_status` oder `battery_state` ohne Profil. Die Bedeutung von True steht dort nur in der Darstellung („Niedrig“ bei True = schwach, „Niedrig“ bei False und „OK“ bei True = umgekehrt). Ohne eindeutige Beschriftung („Ja/Nein“, „An/Aus“) wird nicht geraten.

### Changed
- „Gefundene Geräte“, „Zustand“, „Meldungen“ und „Quittieren und Batterietagebuch“ sind standardmäßig zugeklappt (Symcon merkt sich den Zustand der Panels nicht; „Geräte-Einstellungen“ und der Hinweis „Rückmeldungen“ bleiben, wie sie waren).
- Die Auswahl „Sortieren nach“ und „Reihenfolge“ in „Geräte-Einstellungen“ entfällt: Die Liste sortiert sich per Klick auf einen Spaltenkopf (an einer Anlage bestätigt). Die Eigenschaften `DeviceSortBy`/`DeviceSortDir` und die Funktion `BWACH_SetDeviceSort` bleiben aus Kompatibilität bestehen.

## [0.12.1] - 2026-10-10

### Added
- **Anzeigename je Gerät:** Neue Spalte „Anzeigename“ in „Geräte-Einstellungen“ (Wunsch aus dem Forum). Der Name gilt in Kachel, Tabellen, Meldungen, Einkaufsliste, Tagebuch und Geräteliste. Leer = Name der Instanz. Zeilenumbrüche und Steuerzeichen werden entfernt, höchstens 60 Zeichen. Bei Geräten mit mehreren Sensoren in einer Instanz („Wetterstation › Sensor 2“) ersetzt der Anzeigename den Instanznamen, der Sensorname bleibt dahinter. **Gruppen-Regeln arbeiten weiter mit dem Namen der Instanz**, damit eine Umbenennung keine Regel verstellt.
- **Suchfeld in der Kachel** über den Listen (Handlungsbedarf, Alle und die Filter): filtert nach Name, Ort und System, ohne Groß-/Kleinschreibung, behält den Text und den Cursor beim Neuaufbau. In Einkauf, Statistik und Tagebuch gibt es kein Suchfeld.

## [0.12.0] - 2026-10-10

### Added
- **HomeMatic: Kanäle eines Geräts sind ein Gerät.** Ein HomeMatic-Gerät besteht aus Kanälen (je eine Instanz): Kanal `:0` („MAINTENANCE“) und Funktionskanäle (`:1`, „SMOKE_DETECTOR“ …). Viele haben ein `LOWBAT`. Bisher legte der Wächter für jeden Kanal ein eigenes Gerät an; der Wartungskanal aktualisiert sich oft nie und stand dann dauerhaft bei Funkstille (Rückmeldung eines Nutzers im Forum). Jetzt werden die Kanäle über die Seriennummer der Adresse zusammengefasst: Name und Ort vom ersten Funktionskanal, Lebenszeichen vom jüngsten Kanal, bei mehreren `LOWBAT` zählt das schlechtere. Erkannt wird über die Eigenschaft `Address` der HomeMatic-Instanz („LEQ0141683:1“), notfalls über den Instanznamen. **An echten HomeMatic-Geräten noch nicht geprüft.**

## [0.11.5] - 2026-10-10

### Changed
- **Name mit Bindestrich:** Der Bibliotheksname lautet jetzt „DG65-Toolkit Batteriewächter“ (vorher „DG65 Toolkit Batteriewächter“, mit Leerzeichen). Er steht in `library.json`, in `module.json` als Alias (der alte Name bleibt als Alias, damit die Suche beide findet), im README und in der `CLAUDE.md`. Der technische Name (Klasse `Batteriewaechter`, Präfix `BWACH`, Repository) ändert sich nicht.
- **Sortierung der Geräte-Einstellungen:** Am Auswahlfeld „Sortieren nach“ steht jetzt, dass die Sortierung nach „Änderungen übernehmen“ gilt. Im offenen Formular sortiert sich die Liste nicht sofort um (an einer Anlage geprüft); die Wahl gilt beim nächsten Öffnen.

## [0.11.4] - 2026-10-10

### Changed
- **Forum-Link immer erreichbar:** „Dokumentation & Hilfe“ hat die Schaltfläche „💬 Forum-Thread“. Sie bleibt, auch wenn man den Hinweis im Panel „Rückmeldungen“ weggeklickt hat.
- **Hinweis „Rückmeldungen“ erscheint nach dem Update einmal wieder:** Wer ihn in einer früheren Version mit „Verstanden – nicht mehr anzeigen“ weggeklickt hatte, sah den neuen Hinweis mit dem Forum-Thread nie. Der Hinweis führt jetzt einen Stand mit (`thread-144608`) und erscheint für alle, die einen älteren Stand weggeklickt haben, einmal neu. Der Attributname `ForumHintGone` bleibt aus Kompatibilität angelegt, wird aber nicht mehr gelesen.

## [0.11.3] - 2026-10-09

### Fixed
- **„Noch ohne Zelltyp“ und „Erste Schritte“ zählten ausgenommene Geräte mit.** An einer Anlage mit alten, ausgenommenen Instanzen standen deren Namen in der Warnung, obwohl sie gar nicht überwacht werden. Ausgenommene Geräte (einzeln oder per Regel) zählen jetzt nicht mehr.
- **Einkaufsliste senden:** Die Überschrift stand doppelt in der Push-Meldung (Titel „🛒 Batterien einkaufen“ und gleich noch einmal als erste Zeile des Textes). Der Text beginnt jetzt direkt mit den Zeilen.
- Panel „Rückmeldungen“: Der Hinweis nennt den Forum-Thread als Ort für Rückmeldungen und hat die neue Schaltfläche „Zum Forum-Thread“ (der Thread ist am 09.10.2026 erschienen), daneben bleibt „Zum Repository“. Die Version bleibt dabei 0.11.3, ohne neuen Build.
- Die Auswahl „Reihenfolge“ in „Geräte-Einstellungen“ zeigte ihre Texte abgeschnitten („aufsteigend (A–Z, niedrig …“). Jetzt „aufsteigend (A–Z)“ und „absteigend (Z–A)“.

## [0.11.2] - 2026-10-09

### Changed
- Durchsicht als Neunutzer (Neuinstallations-Simulation der Store-Checkliste, Punkt 12): Anrede einheitlich „du“ (vorher gemischt „Sie“ und „du“, die anderen Module des Verbunds sagen „du“). Veraltete Sätze in „Dokumentation & Hilfe“ korrigiert („die Auswertung nach Zelltyp folgt in einer späteren Version“, „antwortet es nach 14 Tagen nicht“, unvollständige Skript-Liste, Auswertung des Tagebuchs).
- **Das Panel „Neu bis Version“ entfällt bis zur ersten veröffentlichten Version** (Dietmar, 09.10.2026): Vorher bekam eine neue Instanz die ganze Entwicklungsgeschichte ab Version 0.1.0 zu sehen (über 60 Zeilen). Die Geschichte steht im `CHANGELOG.md`. Ab der ersten Veröffentlichung wird die Liste (`NEWS_VERSIONS`) mit den Änderungen seitdem gepflegt; das Panel zeigt dann höchstens die letzten drei Versionen mit Verweis auf das Änderungsprotokoll.

## [0.11.1] - 2026-10-09

### Fixed
- **Diagnose fürs Forum:** Die Liste „nach Batterie aussehend, aber nicht erkannt“ enthielt an einer Anlage mit Heimspeicher über 100 Zeilen (`bat1_*`, Variablen ohne Instanz oder ohne Ident). Sie nennt jetzt nur noch Variablen von Geräteinstanzen mit Ident, ohne Module der Ausschlussliste. Die erkannten und die ausgeschlossenen Signale stehen unverändert da.

## [0.11.0] - 2026-10-09

### Added
- **Abfrage bei Funkstille:** Bei Geräten mit eingeschalteter Spalte „Abfragen“ (Z-Wave, Matter) schickt der Wächter nicht nur bei altem Batteriewert, sondern auch bei überfälligem Lebenszeichen eine Statusanfrage (höchstens alle N Tage, wie bisher). Bei einer Anfrage wegen Funkstille zählt **jedes** Lebenszeichen nach der Anfrage als Antwort („schläft nur“); bei einer Anfrage wegen alten Werts zählt weiter nur der neue Batteriewert. „Keine Antwort“ gilt bei Z-Wave nach 14 Tagen (schlafende Geräte antworten erst beim Aufwachen), bei Matter nach 6 Stunden. Auch **Gruppen-Regeln** können „Abfragen“ einschalten (neue Spalte).
- **Tauschrunde in einem Rutsch quittieren:** In der Kachel unter „Einkauf“ trägt „✔ Alles getauscht (N)“ je Ort alle Geräte der Tauschrunde dieses Ortes als getauscht ein (zweiter Klick bestätigt, nur mit erlaubtem Quittieren). Im Formular unter „Quittieren“: „Ort aus der Tauschrunde“ und „✔️ Alle dort getauscht“, für Skripte `BWACH_AcknowledgePlace($id, '<Ort>')`. Bewertet wird erst am Ende einmal neu.
- **Genauigkeit der Prognose:** Der Wächter merkt sich höchstens einmal pro Woche, was die Prognose bei hoher oder mittlerer Sicherheit sagt (Prozent und Entladung je Tag; Variable „Prognose-Protokoll“, höchstens 8 Einträge je Gerät). Bei einem **erkannten** Wechsel vergleicht er die älteste Prognose, die 14 bis 150 Tage alt ist, mit dem Stand vor dem Wechsel und speichert Vorhersage, Stand und Abweichung im Tagebuch. Statistik (Kachel und Tabelle) zeigt Anzahl der Vergleiche, mittlere Abweichung in Prozentpunkten und die Richtung. Von Hand eingetragene Wechsel werden nicht verglichen (der Stand davor ist nicht sicher bekannt). Braucht Wochen bis Monate Verlauf.
- **Erste Schritte:** neues Panel mit Checkliste (Geräte gesucht, Zelltypen bekannt, Meldungen mit Zustellweg, Hinweis auf die Kachel, Matter-Hinweis), die sich selbst abhakt und aufgeklappt ist, solange etwas offen ist.
- **Diagnose fürs Forum** (`BWACH_Diagnosis($id)`, Schaltfläche unter „Gefundene Geräte“): Version, je Modul die erkannten Signale mit Ident, Typ, Profil und Erkennungsweg, die Ausschlüsse und eine Liste von Variablen, die nach Batterie aussehen, aber nicht erkannt wurden. Ohne Gerätenamen und Objekt-IDs.

## [0.10.1] - 2026-10-09

### Added
- **Abfragen für Matter:** Die Statusanfrage gilt jetzt auch für Matter-Geräte (`MATTER_RequestStatus` an der Instanz der Stromversorgung, Endpunkt 0, wo der Batteriestand liegt). Sie war bisher nur für Z-Wave (`ZW_RequestStatus`) eingebaut, weil das die einzige geprüfte Funktion war; Matter-Instanzen haben aber `MATTER_RequestStatus`. In einem Test an zwei Leckagesensoren, die seit 21 Stunden nichts von sich aus gesendet hatten, kamen neue Werte nach 9 und 15 Sekunden. Eine Anfrage an einen Fensterkontakt (Endpunkt 1) hat den Wert in 15 Sekunden nicht aktualisiert; die Wirkung ist also nicht für jedes Gerät belegt.

### Changed
- Die Spalte heißt „Abfragen (Z-Wave, Matter)“, Hilfetext, Hinweis und Dokumentation nennen beide Systeme (vorher stand Z-Wave nur im Hinweis weiter unten, nicht an der Spalte).

## [0.10.0] - 2026-10-09

### Added
- **Vorrat:** Liste „Vorrat zu Hause“ unter „Prognose und Einkauf“ (Zelltyp, Stück). Die Einkaufsliste zieht ihn ab: „1× AAA (Bedarf 3, 2 vorrätig)“; reicht der Vorrat, steht „AAA: Vorrat reicht (3 nötig, 5 da)“ (Kachel, Text, Mail). Mehrere Zeilen desselben Zelltyps werden summiert. AA/AAA als Alkali und als Akku sind getrennte Bezeichnungen, ihr Vorrat zählt nicht gegenseitig.
- **Vorsorglich tauschen:** Einstellung „Kritische Geräte vorsorglich tauschen alle N Monate“ (0 = aus, Standard aus). Gilt nur für kritische Geräte (einzeln oder per Gruppen-Regel) mit erfasstem letzten Wechsel im Tagebuch (erkannt, eingetragen oder nachgetragen). Ist das Intervall um, steht das Gerät mit Grund in Kachel (🗓, Filter „Vorsorge“), Tauschrunde und Einkauf und wird gemeldet („🗓 Vorsorglich tauschen“), der Batteriestatus bleibt „ok“. Ohne erfassten Wechsel passiert nichts.
- **Wechsel nachtragen:** Unter „Quittieren und Batterietagebuch“ lässt sich ein früherer Wechsel mit Datum (TT.MM.JJJJ oder JJJJ-MM-TT) eintragen (`BWACH_AddReplacement($id, $key, $date)`). Er geht einsortiert ins Tagebuch (Typ „nachgetragen“) und in Lebensdauer und Vorsorge; es gibt keine Meldung und keine Wartezeit. Ungültige Tage, Zukunft und Jahre vor 2015 werden abgelehnt, ein Eintrag innerhalb von 3 Tagen um dasselbe Datum ebenso (Doppelte).
- **Kachel „📍 Nach Ort“:** gruppiert die Liste mit Zwischenüberschriften je Ort (alphabetisch, „ohne Ort“ zuletzt, mit Anzahl). Die Sortierung wirkt innerhalb der Gruppen; die Wahl merkt sich der Browser.
- **Geräteliste als CSV** (`BWACH_DeviceListCsv($id)`, Schaltfläche „📄 Geräteliste als CSV“): Name, Ort, System, Zelltyp, Zellen, Batteriestand, Status, Funk, letzter Wechsel, Lebenszeichen vor Tagen; Semikolon, nach Ort und Name sortiert.

## [0.9.0] - 2026-10-09

### Added
- **Einkaufsliste mitnehmen:** Die Kachel zeigt unter „Einkauf“ die Schaltflächen „📋 Kopieren“ (Liste und Tauschrunde als Text in die Zwischenablage) und „✉️ Senden“ (Push und/oder E-Mail über die unter „Meldungen“ eingestellten Wege, unabhängig vom Schalter „Meldungen aktiv“, weil du es von Hand auslöst). Im Formular: „Einkaufsliste als Text“ und „Einkaufsliste jetzt senden“ unter „Prognose und Einkauf“; für Skripte `BWACH_ShoppingText($id)` und `BWACH_SendShopping($id)`.
- **Optionale Linkvorlage** („Link für die Einkaufsliste“, Standard leer): Eine URL mit `{Zelltyp}` (https:// oder http://), z. B. die Suche eines Händlers. Die Kachel zeigt hinter jeder Zeile der Einkaufsliste einen Suchlink (neues Fenster, ohne Referrer). Es ist kein Händler eingebaut und es wird nichts dorthin gesendet; Vorlagen ohne http(s) oder ohne `{Zelltyp}`, mit Leer- oder Sonderzeichen oder über 300 Zeichen werden ignoriert.

## [0.8.2] - 2026-10-09

### Fixed
- Zu lange Beschriftungen von Schaltflächen im Formular wurden abgeschnitten („Wie sicher ist die Prognose, und was heißt „aus Spannung berechnet“?“). Gekürzt: „Wie sicher ist die Prognose?“, „Wie arbeiten Meldungen zusammen?“, „Aus BY_BatterieMonitor übernehmen“, „Außer-Betrieb-Geräte aufnehmen“. Die ausführliche Frage steht als Überschrift im geöffneten Fenster. Der Prüfstand lässt keine Schaltflächen-Beschriftung über 40 Zeichen mehr zu.

## [0.8.1] - 2026-10-09

### Added
- „Geräte-Einstellungen“ erklärt unter „Was bedeuten die Spalten?“ (aufklappbar) Gruppe/Ereignismelder, Kritisch, Ohne Altersprüfung, Ausnehmen, Zelltyp und Anzahl Zellen sowie Abfragen. Die Schwellen im Text (schwach ab 30 % statt 20 %, 7 statt 30 Tage Funkstille, 90 Tage Wertalter, Abfrage ab 14 Tagen alle 7 Tage …) sind die aktuell eingestellten Werte, nicht feste Zahlen. Abfragen sagt ehrlich, dass die Wirkung nicht belegt ist.

## [0.8.0] - 2026-10-09

### Added
- **Gruppen-Regeln** (neues Panel „Gruppen“): Eine Regel trägt Zelltyp, Anzahl Zellen, Gruppe „Ereignismelder“, „kritisch“ und „ausnehmen“ für alle Geräte ein, auf die ihr Muster passt. Kriterien: Ort (Name der Kategorie der Instanz), System (Modulname) oder Name; mehrere Muster mit Komma (ODER), ohne Groß-/Kleinschreibung, Teiltreffer. Es gilt die erste passende Regel von oben (Reihenfolge per Drag & Drop änderbar); Regeln lassen sich abschalten. Die Spalte „Treffer“ zeigt, wie viele Geräte eine Regel als erste trifft.
- **Vorrang:** Eine eigene Einstellung in „Geräte-Einstellungen“ geht vor, sobald sie vom Standard abweicht (Zelltyp gewählt, Ereignismelder, kritisch, ausgenommen). Zelltyp und Zellenzahl zählen als Paar. Neue Geräte, die später dazukommen und auf eine Regel passen, bekommen die Werte ohne weiteres Zutun.
- „Geräte-Einstellungen“ zeigt in der neuen Spalte „Gilt“, was am Ende für ein Gerät zählt (Zelltyp, Zellenzahl, Ereignismelder, kritisch, ausgenommen) und aus welcher Regel es kommt. Das Panel „Gruppen“ nennt die Geräte, die noch keinen Zelltyp haben.

### Changed
- Die Spalte „Geräteinstanz“ in „Geräte-Einstellungen“ sortiert nach dem Gerätenamen, den auch Kachel und Regeln verwenden (bei Matter der Name der Funktionsinstanz, z. B. „Badfenster Senkrecht“ statt „… Stromversorgung“).

## [0.7.1] - 2026-10-09

### Added
- **Sortierung der Liste „Geräte-Einstellungen“ in der Konsole:** „Sortieren nach“ (Name, Ort, System, Batteriestand, Zelltyp, Gruppe, Kritisch) und „Reihenfolge“ (aufsteigend/absteigend) über der Liste. Die Wahl wird mit „Übernehmen“ in `DeviceSortBy`/`DeviceSortDir` gespeichert und gilt beim nächsten Öffnen als Startsortierung (Standard: nach Name aufsteigend). (Berichtigt in 0.11.5: Der Versuch, die Liste schon im offenen Formular per `UpdateFormField` umzusortieren, wirkt nicht; die Sortierung gilt erst nach „Übernehmen“.)
- Die Liste zeigt je Gerät **Ort**, **System** und den aktuellen **Batteriestand** (nur zur Ansicht, keine Einstellungen). Die Spalte „Geräteinstanz“ sortiert nach dem Namen, „Batteriestand“ nach der Zahl (Geräte ohne Wert stehen bei „aufsteigend“ hinten). Zwei versteckte Spalten tragen die Sortierwerte.

## [0.7.0] - 2026-10-09

### Added
- **Sortierung in der Kachel:** Dringlichkeit (Standard, wie bisher), Name, Ort, Batteriestand (niedrigster zuerst), Alter des Werts (ältester zuerst), Lebenszeichen (am längsten still zuerst), Zelltyp und System. Ein zweiter Klick auf dieselbe Eigenschaft kehrt die Reihenfolge um. Geräte ohne Wert stehen in jeder Richtung hinten, bei Gleichstand bleibt die Reihenfolge nach Dringlichkeit. Deutsche Sortierung (Umlaute, Zahlen in Namen natürlich: „Fenster 2“ vor „Fenster 10“). Die Wahl wird im Browser gemerkt (nur Komfort, ohne Speicher gilt die Standardreihenfolge). Gilt für alle Listenansichten (Handlungsbedarf, Alle und die Filter).
- Die Kachel-Daten tragen dafür Alter des Werts und Lebenszeichen in Sekunden sowie den Zelltyp als Kurzbezeichnung.

## [0.6.4] - 2026-10-09

### Changed
- „Geräte-Einstellungen“ trägt den Zelltyp ein, den das Gerät selbst meldet (Matter „Ersatz Beschreibung“: CR2032, CR2450, CR123A, RCR123A, 16340, AA, AAA, 9V). Das gilt für neue Zeilen und für gespeicherte Zeilen, in denen noch kein Zelltyp gewählt ist; eine eigene Wahl wird nie überschrieben. Die Werte stehen nur in der Liste und werden erst mit „Übernehmen“ gespeichert. Bei AA/AAA nennt das Gerät nur die Bauform: Alkali ist angenommen, der Hinweis im Formular sagt es.

## [0.6.3] - 2026-10-09

### Changed
- Der Matter-Hinweis nennt den Weg zur Stromversorgungs-Instanz: „Instanz hinzufügen“, Modul „Matter Gerät“, Knoten-ID und Endpunkt 0 von Hand. Der Matter Konfigurator bietet den Endpunkt 0 nicht an und zeigt solche Instanzen rot (mit Pfad statt Name, ohne ID-Nummer und Info-Symbol); sie arbeiten trotzdem richtig. So an einer Anlage mit 15 Knoten bestätigt (09.10.2026).

## [0.6.2] - 2026-10-08

### Added
- „Gefundene Geräte“ (und der Trockenlauf) nennt Matter-Knoten, die nur Funktionsinstanzen haben (Kontakt, Sensor), aber keine Instanz für die Stromversorgung (Endpunkt 0). Symcon legt diese Instanz nicht von allein an, ohne sie gibt es keinen Batteriewert. Der Hinweis nennt die Geräte (höchstens 8, dann „und N weitere“) und sagt, dass netzbetriebene Geräte keine Batterie haben, weil der Wächter von hier aus nicht sehen kann, ob ein Gerät eine hat.

## [0.6.1] - 2026-10-08

### Changed
- Matter-Knoten mit mehreren Funktionsinstanzen (Knoten 3: Lichtsensor und Anwesenheitssensor): Der Gerätename ist der Name der Stromversorgungs-Instanz ohne den Zusatz „Stromversorgung“ („Anwesenheitssensor“). Vorher wurde zufällig die erste Funktionsinstanz genommen („Lichtsensor“). Bei genau einer Funktionsinstanz (Kontakt) bleibt es bei deren Namen.

## [0.6.0] - 2026-10-08

### Added
- **Matter-Stromversorgung (Endpunkt 0):** Instanzen mit `PowerSource_BatPercentRemaining` (Halbprozent), `PowerSource_BatReplacementNeeded` (Warnsignal „Batterie ersetzen“) und `PowerSource_BatReplacementDescription` werden erkannt. Live an einem IKEA-Öffnungskontakt gesehen (Knoten 14: Rohwert 200 = 100 %, Beschreibung „AAA“). `PowerSource_BatChargeLevel` (0 = OK, 1 = Warnung, 2 = kritisch) wird bewusst nicht gelesen, weil es denselben Zustand grob wiederholt.
- **Zusammenfassung je Knoten:** Die Stromversorgungs-Instanz und die Funktionsinstanz (Kontakt, Endpunkt 1) desselben Matter-Knotens sind ein Gerät. Name und Ort kommen vom Funktionsendpunkt, als Lebenszeichen zählt auch dessen letzte Meldung (ein Kontakt meldet bei jedem Öffnen, die Stromversorgung selten).
- **Zelltyp vom Gerät:** Meldet das Gerät eine Ersatz-Beschreibung (CR2032, CR2450, CR123A, RCR123A, 16340, AA, AAA, 9V) und ist in den Geräte-Einstellungen kein Zelltyp gewählt, gilt diese. Ein gewählter Zelltyp hat immer Vorrang. Bei AA/AAA ist nur die Bauform bekannt (Alkali angenommen), die Kachel sagt das.

### Changed
- Der Matter-Batteriestand gilt nicht mehr als „laut Dokumentation, ungetestet“.

## [0.5.3] - 2026-10-08

### Fixed
- Batteriespannungen über 100 V werden als Millivolt gelesen (Matter `PowerSource_BatVoltage` liefert z. B. 3000 für 3,0 V) statt als „3000 V“ angezeigt und mit „passt nicht zum Zelltyp“ abgelehnt. Betrifft Matter-Geräte, sobald Symcon diese Variable anlegt; die Erkennung der Matter-Idents (`PowerSource_BatPercentRemaining`, `PowerSource_BatVoltage`) ist nach Dokumentation, nicht an einem echten Gerät geprüft.

## [0.5.2] - 2026-10-08

### Added
- Zelltyp **„RCR123A / 16340 Akku (Li-Ion, 3,7 V)“** mit der Li-Ion-Spannungskurve (4,2 V voll, 3,0 V leer), in der Einkaufsliste als „RCR123A (Akku)“ getrennt von der **CR123A-Batterie** (3 V, nicht wiederaufladbar, Anzeige jetzt mit diesem Zusatz). Beide Typen haben völlig verschiedene Spannungen: 3,7 V sind für die Batterie unmöglich, für den Akku 35 %.
- Der Vorschlag aus der Spannung nennt CR123A und RCR123A auch zu zweit oder zu dritt in Reihe (z. B. 6 V = 2× CR123A); Knopfzellen, 9-V-Block und fest eingebaute Akkus nur einzeln.

## [0.5.1] - 2026-10-08

### Changed
- **Geräte-Einstellungen listet jedes erkannte Gerät schon auf** (neutrale Standardwerte: Standard, nicht kritisch, Zelltyp unbekannt, 1 Zelle, keine Abfrage). Je Gerät nur noch Zelltyp und Anzahl Zellen wählen und „Übernehmen“ klicken, statt jede Zeile von Hand hinzuzufügen. Gespeicherte Zeilen bleiben unverändert und vorn, doppelte werden nicht verdoppelt, Zeilen verschwundener Instanzen bleiben erhalten. Gespeichert wird erst mit „Übernehmen“. Geräte ohne Geräteinstanz (manuell ergänzte Variablen) gehören nicht in die Liste.
- Der Hinweis „Zelltyp fehlt“ in Kachel und Tabelle sagt jetzt genau, wo man den Zelltyp einträgt (Instanzname, Panel „Geräte-Einstellungen“, Spalte „Zelltyp“).

## [0.5.0] - 2026-10-08

### Added
- **Vergleich mit Gleichartigen:** Ein Gerät, das mindestens doppelt so schnell entlädt wie der Median seiner Gruppe (gleiches System, gleicher Zelltyp, mindestens 4 Geräte mit bekannter Entladerate, mindestens 0,05 Prozentpunkte pro Tag), wird als auffällig markiert, mit möglichen Ursachen. Nur Tabelle, Kachel und Wochenbericht, keine eigene Push-Meldung.
- **Funkqualität** (Zigbee `linkquality`, RSSI, Signalstärke) wird je Gerät angezeigt und eingeordnet; ein schwaches Signal wird bei auffälliger Entladung als möglicher Grund genannt.
- **Kälteeinfluss:** Mit einer Außentemperatur-Variable (optional) speichert der Verlauf die Temperatur mit; ausgewertet wird erst, wenn mindestens 3 Geräte je 3 Zeitabschnitte bei Kälte und bei Wärme haben, vorher steht „noch nicht genug Daten“ mit Zahlen. Eine Außentemperatur, die älter als 12 Stunden ist, wird nicht verwendet.
- **Abfrage schlafender Z-Wave-Geräte** (je Gerät einzuschalten, standardmäßig aus): Ist der Batteriewert älter als eingestellt (14 Tage), schickt der Wächter höchstens alle 7 Tage eine Statusanfrage (`ZW_RequestStatus`) und zeigt, ob das Gerät geantwortet hat; ohne Antwort nach 14 Tagen wird das ein Befund („reagiert nicht auf Abfragen“).
- **Übernahme aus BY_BatterieMonitor:** Push, E-Mail und SMTP-Instanz einer alten Instanz lassen sich in die Maske übernehmen (gespeichert wird erst mit „Übernehmen“). Der Wächter nennt, was nicht übernommen wird.

## [0.4.0] - 2026-10-08

### Added
- **Zelltyp je Gerät** (CR2032, CR2450, CR123A, AA/AAA Alkali, AA/AAA NiMH, AA Lithium, 9-V-Block, Li-Ion/LiPo) und Anzahl Zellen. Meldet ein Gerät nur eine Spannung, rechnet der Wächter sie mit einer typischen Entladekurve in einen Ladezustand um (Näherung, in der Anzeige immer „aus Spannung berechnet“). Passt die Spannung nicht zum Zelltyp, meldet er das, statt zu raten. Ohne Zelltyp nennt er Kandidaten für die Spannung, wählt aber nie selbst.
- **Verlauf und Prognose:** Der Wächter schreibt den Verlauf jedes Geräts im Modul selbst mit (kein Symcon-Archiv) und schätzt die Restlaufzeit mit einer robusten Geraden (Theil-Sen), mit Sicherheit hoch/mittel/gering. Nur der Abschnitt seit dem letzten Batteriewechsel zählt. Zu wenig Verlauf, zu grobe Stufen oder keine erkennbare Entladung werden ehrlich benannt, nie geraten.
- **„Batterie bald leer“** als eigener Befund und eigene Meldung, nur bei hoher oder mittlerer Sicherheit der Prognose und unter einer einstellbaren Restlaufzeit.
- **Einkaufsliste** („4× CR2032, 7× AAA“, nach Zellenzahl) und **Tauschrunde** (nach Ort gebündelt, mit „am besten bis TT.MM.JJJJ“), in der Kachel, als Variable „Einkauf und Tauschrunde“ und vorn im Wochenbericht.
- **Lebensdauerstatistik** aus den Batteriewechseln: je Gerät, je Zelltyp und je System; Restlaufzeit je Gerät. Variable „Lebensdauer und Entladung“ und Kachel-Ansicht „📈 Statistik“.
- **Gelerntes Meldeverhalten:** Sendet ein Gerät sehr regelmäßig, wird Funkstille früher erkannt (3× das 90. Perzentil der beobachteten Abstände, mindestens 6 Stunden, nie über der eingestellten Schwelle). Abschaltbar.
- **Verwaist-Vorschlag:** Ein Gerät, das seit über 60 Tagen still ist (einstellbar), wird als „vermutlich ausgebaut“ vorgeschlagen.
- Kachel: Filter „⏳ bald leer“, Ansichten „🛒 Einkauf“ und „📈 Statistik“, Prognose und Zelltyp beim Aufklappen. Neue Kennzahl „Bald leer“ und Spalte „Restlaufzeit“ in den Tabellen.

### Changed
- Der Wochenbericht nennt Kennzahlen und Einkauf vorn (Push kürzt bei 256 Byte), die Geräteliste danach.

## [0.3.1] - 2026-10-08

### Changed
- Schlägt der E-Mail-Versand fehl, nennt die **Testmeldung** die Ursache, statt auf das Meldungslog zu verweisen: z. B. „Anmeldung abgelehnt: Benutzername oder Passwort der SMTP-Instanz stimmen nicht (bei iCloud … App-spezifisches Passwort)“. Erkannt werden Anmeldung, Servername, Zeitüberschreitung, Verbindung abgelehnt, Zertifikat und Empfänger; alles andere wird unverändert als Meldung des SMTP-Moduls durchgereicht. Dieselbe Ursache steht im Meldungslog, auch wenn eine echte Meldung nicht zugestellt werden konnte.

## [0.3.0] - 2026-10-08

### Added
- **Kachel** für die Kachel-Visualisierung (Meilenstein 3): Geräte nach Dringlichkeit, Filter (Handlungsbedarf, Alle, leer, schwach, Funkstille, Daten prüfen), Batterietagebuch, Quittieren per Antippen („Habe ich getauscht“, „Erinnere mich später“, „Außer Betrieb“ mit zweitem Klick zur Sicherheit). Ohne eigenen Titel. Quittieren aus der Kachel lässt sich abschalten.

### Changed
- Gerätewahl beim Quittieren nennt den Befund kurz („leer, Funkstille“); Tagebuch- und „Außer Betrieb“-Zeile frischen sich nach dem Quittieren sofort auf (waren bis zum Neuöffnen veraltet).
- Dativ nach „vor“ und „seit“ („seit 16 Tagen“ statt „seit 16 Tage“). Die Kachel zeigt als Unterzeile den Hauptbefund statt des ersten Grundes in der Liste.

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
