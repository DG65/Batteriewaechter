# Changelog

Alle nennenswerten Änderungen an diesem Modul werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

## [0.7.1] - 2026-10-09

### Added
- **Sortierung der Liste „Geräte-Einstellungen“ in der Konsole:** „Sortieren nach“ (Name, Ort, System, Batteriestand, Zelltyp, Gruppe, Kritisch) und „Reihenfolge“ (aufsteigend/absteigend) über der Liste. Das Umsortieren geschieht im offenen Formular (`UpdateFormField` auf die Eigenschaft `sort` der Liste) und lässt ungespeicherte Eingaben unberührt. Die Wahl wird mit „Übernehmen“ in `DeviceSortBy`/`DeviceSortDir` gespeichert und gilt beim nächsten Öffnen als Startsortierung (Standard: nach Name aufsteigend).
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
