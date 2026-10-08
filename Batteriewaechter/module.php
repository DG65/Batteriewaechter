<?php

// ===========================================================================
// Batteriewächter (DG65 Toolkit) — überwacht die Batterien von Geräten im
// Haus (Funksensoren, Thermostate, Fensterkontakte, Rauchmelder …).
//
// WAS ES ANDERS MACHT ALS EIN REINER „LEER“-MELDER:
//   Finden   — Batteriesignale werden über Variablenprofile (~Battery …),
//              Idents und Darstellungen gefunden und je Gerät zusammengeführt
//              (Prozent, „schwach“-Flag und Spannung eines Geräts sind EIN Gerät).
//              Heimspeicher, Sammelwerte und Skriptvariablen werden begründet
//              ausgeschlossen. Der Trockenlauf zeigt vorher, was gefunden würde.
//   Verstehen — Status ok/schwach/leer/unbekannt, dazu Datenqualität:
//              Widerspruch zwischen Flag und Prozent, Werte außerhalb 0–100 %,
//              Batteriewert veraltet. Ein dreijahre alter „OK“-Wert ist kein OK.
//   Drei Zeiten — Alter des Batteriewerts, Lebenszeichen des Geräts (jüngste
//              Aktualisierung einer Messwert-Variable ohne Aktion) und das Gerät
//              selbst. Tote Funkverbindung und schwache Batterie sind
//              verschiedene Befunde.
//
// Anzeige: Kachel für die Kachel-Visualisierung (sortierte Liste, Filter, Tagebuch,
// Quittieren) plus HTML-Tabellen und Kennzahlen als Variablen.
//
// Ereignisgesteuert: Änderungen der Batteriewerte werden sofort bewertet
// (kurze Verzögerung gegen Meldungsschwärme), Funkstille und Wertealter
// zeitgesteuert (Standard stündlich). Kein Archiv nötig.
//
// Eigenständig: setzt kein anderes Modul voraus und wird von keinem
// vorausgesetzt.
// ===========================================================================

require_once __DIR__ . '/BWACHLogik.php';
require_once __DIR__ . '/BWACHMeldung.php';
require_once __DIR__ . '/BWACHPrognose.php';

class Batteriewaechter extends IPSModule
{
    /** Grund des letzten fehlgeschlagenen E-Mail-Versands (für Testmeldung und Meldungslog). */
    private string $lastMailError = '';

    private const LIBRARY_GUID = '{68C5991B-8E85-23AC-C254-B446AF035AF8}';

    // Formular-Konvention (SUITE.md "Einheitliche Formular-Optik", NEWS_VERSIONS-Muster)
    private const NEWS_VERSIONS = [
        '0.6.0' => [
            '• Matter: Die Batterie eines Matter-Geräts steht in einer eigenen Instanz für die „Stromversorgung“ (Endpunkt 0), die man im Matter Konfigurator anlegt. Der Wächter erkennt sie, fasst sie mit dem Kontakt desselben Knotens zu einem Gerät zusammen und nimmt dessen Namen.',
            '• Als Lebenszeichen zählt bei Matter auch der Kontakt: Er meldet bei jedem Öffnen, die Stromversorgung nur selten. Ein Fensterkontakt gilt so nicht mehr fälschlich als still.',
            '• „Ersatz erforderlich“ des Geräts wird als Warnsignal ausgewertet. Meldet das Gerät eine Ersatz-Beschreibung („AAA“, „CR2032“ …) und ist kein Zelltyp gewählt, übernimmt der Wächter sie; bei AA/AAA steht dabei, dass nur die Bauform bekannt ist.',
            '• Der Matter-Batteriestand (Halbprozent-Skala) ist an einem Gerät bestätigt und nicht mehr als „ungetestet“ gekennzeichnet.',
        ],
        '0.5.3' => [
            '• Batteriespannungen über 100 V (Matter meldet Millivolt, z. B. 3000) werden als Millivolt gelesen und nicht als „3000 V“ angezeigt.',
        ],
        '0.5.2' => [
            '• Neuer Zelltyp „RCR123A / 16340 Akku (Li-Ion, 3,7 V)“ neben der nicht wiederaufladbaren CR123A-Batterie (3 V): die beiden haben völlig verschiedene Spannungen, die Einkaufsliste führt sie getrennt.',
        ],
        '0.5.1' => [
            '• „Geräte-Einstellungen“ listet jetzt jedes erkannte Gerät schon auf (neutrale Standardwerte). Je Gerät nur noch Zelltyp und Anzahl Zellen wählen und „Übernehmen“ klicken — kein Hinzufügen von Hand mehr.',
            '• Der Hinweis „Zelltyp fehlt“ in der Kachel und in der Tabelle sagt jetzt genau, wo man den Zelltyp einträgt.',
        ],
        '0.5.0' => [
            '• Vergleich mit Gleichartigen: Ein Gerät, das mehr als doppelt so schnell entlädt wie seine Gruppe (gleiches System, gleicher Zelltyp, mindestens 4 Geräte), wird als auffällig markiert — mit möglichen Ursachen.',
            '• Funkqualität (Zigbee linkquality, RSSI) wird angezeigt, ein schwaches Signal als möglicher Grund genannt.',
            '• Kälteeinfluss: Mit einer Außentemperatur-Variable wertet der Wächter aus, ob sich die Batterien bei Kälte schneller entladen — ehrlich erst, wenn genug Daten da sind.',
            '• Abfrage schlafender Z-Wave-Geräte (je Gerät einzuschalten, standardmäßig aus): Ist der Batteriewert alt, schickt der Wächter höchstens alle paar Tage eine Statusanfrage und zeigt, ob das Gerät geantwortet hat.',
            '• Einstellungen aus einer alten BY_BatterieMonitor-Instanz lassen sich in die Maske übernehmen (Push, E-Mail, SMTP-Instanz).',
        ],
        '0.4.0' => [
            '• Prognose: Der Wächter schreibt den Verlauf jedes Geräts mit und schätzt die Restlaufzeit („reicht noch etwa 23 Tage, mittlere Sicherheit“) — oder sagt ehrlich, warum er es nicht kann. Neue Meldung „Batterie bald leer“ bei ausreichender Sicherheit.',
            '• Zelltyp je Gerät (CR2032, AA, AAA …): Spannungen werden in einen Ladezustand umgerechnet (Näherung, als solche gekennzeichnet), der Wächter erkennt, wenn die Spannung nicht zum Zelltyp passt.',
            '• Einkaufsliste („4× CR2032, 7× AAA“) und Tauschrunde (nach Ort gebündelt, mit Vorschlag bis wann), Lebensdauerstatistik je Gerät, Zelltyp und System; im Wochenbericht und als Variablen.',
            '• Gelernter Meldetakt: Sendet ein Gerät sehr regelmäßig, erkennt der Wächter Funkstille früher. Geräte, die seit über 60 Tagen still sind, schlägt er als „vermutlich ausgebaut“ vor.',
        ],
        '0.3.1' => [
            '• Testmeldung nennt bei einem fehlgeschlagenen E-Mail-Versand die Ursache, z. B. „Anmeldung abgelehnt: Benutzername oder Passwort der SMTP-Instanz stimmen nicht“, statt auf das Meldungslog zu verweisen. Die Ursache steht auch im Meldungslog.',
        ],
        '0.3.0' => [
            '• Kachel für die Kachel-Visualisierung: Geräte nach Dringlichkeit, Filter (Handlungsbedarf, leer, schwach, Funkstille, Daten prüfen), Batterietagebuch und Quittieren per Antippen. Instanz einfach als Kachel hinzufügen.',
            '• Gerätewahl beim Quittieren nennt den Befund kurz („leer, Funkstille“), Tagebuch- und „Außer Betrieb“-Zeile frischen sich nach dem Quittieren sofort auf.',
        ],
        '0.2.0' => [
            '• Meldungen ohne Nerven: erste Meldung, Erinnerung nach N Tagen (kritische Geräte früher), Ruhezeiten, Wochenbericht — per Push (Kachel-Visualisierung und WebFront) und E-Mail. Mehrere Befunde eines Laufs kommen als EINE Nachricht. Standardmäßig AUS, bis Sie „Meldungen aktiv“ einschalten.',
            '• Quittieren: „Habe ich getauscht“, „Erinnere mich später“, „Außer Betrieb“. Eskalation für kritische Geräte, die niemand beachtet.',
            '• Batterietagebuch: ein Batteriewechsel wird erkannt (Prozentwert springt hoch, „schwach“-Flag wird zurückgesetzt) oder von Hand eingetragen und samt Datum festgehalten.',
        ],
        '0.1.0' => [
            '• Erstes Release: findet Batteriesignale automatisch (Profile ~Battery/~Battery.100/~Battery.Reversed, typische Idents) und führt sie je Gerät zusammen.',
            '• Status ok/schwach/leer/unbekannt mit Datenqualität: Widerspruch zwischen Flag und Prozent, unplausible Werte, veraltete Batteriewerte.',
            '• Funkstille als eigener Befund, getrennt von der Batterie (Lebenszeichen des Geräts statt Alter des Batteriewerts).',
            '• Trockenlauf „Was würde gefunden?“ mit Begründung für jeden Ausschluss; Ergebnis als Tabelle und Kennzahlen-Variablen.',
        ],
    ];
    // Push-Ziele (SUITE.md Stolperstein 22): klassisches WebFront UND Kachel-Visualisierung, je eigene Funktion
    private const WEBFRONT_GUID = '{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}';
    private const KACHEL_GUID   = '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}';
    private const MAX_DIARY_ROWS = 50;

    private const REPO_URL    = 'https://github.com/DG65/Batteriewaechter';
    private const LICENSE_URL = 'https://github.com/DG65/Batteriewaechter/blob/beta/LICENSE';
    private const PAYPAL_URL  = 'https://paypal.me/DietmarGureth';

    private const VM_UPDATE_MSG   = 10603;
    private const KERNEL_MSG      = 10100;
    private const KR_READY_MSG    = 10103;
    private const DEBOUNCE_MS     = 5000;   // Sammelfenster für Meldungsschwärme
    private const REDISCOVER_SEC  = 86400;  // Suche höchstens täglich automatisch
    private const MAX_PREVIEW_ROWS = 25;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('LowPercent', 20);
        $this->RegisterPropertyInteger('EmptyPercent', 5);
        $this->RegisterPropertyInteger('CriticalLowPercent', 30);
        $this->RegisterPropertyInteger('ValueOldDays', 90);
        $this->RegisterPropertyInteger('StillDays', 7);
        $this->RegisterPropertyInteger('StillDaysEvent', 30);
        $this->RegisterPropertyInteger('CheckIntervalMin', 60);
        $this->RegisterPropertyString('ExcludedModules', implode(', ', BWACHLogik::DEFAULT_EXCLUDED_MODULES));
        $this->RegisterPropertyInteger('ForecastHorizonDays', 30);
        $this->RegisterPropertyInteger('SoonDays', 14);
        $this->RegisterPropertyBoolean('LearnIntervals', true);
        $this->RegisterPropertyInteger('OutdoorTempVar', 0);
        $this->RegisterPropertyInteger('ColdBelow', 5);
        $this->RegisterPropertyInteger('PollAfterDays', 14);
        $this->RegisterPropertyInteger('PollEveryDays', 7);
        $this->RegisterPropertyInteger('OrphanDays', 60);
        $this->RegisterPropertyBoolean('TileAllowAck', true);
        $this->RegisterPropertyBoolean('NameSearch', false);
        $this->RegisterPropertyString('ManualVariables', '[]');
        $this->RegisterPropertyString('DeviceSettings', '[]');

        // Meldungen — bewusst AUS, bis der Nutzer sie einschaltet
        $this->RegisterPropertyBoolean('NotificationsActive', false);
        $this->RegisterPropertyBoolean('NotifyPush', true);
        $this->RegisterPropertyString('PushTargets', '[]');
        $this->RegisterPropertyBoolean('NotifyMail', false);
        $this->RegisterPropertyInteger('MailInstance', 0);
        $this->RegisterPropertyString('MailTo', '');
        $this->RegisterPropertyInteger('ReminderDays', 7);
        $this->RegisterPropertyInteger('CriticalReminderDays', 2);
        $this->RegisterPropertyInteger('SnoozeDays', 7);
        $this->RegisterPropertyInteger('EscalateHours', 24);
        $this->RegisterPropertyBoolean('EscalatePush', true);
        $this->RegisterPropertyBoolean('EscalateMail', true);
        $this->RegisterPropertyBoolean('QuietEnabled', true);
        $this->RegisterPropertyInteger('QuietFromHour', 22);
        $this->RegisterPropertyInteger('QuietToHour', 7);
        $this->RegisterPropertyBoolean('CriticalIgnoresQuiet', true);
        $this->RegisterPropertyBoolean('DigestEnabled', true);
        $this->RegisterPropertyInteger('DigestWeekday', 1);
        $this->RegisterPropertyInteger('DigestHour', 8);
        $this->RegisterPropertyBoolean('DigestWhenOk', false);
        $this->RegisterPropertyInteger('ReplaceJumpPercent', 25);

        $this->RegisterAttributeString('Found', '');
        $this->RegisterAttributeInteger('LastDiscoveryTs', 0);
        $this->RegisterAttributeInteger('LastCheckTs', 0);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeBoolean('ForumHintGone', false);

        $this->RegisterTimer('Tick', 0, 'BWACH_Tick($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Debounce', 0, 'BWACH_Debounced($_IPS[\'TARGET\']);');

        $this->RegisterMessage(0, self::KERNEL_MSG);
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);

        $this->MaintainVariable('Total', 'Geräte überwacht', VARIABLETYPE_INTEGER, '', 10, true);
        $this->MaintainVariable('Empty', 'Batterie leer', VARIABLETYPE_INTEGER, '', 20, true);
        $this->MaintainVariable('Low', 'Batterie schwach', VARIABLETYPE_INTEGER, '', 30, true);
        $this->MaintainVariable('Silent', 'Funkstille', VARIABLETYPE_INTEGER, '', 40, true);
        $this->MaintainVariable('Check', 'Daten prüfen', VARIABLETYPE_INTEGER, '', 50, true);
        $this->MaintainVariable('Unknown', 'Status unbekannt', VARIABLETYPE_INTEGER, '', 60, true);
        $this->MaintainVariable('StatusLine', 'Zusammenfassung', VARIABLETYPE_STRING, '', 70, true);
        $this->MaintainVariable('TableProblems', 'Handlungsbedarf', VARIABLETYPE_STRING, '~HTMLBox', 80, true);
        $this->MaintainVariable('TableAll', 'Alle Geräte', VARIABLETYPE_STRING, '~HTMLBox', 90, true);
        $this->MaintainVariable('Soon', 'Bald leer', VARIABLETYPE_INTEGER, '', 55, true);
        $this->MaintainVariable('TableDiary', 'Batterietagebuch', VARIABLETYPE_STRING, '~HTMLBox', 100, true);
        $this->MaintainVariable('TableShopping', 'Einkauf und Tauschrunde', VARIABLETYPE_STRING, '~HTMLBox', 110, true);
        $this->MaintainVariable('TableStats', 'Lebensdauer und Entladung', VARIABLETYPE_STRING, '~HTMLBox', 120, true);
        // Zustandsspeicher als versteckte Variablen (wie Schein): Attribute gehen beim Neu-Registrieren des
        // Moduls verloren (SUITE.md Stolperstein 5), Variablen bleiben.
        foreach (['NotifyState' => 'Meldezustand', 'Diary' => 'Tagebuch-Daten', 'LastSeen' => 'Zuletzt gesehen', 'Retired' => 'Außer Betrieb', 'Meta' => 'Sonstiges', 'History' => 'Verlauf', 'LifeObs' => 'Meldeverhalten', 'Poll' => 'Abfragen'] as $ident => $name) {
            $existed = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            $this->MaintainVariable($ident, $name, VARIABLETYPE_STRING, '', 200, true);
            if ($existed === false) {
                $id = IPS_GetObjectIDByIdent($ident, $this->InstanceID);
                if ($id !== false) {
                    IPS_SetHidden($id, true);
                }
            }
        }

        $this->SetStatus(102);

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $this->startUp();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ((int)$Message === self::KERNEL_MSG && is_array($Data) && (int)($Data[0] ?? 0) === self::KR_READY_MSG) {
            $this->startUp();
            return;
        }
        if ((int)$Message === self::VM_UPDATE_MSG) {
            // Meldungsschwärme (mehrere Geräte funken gleichzeitig) sammeln, dann einmal bewerten.
            $this->SetTimerInterval('Debounce', self::DEBOUNCE_MS);
        }
    }

    /** Erste Suche und Takt — nach Kernel-Start und nach jeder Konfigurationsänderung. */
    private function startUp(): void
    {
        $interval = max(5, $this->ReadPropertyInteger('CheckIntervalMin'));
        $this->SetTimerInterval('Tick', $interval * 60 * 1000);
        $this->Search();
    }

    // =====================================================================
    //  Öffentliche Funktionen
    // =====================================================================

    /** Zyklischer Lauf: täglich neu suchen, sonst nur bewerten. */
    public function Tick(): void
    {
        $found = $this->found();
        if ($found === null || ($this->now() - $this->ReadAttributeInteger('LastDiscoveryTs')) > self::REDISCOVER_SEC) {
            $this->Search();
            return;
        }
        $this->Check();
    }

    /** Verzögerter Lauf nach Batteriemeldungen. */
    public function Debounced(): void
    {
        $this->SetTimerInterval('Debounce', 0);
        $this->Check();
    }

    /** Sucht alle Batteriesignale im Objektbaum und bewertet danach. */
    public function Search(): string
    {
        $vars   = $this->collectVariables();
        $result = BWACHLogik::classify($vars, [
            'excludedModules' => $this->excludedModules(),
            'nameSearch'      => $this->ReadPropertyBoolean('NameSearch'),
            'manual'          => $this->manualVariables(),
        ]);
        $this->WriteAttributeString('Found', json_encode($result));
        $this->WriteAttributeInteger('LastDiscoveryTs', $this->now());
        $this->syncMessages($result);

        $line = $this->discoveryLine($result);
        $this->UpdateFormField('DiscoveryStatus', 'caption', $line);
        $this->Check();
        return $line;
    }

    /** Bewertet alle gefundenen Geräte und schreibt Kennzahlen und Tabellen. */
    public function Check(): string
    {
        $found = $this->found();
        if ($found === null) {
            return 'ℹ️ Noch nicht gesucht — zuerst „Jetzt neu suchen“.';
        }
        $rows = $this->evaluateAll($found);
        if ($this->pollDevices($rows, $this->now())) {
            $rows = $this->evaluateAll($found);   // Stand der Abfragen gehört in die Bewertung
        }
        $results = array_column($rows, 'r');
        $sum     = BWACHLogik::summarize($results);
        $sum['soon'] = count(array_filter($results, function ($r) { return !empty($r['soon']); }));
        $this->setIntIfChanged('Soon', $sum['soon']);

        $this->setIntIfChanged('Total', $sum['total']);
        $this->setIntIfChanged('Empty', $sum['empty']);
        $this->setIntIfChanged('Low', $sum['low']);
        $this->setIntIfChanged('Silent', $sum['silent']);
        $this->setIntIfChanged('Check', $sum['check']);
        $this->setIntIfChanged('Unknown', $sum['unknown']);

        $now  = $this->now();
        $line = $this->summaryLine($sum, $now);
        $this->setStringIfChanged('StatusLine', $line);
        $this->updateHistory($rows, $now);
        $this->detectReplacements($rows, $now);
        $this->processNotifications($rows, $sum, $now);

        $notes = $this->snoozeNotes($now);
        $this->setStringIfChanged('TableAll', $this->renderTable($rows, false, $notes));
        $this->setStringIfChanged('TableProblems', $this->renderTable($rows, true, $notes));
        $this->setStringIfChanged('TableDiary', $this->renderDiary());
        $views = $this->views($rows, $now);
        $this->setStringIfChanged('TableShopping', $this->renderShopping($views));
        $this->setStringIfChanged('TableStats', $this->renderStats($views));
        $this->UpdateVisualizationValue($this->tilePayload($rows, $sum, $now, $notes, $views));
        $this->WriteAttributeInteger('LastCheckTs', $now);
        $this->UpdateFormField('CheckStatus', 'caption', $line);
        return $line;
    }

    /** Trockenlauf: zeigt, was gefunden, ausgeschlossen und nur vorgeschlagen wird. */
    public function Preview(): string
    {
        $found = $this->found();
        if ($found === null) {
            return 'ℹ️ Noch nicht gesucht — zuerst „Jetzt neu suchen“.';
        }
        $out = [$this->discoveryLine($found), ''];

        $out[] = 'GERÄTE (' . count($found['devices']) . '):';
        $n = 0;
        foreach ($found['devices'] as $id => $d) {
            if (++$n > self::MAX_PREVIEW_ROWS) {
                $out[] = '  … und ' . (count($found['devices']) - self::MAX_PREVIEW_ROWS) . ' weitere';
                break;
            }
            $parts = [];
            foreach ($d['signals'] as $kind => $list) {
                $labels = [];
                foreach ($list as $s) {
                    $labels[] = '#' . $s['vid'] . ' (' . $s['basis'] . ($s['unverified'] ? ', laut Dokumentation, ungetestet' : '') . ')';
                }
                $parts[] = $this->kindLabel($kind) . ' ' . implode(', ', $labels);
            }
            $out[] = '  • ' . $d['name'] . ' [' . ($d['module'] !== '' ? $d['module'] : 'Variable') . ', Schlüssel ' . $id . ']: ' . implode('; ', $parts);
        }

        $out[] = '';
        $out[] = 'AUSGESCHLOSSEN (' . count($found['excluded']) . '):';
        $byReason = [];
        foreach ($found['excluded'] as $e) {
            $byReason[$e['reason']][] = $e['name'];
        }
        foreach ($byReason as $reason => $names) {
            $out[] = '  • ' . count($names) . '× ' . $reason . ' — z. B. ' . implode(', ', array_slice(array_unique($names), 0, 3));
        }
        if (!$found['excluded']) {
            $out[] = '  (nichts)';
        }

        $out[] = '';
        $out[] = 'NUR NACH NAMEN VORGESCHLAGEN (' . count($found['suggestions']) . ')'
            . ($this->ReadPropertyBoolean('NameSearch') ? '' : ' — nicht aufgenommen, solange „Auch nach Namen suchen“ aus ist:');
        foreach (array_slice($found['suggestions'], 0, self::MAX_PREVIEW_ROWS) as $s) {
            $out[] = '  • #' . $s['vid'] . ' ' . $s['name'] . ' (' . $this->kindLabel($s['kind']) . ')';
        }
        if (!$found['suggestions']) {
            $out[] = '  (nichts)';
        }
        return implode("\n", $out);
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    public function AckNews(): void
    {
        $this->WriteAttributeString('SeenNews', $this->installedVersion());
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function AckForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
    }

    // =====================================================================
    //  Verlauf, Einkauf, Tauschrunde, Statistik
    // =====================================================================

    /** Schreibt Verlauf und Meldeverhalten mit; beides lebt im Modul, ein Symcon-Archiv ist nicht nötig. */
    private function updateHistory(array $rows, int $now): void
    {
        $hist = $this->loadJson('History');
        $obs  = $this->loadJson('LifeObs');
        $h0   = $hist;
        $o0   = $obs;
        foreach ($rows as $row) {
            $key = $row['id'];
            $series = BWACHPrognose::historyAdd($hist[$key] ?? [], $now, $row['r']['percent'], $this->outdoorTemp());
            if ($series) {
                $hist[$key] = $series;
            }
            $obs[$key]  = BWACHPrognose::observeLife($obs[$key] ?? [], (int)$row['life']);
        }
        if ($hist !== $h0) {
            $this->saveJson('History', $hist);
        }
        if ($obs !== $o0) {
            $this->saveJson('LifeObs', $obs);
        }
    }

    /** Außentemperatur für den Verlauf; ohne Auswahl null. */
    protected function outdoorTemp(): ?float
    {
        $vid = $this->ReadPropertyInteger('OutdoorTempVar');
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            return null;
        }
        $v = IPS_GetVariable($vid);
        $age = $this->now() - (int)($v['VariableUpdated'] ?? 0);
        $val = GetValue($vid);
        // Ein alter Wert (Sensor ausgefallen) wäre eine falsche Außentemperatur im Verlauf
        return (is_int($val) || is_float($val)) && $age <= 12 * 3600 ? (float)$val : null;
    }

    /** Funkqualität oder Signalstärke eines Geräts, falls die Instanz eine solche Variable hat. */
    private function signalInfo(int $inst): ?array
    {
        if (!IPS_InstanceExists($inst)) {
            return null;
        }
        foreach (IPS_GetChildrenIDs($inst) as $c) {
            $o = IPS_GetObject($c);
            if ((int)($o['ObjectType'] ?? 0) === 2 && BWACHLogik::isSignalIdent((string)$o['ObjectIdent'])) {
                $val = GetValue($c);
                if (is_int($val) || is_float($val)) {
                    return BWACHLogik::classifySignal((string)$o['ObjectIdent'], (float)$val);
                }
            }
        }
        return null;
    }

    /** Einkaufsliste, Tauschrunde und Lebensdauerstatistik aus den aktuellen Zeilen. */
    private function views(array $rows, int $now): array
    {
        $horizon = $this->ReadPropertyInteger('ForecastHorizonDays');
        $items   = [];
        $cellOf  = [];
        $modOf   = [];
        foreach ($rows as $row) {
            $f = $row['forecast'];
            $items[] = [
                'name'   => $row['name'],
                'place'  => $row['place'],
                'cell'   => $row['cell'],
                'cells'  => $row['cells'],
                'status' => $row['r']['status'],
                'days'   => ($f['days'] !== null && $f['confidence'] !== 'keine') ? $f['days'] : null,
                'text'   => $row['r']['status'] === BWACHLogik::ST_OK ? BWACHPrognose::forecastText($f) : implode('; ', array_slice($row['r']['reasons'], 0, 1)),
            ];
            $cellOf[$row['id']] = BWACHZelle::isKnown($row['cell']) ? BWACHZelle::shopLabel($row['cell']) : '';
            $modOf[$row['id']]  = $row['module'];
        }
        $life = BWACHPrognose::lifetimes($this->loadJson('Diary'));
        $peers = [];
        foreach ($rows as $row) {
            if (isset($row['peer'])) {
                $peers[] = ['name' => $row['name'], 'text' => BWACHLogik::num($row['peer']['factor']) . '× schneller als ' . $row['peer']['n'] . ' vergleichbare Geräte (' . BWACHLogik::num($row['peer']['rate']) . ' statt ' . BWACHLogik::num($row['peer']['median']) . ' Prozentpunkte/Tag)'];
            }
        }
        $cold = $this->ReadPropertyInteger('OutdoorTempVar') > 0
            ? BWACHPrognose::coldEffect($this->loadJson('History'), (float)$this->ReadPropertyInteger('ColdBelow'), $this->ReadPropertyInteger('ReplaceJumpPercent'))['text']
            : 'Kälteeinfluss: keine Außentemperatur-Variable gewählt (unter „Prognose und Einkauf“ eine wählen, dann wertet der Wächter aus, ob sich Batterien bei Kälte schneller entladen)';
        return [
            'horizon'  => $horizon,
            'shopping' => BWACHPrognose::shopping($items, $horizon),
            'round'    => BWACHPrognose::tauschrunde($items, $horizon, $now),
            'life'     => $life,
            'byCell'   => BWACHPrognose::lifetimeByGroup($life, $cellOf),
            'byModule' => BWACHPrognose::lifetimeByGroup($life, $modOf),
            'items'    => $items,
            'peers'    => $peers,
            'cold'     => $cold,
        ];
    }

    private function renderShopping(array $v): string
    {
        $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $css = '<style>.bw{border-collapse:collapse;width:100%;font-size:14px}.bw th,.bw td{padding:4px 10px;text-align:left;border-bottom:1px solid rgba(128,128,128,.35)}</style>';
        $out = $css . '<div style="padding:4px 2px"><b>🛒 Einkaufsliste für die nächsten ' . (int)$v['horizon'] . ' Tage</b></div>';
        $sh = $v['shopping'];
        if (!$sh['need']) {
            return $out . '<div style="padding:8px">✅ Nichts zu besorgen: kein Gerät ist schwach oder laut Prognose bald leer.</div>';
        }
        $out .= '<div style="padding:4px 2px">' . ($sh['lines'] ? $e(implode(', ', $sh['lines'])) : 'Keine Zelltypen bekannt.') . '</div>';
        if ($sh['missing']) {
            $out .= '<div style="padding:4px 2px;opacity:.8">ℹ️ Zelltyp fehlt bei: ' . $e(implode(', ', $sh['missing'])) . ' (in der Instanz „' . $e(IPS_GetName($this->InstanceID)) . '“ unter „Geräte-Einstellungen“ in der Spalte „Zelltyp“ wählen, dann zählt die Liste mit).</div>';
        }
        $r = $v['round'];
        $out .= '<div style="padding:10px 2px 4px"><b>🔧 Tauschrunde</b>' . ($r['until'] !== null ? ' — am besten bis ' . date('d.m.Y', $r['until']) : '') . ' (' . $r['count'] . ($r['count'] === 1 ? ' Gerät' : ' Geräte') . ')</div>';
        $body = '';
        foreach ($r['places'] as $place => $devs) {
            foreach ($devs as $d) {
                $cell = BWACHZelle::shopLabel((string)$d['cell']);
                $body .= '<tr><td>' . $e($place) . '</td><td>' . $e($d['name']) . '</td><td>' . $e($cell !== null ? max(1, (int)$d['cells']) . '× ' . $cell : '—') . '</td><td>' . $e($d['text']) . '</td></tr>';
            }
        }
        return $out . '<table class="bw"><tr><th>Ort</th><th>Gerät</th><th>Batterie</th><th>Stand</th></tr>' . $body . '</table>';
    }

    private function renderStats(array $v): string
    {
        $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $css = '<style>.bw{border-collapse:collapse;width:100%;font-size:14px}.bw th,.bw td{padding:4px 10px;text-align:left;border-bottom:1px solid rgba(128,128,128,.35)}</style>';
        $out = $css;
        $out .= '<div style="padding:4px 2px"><b>📈 Restlaufzeit je Gerät</b></div>';
        $body = '';
        $items = $v['items'];
        usort($items, function ($a, $b) { return [$a['days'] === null ? 1 : 0, $a['days'] ?? 0, $a['name']] <=> [$b['days'] === null ? 1 : 0, $b['days'] ?? 0, $b['name']]; });
        foreach ($items as $i) {
            $body .= '<tr><td>' . $e($i['name']) . '</td><td>' . $e($i['place']) . '</td><td>' . $e($i['text']) . '</td></tr>';
        }
        $out .= '<table class="bw"><tr><th>Gerät</th><th>Ort</th><th>Restlaufzeit</th></tr>' . $body . '</table>';

        $out .= '<div style="padding:10px 2px 4px"><b>👥 Vergleich mit Gleichartigen</b></div>';
        if ($v['peers']) {
            $body = '';
            foreach ($v['peers'] as $pe) {
                $body .= '<tr><td>' . $e($pe['name']) . '</td><td>' . $e($pe['text']) . '</td></tr>';
            }
            $out .= '<table class="bw"><tr><th>Auffälliges Gerät</th><th>Entladung</th></tr>' . $body . '</table>';
        } else {
            $out .= '<div style="padding:4px 2px;opacity:.85">✅ Kein Gerät entlädt auffällig schneller als seine Gruppe (nötig: mindestens 4 Geräte gleichen Systems und Zelltyps mit bekannter Entladerate).</div>';
        }
        $out .= '<div style="padding:10px 2px 4px"><b>🌡 Kälteeinfluss</b></div><div style="padding:4px 2px;opacity:.9">' . $e($v['cold']) . '</div>';
        $out .= '<div style="padding:10px 2px 4px"><b>⏱ Lebensdauer (Zeit zwischen zwei Batteriewechseln)</b></div>';
        if (!$v['life']) {
            return $out . '<div style="padding:8px">ℹ️ Noch zu wenig Daten: je Gerät braucht es mindestens zwei erfasste Batteriewechsel.</div>';
        }
        $body = '';
        foreach ($v['life'] as $l) {
            $body .= '<tr><td>' . $e($l['name']) . '</td><td>' . $e(implode(', ', array_map(function ($d) { return BWACHLogik::num($d) . ' Tage'; }, $l['days']))) . '</td></tr>';
        }
        $out .= '<table class="bw"><tr><th>Gerät</th><th>Lebensdauern</th></tr>' . $body . '</table>';
        foreach (['byCell' => 'je Zelltyp', 'byModule' => 'je System'] as $k => $title) {
            if (!$v[$k]) {
                continue;
            }
            $body = '';
            foreach ($v[$k] as $g => $s) {
                $body .= '<tr><td>' . $e($g) . '</td><td>' . BWACHLogik::num($s['median']) . ' Tage</td><td>' . $s['n'] . ' Intervalle, ' . $s['devices'] . ($s['devices'] === 1 ? ' Gerät' : ' Geräte') . '</td></tr>';
            }
            $out .= '<div style="padding:10px 2px 4px"><b>Mittlere Lebensdauer ' . $title . '</b></div><table class="bw"><tr><th>' . ($k === 'byCell' ? 'Zelle' : 'System') . '</th><th>Median</th><th>Grundlage</th></tr>' . $body . '</table>';
        }
        return $out;
    }

    // =====================================================================
    //  Kachel
    // =====================================================================

    public function GetVisualizationTile()
    {
        $found = $this->found();
        $rows  = $found !== null ? $this->evaluateAll($found) : [];
        $now   = $this->now();
        $payload = $this->tilePayload($rows, BWACHLogik::summarize(array_column($rows, 'r')), $now, $this->snoozeNotes($now));
        $html = file_get_contents(__DIR__ . '/module.html');
        // handleMessage() entsteht erst im HTML, der erste Aufruf muss deshalb dahinter.
        // JSON_HEX_TAG: ein Gerätename wie „</script>“ darf die Kachel nicht aufbrechen.
        return $html . '<script>handleMessage(' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) . ');</script>';
    }

    /** Rückkanal aus der Kachel: 'ack' (Quittieren) und 'refresh'. */
    public function RequestAction($Ident, $Value)
    {
        $now  = $this->now();
        $meta = $this->loadJson('Meta');
        if ($Ident === 'ack') {
            $d = json_decode((string)$Value, true);
            if (!$this->ReadPropertyBoolean('TileAllowAck')) {
                $msg = '⛔ Das Quittieren aus der Kachel ist in den Einstellungen ausgeschaltet.';
            } elseif (is_array($d) && isset($d['key'], $d['action'])) {
                $msg = $this->Acknowledge((string)$d['key'], (string)$d['action']);
            } else {
                $msg = '⛔ Ungültige Anfrage aus der Kachel.';
            }
            $meta['ack'] = ['m' => $msg, 't' => $now];
            $this->saveJson('Meta', $meta);
        } elseif ($Ident === 'refresh') {
            $this->Check();
        }
        $found = $this->found();
        if ($found !== null) {
            $rows = $this->evaluateAll($found);
            $this->UpdateVisualizationValue($this->tilePayload($rows, BWACHLogik::summarize(array_column($rows, 'r')), $now, $this->snoozeNotes($now)));
        }
    }

    /** Daten für die Kachel. Alle Texte kommen fertig formuliert, die Kachel setzt nur noch zusammen. */
    /** Zelltyp für die Kachel; kommt er vom Gerät (Matter), steht das dabei. */
    private function cellText(array $row): string
    {
        if (!BWACHZelle::isKnown($row['cell'])) {
            return $row['cellHint'] !== '' ? 'Gerät nennt als Ersatz: ' . $row['cellHint'] . ' (Zelltyp nicht eindeutig, bitte unter „Geräte-Einstellungen“ wählen)' : '';
        }
        $text = ($row['cells'] > 1 ? $row['cells'] . '× ' : '') . BWACHZelle::label($row['cell']);
        if (!$row['cellAuto']) {
            return $text;
        }
        // AA/AAA nennen nur die Bauform, ob Alkali oder Akku, weiß das Gerät nicht
        $form = in_array($row['cell'], ['aa_alkali', 'aaa_alkali'], true);
        return $text . ' — laut Gerät („' . $row['cellHint'] . '“)' . ($form ? ', nur die Bauform: ob Alkali oder Akku, ist unbekannt' : '');
    }

    private function tilePayload(array $rows, array $sum, int $now, array $notes, ?array $views = null): string
    {
        $devices = [];
        foreach ($rows as $row) {
            $r = $row['r'];
            $devices[] = [
                'id'           => $row['id'],
                'name'         => $row['name'],
                'place'        => $row['place'],
                'module'       => $row['module'],
                'status'       => $r['status'],
                'funk'         => $r['funk'],
                'percent'      => $r['percent'] === null ? null : round($r['percent'], 1),
                'percentText'  => $r['percent'] === null ? '' : BWACHLogik::num($r['percent']) . ' %',
                'voltageText'  => $r['voltage'] === null ? '' : BWACHLogik::num($r['voltage']) . ' V',
                'valueAgeText' => $r['valueAge'] === null ? '—' : 'vor ' . BWACHLogik::daysDat($r['valueAge']),
                'lifeText'     => $r['lifeAge'] === null ? '—' : 'vor ' . BWACHLogik::daysDat($r['lifeAge']),
                'headline'     => $this->headline($r),
                'critical'     => (bool)$r['critical'],
                'quality'      => $r['quality'],
                'urgency'      => $r['urgency'],
                'reasons'      => $r['reasons'],
                'note'         => $notes[$row['id']] ?? '',
                'soon'         => !empty($r['soon']),
                'forecastText' => $r['percent'] === null ? '' : BWACHPrognose::forecastText($row['forecast']),
                'cellText'     => $this->cellText($row),
                'derived'      => !empty($r['derived']),
                'signalText'   => $row['signal']['text'] ?? '',
                'pollText'     => $row['poll'],
            ];
        }
        $diary = [];
        foreach (array_slice(array_reverse($this->loadJson('Diary')), 0, 25) as $d) {
            $diary[] = ['when' => date('d.m.Y', (int)$d['t']), 'name' => (string)$d['name'], 'type' => $d['type'] === 'erkannt' ? 'erkannt' : 'eingetragen', 'note' => (string)$d['note']];
        }
        $views   = $views ?? $this->views($rows, $now);
        $meta    = $this->loadJson('Meta');
        $message = (isset($meta['ack']) && $now - (int)$meta['ack']['t'] <= 30) ? (string)$meta['ack']['m'] : '';
        return json_encode([
            'summary'  => $sum,
            'devices'  => $devices,
            'diary'    => $diary,
            'allowAck' => $this->ReadPropertyBoolean('TileAllowAck'),
            'shopping' => $this->tileShopping($views),
            'asOf'     => date('H:i', $now) . ' Uhr',
            'message'  => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    // =====================================================================
    //  Quittieren, Tagebuch, Meldungen
    // =====================================================================

    /**
     * Quittiert ein Gerät: 'getauscht' (Batteriewechsel, kommt ins Tagebuch), 'zurueckgestellt'
     * (Erinnerung später) oder 'ausser_betrieb' (Gerät dauerhaft ausnehmen).
     */
    public function Acknowledge(string $key, string $action): string
    {
        if ($key === '') {
            return 'ℹ️ Bitte zuerst ein Gerät wählen.';
        }
        if (!in_array($action, [BWACHMeldung::ACK_REPLACED, BWACHMeldung::ACK_SNOOZE, BWACHMeldung::ACK_RETIRED], true)) {
            return '⛔ Unbekannte Aktion „' . $action . '“.';
        }
        $found = $this->found();
        $name  = null;
        $row   = null;
        if ($found !== null && isset($found['devices'][$key])) {
            foreach ($this->evaluateAll($found) as $r) {
                if ($r['id'] === $key) {
                    $row = $r;
                    break;
                }
            }
            $name = $row !== null ? $row['name'] : (string)$found['devices'][$key]['name'];
        }
        if ($name === null) {
            return '⛔ Gerät „' . $key . '“ nicht gefunden (vielleicht schon außer Betrieb oder ausgenommen).';
        }
        $now   = $this->now();
        $state = $this->loadJson('NotifyState');

        if ($action === BWACHMeldung::ACK_REPLACED) {
            $diary = BWACHMeldung::diaryAdd($this->loadJson('Diary'), ['t' => $now, 'key' => $key, 'name' => $name, 'type' => 'manuell', 'note' => 'von Hand eingetragen']);
            $this->saveJson('Diary', $diary);
            $msg = '✅ Batteriewechsel bei „' . $name . '“ eingetragen. Der Wächter wartet ' . BWACHMeldung::REPLACE_GRACE_DAYS . ' Tage, bis das Gerät den neuen Stand meldet.';
        } elseif ($action === BWACHMeldung::ACK_SNOOZE) {
            $days = $this->ReadPropertyInteger('SnoozeDays');
            $msg  = '✅ „' . $name . '“ zurückgestellt bis ' . date('d.m.Y', $now + max(1, $days) * 86400) . '. Danach meldet sich der Wächter wieder, falls der Befund bleibt.';
        } else {
            $retired = $this->loadJson('Retired');
            $retired[$key] = $now;
            $this->saveJson('Retired', $retired);
            $msg = '✅ „' . $name . '“ ist außer Betrieb und wird nicht mehr überwacht. Wieder aufnehmen: „Außer Betrieb“-Liste unter „Quittieren“.';
        }
        $this->saveJson('NotifyState', BWACHMeldung::acknowledge($state, $key, $action, $now, $this->ReadPropertyInteger('SnoozeDays')));
        $this->Check();
        $this->UpdateFormField('AckStatus', 'caption', $msg);
        $this->UpdateFormField('DiaryLine', 'caption', $this->diaryLine());
        $this->UpdateFormField('RetiredLine', 'caption', $this->retiredLine($found));
        return $msg;
    }

    /**
     * Übernimmt die Einstellungen einer alten BY_BatterieMonitor-Instanz in die OFFENE Maske. Es wird nichts
     * gespeichert (Store-Review: keine Selbstpersistenz in Buttons): der Nutzer prüft und klickt „Übernehmen“.
     */
    public function ImportOld(): string
    {
        $old = $this->oldInstances();
        if (!$old) {
            return 'ℹ️ Keine BY_BatterieMonitor-Instanz gefunden — es gibt nichts zu übernehmen.';
        }
        $id  = array_key_first($old);
        $cfg = json_decode((string)IPS_GetConfiguration($id), true);
        if (!is_array($cfg)) {
            return '⛔ Die Einstellungen der alten Instanz #' . $id . ' lassen sich nicht lesen.';
        }
        $res = BWACHLogik::importOldConfig($cfg, function (int $i) { return IPS_InstanceExists($i); });
        foreach ($res['fields'] as $field => $value) {
            $this->UpdateFormField($field, $field === 'PushTargets' ? 'values' : 'value', $value);
        }
        $msg = '✅ Aus „' . $old[$id] . '“ (#' . $id . ') in die Maske übernommen: ' . implode(', ', $res['taken']) . '.'
            . "\nNicht übernommen: " . implode('; ', $res['skipped']) . '.'
            . "\nDie Werte stehen jetzt im Formular, aber noch NICHT gespeichert: bitte prüfen und „Übernehmen“ klicken."
            . (count($old) > 1 ? "\nHinweis: Es gibt " . count($old) . ' alte Instanzen, übernommen wurde die erste.' : '')
            . "\nDanach kann die alte Instanz entfernt werden. Der Wächter findet die Batterien ohnehin von selbst, die alte Geräteliste braucht es nicht.";
        $this->UpdateFormField('ImportStatus', 'caption', 'ℹ️ Letzte Übernahme aus „' . $old[$id] . '“ — noch „Übernehmen“ klicken, wenn die Werte stimmen.');
        return $msg;
    }

    /** @return array<int,string> Instanz-ID => Name der alten BY_BatterieMonitor-Instanzen */
    private function oldInstances(): array
    {
        $out = [];
        foreach (IPS_GetInstanceList() as $i) {
            if ((string)(IPS_GetInstance($i)['ModuleInfo']['ModuleName'] ?? '') === 'BatterieMonitor') {
                $out[(int)$i] = IPS_GetName($i);
            }
        }
        return $out;
    }

    /** Nimmt ein Gerät, das außer Betrieb gesetzt war, wieder auf. */
    public function Unretire(string $key): string
    {
        $retired = $this->loadJson('Retired');
        if (!isset($retired[$key])) {
            return 'ℹ️ Gerät „' . $key . '“ stand nicht auf der Liste „Außer Betrieb“.';
        }
        unset($retired[$key]);
        $this->saveJson('Retired', $retired);
        $this->Check();
        return '✅ Gerät wieder aufgenommen.';
    }

    /** Nimmt alle außer Betrieb gesetzten Geräte wieder auf. */
    public function UnretireAll(): string
    {
        $n = count($this->loadJson('Retired'));
        $this->saveJson('Retired', []);
        $this->Check();
        $msg = $n === 0 ? 'ℹ️ Es war kein Gerät außer Betrieb.' : '✅ ' . $n . ($n === 1 ? ' Gerät wieder aufgenommen.' : ' Geräte wieder aufgenommen.');
        $this->UpdateFormField('AckStatus', 'caption', $msg);
        $this->UpdateFormField('RetiredLine', 'caption', $this->retiredLine($this->found()));
        return $msg;
    }

    /** Schickt eine Testmeldung über die eingestellten Wege und sagt, was ankam. */
    public function SendTest(): string
    {
        $m = ['title' => '🧪 Batteriewächter Test', 'text' => 'Wenn diese Meldung ankommt, funktioniert der Zustellweg. Keine echte Batteriemeldung.', 'sound' => 'bell'];
        $parts = [];
        if ($this->ReadPropertyBoolean('NotifyPush')) {
            $r = $this->sendPush($m['title'], $m['text'], $m['sound']);
            $parts[] = $r['targets'] === 0
                ? 'Push: ⚠️ keine Push-Ziele gefunden (weder Kachel-Visualisierung noch WebFront)'
                : 'Push: ' . ($r['ok'] === $r['targets'] ? '✅ ' : '⚠️ ') . $r['ok'] . ' von ' . $r['targets'] . ' Zielen';
        }
        if ($this->ReadPropertyBoolean('NotifyMail')) {
            $parts[] = 'E-Mail: ' . ($this->sendMail($m['title'], $m['text']) ? '✅ gesendet' : '⚠️ nicht gesendet — ' . $this->lastMailError);
        }
        $out = $parts ? implode(' · ', $parts) : 'ℹ️ Kein Zustellweg ausgewählt (Push oder E-Mail unter „Meldungen“ ankreuzen).';
        $this->UpdateFormField('NotifyStatus', 'caption', $out);
        return $out;
    }

    private function loadJson(string $ident): array
    {
        $d = json_decode((string)$this->GetValue($ident), true);
        return is_array($d) ? $d : [];
    }

    private function saveJson(string $ident, array $data): void
    {
        $this->setStringIfChanged($ident, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /** Hinweistexte für zurückgestellte Geräte in den Tabellen. */
    private function snoozeNotes(int $now): array
    {
        $out = [];
        foreach ($this->loadJson('NotifyState') as $key => $s) {
            if ((int)$s['snooze'] > $now) {
                $out[(string)$key] = '💤 bis ' . date('d.m.Y', (int)$s['snooze']);
            }
        }
        return $out;
    }

    /** Erkennt Batteriewechsel (Prozentwert springt hoch, Flag wird zurückgesetzt) und schreibt sie ins Tagebuch. */
    private function detectReplacements(array $rows, int $now): void
    {
        $last  = $this->loadJson('LastSeen');
        $diary = $this->loadJson('Diary');
        $state = $this->loadJson('NotifyState');
        $jump  = $this->ReadPropertyInteger('ReplaceJumpPercent');
        $newLast = $last;
        $changed = false;

        foreach ($rows as $row) {
            $key = $row['id'];
            $cur = ['p' => $row['r']['percent'], 'f' => $row['r']['flagLow']];
            $prev = isset($last[$key]) ? ['p' => $last[$key]['p'] ?? null, 'f' => $last[$key]['f'] ?? null] : null;
            $desc = BWACHMeldung::detectReplacement($prev, $cur, $jump);
            if ($desc !== null) {
                $diary = BWACHMeldung::diaryAdd($diary, ['t' => $now, 'key' => $key, 'name' => $row['name'], 'type' => 'erkannt', 'note' => $desc]);
                // Der alte Befund ist erledigt; ein neuer Befund wäre eine neue Meldung
                unset($state[$key]);
                $changed = true;
            }
            if ($prev === null || $prev['p'] !== $cur['p'] || $prev['f'] !== $cur['f']) {
                $newLast[$key] = $cur + ['t' => $now];
            }
        }
        if ($newLast !== $last) {
            $this->saveJson('LastSeen', $newLast);
        }
        if ($changed) {
            $this->saveJson('Diary', $diary);
            $this->saveJson('NotifyState', $state);
        }
    }

    /** Meldungen und Wochenbericht — nur, wenn „Meldungen aktiv“ eingeschaltet ist. */
    private function processNotifications(array $rows, array $sum, int $now): void
    {
        if (!$this->ReadPropertyBoolean('NotificationsActive')) {
            return;
        }
        $byKey   = [];
        $current = [];
        foreach ($rows as $row) {
            $probs = BWACHMeldung::problems($row['r']);
            $byKey[$row['id']] = $row;
            if ($probs) {
                $current[$row['id']] = ['probs' => $probs, 'critical' => (bool)$row['r']['critical']];
            }
        }
        $old = $this->loadJson('NotifyState');
        $res = BWACHMeldung::decide($old, $current, $now, [
            'remDays'     => $this->ReadPropertyInteger('ReminderDays'),
            'critRemDays' => $this->ReadPropertyInteger('CriticalReminderDays'),
            'escHours'    => $this->ReadPropertyInteger('EscalateHours'),
            'clearHours'  => 24,
            'quiet'       => BWACHMeldung::isQuiet($now, $this->ReadPropertyBoolean('QuietEnabled'), $this->ReadPropertyInteger('QuietFromHour'), $this->ReadPropertyInteger('QuietToHour')),
            'ignoreQuiet' => $this->ReadPropertyBoolean('CriticalIgnoresQuiet'),
        ]);
        $state = $res['state'];

        $normal = ['push' => $this->ReadPropertyBoolean('NotifyPush'), 'mail' => $this->ReadPropertyBoolean('NotifyMail')];
        $esc    = ['push' => $this->ReadPropertyBoolean('EscalatePush'), 'mail' => $this->ReadPropertyBoolean('EscalateMail')];
        foreach (['neu' => $normal, 'erinnerung' => $normal, 'eskalation' => $esc] as $kind => $channels) {
            $keys = $res['events'][$kind];
            if (!$keys) {
                continue;
            }
            $items = [];
            foreach ($keys as $k) {
                $r = $byKey[$k];
                $items[] = ['name' => $r['name'], 'place' => $r['place'], 'probs' => $current[$k]['probs'], 'text' => implode('; ', $r['r']['reasons']), 'critical' => (bool)$r['r']['critical']];
            }
            $m = BWACHMeldung::message($kind, $items);
            if ($this->deliver($m['title'], $m['text'], $m['sound'], $channels) === 0) {
                // Nichts zugestellt: nicht als gemeldet verbuchen, beim nächsten Lauf erneut versuchen
                foreach ($keys as $k) {
                    $state[$k]['notified'] = (int)($old[$k]['notified'] ?? 0);
                    $state[$k]['cnt']      = (int)($old[$k]['cnt'] ?? 0);
                    if ($kind === 'eskalation') {
                        $state[$k]['esc'] = (bool)($old[$k]['esc'] ?? false);
                    }
                }
            }
        }
        $this->saveJson('NotifyState', $state);
        $this->sendDigestIfDue($rows, $sum, $now);
    }

    private function sendDigestIfDue(array $rows, array $sum, int $now): void
    {
        $meta = $this->loadJson('Meta');
        $key  = BWACHMeldung::digestDue($now, $this->ReadPropertyBoolean('DigestEnabled'), $this->ReadPropertyInteger('DigestWeekday'), $this->ReadPropertyInteger('DigestHour'), (string)($meta['digest'] ?? ''));
        if ($key === null) {
            return;
        }
        $list = [];
        foreach ($rows as $r) {
            $list[] = ['name' => $r['name'], 'place' => $r['place'], 'text' => implode('; ', $r['r']['reasons']), 'urgency' => $r['r']['urgency']];
        }
        $extra = [];
        $views = $this->views($rows, $now);
        if ($views['shopping']['lines']) {
            $extra[] = '🛒 Einkauf für die nächsten ' . $views['horizon'] . ' Tage: ' . implode(', ', $views['shopping']['lines']) . ($views['shopping']['missing'] ? ' (Zelltyp fehlt bei ' . count($views['shopping']['missing']) . ' Gerät' . (count($views['shopping']['missing']) === 1 ? '' : 'en') . ')' : '');
        }
        $d = BWACHMeldung::digest($sum, $list, $extra);
        if (!$d['anyProblem'] && !$this->ReadPropertyBoolean('DigestWhenOk')) {
            $meta['digest'] = $key;
            $this->saveJson('Meta', $meta);
            return;
        }
        $channels = ['push' => $this->ReadPropertyBoolean('NotifyPush'), 'mail' => $this->ReadPropertyBoolean('NotifyMail')];
        if ($this->deliver($d['title'], $d['text'], $d['sound'], $channels) > 0) {
            $meta['digest'] = $key;
            $this->saveJson('Meta', $meta);
        }
    }

    /** Stellt über die gewählten Wege zu. Rückgabe: Anzahl erfolgreicher Wege (0 = nichts angekommen). */
    private function deliver(string $title, string $text, string $sound, array $channels): int
    {
        $ok = 0;
        if (!empty($channels['push'])) {
            $r = $this->sendPush($title, $text, $sound);
            if ($r['ok'] > 0) {
                $ok++;
            }
        }
        if (!empty($channels['mail']) && $this->sendMail($title, $text)) {
            $ok++;
        }
        if ($ok === 0) {
            IPS_LogMessage('Batteriewächter', 'Meldung „' . $title . '“ konnte über keinen Weg zugestellt werden (Push-Ziele und E-Mail unter „Meldungen“ prüfen).' . (!empty($channels['mail']) && $this->lastMailError !== '' ? ' E-Mail: ' . $this->lastMailError : ''));
        }
        return $ok;
    }

    /** @return array ['targets'=>int,'ok'=>int] */
    protected function sendPush(string $title, string $text, string $sound): array
    {
        $targets = $this->pushTargets();
        $ok = 0;
        foreach ($targets as $id => $type) {
            try {
                if ($type === 'kachel') {
                    $good = function_exists('VISU_PostNotificationEx') && VISU_PostNotificationEx($id, BWACHMeldung::truncateBytes($title, 32), BWACHMeldung::truncateBytes($text, 256), 'Alert', $sound, 0);
                } else {
                    $good = function_exists('WFC_PushNotification') && WFC_PushNotification($id, BWACHMeldung::truncateBytes($title, 32), BWACHMeldung::truncateBytes($text, 256), $sound, 0);
                }
            } catch (\Throwable $e) {
                $good = false;
            }
            if ($good) {
                $ok++;
            } else {
                IPS_LogMessage('Batteriewächter', 'Push an Instanz #' . $id . ' (' . $type . ') fehlgeschlagen.');
            }
        }
        return ['targets' => count($targets), 'ok' => $ok];
    }

    /** Push-Ziele: Kachel-Visualisierung UND klassisches WebFront (SUITE.md Stolperstein 22); Auswahl in den Einstellungen oder alle gefundenen. */
    private function pushTargets(): array
    {
        $all = [];
        foreach ((array)IPS_GetInstanceListByModuleID(self::KACHEL_GUID) as $id) {
            $all[(int)$id] = 'kachel';
        }
        foreach ((array)IPS_GetInstanceListByModuleID(self::WEBFRONT_GUID) as $id) {
            $all[(int)$id] = 'webfront';
        }
        $rows = json_decode((string)$this->ReadPropertyString('PushTargets'), true);
        $chosen = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $id = (int)($r['Instance'] ?? 0);
                if ($id > 0) {
                    $chosen[$id] = true;
                }
            }
        }
        return $chosen ? array_intersect_key($all, $chosen) : $all;
    }

    protected function sendMail(string $title, string $text): bool
    {
        $this->lastMailError = '';
        $inst = $this->ReadPropertyInteger('MailInstance');
        if ($inst <= 0 || !IPS_InstanceExists($inst)) {
            $this->lastMailError = 'keine SMTP-Instanz ausgewählt (oder die Instanz gibt es nicht mehr)';
            return false;
        }
        $to = trim($this->ReadPropertyString('MailTo'));
        $warnings  = [];
        $exception = '';
        // Das SMTP-Modul meldet den Grund (z. B. „Login denied“) als PHP-Warnung und gibt nur false zurück.
        set_error_handler(function ($no, $str) use (&$warnings) {
            $warnings[] = (string)$str;
            return true;
        });
        $sent = false;
        try {
            if ($to === '') {
                // Ohne Empfänger gilt der in der SMTP-Instanz eingestellte
                if (!function_exists('SMTP_SendMail')) {
                    $this->lastMailError = 'die Funktion SMTP_SendMail gibt es nicht (SMTP-Modul fehlt?)';
                } else {
                    $sent = (bool)SMTP_SendMail($inst, $title, $text);
                }
            } elseif (!function_exists('SMTP_SendMailEx')) {
                $this->lastMailError = 'die Funktion SMTP_SendMailEx gibt es nicht (SMTP-Modul fehlt?)';
            } else {
                $body = '<html><body>' . nl2br(htmlspecialchars($text)) . '</body></html>';
                foreach (array_filter(array_map('trim', preg_split('/[,;]+/', $to))) as $addr) {
                    if (SMTP_SendMailEx($inst, $addr, $title, $body)) {
                        $sent = true;
                    } else {
                        IPS_LogMessage('Batteriewächter', 'E-Mail an „' . $addr . '“ fehlgeschlagen.');
                    }
                }
            }
        } catch (\Throwable $e) {
            $exception = $e->getMessage();
        } finally {
            restore_error_handler();
        }
        if (!$sent && $this->lastMailError === '') {
            $this->lastMailError = BWACHMeldung::explainMailError($warnings, $exception);
        }
        if (!$sent) {
            IPS_LogMessage('Batteriewächter', 'E-Mail-Versand fehlgeschlagen: ' . $this->lastMailError);
        }
        return $sent;
    }

    private function renderDiary(): string
    {
        $diary = array_reverse($this->loadJson('Diary'));
        if (!$diary) {
            return '<div style="padding:8px">ℹ️ Noch keine Batteriewechsel erfasst. Der Wächter erkennt sie selbst oder Sie tragen sie unter „Quittieren“ ein.</div>';
        }
        $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $body = '';
        foreach (array_slice($diary, 0, self::MAX_DIARY_ROWS) as $d) {
            $body .= '<tr><td>' . $e(date('d.m.Y H:i', (int)$d['t'])) . '</td><td>' . $e($d['name']) . '</td><td>'
                . ($d['type'] === 'erkannt' ? '🔎 erkannt' : '✍️ eingetragen') . '</td><td>' . $e($d['note']) . '</td></tr>';
        }
        return '<style>.bw{border-collapse:collapse;width:100%;font-size:14px}.bw th,.bw td{padding:4px 10px;text-align:left;border-bottom:1px solid rgba(128,128,128,.35)}</style>'
            . '<table class="bw"><tr><th>Wann</th><th>Gerät</th><th>Art</th><th>Hinweis</th></tr>' . $body . '</table>';
    }

    // =====================================================================
    //  Rohdaten aus Symcon
    // =====================================================================

    protected function now(): int
    {
        return time();
    }

    /** Alle Variablen mit dem, was die Erkennung braucht. */
    protected function collectVariables(): array
    {
        $out       = [];
        $instCache = [];
        foreach (IPS_GetVariableList() as $vid) {
            $obj = IPS_GetObject($vid);
            $var = IPS_GetVariable($vid);
            if (!is_array($obj) || !is_array($var)) {
                continue;
            }
            $parent = (int)($obj['ParentID'] ?? 0);
            if (!array_key_exists($parent, $instCache)) {
                $instCache[$parent] = $this->instanceInfo($parent);
            }
            $inst = $instCache[$parent];
            $out[] = [
                'vid'              => (int)$vid,
                'ident'            => (string)($obj['ObjectIdent'] ?? ''),
                'name'             => (string)($obj['ObjectName'] ?? ''),
                'type'             => (int)($var['VariableType'] ?? -1),
                'profile'          => $this->resolveProfile($var),
                'parentId'         => $parent,
                'parentIsInstance' => $inst !== null,
                'moduleName'       => $inst['module'] ?? '',
                'instanceName'     => $inst['name'] ?? '',
                'node'             => $inst['node'] ?? '',
            ];
        }
        return $out;
    }

    private function instanceInfo(int $parent): ?array
    {
        if ($parent <= 0 || !IPS_InstanceExists($parent)) {
            return null;
        }
        $i    = IPS_GetInstance($parent);
        $info = ['module' => (string)($i['ModuleInfo']['ModuleName'] ?? ''), 'name' => IPS_GetName($parent)];
        $m    = $this->matterNode($parent, $info['module']);
        if ($m !== null) {
            $info['node'] = $m['node'];
        }
        return $info;
    }

    /**
     * Matter: Knoten und Endpunkt einer Matter-Gerät-Instanz. Ein Matter-Gerät besteht aus mehreren Instanzen
     * (je Endpunkt eine): der Kontakt liegt auf Endpunkt 1, die Batterie (Stromversorgung) auf Endpunkt 0.
     *
     * @return array|null ['node'=>string,'endpoint'=>int]
     */
    private function matterNode(int $inst, ?string $module = null): ?array
    {
        $module = $module ?? (string)(IPS_GetInstance($inst)['ModuleInfo']['ModuleName'] ?? '');
        if ($module !== 'Matter Device') {
            return null;
        }
        $cfg = json_decode((string)IPS_GetConfiguration($inst), true);
        if (!is_array($cfg) || !isset($cfg['NodeId'])) {
            return null;
        }
        return ['node' => (string)$cfg['NodeId'], 'endpoint' => (int)($cfg['EndpointId'] ?? 0)];
    }

    /** Die übrigen Instanzen desselben Matter-Knotens (ohne Endpunkt 0), nach Endpunkt sortiert. */
    private function matterSiblings(int $inst): array
    {
        $m = $this->matterNode($inst);
        if ($m === null) {
            return [];
        }
        $guid = (string)(IPS_GetInstance($inst)['ModuleInfo']['ModuleID'] ?? '');
        $out  = [];
        foreach ($guid !== '' ? IPS_GetInstanceListByModuleID($guid) : [] as $other) {
            $o = (int)$other === $inst ? null : $this->matterNode((int)$other);
            if ($o !== null && $o['node'] === $m['node'] && $o['endpoint'] > 0) {
                $out[(int)$other] = $o['endpoint'];
            }
        }
        asort($out);
        return array_keys($out);
    }

    /** Text der Variable „Ersatz Beschreibung“ (Matter, z. B. „AAA“) einer Instanz, sonst leer. */
    private function replacementText(int $inst): string
    {
        foreach (IPS_GetChildrenIDs($inst) as $c) {
            if ((string)(IPS_GetObject($c)['ObjectIdent'] ?? '') === 'PowerSource_BatReplacementDescription' && IPS_VariableExists($c)) {
                return trim((string)GetValue($c));
            }
        }
        return '';
    }

    /**
     * Profil einer Variablen. Neue Darstellungen (Presentation) tragen die
     * klassischen Profilfelder LEER — dann steht das Profil, falls überhaupt,
     * im Schlüssel PROFILE der Darstellung (SUITE.md Stolperstein 21).
     */
    private function resolveProfile(array $var): string
    {
        $p = (string)($var['VariableCustomProfile'] ?? '');
        if ($p === '') {
            $p = (string)($var['VariableProfile'] ?? '');
        }
        if ($p !== '') {
            return $p;
        }
        foreach (['VariableCustomPresentation', 'VariablePresentation'] as $key) {
            $pres = $var[$key] ?? null;
            if (is_array($pres) && !empty($pres['PROFILE'])) {
                return (string)$pres['PROFILE'];
            }
        }
        return '';
    }

    private function found(): ?array
    {
        $raw = (string)$this->ReadAttributeString('Found');
        if ($raw === '') {
            return null;
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : null;
    }

    private function excludedModules(): array
    {
        $list = array_map('trim', explode(',', (string)$this->ReadPropertyString('ExcludedModules')));
        return array_values(array_filter($list, function ($s) { return $s !== ''; }));
    }

    private function manualVariables(): array
    {
        $rows = json_decode((string)$this->ReadPropertyString('ManualVariables'), true);
        $out  = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $vid = (int)($r['Variable'] ?? 0);
                $kind = (string)($r['Kind'] ?? '');
                if ($vid > 0 && in_array($kind, [BWACHLogik::KIND_PERCENT, BWACHLogik::KIND_FLAG, BWACHLogik::KIND_FLAG_REV, BWACHLogik::KIND_VOLTAGE], true)) {
                    $out[] = ['vid' => $vid, 'kind' => $kind];
                }
            }
        }
        return $out;
    }

    /** Geräteeinstellungen nach Instanz-ID. */
    private function deviceSettings(): array
    {
        $rows = json_decode((string)$this->ReadPropertyString('DeviceSettings'), true);
        $out  = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $id = (int)($r['Instance'] ?? 0);
                if ($id > 0) {
                    $out[$id] = [
                        'group'     => (string)($r['Group'] ?? BWACHLogik::GROUP_STANDARD),
                        'critical'  => (bool)($r['Critical'] ?? false),
                        'ignoreAge' => (bool)($r['IgnoreAge'] ?? false),
                        'excluded'  => (bool)($r['Excluded'] ?? false),
                        'cell'      => BWACHZelle::isKnown((string)($r['Cell'] ?? '')) ? (string)$r['Cell'] : BWACHZelle::UNKNOWN,
                        'cells'     => max(1, min(12, (int)($r['Cells'] ?? 1))),
                        'poll'      => (bool)($r['Poll'] ?? false),
                    ];
                }
            }
        }
        return $out;
    }

    // =====================================================================
    //  Bewertung
    // =====================================================================

    /** @return array Liste von ['id','name','place','module','r'=>Ergebnis], nach Dringlichkeit sortiert */
    private function evaluateAll(array $found): array
    {
        $now      = $this->now();
        $settings = $this->deviceSettings();
        $retired  = $this->loadJson('Retired');
        $hist     = $this->loadJson('History');
        $lifeObs  = $this->loadJson('LifeObs');
        $pollSt   = $this->loadJson('Poll');
        $learn    = $this->ReadPropertyBoolean('LearnIntervals');
        $rows     = [];

        foreach ($found['devices'] as $key => $d) {
            // Geteilte Einträge (mehrere Sensoren einer Instanz) tragen die Instanz in 'parent'.
            $id = (int)($d['parent'] ?? $key);
            $st = $settings[$id] ?? ['group' => BWACHLogik::GROUP_STANDARD, 'critical' => false, 'ignoreAge' => false, 'excluded' => false, 'cell' => BWACHZelle::UNKNOWN, 'cells' => 1, 'poll' => false];
            if ($st['excluded'] || isset($retired[(string)$key])) {
                continue;
            }
            $isInstance = IPS_InstanceExists($id);
            $sig  = $this->readSignals($d['signals']);
            $life = $this->lifeSign($id, $isInstance, $d['signals']);
            // Matter: Batterie (Endpunkt 0) und Kontakt (Endpunkt 1) sind ein Gerät. Name und Lebenszeichen
            // kommen vom Funktionsendpunkt: die Stromversorgung meldet sich selten, der Kontakt bei jedem Öffnen.
            $sibs = ($isInstance && isset($d['node'])) ? $this->matterSiblings($id) : [];
            foreach ($sibs as $sb) {
                $life = max($life, $this->lifeSign($sb, true, []));
            }
            // Zelltyp: was der Nutzer gewählt hat; sonst, was das Gerät selbst als Ersatz nennt
            $cellHint = $isInstance ? $this->replacementText($id) : '';
            $cellAuto = false;
            if ($st['cell'] === BWACHZelle::UNKNOWN && $cellHint !== '') {
                $auto = BWACHZelle::fromDescription($cellHint);
                if ($auto !== null) {
                    $st['cell'] = $auto;
                    $cellAuto   = true;
                }
            }

            $defaultStill = ($st['group'] === BWACHLogik::GROUP_EVENT ? $this->ReadPropertyInteger('StillDaysEvent') : $this->ReadPropertyInteger('StillDays')) * 86400;
            $stillSec = $learn ? BWACHPrognose::learnedThreshold($lifeObs[(string)$key] ?? [], $defaultStill) : $defaultStill;
            $r = BWACHLogik::evaluate($sig, $life, $now, [
                'cell'           => $st['cell'],
                'cells'          => $st['cells'],
                'stillSec'       => $stillSec < $defaultStill ? $stillSec : 0,
                'orphanDays'     => $this->ReadPropertyInteger('OrphanDays'),
                'critical'       => $st['critical'],
                'group'          => $st['group'],
                'ignoreAge'      => $st['ignoreAge'],
                'lowPct'         => $this->ReadPropertyInteger('LowPercent'),
                'emptyPct'       => $this->ReadPropertyInteger('EmptyPercent'),
                'critLowPct'     => $this->ReadPropertyInteger('CriticalLowPercent'),
                'valueOldDays'   => $this->ReadPropertyInteger('ValueOldDays'),
                'stillDays'      => $this->ReadPropertyInteger('StillDays'),
                'stillDaysEvent' => $this->ReadPropertyInteger('StillDaysEvent'),
            ]);
            $name = (string)$d['name'];
            if ($isInstance) {
                $name = IPS_GetName($sibs ? $sibs[0] : $id);
                if (isset($d['parent'])) {
                    $first = null;
                    foreach ($d['signals'] as $list) {
                        $first = $list[0]['vid'];
                    }
                    $name .= ' › ' . ($first !== null && IPS_VariableExists((int)$first) ? IPS_GetName((int)$first) : '?');
                }
            } elseif (IPS_VariableExists($id)) {
                $name = IPS_GetName($id);
            }
            $place = '';
            if ($isInstance) {
                $parent = (int)IPS_GetParent($id);
                $place  = $parent > 0 ? IPS_GetName($parent) : '';
            }
            // Prognose aus dem im Modul geführten Verlauf; „bald leer“ nur bei ausreichender Sicherheit
            $f = BWACHPrognose::forecast($hist[(string)$key] ?? [], (float)$this->ReadPropertyInteger('EmptyPercent'), $this->ReadPropertyInteger('ReplaceJumpPercent'));
            $r['soon'] = false;
            if ($r['status'] === BWACHLogik::ST_OK && $f['days'] !== null && in_array($f['confidence'], ['hoch', 'mittel'], true) && $f['days'] <= $this->ReadPropertyInteger('SoonDays')) {
                $r['soon'] = true;
                $r['reasons'][] = 'Batterie bald leer: ' . BWACHPrognose::forecastText($f);
                $r['urgency'] = max($r['urgency'], 650 + ($r['critical'] ? 100 : 0));
            }
            // Abfrage schlafender Geräte: Stand der letzten Anfrage und ob das Gerät geantwortet hat
            $pollText = '';
            if (isset($pollSt[(string)$key])) {
                $ps = $pollSt[(string)$key];
                $when = date('d.m.Y', (int)$ps['t']);
                $miss = (int)($ps['miss'] ?? 0);
                if (!empty($ps['ans'])) {
                    $pollText = 'Abfrage am ' . $when . ' gesendet, Gerät hat geantwortet';
                } elseif ($ps['ans'] === false || $miss > 0) {
                    $since = $miss > 0 ? $miss : (int)$ps['t'];
                    $pollText = 'Abfragen seit ' . date('d.m.Y', $since) . ' ohne Antwort (zuletzt am ' . $when . ' gesendet)';
                    $r['quality'][] = 'keine_antwort';
                    $r['reasons'][] = 'Reagiert nicht auf Abfragen (seit ' . date('d.m.Y', $since) . ' ohne Antwort)';
                    $r['urgency'] = max($r['urgency'], 400 + ($r['critical'] ? 100 : 0));
                } else {
                    $pollText = 'Abfrage am ' . $when . ' gesendet, Antwort steht noch aus (schlafende Geräte antworten erst beim Aufwachen)';
                }
            }
            $rows[] = ['id' => (string)$key, 'name' => $name, 'place' => $place, 'module' => (string)$d['module'], 'r' => $r,
                'forecast' => $f, 'cell' => $st['cell'], 'cells' => $st['cells'], 'life' => $life, 'inst' => $id, 'poll' => $pollText,
                'cellHint' => $cellHint, 'cellAuto' => $cellAuto,
                'signal' => $isInstance ? $this->signalInfo($id) : null, 'pollOn' => $st['poll']];
        }
        $this->applyPeers($rows);
        usort($rows, function ($a, $b) {
            return [$b['r']['urgency'], $a['name']] <=> [$a['r']['urgency'], $b['name']];
        });
        return $rows;
    }

    /** Vergleich mit Gleichartigen: gleiches System und gleicher Zelltyp; auffällig ab doppelter Entladerate. */
    private function applyPeers(array &$rows): void
    {
        $rates = [];
        foreach ($rows as $i => $row) {
            $slope = $row['forecast']['slope'];
            if ($slope !== null && $slope < 0) {
                $rates[$i] = ['rate' => -$slope, 'group' => $row['module'] . '|' . (BWACHZelle::isKnown($row['cell']) ? $row['cell'] : 'ohne Zelltyp')];
            }
        }
        foreach (BWACHPrognose::peerOutliers($rates) as $i => $o) {
            $weak = $rows[$i]['signal'] !== null && $rows[$i]['signal']['level'] === 'schwach';
            $rows[$i]['r']['quality'][] = 'auffaellig';
            $rows[$i]['r']['reasons'][] = 'Entlädt ' . BWACHLogik::num($o['factor']) . '× schneller als vergleichbare Geräte (' . BWACHLogik::num($o['rate']) . ' statt im Median ' . BWACHLogik::num($o['median']) . ' Prozentpunkte pro Tag, ' . $o['n'] . ' Geräte im Vergleich)'
                . ($weak ? '. Das Funksignal ist schwach, häufiges Wiederholen kostet Batterie' : '. Mögliche Ursachen: defektes Gerät, schlechte Funkverbindung, Dauersenden');
            $rows[$i]['r']['urgency'] = max($rows[$i]['r']['urgency'], 450 + ($rows[$i]['r']['critical'] ? 100 : 0));
            $rows[$i]['peer'] = $o;
        }
    }

    /**
     * Schickt schlafenden Z-Wave-Geräten, deren Batteriewert alt ist, eine Statusanfrage — nur bei Geräten, für die der
     * Nutzer das eingeschaltet hat, höchstens alle N Tage und nur mit einer belegt vorhandenen Symcon-Funktion.
     * Ein schlafendes Gerät beantwortet die Anfrage erst beim nächsten Aufwachen; bis dahin liegt sie in der
     * Warteschlange der Z-Wave-Instanz.
     */
    private function pollDevices(array $rows, int $now): bool
    {
        $state = $this->loadJson('Poll');
        $s0    = $state;
        $after = $this->ReadPropertyInteger('PollAfterDays') * 86400;
        $every = $this->ReadPropertyInteger('PollEveryDays') * 86400;
        foreach ($rows as $row) {
            $key = $row['id'];
            $age = $row['r']['valueAge'];
            // Antwort auswerten: der Batteriewert wurde seit der Anfrage aktualisiert
            if (isset($state[$key]) && $state[$key]['ans'] === null) {
                if ($age !== null && $now - $age > (int)$state[$key]['t']) {
                    $state[$key]['ans']  = true;
                    $state[$key]['miss'] = 0;
                } elseif ($now - (int)$state[$key]['t'] > 14 * 86400) {
                    $state[$key]['ans'] = false;
                }
            }
            if (!$row['pollOn'] || $row['module'] !== 'Z-Wave Module' || $age === null || $age <= $after) {
                continue;
            }
            if (isset($state[$key]) && $now - (int)$state[$key]['t'] < $every) {
                continue;
            }
            if (!function_exists('ZW_RequestStatus') || !IPS_InstanceExists((int)$row['inst'])) {
                continue;
            }
            try {
                $ok = (bool)ZW_RequestStatus((int)$row['inst']);
            } catch (\Throwable $e) {
                $ok = false;
                IPS_LogMessage('Batteriewächter', 'Abfrage von „' . $row['name'] . '“ fehlgeschlagen: ' . $e->getMessage());
            }
            if ($ok) {
                // Ein unbeantworteter Stand bleibt sichtbar, auch wenn erneut angefragt wird
                $prev = $state[$key] ?? null;
                $miss = 0;
                if ($prev !== null && $prev['ans'] !== true) {
                    $miss = (int)($prev['miss'] ?? 0) ?: ($prev['ans'] === false ? (int)$prev['t'] : 0);
                }
                $state[$key] = ['t' => $now, 'ans' => null, 'miss' => $miss];
            } else {
                IPS_LogMessage('Batteriewächter', 'Abfrage von „' . $row['name'] . '“ wurde nicht angenommen.');
            }
        }
        if ($state !== $s0) {
            $this->saveJson('Poll', $state);
            return true;
        }
        return false;
    }

    /**
     * Liest die Signale eines Geräts. Mehrere Variablen derselben Art an einem
     * Gerät: beim Prozentwert zählt der niedrigste, beim Flag ein gesetztes
     * „schwach“ — im Zweifel die schlechtere Aussage.
     */
    private function readSignals(array $signals): array
    {
        $sig = [];
        foreach ($signals as $kind => $list) {
            foreach ($list as $s) {
                $vid = (int)$s['vid'];
                if (!IPS_VariableExists($vid)) {
                    continue;
                }
                $var = IPS_GetVariable($vid);
                $val = GetValue($vid);
                $upd = (int)($var['VariableUpdated'] ?? 0);
                if ($kind === BWACHLogik::KIND_PERCENT) {
                    $scaled = (float)$val * (float)$s['scale'];
                    if (!isset($sig['percent']) || $scaled < (float)$sig['percent']['value'] * (float)$sig['percent']['scale']) {
                        $sig['percent'] = ['value' => (float)$val, 'updated' => $upd, 'scale' => (float)$s['scale']];
                    }
                } elseif ($kind === BWACHLogik::KIND_FLAG || $kind === BWACHLogik::KIND_FLAG_REV) {
                    $rev = $kind === BWACHLogik::KIND_FLAG_REV;
                    $low = $rev ? !(bool)$val : (bool)$val;
                    if (!isset($sig['flag']) || ($low && !$this->flagIsLow($sig['flag']))) {
                        $sig['flag'] = ['value' => (bool)$val, 'updated' => $upd, 'reversed' => $rev];
                    }
                } elseif ($kind === BWACHLogik::KIND_VOLTAGE && !isset($sig['voltage'])) {
                    // Matter meldet die Batteriespannung in Millivolt (z. B. 3000); über 100 V gibt es bei Batterien nicht
                    $volt = (float)$val;
                    $sig['voltage'] = ['value' => $volt > 100 ? $volt / 1000 : $volt, 'updated' => $upd];
                }
            }
        }
        return $sig;
    }

    private function flagIsLow(array $flag): bool
    {
        return !empty($flag['reversed']) ? !(bool)$flag['value'] : (bool)$flag['value'];
    }

    /** Jüngste Aktualisierung einer Variable der Geräteinstanz, die NICHT schaltbar ist (also vom Gerät kommt). */
    private function lifeSign(int $id, bool $isInstance, array $signals): int
    {
        $max = 0;
        if ($isInstance) {
            foreach (IPS_GetChildrenIDs($id) as $c) {
                $o = IPS_GetObject($c);
                if ((int)($o['ObjectType'] ?? 0) !== 2) {
                    continue;
                }
                $var = IPS_GetVariable($c);
                // Variablen mit Aktion (Sollwert, Schalter) schreibt oft Symcon selbst, z. B. eine
                // Heizungssteuerung — das ist kein Lebenszeichen des Geräts. Live gefunden: ausgebaute
                // Thermostate meldeten täglich „Sollwert aktualisiert“.
                if ((int)($var['VariableAction'] ?? 0) > 0 || (int)($var['VariableCustomAction'] ?? 0) > 0) {
                    continue;
                }
                $max = max($max, (int)($var['VariableUpdated'] ?? 0));
            }
        } else {
            foreach ($signals as $list) {
                foreach ($list as $s) {
                    if (IPS_VariableExists((int)$s['vid'])) {
                        $max = max($max, (int)(IPS_GetVariable((int)$s['vid'])['VariableUpdated'] ?? 0));
                    }
                }
            }
        }
        return $max;
    }

    /** Meldet sich bei allen gefundenen Batterievariablen an und bei weggefallenen ab. */
    private function syncMessages(array $found): void
    {
        $want = [];
        foreach ($found['devices'] as $d) {
            foreach ($d['signals'] as $list) {
                foreach ($list as $s) {
                    $want[(int)$s['vid']] = true;
                }
            }
        }
        foreach ($this->GetMessageList() as $sender => $msgs) {
            if ($sender > 0 && in_array(self::VM_UPDATE_MSG, $msgs, true) && !isset($want[$sender])) {
                $this->UnregisterMessage($sender, self::VM_UPDATE_MSG);
            }
        }
        foreach (array_keys($want) as $vid) {
            $this->RegisterMessage($vid, self::VM_UPDATE_MSG);
        }
    }

    // =====================================================================
    //  Darstellung
    // =====================================================================

    private function kindLabel(string $kind): string
    {
        return [
            BWACHLogik::KIND_PERCENT  => 'Prozent',
            BWACHLogik::KIND_FLAG     => '„schwach“-Flag',
            BWACHLogik::KIND_FLAG_REV => '„in Ordnung“-Flag',
            BWACHLogik::KIND_VOLTAGE  => 'Spannung',
        ][$kind] ?? $kind;
    }

    private function discoveryLine(array $found): string
    {
        $n = count($found['devices']);
        $t = $this->ReadAttributeInteger('LastDiscoveryTs');
        $when = date('H:i:s', $t > 0 ? $t : $this->now());
        $icon = $n > 0 ? '✅' : ($t > 0 ? '⚠️' : 'ℹ️');
        $tail = $n === 0 ? ' Unter „Weitere Variablen“ lässt sich ein Signal von Hand ergänzen.' : '';
        return $icon . ' ' . $n . ' ' . ($n === 1 ? 'Gerät' : 'Geräte') . ' gefunden (zuletzt ' . $when . ' Uhr).' . $tail;
    }

    private function summaryLine(array $sum, int $now): string
    {
        if ($sum['total'] === 0) {
            return 'ℹ️ Keine Geräte überwacht (zuletzt geprüft ' . date('d.m.Y H:i', $now) . ' Uhr).';
        }
        $bad = [];
        if ($sum['empty'] > 0) { $bad[] = $sum['empty'] . ' leer'; }
        if ($sum['low'] > 0) { $bad[] = $sum['low'] . ' schwach'; }
        if (($sum['soon'] ?? 0) > 0) { $bad[] = $sum['soon'] . ' bald leer'; }
        if ($sum['silent'] > 0) { $bad[] = $sum['silent'] . ' Funkstille'; }
        if ($sum['check'] > 0) { $bad[] = $sum['check'] . ' mit zweifelhaften Daten'; }
        if ($sum['unknown'] > 0) { $bad[] = $sum['unknown'] . ' unbekannt'; }
        $icon = ($sum['empty'] > 0 || $sum['low'] > 0 || $sum['silent'] > 0 || ($sum['soon'] ?? 0) > 0) ? '⚠️' : ($bad ? 'ℹ️' : '✅');
        return $icon . ' ' . $sum['total'] . ' Geräte überwacht: ' . ($bad ? implode(', ', $bad) : 'alles in Ordnung')
            . ' (geprüft ' . date('d.m.Y H:i', $now) . ' Uhr).';
    }

    private function renderTable(array $rows, bool $onlyProblems, array $notes = []): string
    {
        $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $body = '';
        $n = 0;
        foreach ($rows as $row) {
            $r = $row['r'];
            if ($onlyProblems && $r['urgency'] === 0) {
                continue;
            }
            $n++;
            $badge = [
                BWACHLogik::ST_OK      => '✅ ok',
                BWACHLogik::ST_LOW     => '⚠️ schwach',
                BWACHLogik::ST_EMPTY   => '🪫 leer',
                BWACHLogik::ST_UNKNOWN => '❔ unbekannt',
            ][$r['status']];
            $bat = $badge;
            if ($r['percent'] !== null) {
                $bat .= ' · ' . BWACHLogik::num($r['percent']) . ' %';
            } elseif ($r['voltage'] !== null) {
                $bat .= ' · ' . BWACHLogik::num($r['voltage']) . ' V';
            }
            $age  = $r['valueAge'] === null ? '—' : BWACHLogik::days($r['valueAge']);
            $life = $r['lifeAge'] === null ? '—' : (($r['funk'] === 'still' ? '🔇 ' : '') . BWACHLogik::daysDat($r['lifeAge']));
            $mark = ($r['critical'] ? ' ❗' : '') . (isset($notes[$row['id']]) ? ' ' . $notes[$row['id']] : '');
            $f = $row['forecast'];
            $left = $f['days'] !== null ? '≈ ' . BWACHLogik::days((int)($f['days'] * 86400)) . ' (' . $f['confidence'] . ')' : ($f['confidence'] === 'keine' ? 'keine Entladung' : '—');
            $body .= '<tr><td>' . $e($row['name']) . $mark . '</td><td>' . $e($row['place']) . '</td><td>' . $e($bat) . '</td><td>'
                . $e($age) . '</td><td>' . $e($life) . '</td><td>' . $e($left) . '</td><td>' . $e(implode('; ', $r['reasons'])) . '</td></tr>';
        }
        if ($n === 0) {
            return '<div style="padding:8px">' . ($onlyProblems ? '✅ Kein Handlungsbedarf.' : 'ℹ️ Keine Geräte.') . '</div>';
        }
        return '<style>.bw{border-collapse:collapse;width:100%;font-size:14px}.bw th,.bw td{padding:4px 10px;text-align:left;border-bottom:1px solid rgba(128,128,128,.35)}</style>'
            . '<table class="bw"><tr><th>Gerät</th><th>Ort</th><th>Batterie</th><th>Wert-Alter</th><th>Lebenszeichen vor</th><th>Restlaufzeit</th><th>Befund</th></tr>' . $body . '</table>';
    }

    private function setIntIfChanged(string $ident, int $value): void
    {
        if ((int)$this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    private function setStringIfChanged(string $ident, string $value): void
    {
        if ((string)$this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    // =====================================================================
    //  Formular
    // =====================================================================

    private static function baseVersion(string $version): string
    {
        return preg_replace('/-.*$/', '', $version);
    }

    private function installedVersion(): string
    {
        $lib = @IPS_GetLibrary(self::LIBRARY_GUID);
        if (is_array($lib) && !empty($lib['Version'])) {
            return self::baseVersion((string)$lib['Version']);
        }
        $keys = array_keys(self::NEWS_VERSIONS);
        usort($keys, 'version_compare');
        return (string)end($keys);
    }

    private function PurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  Wozu dieses Modul?',
            'items' => [
                ['type' => 'Label', 'caption' => 'Der Batteriewächter findet die Batterien Ihrer Funkgeräte (Sensoren, Thermostate, Fenster- und Rauchmelder …) von selbst und sagt Ihnen, welche getauscht werden müssen — bevor ein Gerät ausfällt.'],
                ['type' => 'Label', 'caption' => 'Der Nutzen gegenüber einer einfachen „leer“-Liste: Er unterscheidet eine schwache Batterie von einem Gerät, das gar nichts mehr sendet, und er sagt ehrlich dazu, wenn ein Batteriewert zweifelhaft ist (uralt, widersprüchlich oder außerhalb des Möglichen). Ein „OK“ aus dem Jahr 2023 ist kein OK.'],
                ['type' => 'Label', 'caption' => 'Zuerst „🔎 Jetzt neu suchen“ drücken und mit „Was würde gefunden?“ prüfen, ob die Treffer stimmen. Danach läuft alles von selbst.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'BWACH_AckPurposeIntro($id);'],
            ],
        ];
    }

    private function NewsBanner(): ?array
    {
        $seen = $this->ReadAttributeString('SeenNews');
        if ($seen === '') {
            $seen = '0';
        }
        $pending = [];
        foreach (self::NEWS_VERSIONS as $version => $lines) {
            if (version_compare((string)$version, $seen, '>')) {
                $pending[(string)$version] = $lines;
            }
        }
        if (count($pending) === 0) {
            return null;
        }
        uksort($pending, 'version_compare');
        $items = [];
        foreach ($pending as $version => $lines) {
            $items[] = ['type' => 'Label', 'caption' => 'Version ' . $version . ':'];
            foreach ($lines as $line) {
                $items[] = ['type' => 'Label', 'caption' => $line];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'BWACH_AckNews($id);'];
        return [
            'type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true,
            'caption' => '🆕  Neu bis Version ' . array_key_last($pending),
            'items' => $items,
        ];
    }

    private function DocPanel(): array
    {
        $lib    = @IPS_GetLibrary(self::LIBRARY_GUID);
        $verTxt = (is_array($lib) && isset($lib['Version']))
            ? 'ℹ️ Batteriewächter Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')'
            : 'ℹ️ Batteriewächter';
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '📖  Dokumentation & Hilfe',
            'items' => [
                ['type' => 'Label', 'caption' => $verTxt],
                ['type' => 'Label', 'caption' => 'Was gefunden wird: Variablen mit den Symcon-Profilen ~Battery („schwach“-Flag), ~Battery.Reversed („in Ordnung“-Flag) und ~Battery.100 (Prozent) sowie Variablen mit typischen Bezeichnern (z. B. battery, battery_low, LOWBAT, battery_percent, battery_voltage). Mehrere Signale eines Geräts (z. B. Prozent und Flag) werden zu EINEM Gerät zusammengeführt.'],
                ['type' => 'Label', 'caption' => 'Was bewusst nicht gefunden wird: Heimspeicher und Fahrzeugakkus (Modulliste „Ausgeschlossene Module“), Sammelwerte wie „Schwächste Batterie“ eines Raums und Variablen, die zu keiner Geräteinstanz gehören. Der Trockenlauf nennt zu jedem Ausschluss den Grund.'],
                ['type' => 'Label', 'caption' => 'Die drei Zeiten: (1) Alter des Batteriewerts — wann das Gerät seinen Batteriestand zuletzt gemeldet hat; manche Geräte tun das nur alle paar Monate. (2) Lebenszeichen — die jüngste Aktualisierung einer Messwert-Variable des Geräts (Variablen mit Aktion wie Sollwerte zählen nicht, die schreibt oft Symcon selbst); bleibt sie aus, ist es Funkstille, keine schwache Batterie. (3) Das Gerät selbst mit seinem Status.'],
                ['type' => 'Label', 'caption' => 'Status: „leer“ und „schwach“ aus dem Prozentwert (Schwellen unten) oder dem Flag des Geräts; „unbekannt“, wenn nichts Auswertbares da ist (z. B. nur eine Spannung — die Auswertung nach Zelltyp folgt in einer späteren Version). Meldet ein Gerät Flag und Prozent und beide widersprechen sich, zeigt der Wächter den Widerspruch und bewertet nach dem neueren Signal; bei kritischen Geräten gilt die schlechtere Aussage.'],
                ['type' => 'Label', 'caption' => 'Funkstille: Standard 7 Tage ohne Lebenszeichen. Geräte, die nur bei Ereignissen senden (Fenster-, Rauchmelder), gehören unter „Geräte-Einstellungen“ in die Gruppe „Ereignismelder“ (Standard 30 Tage).'],
                ['type' => 'Label', 'caption' => 'Ergebnis: Kennzahlen-Variablen (leer, schwach, Funkstille …) und zwei Tabellen-Variablen („Handlungsbedarf“, „Alle Geräte“), die sich per Verknüpfung ins WebFront legen lassen.'],
                ['type' => 'Label', 'caption' => 'Meldungen (unter „🔔 Meldungen“, standardmäßig aus): erste Meldung, Erinnerung nach N Tagen, Ruhezeit, Wochenbericht, Eskalation für kritische Geräte — per Push (Kachel-Visualisierung und WebFront) und E-Mail. Unter „✅ Quittieren“ sagen Sie dem Wächter, was mit einem Gerät ist.'],
                ['type' => 'Label', 'caption' => 'Prognose, Einkauf, Tauschrunde: Unter „🧮 Prognose und Einkauf“ einstellbar. Je Gerät den Zelltyp unter „Geräte-Einstellungen“ eintragen, dann kann der Wächter Spannungen umrechnen und die Einkaufsliste zählen. Die Ergebnisse stehen als Variablen „Einkauf und Tauschrunde“ und „Lebensdauer und Entladung“ unter der Instanz.'],
                ['type' => 'Label', 'caption' => 'Auffällige Geräte, Funkqualität, Kälte: Entlädt ein Gerät mehr als doppelt so schnell wie vergleichbare (gleiches System, gleicher Zelltyp, mindestens 4 Geräte mit bekannter Entladerate), markiert der Wächter es und nennt mögliche Ursachen (defekt, schlechte Funkverbindung, Dauersenden). Die Funkqualität zeigt er an, wo das Gerät sie liefert (z. B. Zigbee linkquality). Der Kälteeinfluss braucht eine Außentemperatur-Variable und mehrere Wochen Verlauf bei Kälte UND Wärme.'],
                ['type' => 'Label', 'caption' => 'Abfrage schlafender Geräte: nur Z-Wave, nur wo je Gerät eingeschaltet, nur wenn der Batteriewert älter als eingestellt ist. Der Wächter sagt, ob das Gerät geantwortet hat; antwortet es nach 14 Tagen nicht, steht das als Befund da.'],
                ['type' => 'Label', 'caption' => 'Kachel: Instanz in der Kachel-Visualisierung als Kachel hinzufügen. Antippen einer Zeile klappt sie auf (Gründe, Alter, Schaltflächen zum Quittieren), die Filterzeile oben zeigt nur, was gerade wichtig ist.'],
                ['type' => 'Label', 'caption' => 'Batterietagebuch: Der Wächter erkennt einen Batteriewechsel am Sprung des Prozentwerts oder am zurückgesetzten „schwach“-Flag und hält ihn mit Datum fest. Die Auswertung (Lebensdauer je Gerät und Zelltyp) folgt, sobald genug Wechsel gesammelt sind.'],
                ['type' => 'Label', 'caption' => 'Skripte: BWACH_Search(<InstanzID>) sucht neu, BWACH_Check(<InstanzID>) bewertet, BWACH_Preview(<InstanzID>) liefert den Trockenlauf als Text, BWACH_Acknowledge(<InstanzID>, \'<Schlüssel>\', \'getauscht\'|\'zurueckgestellt\'|\'ausser_betrieb\') quittiert, BWACH_SendTest(<InstanzID>) schickt eine Testmeldung.'],
            ],
        ];
    }

    private function DiscoveryPanel(): array
    {
        $found = $this->found();
        $line  = $found === null ? 'ℹ️ Noch nicht gesucht.' : $this->discoveryLine($found);
        return [
            'type' => 'ExpansionPanel', 'expanded' => true,
            'caption' => '🔎  Gefundene Geräte',
            'items' => [
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '🔎 Jetzt neu suchen', 'onClick' => 'echo BWACH_Search($id);'],
                    ['type' => 'Button', 'caption' => '📋 Was würde gefunden?', 'onClick' => 'echo BWACH_Preview($id);'],
                ]],
                ['type' => 'Label', 'name' => 'DiscoveryStatus', 'caption' => $line],
                ['type' => 'CheckBox', 'name' => 'NameSearch', 'caption' => 'Auch nach Namen suchen (ungenauer — kann Fehltreffer liefern)'],
                ['type' => 'ValidationTextBox', 'name' => 'ExcludedModules', 'caption' => 'Ausgeschlossene Module (Komma getrennt)'],
                ['type' => 'Label', 'caption' => 'ℹ️ Variablen dieser Module gelten als Energiespeicher oder Fahrzeugakku und werden nicht als Geräte-Batterie vorgeschlagen. Die Liste ist nur ein Ausgangspunkt — bei Bedarf anpassen.'],
            ],
        ];
    }

    private function StatusPanel(): array
    {
        $found = $this->found();
        $line  = 'ℹ️ Noch nicht geprüft.';
        if ($found !== null && $this->ReadAttributeInteger('LastCheckTs') > 0) {
            $line = (string)$this->GetValue('StatusLine');
        }
        return [
            'type' => 'ExpansionPanel', 'expanded' => true,
            'caption' => '🔋  Zustand',
            'items' => [
                ['type' => 'Button', 'caption' => '🔄 Jetzt prüfen', 'onClick' => 'echo BWACH_Check($id);'],
                ['type' => 'Label', 'name' => 'CheckStatus', 'caption' => $line],
                ['type' => 'Label', 'caption' => 'Kachel: In der Kachel-Visualisierung diese Instanz als Kachel hinzufügen. Die Kachel zeigt die Geräte nach Dringlichkeit, mit Filter, Batterietagebuch und den Schaltflächen zum Quittieren — ohne eigenen Titel, den liefert der Instanzname.'],
                ['type' => 'CheckBox', 'name' => 'TileAllowAck', 'caption' => 'Quittieren aus der Kachel erlauben („getauscht“, „später“, „außer Betrieb“)'],
                ['type' => 'Label', 'caption' => 'Wer die Kachel sehen darf, kann dann auch quittieren. Ausschalten, wenn die Kachel nur anzeigen soll. Die Tabellen „Handlungsbedarf“, „Alle Geräte“ und „Batterietagebuch“ liegen zusätzlich als Variablen unter der Instanz im Objektbaum (per Verknüpfung ins WebFront legbar).'],
            ],
        ];
    }

    private function importLine(): string
    {
        $old = $this->oldInstances();
        if (!$old) {
            return 'ℹ️ Keine alte BY_BatterieMonitor-Instanz gefunden.';
        }
        $names = [];
        foreach ($old as $i => $n) {
            $names[] = '„' . $n . '“ (#' . $i . ')';
        }
        return 'ℹ️ Gefunden: ' . implode(', ', $names) . '. Push-, E-Mail- und SMTP-Einstellungen lassen sich in die Maske übernehmen (gespeichert wird erst mit „Übernehmen“).';
    }

    private function NotifyPanel(): array
    {
        $targets = $this->pushTargets();
        $nK = count(array_filter($targets, function ($x) { return $x === 'kachel'; }));
        $nW = count($targets) - $nK;
        $chosen = trim($this->ReadPropertyString('PushTargets')) !== '' && trim($this->ReadPropertyString('PushTargets')) !== '[]';
        $pushLine = count($targets) === 0
            ? 'ℹ️ Keine Push-Ziele gefunden (weder Kachel-Visualisierung noch klassisches WebFront) — Push bleibt wirkungslos, E-Mail geht trotzdem.'
            : '✅ Push-Ziele: ' . $nK . ' Kachel-Visualisierung' . ($nK === 1 ? '' : 'en') . ', ' . $nW . ' WebFront' . ($chosen ? ' (nach Ihrer Auswahl unten)' : ' (alle gefundenen, solange unten nichts ausgewählt ist)') . '.';
        $mi = $this->ReadPropertyInteger('MailInstance');
        if (!$this->ReadPropertyBoolean('NotifyMail')) {
            $mailLine = 'ℹ️ E-Mail ist aus.';
        } elseif ($mi <= 0 || !IPS_InstanceExists($mi)) {
            $mailLine = '⚠️ E-Mail ist eingeschaltet, aber es ist keine SMTP-Instanz ausgewählt.';
        } else {
            $to = trim($this->ReadPropertyString('MailTo'));
            $mailLine = '✅ E-Mail über „' . IPS_GetName($mi) . '“ an ' . ($to !== '' ? $to : 'den in der SMTP-Instanz eingestellten Empfänger') . '.';
        }
        $head = $this->ReadPropertyBoolean('NotificationsActive')
            ? '🔔 Meldungen sind AN.'
            : 'ℹ️ Meldungen sind AUS — es wird nichts verschickt, bis Sie „Meldungen aktiv“ einschalten.';

        return [
            'type' => 'ExpansionPanel', 'expanded' => true,
            'caption' => '🔔  Meldungen',
            'items' => [
                ['type' => 'CheckBox', 'name' => 'NotificationsActive', 'caption' => 'Meldungen aktiv'],
                ['type' => 'Label', 'name' => 'NotifyStatus', 'caption' => $head . ' ' . $pushLine . ' ' . $mailLine],
                ['type' => 'Label', 'caption' => 'Gemeldet werden „Batterie leer“, „Batterie schwach“ und „Funkstille“. Zweifelhafte Daten (veraltet, Widerspruch) stehen nur im Wochenbericht und in den Tabellen. Mehrere Befunde eines Laufs kommen als EINE Nachricht.'],
                ['type' => 'CheckBox', 'name' => 'NotifyPush', 'caption' => 'Per Push melden (Kachel-Visualisierung und WebFront)'],
                [
                    'type' => 'List', 'name' => 'PushTargets', 'caption' => 'Nur diese Push-Ziele (leer = alle gefundenen)', 'rowCount' => 3, 'add' => true, 'delete' => true,
                    'columns' => [
                        ['caption' => 'Instanz', 'name' => 'Instance', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'SelectInstance']],
                    ],
                ],
                ['type' => 'CheckBox', 'name' => 'NotifyMail', 'caption' => 'Per E-Mail melden'],
                ['type' => 'SelectInstance', 'name' => 'MailInstance', 'caption' => 'SMTP-Instanz'],
                ['type' => 'ValidationTextBox', 'name' => 'MailTo', 'caption' => 'Empfänger (mehrere mit Komma; leer = der in der SMTP-Instanz eingestellte)'],
                ['type' => 'Button', 'caption' => '📨 Testmeldung senden', 'onClick' => 'echo BWACH_SendTest($id);'],
                ['type' => 'Label', 'name' => 'ImportStatus', 'caption' => $this->importLine()],
                ['type' => 'Button', 'caption' => '📥 Einstellungen aus BY_BatterieMonitor übernehmen', 'onClick' => 'echo BWACH_ImportOld($id);'],
                ['type' => 'NumberSpinner', 'name' => 'ReminderDays', 'caption' => 'Erinnerung, solange der Befund bleibt, alle', 'suffix' => ' Tage', 'minimum' => 1, 'maximum' => 90],
                ['type' => 'NumberSpinner', 'name' => 'CriticalReminderDays', 'caption' => 'Erinnerung bei kritischen Geräten alle', 'suffix' => ' Tage', 'minimum' => 1, 'maximum' => 30],
                ['type' => 'NumberSpinner', 'name' => 'SnoozeDays', 'caption' => '„Erinnere mich später“ verschiebt um', 'suffix' => ' Tage', 'minimum' => 1, 'maximum' => 90],
                ['type' => 'NumberSpinner', 'name' => 'EscalateHours', 'caption' => 'Eskalation für kritische Geräte, wenn niemand reagiert, nach (0 = aus)', 'suffix' => ' Stunden', 'minimum' => 0, 'maximum' => 720],
                ['type' => 'CheckBox', 'name' => 'EscalatePush', 'caption' => 'Eskalation per Push'],
                ['type' => 'CheckBox', 'name' => 'EscalateMail', 'caption' => 'Eskalation per E-Mail'],
                ['type' => 'CheckBox', 'name' => 'QuietEnabled', 'caption' => 'Ruhezeit: nachts nicht melden (die Meldung wird danach nachgeholt)'],
                ['type' => 'NumberSpinner', 'name' => 'QuietFromHour', 'caption' => 'Ruhezeit von', 'suffix' => ' Uhr', 'minimum' => 0, 'maximum' => 23],
                ['type' => 'NumberSpinner', 'name' => 'QuietToHour', 'caption' => 'Ruhezeit bis', 'suffix' => ' Uhr', 'minimum' => 0, 'maximum' => 23],
                ['type' => 'CheckBox', 'name' => 'CriticalIgnoresQuiet', 'caption' => 'Kritische Geräte dürfen die Ruhezeit durchbrechen'],
                ['type' => 'CheckBox', 'name' => 'DigestEnabled', 'caption' => 'Wochenbericht senden'],
                ['type' => 'Select', 'name' => 'DigestWeekday', 'caption' => 'Wochentag', 'options' => [
                    ['caption' => 'Montag', 'value' => 1], ['caption' => 'Dienstag', 'value' => 2], ['caption' => 'Mittwoch', 'value' => 3],
                    ['caption' => 'Donnerstag', 'value' => 4], ['caption' => 'Freitag', 'value' => 5], ['caption' => 'Samstag', 'value' => 6], ['caption' => 'Sonntag', 'value' => 7],
                ]],
                ['type' => 'NumberSpinner', 'name' => 'DigestHour', 'caption' => 'Ab', 'suffix' => ' Uhr', 'minimum' => 0, 'maximum' => 23],
                ['type' => 'CheckBox', 'name' => 'DigestWhenOk', 'caption' => 'Wochenbericht auch senden, wenn alles in Ordnung ist'],
                ['type' => 'PopupButton', 'caption' => 'Wie arbeiten Meldung, Erinnerung und Eskalation zusammen?', 'width' => '480px', 'popup' => [
                    'caption' => 'Meldungen',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Erste Meldung: sobald ein Gerät „leer“, „schwach“ oder „still“ ist. Danach nur noch die Erinnerung im eingestellten Abstand, solange der Befund bleibt. Ein NEUER, schlimmerer Befund (z. B. von „schwach“ auf „leer“) ist wieder eine erste Meldung.'],
                        ['type' => 'Label', 'caption' => 'Ruhezeit: Meldungen werden nicht verworfen, sondern nach der Ruhezeit nachgeholt. Kritische Geräte dürfen sie durchbrechen, wenn das angekreuzt ist.'],
                        ['type' => 'Label', 'caption' => 'Eskalation: nur für kritische Geräte. Hat niemand nach der eingestellten Zeit quittiert, geht eine zweite Meldung über die Eskalationswege.'],
                        ['type' => 'Label', 'caption' => 'Wackelnde Werte: Ein Befund gilt erst als beendet, wenn er 24 Stunden lang weg war. So meldet ein Gerät, das zwischen „leer“ und „ok“ springt, nicht jedes Mal neu.'],
                        ['type' => 'Label', 'caption' => 'Zustellung: Kann eine Meldung über keinen Weg zugestellt werden, steht das im Symcon-Meldungslog, und der Wächter versucht es beim nächsten Lauf erneut.'],
                    ],
                ]],
            ],
        ];
    }

    /** Einkauf, Tauschrunde und Statistik in der Form, die die Kachel braucht. */
    private function tileShopping(array $v): array
    {
        $places = [];
        foreach ($v['round']['places'] as $place => $devs) {
            $list = [];
            foreach ($devs as $d) {
                $cell = BWACHZelle::shopLabel((string)$d['cell']);
                $list[] = ['name' => $d['name'], 'need' => $cell !== null ? max(1, (int)$d['cells']) . '× ' . $cell : '', 'text' => $d['text']];
            }
            $places[] = ['place' => $place, 'devices' => $list];
        }
        $stats = [];
        foreach (['byCell', 'byModule'] as $k) {
            foreach ($v[$k] as $g => $s) {
                $stats[] = ['group' => ($k === 'byCell' ? 'Zelle ' : 'System ') . $g, 'text' => BWACHLogik::num($s['median']) . ' Tage (' . $s['n'] . ' Intervalle)'];
            }
        }
        foreach ($v['peers'] as $pe) {
            $stats[] = ['group' => '👥 ' . $pe['name'], 'text' => $pe['text']];
        }
        $stats[] = ['group' => '🌡', 'text' => $v['cold']];
        foreach ($v['life'] as $l) {
            $stats[] = ['group' => $l['name'], 'text' => implode(', ', array_map(function ($d) { return BWACHLogik::num($d) . ' Tage'; }, $l['days']))];
        }
        return [
            'horizon' => $v['horizon'],
            'where'   => 'Instanz „' . IPS_GetName($this->InstanceID) . '“ öffnen, Panel „Geräte-Einstellungen“, Spalte „Zelltyp“',
            'lines'   => $v['shopping']['lines'],
            'missing' => $v['shopping']['missing'],
            'count'   => $v['round']['count'],
            'until'   => $v['round']['until'] === null ? '' : date('d.m.Y', $v['round']['until']),
            'places'  => $places,
            'stats'   => $stats,
        ];
    }

    /** Die wichtigste Begründung eines Geräts für die Unterzeile der Kachel. */
    private function headline(array $r): string
    {
        $want = $r['status'] === BWACHLogik::ST_EMPTY || $r['status'] === BWACHLogik::ST_LOW ? 'Batterie ' : ($r['funk'] === 'still' ? 'Funkstille' : (!empty($r['soon']) ? 'Batterie bald leer' : ''));
        foreach ($r['reasons'] as $reason) {
            if ($want !== '' && strpos($reason, $want) === 0) {
                return $reason;
            }
        }
        return $r['reasons'][0] ?? '';
    }

    /** Kurzer Befund hinter dem Gerätenamen in der Auswahl, z. B. „ (leer, Funkstille)“. */
    private function shortFinding(array $r): string
    {
        $parts = [];
        if ($r['status'] === BWACHLogik::ST_EMPTY) { $parts[] = 'leer'; }
        if ($r['status'] === BWACHLogik::ST_LOW) { $parts[] = 'schwach'; }
        if ($r['funk'] === 'still') { $parts[] = 'Funkstille'; }
        if (!$parts && $r['urgency'] > 0) { $parts[] = 'Daten prüfen'; }
        return $parts ? ' (' . implode(', ', $parts) . ')' : '';
    }

    private function retiredLine(?array $found): string
    {
        $retired = $this->loadJson('Retired');
        if (!$retired) {
            return 'ℹ️ Kein Gerät ist außer Betrieb gesetzt.';
        }
        $names = [];
        foreach (array_keys($retired) as $k) {
            $names[] = $found !== null && isset($found['devices'][$k]) ? (string)$found['devices'][$k]['name'] : (string)$k;
        }
        return '💤 Außer Betrieb (' . count($names) . '): ' . implode(', ', array_slice($names, 0, 8)) . (count($names) > 8 ? ' …' : '');
    }

    private function diaryLine(): string
    {
        $dl = [];
        foreach (array_slice(array_reverse($this->loadJson('Diary')), 0, 6) as $d) {
            $dl[] = date('d.m.Y', (int)$d['t']) . ' ' . $d['name'] . ' (' . ($d['type'] === 'erkannt' ? 'erkannt' : 'eingetragen') . ')';
        }
        return $dl
            ? '📓 Zuletzt im Tagebuch: ' . implode(' · ', $dl)
            : '📓 Das Batterietagebuch ist noch leer. Wechsel werden erkannt (Prozentwert springt hoch, „schwach“-Flag wird zurückgesetzt) oder hier eingetragen. Die ganze Liste steht in der Variable „Batterietagebuch“.';
    }

    private function AckPanel(): array
    {
        $found = $this->found();
        $options = [['caption' => '— Gerät wählen —', 'value' => '']];
        if ($found !== null) {
            $rows = $this->evaluateAll($found);
            usort($rows, function ($a, $b) { return [$b['r']['urgency'], $a['name']] <=> [$a['r']['urgency'], $b['name']]; });
            foreach ($rows as $r) {
                $options[] = ['caption' => $r['name'] . $this->shortFinding($r['r']), 'value' => $r['id']];
            }
        }
        return [
            'type' => 'ExpansionPanel', 'expanded' => true,
            'caption' => '✅  Quittieren und Batterietagebuch',
            'items' => [
                ['type' => 'Label', 'caption' => 'Gerät wählen und sagen, was damit ist: „Habe ich getauscht“ trägt den Wechsel ins Tagebuch ein (3 Tage Wartezeit, bis das Gerät den neuen Stand meldet), „Erinnere mich später“ stellt die Meldung zurück, „Außer Betrieb“ nimmt das Gerät dauerhaft aus der Überwachung.'],
                ['type' => 'Select', 'name' => 'AckDevice', 'caption' => 'Gerät', 'options' => $options],
                ['type' => 'Select', 'name' => 'AckAction', 'caption' => 'Was ist damit?', 'options' => [
                    ['caption' => 'Habe ich getauscht', 'value' => BWACHMeldung::ACK_REPLACED],
                    ['caption' => 'Erinnere mich später', 'value' => BWACHMeldung::ACK_SNOOZE],
                    ['caption' => 'Gerät ist außer Betrieb', 'value' => BWACHMeldung::ACK_RETIRED],
                ]],
                ['type' => 'Button', 'caption' => '✔️ Ausführen', 'onClick' => 'echo BWACH_Acknowledge($id, $AckDevice, $AckAction);'],
                ['type' => 'Label', 'name' => 'AckStatus', 'caption' => 'ℹ️ Noch nichts quittiert.'],
                ['type' => 'Label', 'name' => 'RetiredLine', 'caption' => $this->retiredLine($found)],
                ['type' => 'Button', 'caption' => '↩️ Alle außer Betrieb gesetzten Geräte wieder aufnehmen', 'onClick' => 'echo BWACH_UnretireAll($id);'],
                ['type' => 'Label', 'name' => 'DiaryLine', 'caption' => $this->diaryLine()],
                ['type' => 'NumberSpinner', 'name' => 'ReplaceJumpPercent', 'caption' => 'Wechsel erkennen, wenn der Prozentwert um mindestens so viel steigt', 'suffix' => ' Prozentpunkte', 'minimum' => 5, 'maximum' => 90],
            ],
        ];
    }

    private function ForecastPanel(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧮  Prognose und Einkauf',
            'items' => [
                ['type' => 'Label', 'caption' => 'Der Wächter schreibt von jedem Gerät den Verlauf mit (im Modul selbst, ein Symcon-Archiv ist nicht nötig) und schätzt daraus, wie lange die Batterie noch reicht. Das klappt nur, wo das Gerät den Ladezustand fein genug und oft genug meldet; sonst steht dort ehrlich „Restlaufzeit unbekannt“ mit Grund. Eine Prognose braucht mindestens 4 Messpunkte über 14 Tage seit dem letzten Batteriewechsel.'],
                ['type' => 'NumberSpinner', 'name' => 'ForecastHorizonDays', 'caption' => 'Einkaufsliste und Tauschrunde für die nächsten', 'suffix' => ' Tage', 'minimum' => 7, 'maximum' => 365],
                ['type' => 'NumberSpinner', 'name' => 'SoonDays', 'caption' => '„Bald leer“ melden, wenn die Batterie laut Prognose noch höchstens', 'suffix' => ' Tage reicht', 'minimum' => 3, 'maximum' => 90],
                ['type' => 'Label', 'caption' => 'ℹ️ „Bald leer“ wird nur bei hoher oder mittlerer Sicherheit der Prognose gemeldet, nie bei geringer.'],
                ['type' => 'CheckBox', 'name' => 'LearnIntervals', 'caption' => 'Meldeverhalten lernen (Funkstille früher erkennen, wenn ein Gerät sonst sehr regelmäßig sendet)'],
                ['type' => 'SelectVariable', 'name' => 'OutdoorTempVar', 'caption' => 'Außentemperatur (optional, für den Kälteeinfluss auf die Entladung)'],
                ['type' => 'NumberSpinner', 'name' => 'ColdBelow', 'caption' => 'Als „kalt“ gilt unter', 'suffix' => ' °C', 'minimum' => -20, 'maximum' => 20],
                ['type' => 'Label', 'caption' => 'ℹ️ Der Kälteeinfluss wird erst ausgewertet, wenn mindestens 3 Geräte je 3 Zeitabschnitte bei Kälte und bei Wärme haben — also frühestens nach einem Winter. Bis dahin steht ehrlich „noch nicht genug Daten“.'],
                ['type' => 'NumberSpinner', 'name' => 'PollAfterDays', 'caption' => 'Abfrage schlafender Geräte, wenn der Batteriewert älter ist als', 'suffix' => ' Tage', 'minimum' => 3, 'maximum' => 365],
                ['type' => 'NumberSpinner', 'name' => 'PollEveryDays', 'caption' => 'Abfrage höchstens alle', 'suffix' => ' Tage', 'minimum' => 1, 'maximum' => 90],
                ['type' => 'Label', 'caption' => 'ℹ️ Die Abfrage ist je Gerät unter „Geräte-Einstellungen“ (Spalte „Abfragen“) einzuschalten, standardmäßig AUS. Sie gibt es nur für Z-Wave-Geräte. Ein schlafendes Gerät beantwortet sie erst beim nächsten Aufwachen, bis dahin liegt sie in der Warteschlange der Z-Wave-Instanz; zu häufiges Abfragen füllt diese Warteschlange.'],
                ['type' => 'NumberSpinner', 'name' => 'OrphanDays', 'caption' => 'Gerät als „vermutlich ausgebaut“ vorschlagen, wenn es so lange still ist (0 = aus)', 'suffix' => ' Tage', 'minimum' => 0, 'maximum' => 720],
                ['type' => 'PopupButton', 'caption' => 'Wie sicher ist die Prognose, und was heißt „aus Spannung berechnet“?', 'width' => '500px', 'popup' => [
                    'caption' => 'Prognose und Spannung',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Die Restlaufzeit ist eine Schätzung aus dem bisherigen Verlauf (robuste Gerade, unempfindlich gegen einzelne Ausreißer). Hohe Sicherheit: gleichmäßiger Verlauf über mindestens 60 Tage und 8 Messpunkte; mittlere: mindestens 30 Tage; sonst gering. Batterien entladen sich nicht immer gleichmäßig (Kälte, Last, Funkprobleme) — die Zahl ist eine Orientierung, kein Versprechen.'],
                        ['type' => 'Label', 'caption' => 'Meldet ein Gerät nur eine Spannung, rechnet der Wächter sie mit einer typischen Entladekurve des gewählten Zelltyps in einen Ladezustand um. Das ist eine Näherung (keine Datenblattwerte eines Herstellers) und steht in der Anzeige immer als „aus Spannung berechnet“. Passt die Spannung nicht zum gewählten Zelltyp, meldet der Wächter das, statt zu raten.'],
                        ['type' => 'Label', 'caption' => 'Den Zelltyp kann der Wächter aus der Spannung nicht sicher erkennen (3 V können eine Knopfzelle oder zwei Alkali-Zellen sein). Er nennt Kandidaten, wählt aber nie selbst.'],
                    ],
                ]],
            ],
        ];
    }

    private function ThresholdPanel(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '⚙️  Schwellen',
            'items' => [
                ['type' => 'NumberSpinner', 'name' => 'LowPercent', 'caption' => 'Batterie schwach unter', 'suffix' => ' %', 'minimum' => 1, 'maximum' => 99],
                ['type' => 'NumberSpinner', 'name' => 'EmptyPercent', 'caption' => 'Batterie leer bis', 'suffix' => ' %', 'minimum' => 0, 'maximum' => 50],
                ['type' => 'NumberSpinner', 'name' => 'CriticalLowPercent', 'caption' => 'Schwach unter — kritische Geräte', 'suffix' => ' %', 'minimum' => 1, 'maximum' => 99],
                ['type' => 'NumberSpinner', 'name' => 'ValueOldDays', 'caption' => 'Batteriewert gilt als veraltet nach', 'suffix' => ' Tagen', 'minimum' => 1, 'maximum' => 3650],
                ['type' => 'NumberSpinner', 'name' => 'StillDays', 'caption' => 'Funkstille nach (Standard-Geräte)', 'suffix' => ' Tagen', 'minimum' => 1, 'maximum' => 365],
                ['type' => 'NumberSpinner', 'name' => 'StillDaysEvent', 'caption' => 'Funkstille nach (Ereignismelder)', 'suffix' => ' Tagen', 'minimum' => 1, 'maximum' => 365],
                ['type' => 'NumberSpinner', 'name' => 'CheckIntervalMin', 'caption' => 'Prüfintervall', 'suffix' => ' min', 'minimum' => 5, 'maximum' => 1440],
                ['type' => 'PopupButton', 'caption' => 'Wie arbeiten die Schwellen zusammen?', 'width' => '400px', 'popup' => [
                    'caption' => 'Schwellen',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Prozentwert bis zur „leer“-Grenze = leer, darunter bis zur „schwach“-Grenze = schwach. Kritische Geräte (unter Geräte-Einstellungen markiert) melden schon früher „schwach“.'],
                        ['type' => 'Label', 'caption' => 'Ein Batteriewert gilt als veraltet, wenn das Gerät ihn seit so vielen Tagen nicht mehr gemeldet hat. Das ist kein Alarm, sondern ein Hinweis: der angezeigte Stand ist dann nicht mehr verlässlich. Manche Geräte melden den Batteriestand nur alle paar Monate; für sie lässt sich die Altersprüfung einzeln abschalten.'],
                        ['type' => 'Label', 'caption' => 'Funkstille hängt am Lebenszeichen des Geräts (irgendeine Aktualisierung), nicht am Batteriewert. Wählen Sie die Tage großzügig, wenn ein Gerät nur selten etwas sendet.'],
                    ],
                ]],
            ],
        ];
    }

    /**
     * Zeilen der Geräteliste: die gespeicherten Einstellungen UND jede erkannte Geräteinstanz, die noch fehlt
     * (mit neutralen Standardwerten). So steht jedes erkannte Gerät schon in der Liste, und es fehlt nur noch
     * der Zelltyp. Gespeichert wird erst, wenn der Nutzer „Übernehmen“ klickt.
     *
     * @return array ['rows'=>array[], 'added'=>int]
     */
    private function deviceRows(): array
    {
        $saved = json_decode((string)$this->ReadPropertyString('DeviceSettings'), true);
        $rows  = [];
        $have  = [];
        if (is_array($saved)) {
            foreach ($saved as $r) {
                $id = (int)($r['Instance'] ?? 0);
                if ($id > 0 && isset($have[$id])) {
                    continue;   // doppelte Zeilen derselben Instanz: die erste gilt, wie bei der Auswertung
                }
                if ($id > 0) {
                    $have[$id] = true;
                }
                $rows[] = [
                    'Instance'  => $id,
                    'Group'     => (string)($r['Group'] ?? BWACHLogik::GROUP_STANDARD),
                    'Critical'  => (bool)($r['Critical'] ?? false),
                    'IgnoreAge' => (bool)($r['IgnoreAge'] ?? false),
                    'Excluded'  => (bool)($r['Excluded'] ?? false),
                    'Cell'      => BWACHZelle::isKnown((string)($r['Cell'] ?? '')) ? (string)$r['Cell'] : BWACHZelle::UNKNOWN,
                    'Cells'     => max(1, min(12, (int)($r['Cells'] ?? 1))),
                    'Poll'      => (bool)($r['Poll'] ?? false),
                ];
            }
        }
        $new = [];
        $found = $this->found();
        if ($found !== null) {
            foreach ($found['devices'] as $key => $d) {
                $id = (int)($d['parent'] ?? $key);
                if ($id > 0 && !isset($have[$id]) && IPS_InstanceExists($id)) {
                    $have[$id] = true;
                    $new[$id] = ['Instance' => $id, 'Group' => BWACHLogik::GROUP_STANDARD, 'Critical' => false, 'IgnoreAge' => false,
                        'Excluded' => false, 'Cell' => BWACHZelle::UNKNOWN, 'Cells' => 1, 'Poll' => false];
                }
            }
            uasort($new, function ($a, $b) { return strcasecmp(IPS_GetName($a['Instance']), IPS_GetName($b['Instance'])); });
        }
        return ['rows' => array_merge($rows, array_values($new)), 'added' => count($new)];
    }

    private function DevicesPanel(): array
    {
        $dr = $this->deviceRows();
        $line = $dr['added'] > 0
            ? '✅ Alle erkannten Geräte stehen schon in der Liste (' . $dr['added'] . ' neu, noch nicht gespeichert). Je Gerät nur den Zelltyp und die Anzahl Zellen wählen, dann unten „Übernehmen“ klicken.'
            : (count($dr['rows']) > 0 ? '✅ Alle erkannten Geräte stehen in der Liste.' : 'ℹ️ Noch keine Geräte erkannt: zuerst oben „Jetzt neu suchen“ drücken.');
        return [
            'type' => 'ExpansionPanel', 'expanded' => $dr['added'] > 0,
            'caption' => '🏷️  Geräte-Einstellungen',
            'items' => [
                ['type' => 'Label', 'name' => 'DeviceRowsLine', 'caption' => $line],
                ['type' => 'Label', 'caption' => 'Hier steht jedes erkannte Gerät mit neutralen Standardwerten. Nur ändern, was abweicht: den Zelltyp (z. B. CR2032, AAA) und die Anzahl Zellen — damit rechnet der Wächter Spannungen in einen Ladezustand um und stellt die Einkaufsliste zusammen —, Ereignismelder (Fenster-, Rauchmelder), kritische Geräte (früher und dringlicher), Geräte ohne Altersprüfung oder Geräte, die ganz ausgenommen werden sollen. Die Spalte „Geräteinstanz“ zeigt den Namen der Instanz.'],
                [
                    'type' => 'List', 'name' => 'DeviceSettings', 'caption' => 'Geräte', 'rowCount' => 12, 'add' => true, 'delete' => true,
                    'loadValuesFromConfiguration' => false,
                    'values' => $dr['rows'],
                    'columns' => [
                        ['caption' => 'Geräteinstanz', 'name' => 'Instance', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'SelectInstance']],
                        ['caption' => 'Gruppe', 'name' => 'Group', 'width' => '170px', 'add' => BWACHLogik::GROUP_STANDARD, 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => 'Standard', 'value' => BWACHLogik::GROUP_STANDARD],
                            ['caption' => 'Ereignismelder', 'value' => BWACHLogik::GROUP_EVENT],
                        ]]],
                        ['caption' => 'Kritisch', 'name' => 'Critical', 'width' => '80px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Ohne Altersprüfung', 'name' => 'IgnoreAge', 'width' => '150px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Ausnehmen', 'name' => 'Excluded', 'width' => '100px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Zelltyp', 'name' => 'Cell', 'width' => '230px', 'add' => BWACHZelle::UNKNOWN, 'edit' => ['type' => 'Select', 'options' => BWACHZelle::options()]],
                        ['caption' => 'Anzahl Zellen', 'name' => 'Cells', 'width' => '120px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 12]],
                        ['caption' => 'Abfragen', 'name' => 'Poll', 'width' => '90px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                    ],
                ],
            ],
        ];
    }

    private function ManualPanel(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '➕  Weitere Variablen',
            'items' => [
                ['type' => 'Label', 'caption' => 'Für Batterie-Variablen, die nicht automatisch gefunden werden. Die Art bestimmt, wie der Wert gelesen wird: „Prozent“ (0–100), „Flag: schwach“ (TRUE = Batterie schwach), „Flag: in Ordnung“ (TRUE = Batterie in Ordnung) oder „Spannung“ (wird nur angezeigt).'],
                [
                    'type' => 'List', 'name' => 'ManualVariables', 'caption' => 'Variablen', 'rowCount' => 5, 'add' => true, 'delete' => true,
                    'columns' => [
                        ['caption' => 'Variable', 'name' => 'Variable', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => 'Art', 'name' => 'Kind', 'width' => '200px', 'add' => BWACHLogik::KIND_PERCENT, 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => 'Prozent', 'value' => BWACHLogik::KIND_PERCENT],
                            ['caption' => 'Flag: schwach', 'value' => BWACHLogik::KIND_FLAG],
                            ['caption' => 'Flag: in Ordnung', 'value' => BWACHLogik::KIND_FLAG_REV],
                            ['caption' => 'Spannung', 'value' => BWACHLogik::KIND_VOLTAGE],
                        ]]],
                    ],
                ],
            ],
        ];
    }

    private function ForumHint(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Rückmeldungen',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 Der Batteriewächter ist neu — Fragen, Wünsche oder Fehler sind willkommen (GitHub). Besonders gefragt: Erfahrungen mit Zigbee2MQTT, Matter und HomeMatic, die der Entwickler selbst nicht testen kann.'],
                ['type' => 'Button', 'caption' => 'Zum Repository', 'onClick' => "echo '" . self::REPO_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'BWACH_AckForumHint($id);'],
            ],
        ];
    }

    /** Lizenz-Hinweis — Wortlaut verbundweit identisch (SUITE.md "Einheitliche Formular-Optik", Variante A). */
    private function LicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  Über dieses Modul',
            'items' => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    public function GetConfigurationForm()
    {
        $base   = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $status = $base['status'] ?? [];

        $elements = array_values(array_filter([
            $this->PurposeIntro(), $this->NewsBanner(), $this->DocPanel(),
            $this->DiscoveryPanel(), $this->StatusPanel(), $this->NotifyPanel(), $this->AckPanel(), $this->ForecastPanel(), $this->ThresholdPanel(),
            $this->DevicesPanel(), $this->ManualPanel(),
            $this->ForumHint(), $this->LicenseHint(),
        ]));

        return json_encode(['elements' => $elements, 'actions' => [], 'status' => $status]);
    }
}
