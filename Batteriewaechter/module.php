<?php

// ===========================================================================
// Batteriewächter (DG65-Toolkit) — überwacht die Batterien von Geräten im
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

require_once __DIR__ . '/BWACHText.php';
require_once __DIR__ . '/BWACHLogik.php';
require_once __DIR__ . '/BWACHMeldung.php';
require_once __DIR__ . '/BWACHPrognose.php';

class Batteriewaechter extends IPSModule
{
    /** Grund des letzten fehlgeschlagenen E-Mail-Versands (für Testmeldung und Meldungslog). */
    private string $lastMailError = '';

    public function __construct(int $InstanceID)
    {
        parent::__construct($InstanceID);
        // Logik-Klassen übersetzen über BWACHText; Quelltexte englisch, Deutsch in locale.json.
        BWACHText::setzen(BWACHText::mitMerkliste(function (string $text): string {
            return $this->Translate($text);
        }));
    }

    private const LIBRARY_GUID = '{68C5991B-8E85-23AC-C254-B446AF035AF8}';

    // Formular-Konvention (SUITE.md "Einheitliche Formular-Optik", NEWS_VERSIONS-Muster)
    /**
     * Änderungen je Version für das Panel „Neu“ (Version => Zeilen). Bewusst leer: Das Panel erscheint erst nach der ersten
     * veröffentlichten Version, ab dann werden hier die Änderungen seitdem gepflegt (Verbund-Konvention). Bis dahin steht
     * die Entwicklungsgeschichte nur im CHANGELOG.md.
     */
    private const NEWS_VERSIONS = [];
    // Push-Ziele (SUITE.md Stolperstein 22): klassisches WebFront UND Kachel-Visualisierung, je eigene Funktion
    private const WEBFRONT_GUID = '{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}';
    private const KACHEL_GUID   = '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}';
    /** So lange bleibt „Rückgängig“ nach einer Quittierung stehen (eine versehentlich gedrückte Schaltfläche fällt schnell auf). */
    private const UNDO_SECONDS  = 86400;
    private const MAX_DIARY_ROWS = 50;

    private const REPO_URL    = 'https://github.com/DG65/Batteriewaechter';
    /** Stand des Rückmeldungs-Hinweises: Wer einen älteren weggeklickt hat, sieht den mit dem Forum-Thread einmal wieder. */
    private const FORUM_HINT_REV = 'thread-144608';
    private const FORUM_URL   = 'https://community.symcon.de/t/beta-modul-dg65-toolkit-batteriewaechter-batterien-aller-funkgeraete-im-blick-mit-prognose-einkaufsliste-tauschrunde-und-meldungen/144608';
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
        $this->RegisterPropertyInteger('UnreachMinutes', 30);
        $this->RegisterPropertyBoolean('TileAllowAck', true);
        $this->RegisterPropertyBoolean('LightTheme', false);
        $this->RegisterPropertyString('FormLayout', '[]');
        $this->RegisterPropertyBoolean('NameSearch', false);
        $this->RegisterPropertyString('ManualVariables', '[]');
        $this->RegisterPropertyString('IgnoredVariables', '[]');
        $this->RegisterPropertyString('OwnCells', '[]');
        $this->RegisterPropertyInteger('ConfirmMinutes', 0);
        $this->RegisterPropertyString('DeviceSettings', '[]');
        $this->RegisterPropertyString('GroupRules', '[]');
        $this->RegisterPropertyString('ShopLink', '');
        $this->RegisterPropertyString('Stock', '[]');
        $this->RegisterPropertyInteger('PreventiveMonths', 0);
        $this->RegisterPropertyString('DeviceSortBy', 'name');
        $this->RegisterPropertyString('DeviceSortDir', 'ascending');

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
        // Wechselerkennung: 2 = Prozentsprung und Flag (Standard), 1 = nur Prozentsprung (Flag flackert z. B. bei HomeMatic), 0 = aus
        $this->RegisterPropertyInteger('ReplaceDetect', 2);

        $this->RegisterAttributeString('Found', '');
        $this->RegisterAttributeInteger('LastDiscoveryTs', 0);
        $this->RegisterAttributeInteger('LastCheckTs', 0);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeBoolean('ForumHintGone', false);   // bis 0.11.3: nur noch aus Kompatibilität angelegt
        $this->RegisterAttributeString('ForumHintSeen', '');

        $this->RegisterTimer('Tick', 0, 'BWACH_Tick($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Debounce', 0, 'BWACH_Debounced($_IPS[\'TARGET\']);');

        $this->RegisterMessage(0, self::KERNEL_MSG);
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->initCells();
        $this->SetVisualizationType(1);

        $this->MaintainVariable('Total', BWACHText::t('Devices monitored'), VARIABLETYPE_INTEGER, '', 10, true);
        $this->MaintainVariable('Empty', BWACHText::t('Battery empty'), VARIABLETYPE_INTEGER, '', 20, true);
        $this->MaintainVariable('Low', BWACHText::t('Battery low'), VARIABLETYPE_INTEGER, '', 30, true);
        $this->MaintainVariable('Silent', BWACHText::t('Radio silence'), VARIABLETYPE_INTEGER, '', 40, true);
        $this->MaintainVariable('Action', BWACHText::t('Action needed (count)'), VARIABLETYPE_INTEGER, '', 45, true);
        $this->MaintainVariable('Check', BWACHText::t('Check data'), VARIABLETYPE_INTEGER, '', 50, true);
        $this->MaintainVariable('Unknown', BWACHText::t('Status unknown'), VARIABLETYPE_INTEGER, '', 60, true);
        $this->MaintainVariable('StatusLine', BWACHText::t('Summary'), VARIABLETYPE_STRING, '', 70, true);
        $this->MaintainVariable('TableProblems', BWACHText::t('Action needed'), VARIABLETYPE_STRING, '~HTMLBox', 80, true);
        $this->MaintainVariable('TableAll', BWACHText::t('All devices'), VARIABLETYPE_STRING, '~HTMLBox', 90, true);
        $this->MaintainVariable('Soon', BWACHText::t('Empty soon'), VARIABLETYPE_INTEGER, '', 55, true);
        $this->MaintainVariable('TableDiary', BWACHText::t('Battery log'), VARIABLETYPE_STRING, '~HTMLBox', 100, true);
        $this->MaintainVariable('TableShopping', BWACHText::t('Shopping and replacement round'), VARIABLETYPE_STRING, '~HTMLBox', 110, true);
        $this->MaintainVariable('TableStats', BWACHText::t('Lifetime and discharge'), VARIABLETYPE_STRING, '~HTMLBox', 120, true);
        // Zustandsspeicher als versteckte Variablen (wie Schein): Attribute gehen beim Neu-Registrieren des
        // Moduls verloren (SUITE.md Stolperstein 5), Variablen bleiben.
        foreach (['NotifyState' => 'Notification state', 'Diary' => 'Log data', 'LastSeen' => 'Last seen', 'Retired' => 'Out of service', 'Meta' => 'Miscellaneous', 'History' => 'History', 'LifeObs' => 'Reporting behavior', 'Poll' => 'Queries', 'FcLog' => 'Forecast log', 'Undo' => 'Undo history'] as $ident => $name) {
            $existed = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            $this->MaintainVariable($ident, BWACHText::t($name), VARIABLETYPE_STRING, '', 200, true);
            if ($existed === false) {
                $id = IPS_GetObjectIDByIdent($ident, $this->InstanceID);
                if ($id !== false) {
                    IPS_SetHidden($id, true);
                }
            }
        }

        $this->notifyReset();   // geänderte Einstellungen: sofort wieder versuchen
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
            'ignored'         => $this->ignoredVariables(),
        ]);
        // Symcon 9.1: „Matter Composite Device“ ersetzt die Instanzen je Endpunkt. Gibt es für einen Knoten beides,
        // zählt nur die Composite-Instanz; wer umgestellt hat, behält Einstellungen, Tagebuch und Verlauf.
        $raw    = $result;
        $result = BWACHLogik::matterPreferComposite($result, self::MATTER_COMPOSITE);
        $this->adoptMatterKeys([$this->found() ?? [], $raw], $result);
        $this->WriteAttributeString('Found', json_encode($result));
        $this->WriteAttributeInteger('LastDiscoveryTs', $this->now());
        $this->syncMessages($result);

        $line = $this->discoveryLine($result);
        $this->UpdateFormField('DiscoveryStatus', 'caption', $line);
        $this->Check();
        return $line;
    }

    /**
     * Übernahme nach der Umstellung auf „Matter Composite Device“: Ein Gerät je Endpunkt (alte Instanz, noch vorhanden
     * oder schon gelöscht) und ein Composite-Gerät desselben Knotens sind dasselbe Gerät. Die Zustände (Tagebuch, Verlauf,
     * Meldezustand …) ziehen auf den neuen Schlüssel um; die Geräte-Einstellungen sind eine Eigenschaft und bleiben dort,
     * wo sie stehen, gelten über die Zuordnung in „Meta“ aber für das neue Gerät, bis der Nutzer sie übernimmt.
     */
    private function adoptMatterKeys(array $sources, array $new): void
    {
        $known = $this->loadJson('Meta')['adopted'] ?? [];
        $map   = array_diff_assoc(BWACHLogik::matterAdoption($sources, $new, self::MATTER_COMPOSITE), array_map('strval', $known));
        if (!$map) {
            return;
        }
        foreach (['LastSeen', 'History', 'LifeObs', 'Poll', 'NotifyState', 'Retired', 'FcLog'] as $ident) {
            $data = $this->loadJson($ident);
            $hit  = false;
            foreach ($map as $newKey => $oldKey) {
                if (array_key_exists($oldKey, $data) && !array_key_exists($newKey, $data)) {
                    $data[$newKey] = $data[$oldKey];
                    unset($data[$oldKey]);
                    $hit = true;
                }
            }
            if ($hit) {
                $this->saveJson($ident, $data);
            }
        }
        $diary = $this->loadJson('Diary');
        $hit   = false;
        foreach ($diary as $i => $e) {
            $k = array_search((string)($e['key'] ?? ''), array_map('strval', $map), true);
            if ($k !== false) {
                $diary[$i]['key'] = (string)$k;
                $hit = true;
            }
        }
        if ($hit) {
            $this->saveJson('Diary', $diary);
        }
        $meta = $this->loadJson('Meta');
        $meta['adopted'] = $map + ($meta['adopted'] ?? []);   // + statt array_merge: Instanz-IDs sind Zahlenschlüssel
        $this->saveJson('Meta', $meta);
        $this->LogMessage(BWACHText::t('Matter composite devices: settings and history of %s device(s) taken over from the deleted instances per endpoint.', count($map)), KL_MESSAGE);
    }

    /** Steht in der gemerkten Geräteliste eine Instanz, die es nicht mehr gibt? */
    private function hasMissingInstance(array $found): bool
    {
        foreach ($found['devices'] ?? [] as $key => $d) {
            $id = (int)($d['parent'] ?? $key);
            if ($id > 0 && IPS_ObjectExists($id) === false) {
                return true;
            }
        }
        return false;
    }

    /** Bewertet alle gefundenen Geräte und schreibt Kennzahlen und Tabellen. */
    public function Check(): string
    {
        $found = $this->found();
        if ($found === null) {
            return BWACHText::t('ℹ️ Not searched yet — first click "Search again now".');
        }
        // Eine gemerkte Instanz ist verschwunden (gelöscht, ersetzt, z. B. Matter Composite ab Symcon 9.1): neu suchen,
        // höchstens alle 10 Minuten, damit eine dauerhaft fehlende Instanz keine Suchschleife auslöst
        if ($this->now() - $this->ReadAttributeInteger('LastDiscoveryTs') > 600 && $this->hasMissingInstance($found)) {
            return $this->Search();
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
        $this->setIntIfChanged('Action', $sum['action']);
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
        $this->UpdateFormField('TimerStatus', 'caption', $this->timerLine());
        return $line;
    }

    /** Zustand des Tick-Timers für Zustandspanel und Diagnose, direkt aus Symcon gelesen. */
    private function timerLine(): string
    {
        $timer = null;
        foreach (IPS_GetTimerList() as $id) {
            $t = IPS_GetTimer($id);
            if ((int)$t['InstanceID'] === $this->InstanceID && $t['Name'] === 'Tick') {
                $timer = [
                    'interval' => (int)$t['Interval'],
                    'lastRun'  => (int)$t['LastRun'],
                    'nextRun'  => (int)$t['NextRun'],
                    'running'  => (bool)$t['Running'],
                ];
                break;
            }
        }
        return BWACHLogik::timerLine($timer, $this->now());
    }

    /** Trockenlauf: zeigt, was gefunden, ausgeschlossen und nur vorgeschlagen wird. */
    public function Preview(): string
    {
        $found = $this->found();
        if ($found === null) {
            return BWACHText::t('ℹ️ Not searched yet — first click "Search again now".');
        }
        $out = [$this->discoveryLine($found), ''];

        $out[] = BWACHText::t('DEVICES (%s):', count($found['devices']));
        $n = 0;
        foreach ($found['devices'] as $id => $d) {
            if (++$n > self::MAX_PREVIEW_ROWS) {
                $out[] = BWACHText::t('  … and %s more', count($found['devices']) - self::MAX_PREVIEW_ROWS);
                break;
            }
            $parts = [];
            foreach ($d['signals'] as $kind => $list) {
                $labels = [];
                foreach ($list as $s) {
                    $labels[] = $s['unverified'] ? BWACHText::t('#%s (%s, according to documentation, untested)', $s['vid'], BWACHLogik::basisText((string)$s['basis'])) : '#' . $s['vid'] . ' (' . BWACHLogik::basisText((string)$s['basis']) . ')';
                }
                $parts[] = $this->kindLabel($kind) . ' ' . implode(', ', $labels);
            }
            $out[] = BWACHText::t('  • %s [%s, key %s]: %s', $d['name'], $d['module'] !== '' ? $d['module'] : BWACHText::t('Variable'), $id, implode('; ', $parts));
        }

        $out[] = '';
        $out[] = BWACHText::t('EXCLUDED (%s):', count($found['excluded']));
        $byReason = [];
        foreach ($found['excluded'] as $e) {
            $byReason[$e['reason']][] = $e['name'];
        }
        foreach ($byReason as $reason => $names) {
            $out[] = BWACHText::t('  • %s× %s — e.g. %s', count($names), $reason, implode(', ', array_slice(array_unique($names), 0, 3)));
        }
        if (!$found['excluded']) {
            $out[] = BWACHText::t('  (none)');
        }

        $out[] = '';
        $out[] = $this->ReadPropertyBoolean('NameSearch')
            ? BWACHText::t('ONLY SUGGESTED BY NAME (%s)', count($found['suggestions']))
            : BWACHText::t('ONLY SUGGESTED BY NAME (%s) — not included while "Also search by name" is off:', count($found['suggestions']));
        foreach (array_slice($found['suggestions'], 0, self::MAX_PREVIEW_ROWS) as $s) {
            $out[] = '  • #' . $s['vid'] . ' ' . $s['name'] . ' (' . $this->kindLabel($s['kind']) . ')';
        }
        if (!$found['suggestions']) {
            $out[] = BWACHText::t('  (none)');
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
        $this->WriteAttributeString('ForumHintSeen', self::FORUM_HINT_REV);
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
        $fc   = $this->loadJson('FcLog');
        $h0   = $hist;
        $o0   = $obs;
        $f0   = $fc;
        foreach ($rows as $row) {
            $key = $row['id'];
            $fc[$key] = BWACHPrognose::accLog($fc[$key] ?? [], $now, $row['r']['percent'], $row['forecast']['slope'], (string)$row['forecast']['confidence']);
            if (!$fc[$key]) {
                unset($fc[$key]);
            }
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
        if ($fc !== $f0) {
            $this->saveJson('FcLog', $fc);
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

    /** Vorrat zu Hause: Bezeichnung (z. B. „AAA“) => Stück, aus der Liste „Vorrat“. */
    private function stockByLabel(): array
    {
        $this->initCells();
        $rows = json_decode((string)$this->ReadPropertyString('Stock'), true);
        $out  = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $label = BWACHZelle::shopLabel((string)($r['Cell'] ?? ''));
                if ($label !== null) {
                    $out[$label] = ($out[$label] ?? 0) + max(0, min(9999, (int)($r['Count'] ?? 0)));
                }
            }
        }
        return $out;
    }

    /** Genauigkeit der Prognose als Satz. */
    private function accuracyText(array $a): string
    {
        if ($a['n'] === 0) {
            return BWACHText::t('No replacement with comparison yet: when a replacement is detected, the monitor compares what the forecast said 14 to 150 days earlier with the actual level. This needs weeks to months of history.');
        }
        $dir = abs($a['bias']) < 1 ? BWACHText::t('with no clear direction') : ($a['bias'] > 0 ? BWACHText::t('the batteries lasted longer than predicted on average') : BWACHText::t('the batteries discharged faster than predicted on average'));
        return $a['n'] === 1
            ? BWACHText::t('%s replacement compared: off by %s percentage points on average, %s.', $a['n'], BWACHLogik::num($a['meanAbs']), $dir)
            : BWACHText::t('%s replacements compared: off by %s percentage points on average, %s.', $a['n'], BWACHLogik::num($a['meanAbs']), $dir);
    }

    /** Einkaufsliste, Tauschrunde und Lebensdauerstatistik aus den aktuellen Zeilen. */
    private function views(array $rows, int $now): array
    {
        $this->initCells();
        $horizon = $this->ReadPropertyInteger('ForecastHorizonDays');
        $items   = [];
        $cellOf  = [];
        $modOf   = [];
        $cellN   = [];
        foreach ($rows as $row) {
            $f = $row['forecast'];
            $items[] = [
                'id'     => $row['id'],
                'name'   => $row['name'],
                'place'  => $row['place'],
                'cell'   => $row['cell'],
                'cells'  => $row['cells'],
                'status' => $row['r']['status'],
                'preventive' => !empty($row['r']['preventive']),
                'days'   => ($f['days'] !== null && $f['confidence'] !== 'keine') ? $f['days'] : null,
                'text'   => !empty($row['r']['preventive']) && $row['r']['status'] === BWACHLogik::ST_OK ? BWACHText::t('Preventive replacement due') : ($row['r']['status'] === BWACHLogik::ST_OK ? BWACHPrognose::forecastText($f) : implode('; ', array_slice($row['r']['reasons'], 0, 1))),
            ];
            $cellOf[$row['id']] = BWACHZelle::isKnown($row['cell']) ? BWACHZelle::shopLabel($row['cell']) : '';
            $modOf[$row['id']]  = $row['module'];
            $cellN[$row['id']]  = ['label' => (string)$cellOf[$row['id']], 'cells' => (int)$row['cells']];
        }
        $life = BWACHPrognose::lifetimes($this->loadJson('Diary'));
        $peers = [];
        foreach ($rows as $row) {
            if (isset($row['peer'])) {
                $peers[] = ['name' => $row['name'], 'text' => BWACHText::t('%s× faster than %s comparable devices (%s instead of %s percentage points/day)', BWACHLogik::num($row['peer']['factor']), $row['peer']['n'], BWACHLogik::num($row['peer']['rate']), BWACHLogik::num($row['peer']['median']))];
            }
        }
        $cold = $this->ReadPropertyInteger('OutdoorTempVar') > 0
            ? BWACHPrognose::coldEffect($this->loadJson('History'), (float)$this->ReadPropertyInteger('ColdBelow'), $this->ReadPropertyInteger('ReplaceJumpPercent'))['text']
            : BWACHText::t('Effect of cold: no outdoor temperature variable selected (select one under "Forecast and shopping", then the monitor evaluates whether batteries discharge faster in the cold)');
        return [
            'horizon'  => $horizon,
            'shopping' => BWACHPrognose::shopping($items, $horizon, $this->stockByLabel()),
            'round'    => BWACHPrognose::tauschrunde($items, $horizon, $now),
            'life'     => $life,
            'byCell'   => BWACHPrognose::lifetimeByGroup($life, $cellOf),
            'byModule' => BWACHPrognose::lifetimeByGroup($life, $modOf),
            'changes'  => BWACHPrognose::changeStats($this->loadJson('Diary'), $cellN, $now),
            'items'    => $items,
            'peers'    => $peers,
            'cold'     => $cold,
            'accuracy' => BWACHPrognose::accSummary($this->loadJson('Diary')),
        ];
    }

    private function renderShopping(array $v): string
    {
        $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $css = '<style>.bw{border-collapse:collapse;width:100%;font-size:14px}.bw th,.bw td{padding:4px 10px;text-align:left;border-bottom:1px solid rgba(128,128,128,.35)}</style>';
        $out = $css . '<div style="padding:4px 2px"><b>' . BWACHText::t('🛒 Shopping list for the next %s days', (int)$v['horizon']) . '</b></div>';
        $sh = $v['shopping'];
        if (!$sh['need']) {
            return $out . '<div style="padding:8px">' . BWACHText::t('✅ Nothing to buy: no device is low or, according to the forecast, empty soon.') . '</div>';
        }
        $out .= '<div style="padding:4px 2px">' . ($sh['lines'] ? $e(implode(', ', $sh['lines'])) : BWACHText::t('No cell types known.')) . '</div>';
        if ($sh['missing']) {
            $out .= '<div style="padding:4px 2px;opacity:.8">' . BWACHText::t('ℹ️ Cell type missing for: %s (select it in the instance "%s" under "Device settings" in the column "Cell type", then the list includes it).', $e(implode(', ', $sh['missing'])), $e(IPS_GetName($this->InstanceID))) . '</div>';
        }
        $r = $v['round'];
        $cnt  = $r['count'] === 1 ? BWACHText::t('%s device', $r['count']) : BWACHText::t('%s devices', $r['count']);
        $out .= '<div style="padding:10px 2px 4px">' . ($r['until'] !== null ? BWACHText::t('<b>🔧 Replacement round</b> — ideally by %s (%s)', date('d.m.Y', $r['until']), $cnt) : BWACHText::t('<b>🔧 Replacement round</b> (%s)', $cnt)) . '</div>';
        $body = '';
        foreach ($r['places'] as $place => $devs) {
            foreach ($devs as $d) {
                $cell = BWACHZelle::shopLabel((string)$d['cell']);
                $body .= '<tr><td>' . $e($place) . '</td><td>' . $e($d['name']) . '</td><td>' . $e($cell !== null ? max(1, (int)$d['cells']) . '× ' . $cell : '—') . '</td><td>' . $e($d['text']) . '</td></tr>';
            }
        }
        return $out . '<table class="bw"><tr><th>' . BWACHText::t('Location') . '</th><th>' . BWACHText::t('Device') . '</th><th>' . BWACHText::t('Battery') . '</th><th>' . BWACHText::t('Condition') . '</th></tr>' . $body . '</table>';
    }

    private function renderStats(array $v): string
    {
        $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $css = '<style>.bw{border-collapse:collapse;width:100%;font-size:14px}.bw th,.bw td{padding:4px 10px;text-align:left;border-bottom:1px solid rgba(128,128,128,.35)}</style>';
        $out = $css;
        $out .= '<div style="padding:4px 2px"><b>' . BWACHText::t('📈 Days remaining per device') . '</b></div>';
        $body = '';
        $items = $v['items'];
        usort($items, function ($a, $b) { return [$a['days'] === null ? 1 : 0, $a['days'] ?? 0, $a['name']] <=> [$b['days'] === null ? 1 : 0, $b['days'] ?? 0, $b['name']]; });
        foreach ($items as $i) {
            $body .= '<tr><td>' . $e($i['name']) . '</td><td>' . $e($i['place']) . '</td><td>' . $e($i['text']) . '</td></tr>';
        }
        $out .= '<table class="bw"><tr><th>' . BWACHText::t('Device') . '</th><th>' . BWACHText::t('Location') . '</th><th>' . BWACHText::t('Days remaining') . '</th></tr>' . $body . '</table>';

        $out .= '<div style="padding:10px 2px 4px"><b>' . BWACHText::t('👥 Comparison with similar devices') . '</b></div>';
        if ($v['peers']) {
            $body = '';
            foreach ($v['peers'] as $pe) {
                $body .= '<tr><td>' . $e($pe['name']) . '</td><td>' . $e($pe['text']) . '</td></tr>';
            }
            $out .= '<table class="bw"><tr><th>' . BWACHText::t('Conspicuous device') . '</th><th>' . BWACHText::t('Discharge') . '</th></tr>' . $body . '</table>';
        } else {
            $out .= '<div style="padding:4px 2px;opacity:.85">' . BWACHText::t('✅ No device discharges noticeably faster than its group (requires at least 4 devices of the same system and cell type with a known discharge rate).') . '</div>';
        }
        $out .= '<div style="padding:10px 2px 4px"><b>' . BWACHText::t('🎯 Forecast accuracy') . '</b></div><div style="padding:4px 2px;opacity:.9">' . $e($this->accuracyText($v['accuracy'])) . '</div>';
        $out .= '<div style="padding:10px 2px 4px"><b>' . BWACHText::t('🌡 Effect of cold') . '</b></div><div style="padding:4px 2px;opacity:.9">' . $e($v['cold']) . '</div>';
        $out .= '<div style="padding:10px 2px 4px"><b>' . BWACHText::t('🔋 Replaced batteries') . '</b></div>';
        if (!$v['changes']) {
            $out .= '<div style="padding:4px 2px;opacity:.85">' . BWACHText::t('ℹ️ No battery replacement recorded yet.') . '</div>';
        } else {
            $body = '';
            foreach ($v['changes'] as $c) {
                $body .= '<tr><td>' . $e($c['label']) . '</td><td>' . $e(BWACHPrognose::changeStatsText($c)) . '</td></tr>';
            }
            $out .= '<table class="bw"><tr><th>' . BWACHText::t('Period') . '</th><th>' . BWACHText::t('Replaced') . '</th></tr>' . $body . '</table>';
        }
        $out .= '<div style="padding:10px 2px 4px"><b>' . BWACHText::t('⏱ Lifetime (time between two battery replacements)') . '</b></div>';
        if (!$v['life']) {
            return $out . '<div style="padding:8px">' . BWACHText::t('ℹ️ Not enough data yet: each device needs at least two recorded battery replacements.') . '</div>';
        }
        $body = '';
        foreach ($v['life'] as $l) {
            $body .= '<tr><td>' . $e($l['name']) . '</td><td>' . $e(implode(', ', array_map(function ($d) { return BWACHText::t('%s days', BWACHLogik::num($d)); }, $l['days']))) . '</td></tr>';
        }
        $out .= '<table class="bw"><tr><th>' . BWACHText::t('Device') . '</th><th>' . BWACHText::t('Lifetimes') . '</th></tr>' . $body . '</table>';
        foreach (['byCell' => BWACHText::t('Average lifetime per cell type'), 'byModule' => BWACHText::t('Average lifetime per system')] as $k => $title) {
            if (!$v[$k]) {
                continue;
            }
            $body = '';
            foreach ($v[$k] as $g => $s) {
                $body .= '<tr><td>' . $e($g) . '</td><td>' . BWACHText::t('%s days', BWACHLogik::num($s['median'])) . '</td><td>' . ($s['devices'] === 1 ? BWACHText::t('%s intervals, %s device', $s['n'], $s['devices']) : BWACHText::t('%s intervals, %s devices', $s['n'], $s['devices'])) . '</td></tr>';
            }
            $out .= '<div style="padding:10px 2px 4px"><b>' . $title . '</b></div><table class="bw"><tr><th>' . ($k === 'byCell' ? BWACHText::t('Cell') : BWACHText::t('System')) . '</th><th>' . BWACHText::t('Median') . '</th><th>' . BWACHText::t('Basis') . '</th></tr>' . $body . '</table>';
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
        return '<script>var BWACH_TEXTE = ' . json_encode($this->tileTexts(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) . ';</script>'
            . $html . '<script>handleMessage(' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) . ');</script>';
    }

    /** Wörterbuch der fest in module.html stehenden Kacheltexte: Englisch => übersetzt (die Kachel schlägt per T() nach). */
    private function tileTexts(): array
    {
        $out = [];
        foreach ([
            'Action needed',
            'All',
            'empty',
            'low',
            'empty soon',
            'Radio silence',
            'Preventive',
            'Check data',
            'Urgency',
            'Name',
            'Location',
            'Battery level',
            'Age of value',
            'Sign of life',
            'Last replaced',
            'Cell type',
            'System',
            'Battery value reported: %s · Sign of life: %s',
            'Measured voltage %s',
            '(calculated from it: %s, an approximation)',
            'State of charge calculated from the voltage (approximation)',
            'I replaced it',
            'Remind me later',
            'Really out of service?',
            'Out of service',
            'Shopping list copied',
            'Copying is not possible here. The form offers “Shopping list as text”.',
            'Shopping for the next %s days',
            'Stock is sufficient.',
            'Nothing to buy.',
            'No cell types known. Enter the cell type per device: %s.',
            'Search at retailer',
            'Copy',
            'Send',
            'Cell type missing for: %s. Enter it: %s.',
            'Replacement round',
            'device',
            'devices',
            'ideally by %s',
            'No replacement round needed.',
            'Really all %s in %s?',
            'All replaced (%s)',
            'Replaced batteries',
            'Lifetime between two battery replacements',
            'Not enough data yet: each device needs at least two recorded battery replacements.',
            'Days remaining unknown',
            'Days remaining per device',
            'No forecast yet for %s %s: too little history or too coarse a display. Expand the row to see why.',
            'No forecast possible yet.',
            'No battery replacements recorded yet. The monitor detects them itself, or you enter them via “I replaced it”.',
            'detected',
            'entered',
            'added later',
            'Shows only the devices of this location',
            'Applies only to the device list; shopping, statistics and log show all locations',
            'All locations',
            '(no location)',
            'no location',
            'Shopping',
            'Statistics',
            'Log',
            'Search (name, location, system)',
            'No devices are monitored yet. Press “Search again now” in the instance.',
            'No device matches “%s”.',
            'All OK — no device needs attention.',
            'No device in this view.',
            'Sort:',
            'By location',
            'Undo: %s',
            'Hide without undoing anything',
            '%s devices monitored · as of %s',
            '%s device monitored · as of %s',
        ] as $en) {
            $out[$en] = BWACHText::t($en);
        }
        return $out;
    }

    /** Rückkanal aus der Kachel: 'ack' (Quittieren) und 'refresh'. */
    public function RequestAction($Ident, $Value)
    {
        $now  = $this->now();
        $meta = $this->loadJson('Meta');
        if ($Ident === 'ack') {
            $d = json_decode((string)$Value, true);
            if (!$this->ReadPropertyBoolean('TileAllowAck')) {
                $msg = BWACHText::t('⛔ Acknowledging from the tile is switched off in the settings.');
            } elseif (is_array($d) && isset($d['key'], $d['action'])) {
                $msg = $this->Acknowledge((string)$d['key'], (string)$d['action']);
            } else {
                $msg = BWACHText::t('⛔ Invalid request from the tile.');
            }
            $meta['ack'] = ['m' => $msg, 't' => $now];
            $this->saveJson('Meta', $meta);
        } elseif ($Ident === 'ack_place') {
            $d = json_decode((string)$Value, true);
            if (!$this->ReadPropertyBoolean('TileAllowAck')) {
                $msg = BWACHText::t('⛔ Acknowledging from the tile is switched off in the settings.');
            } elseif (is_array($d) && isset($d['place'])) {
                $msg = $this->AcknowledgePlace((string)$d['place']);
            } else {
                $msg = BWACHText::t('⛔ Invalid request from the tile.');
            }
            $meta['ack'] = ['m' => $msg, 't' => $now];
            $this->saveJson('Meta', $meta);
        } elseif ($Ident === 'undo') {
            $meta['ack'] = ['m' => $this->ReadPropertyBoolean('TileAllowAck') ? $this->UndoAcknowledge() : BWACHText::t('⛔ Acknowledging from the tile is switched off in the settings.'), 't' => $now];
            $this->saveJson('Meta', $meta);
        } elseif ($Ident === 'undo_dismiss') {
            $this->saveJson('Undo', []);   // „Rückgängig“ ausblenden, ohne etwas zurückzunehmen
        } elseif ($Ident === 'shop_send') {
            $meta['ack'] = ['m' => $this->SendShopping(), 't' => $now];
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
            return $row['cellHint'] !== '' ? BWACHText::t('Device names as replacement: %s (cell type ambiguous, please select under "Device settings")', $row['cellHint']) : '';
        }
        $text = ($row['cells'] > 1 ? $row['cells'] . '× ' : '') . BWACHZelle::label($row['cell']);
        if (!$row['cellAuto']) {
            return $text;
        }
        // AA/AAA nennen nur die Bauform, ob Alkali oder Akku, weiß das Gerät nicht
        $form = in_array($row['cell'], ['aa_alkali', 'aaa_alkali', 'c_alkali', 'd_alkali'], true);
        return $form
            ? BWACHText::t('%s — according to the device ("%s"), only the form factor: whether alkaline or rechargeable is unknown', $text, $row['cellHint'])
            : BWACHText::t('%s — according to the device ("%s")', $text, $row['cellHint']);
    }

    /** „Letzter Wechsel: 12.10.2026 (vor 3 Tagen), die Batterie davor hielt 14 Monate“ aus den Tagebucheinträgen; leer ohne Eintrag. */
    private function changeText(?array $c, int $now): string
    {
        if ($c === null) {
            return '';
        }
        $text = BWACHText::t('Last replacement: %s (%s ago)', date('d.m.Y', (int)$c['last']), BWACHLogik::daysDat(max(0, $now - (int)$c['last'])));
        if ($c['prev'] !== null && (int)$c['last'] > (int)$c['prev']) {
            $text = BWACHText::t('%s · the previous battery lasted %s', $text, BWACHLogik::daysDat((int)$c['last'] - (int)$c['prev']));
        }
        return $text;
    }

    private function tilePayload(array $rows, array $sum, int $now, array $notes, ?array $views = null): string
    {
        $this->initCells();
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
                'valueAgeSec'  => $r['valueAge'],
                'lifeAgeSec'   => $r['lifeAge'],
                'cellKey'      => BWACHZelle::isKnown($row['cell']) ? (string)BWACHZelle::shopLabel($row['cell']) : '',
                'valueAgeText' => $r['valueAge'] === null ? '—' : BWACHText::t('%s ago', BWACHLogik::daysDat($r['valueAge'])),
                'lifeText'     => $r['lifeAge'] === null ? '—' : BWACHText::t('%s ago', BWACHLogik::daysDat($r['lifeAge'])),
                'headline'     => $this->headline($r),
                'critical'     => (bool)$r['critical'],
                'quality'      => $r['quality'],
                'urgency'      => $r['urgency'],
                'reasons'      => $r['reasons'],
                'note'         => $notes[$row['id']] ?? '',
                'soon'         => !empty($r['soon']),
                'preventive'   => !empty($r['preventive']),
                'forecastText' => $r['percent'] === null ? '' : BWACHPrognose::forecastText($row['forecast']),
                'cellText'     => $this->cellText($row),
                'derived'      => !empty($r['derived']),
                'signalText'   => $row['signal']['text'] ?? '',
                'pollText'     => $row['poll'],
                'idText'       => $this->idText($row),
                'changeText'   => $this->changeText($row['change'] ?? null, $now),
                'changeAgeSec' => isset($row['change']['last']) ? max(0, $now - (int)$row['change']['last']) : null,
            ];
        }
        $diary = [];
        foreach (array_slice(array_reverse($this->loadJson('Diary')), 0, 25) as $d) {
            $diary[] = ['when' => date('d.m.Y', (int)$d['t']), 'name' => (string)$d['name'], 'type' => $d['type'] === 'erkannt' ? 'erkannt' : ($d['type'] === 'nachgetragen' ? 'nachgetragen' : 'eingetragen'), 'note' => (string)$d['note']];
        }
        $views   = $views ?? $this->views($rows, $now);
        $meta    = $this->loadJson('Meta');
        $message = (isset($meta['ack']) && $now - (int)$meta['ack']['t'] <= 30) ? (string)$meta['ack']['m'] : '';
        return json_encode([
            'summary'  => $sum,
            'devices'  => $devices,
            'diary'    => $diary,
            'allowAck' => $this->ReadPropertyBoolean('TileAllowAck'),
            'light'    => $this->ReadPropertyBoolean('LightTheme'),
            'shopping' => $this->tileShopping($views),
            'asOf'     => BWACHText::t("%s", date('H:i', $now)),
            'message'  => $message,
            'undoText' => $this->undoLabel(),
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
            return BWACHText::t('ℹ️ Please select a device first.');
        }
        if (!in_array($action, [BWACHMeldung::ACK_REPLACED, BWACHMeldung::ACK_SNOOZE, BWACHMeldung::ACK_RETIRED], true)) {
            return BWACHText::t('⛔ Unknown action "%s".', $action);
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
            return BWACHText::t('⛔ Device "%s" not found (perhaps already out of service or excluded).', $key);
        }
        $undo = [];
        $msg  = $this->ackApply((string)$key, $action, $name, $undo);
        $this->undoStore($undo, $action === BWACHMeldung::ACK_REPLACED ? BWACHText::t('Battery replacement for "%s"', $name) : ($action === BWACHMeldung::ACK_SNOOZE ? BWACHText::t('Postponed "%s"', $name) : BWACHText::t('Out of service "%s"', $name)));
        $this->Check();
        $this->UpdateFormField('AckStatus', 'caption', $msg);
        $this->UpdateFormField('DiaryLine', 'caption', $this->diaryLine());
        $this->refreshRetiredFields($found);
        return $msg;
    }

    /** Führt eine Quittierung aus (Tagebuch, Außer-Betrieb-Liste, Meldezustand), ohne neu zu bewerten. */
    private function ackApply(string $key, string $action, string $name, ?array &$undo = null): string
    {
        $now   = $this->now();
        $state = $this->loadJson('NotifyState');
        $item  = ['key' => $key, 'name' => $name, 'action' => $action, 'state' => $state[$key] ?? null, 'retired' => $this->loadJson('Retired')[$key] ?? null, 'diaryT' => null];
        if ($action === BWACHMeldung::ACK_REPLACED) {
            $before = $this->loadJson('Diary');
            $diary  = BWACHMeldung::diaryAdd($before, ['t' => $now, 'key' => $key, 'name' => $name, 'type' => 'manuell', 'note' => BWACHText::t('entered by hand')]);
            if (count($diary) > count($before)) {
                $item['diaryT'] = $now;
            }
            $this->saveJson('Diary', $diary);
            $msg = BWACHText::t('✅ Battery replacement for "%s" entered. The monitor waits %s days until the device reports the new level.', $name, BWACHMeldung::REPLACE_GRACE_DAYS);
        } elseif ($action === BWACHMeldung::ACK_SNOOZE) {
            $days = $this->ReadPropertyInteger('SnoozeDays');
            $msg  = BWACHText::t('✅ "%s" postponed until %s. After that the monitor reports again if the finding persists.', $name, date('d.m.Y', $now + max(1, $days) * 86400));
        } else {
            $retired = $this->loadJson('Retired');
            $retired[$key] = $now;
            $this->saveJson('Retired', $retired);
            $msg = BWACHText::t('✅ "%s" is out of service and is no longer monitored. To put it back into service: under "Device settings" or "Acknowledge".', $name);
        }
        $this->saveJson('NotifyState', BWACHMeldung::acknowledge($state, $key, $action, $now, $this->ReadPropertyInteger('SnoozeDays')));
        if ($undo !== null) {
            $undo[] = $item;
        }
        return $msg;
    }

    /** Merkt sich die letzte Quittierung (oder Tauschrunde eines Ortes), damit sie sich zurücknehmen lässt. */
    private function undoStore(array $items, string $label): void
    {
        $this->saveJson('Undo', $items ? ['t' => $this->now(), 'label' => $label, 'items' => $items] : []);
    }

    /** Text für die Schaltfläche „Rückgängig“ (leer, wenn es nichts zurückzunehmen gibt). */
    private function undoLabel(): string
    {
        $u = $this->loadJson('Undo');
        return (!empty($u['items']) && $this->now() - (int)$u['t'] <= self::UNDO_SECONDS) ? (string)$u['label'] : '';
    }

    /**
     * Nimmt die letzte Quittierung zurück: Meldezustand und „Außer Betrieb“ werden auf den Stand davor gesetzt,
     * ein dabei angelegter Tagebucheintrag wird entfernt. Nur die jeweils letzte Quittierung (eine Tauschrunde
     * zählt als eine), bis zu 24 Stunden lang; ein Wechsel von Hand oder das Löschen erkannter Wechsel hebt es auf.
     */
    public function UndoAcknowledge(): string
    {
        $u = $this->loadJson('Undo');
        if (empty($u['items']) || $this->now() - (int)$u['t'] > self::UNDO_SECONDS) {
            return BWACHText::t('ℹ️ There is nothing to undo.');
        }
        $state   = $this->loadJson('NotifyState');
        $retired = $this->loadJson('Retired');
        $diary   = $this->loadJson('Diary');
        foreach ($u['items'] as $it) {
            $k = (string)$it['key'];
            if ($it['state'] === null) {
                unset($state[$k]);
            } else {
                $state[$k] = $it['state'];
            }
            if ($it['retired'] === null) {
                unset($retired[$k]);
            } else {
                $retired[$k] = $it['retired'];
            }
            if ($it['diaryT'] !== null) {
                $diary = array_values(array_filter($diary, function ($e) use ($k, $it) {
                    return !((string)$e['key'] === $k && (int)$e['t'] === (int)$it['diaryT'] && (string)$e['type'] === 'manuell');
                }));
            }
        }
        $this->saveJson('NotifyState', $state);
        $this->saveJson('Retired', $retired);
        $this->saveJson('Diary', $diary);
        $this->saveJson('Undo', []);
        $n   = count($u['items']);
        $this->Check();
        $msg = $n > 1 ? BWACHText::t('↩️ Undone: %s (%s devices).', $u['label'], $n) : BWACHText::t('↩️ Undone: %s.', $u['label']);
        $this->UpdateFormField('AckStatus', 'caption', $msg);
        $this->UpdateFormField('DiaryLine', 'caption', $this->diaryLine());
        $this->refreshRetiredFields($this->found());
        return $msg;
    }

    /**
     * Trägt alle Geräte eines Ortes, die in der Tauschrunde stehen, als getauscht ein (nach einer Runde durch den Flur).
     * Der Wächter bewertet erst am Ende neu.
     */
    public function AcknowledgePlace(string $place): string
    {
        $found = $this->found();
        if ($found === null) {
            return BWACHText::t('ℹ️ Not searched yet: first click "Search again now".');
        }
        // Gleicher Schlüssel wie BWACHPrognose::tauschrunde (dort übersetzt), sonst findet die englische Oberfläche nichts.
        $place = $place === '' ? BWACHText::t('no location') : $place;
        $now   = $this->now();
        $v     = $this->views($this->evaluateAll($found), $now);
        $devs  = $v['round']['places'][$place] ?? [];
        if (!$devs) {
            return BWACHText::t('ℹ️ Nothing is in the replacement round for "%s".', $place);
        }
        $n = 0;
        $undo = [];
        foreach ($devs as $d) {
            $this->ackApply((string)$d['id'], BWACHMeldung::ACK_REPLACED, (string)$d['name'], $undo);
            $n++;
        }
        $this->undoStore($undo, BWACHText::t('Everything replaced in "%s"', $place));
        $this->Check();
        $msg = $n === 1
            ? BWACHText::t('✅ %s device in "%s" entered as replaced. The monitor waits %s days until the devices report the new level.', $n, $place, BWACHMeldung::REPLACE_GRACE_DAYS)
            : BWACHText::t('✅ %s devices in "%s" entered as replaced. The monitor waits %s days until the devices report the new level.', $n, $place, BWACHMeldung::REPLACE_GRACE_DAYS);
        $this->UpdateFormField('AckStatus', 'caption', $msg);
        $this->UpdateFormField('DiaryLine', 'caption', $this->diaryLine());
        return $msg;
    }

    /**
     * Trägt einen Batteriewechsel mit Datum nach (z. B. einen, der vor dem Wächter stattfand). Er geht ins
     * Tagebuch und damit in Lebensdauer und Vorsorge; Meldungen und Wartezeiten bleiben unberührt.
     */
    public function AddReplacement(string $key, string $date): string
    {
        if ($key === '') {
            return BWACHText::t('ℹ️ Please select a device first.');
        }
        $now = $this->now();
        $t   = BWACHMeldung::parseDate($date, $now);
        if ($t === null) {
            return BWACHText::t('⛔ Date unreadable or in the future: please enter DD.MM.YYYY (e.g. %s).', date('d.m.Y', $now - 86400 * 30));
        }
        $found = $this->found();
        if ($found === null || !isset($found['devices'][$key])) {
            return BWACHText::t('⛔ Device "%s" not found.', $key);
        }
        $name = $key;
        foreach ($this->evaluateAll($found) as $r) {
            if ($r['id'] === $key) {
                $name = $r['name'];
                break;
            }
        }
        $diary = $this->loadJson('Diary');
        $new   = BWACHMeldung::diaryAdd($diary, ['t' => $t, 'key' => $key, 'name' => $name, 'type' => 'nachgetragen', 'note' => BWACHText::t('added later by hand')]);
        if (count($new) === count($diary)) {
            return BWACHText::t('ℹ️ There is already an entry for "%s" within %s days of this date. Nothing changed.', $name, BWACHMeldung::DIARY_DEDUPE_DAYS);
        }
        usort($new, function ($a, $b) { return (int)$a['t'] <=> (int)$b['t']; });
        $this->saveJson('Diary', $new);
        $this->saveJson('Undo', []);   // das letzte „Rückgängig“ gälte sonst für einen Stand, den es nicht mehr gibt
        $this->Check();
        $msg = BWACHText::t('✅ Replacement for "%s" on %s added later.', $name, date('d.m.Y', $t));
        $this->UpdateFormField('AckStatus', 'caption', $msg);
        $this->UpdateFormField('DiaryLine', 'caption', $this->diaryLine());
        return $msg;
    }

    /** Diagnose für das Forum: was wurde bei welchem Modul gefunden und was nicht, ohne Gerätenamen und IDs. */
    public function Diagnosis(): string
    {
        $vars   = $this->collectVariables();
        $result = BWACHLogik::classify($vars, [
            'excludedModules' => $this->excludedModules(),
            'nameSearch'      => $this->ReadPropertyBoolean('NameSearch'),
            'manual'          => $this->manualVariables(),
            'ignored'         => $this->ignoredVariables(),
        ]);
        $kernel = function_exists('IPS_GetKernelVersion') ? (string)IPS_GetKernelVersion() : '?';
        return BWACHLogik::diagnosis($vars, $result, $this->installedVersion(), $kernel, $this->excludedModules()) . "\n" . $this->timerLine();
    }

    /** Geräteliste als CSV (Semikolon, UTF-8): zum Kopieren oder Ausdrucken. */
    public function DeviceListCsv(): string
    {
        $found = $this->found();
        if ($found === null) {
            return BWACHText::t('Not searched yet: first click "Search again now".');
        }
        $now   = $this->now();
        $diary = $this->loadJson('Diary');
        $out   = [BWACHLogik::csvRow([BWACHText::t('Name'), BWACHText::t('Location'), BWACHText::t('System'), BWACHText::t('Cell type'), BWACHText::t('Cells'), BWACHText::t('Battery level %'), BWACHText::t('Status'), BWACHText::t('Radio quality'), BWACHText::t('Last replacement'), BWACHText::t('Sign of life, days ago')])];
        $rows  = $this->evaluateAll($found);
        usort($rows, function ($a, $b) { return strcasecmp($a['place'] . "\0" . $a['name'], $b['place'] . "\0" . $b['name']); });
        foreach ($rows as $row) {
            $last = BWACHMeldung::lastReplacement($diary, (string)$row['id']);
            $out[] = BWACHLogik::csvRow([
                $row['name'], $row['place'], $row['module'],
                BWACHZelle::isKnown($row['cell']) ? (string)BWACHZelle::shopLabel($row['cell']) : '',
                BWACHZelle::isKnown($row['cell']) ? (int)$row['cells'] : '',
                $row['r']['percent'] === null ? '' : BWACHLogik::num($row['r']['percent']),
                $row['r']['status'], $row['r']['funk'],
                $last === null ? '' : date('d.m.Y', $last),
                $row['r']['lifeAge'] === null ? '' : (int)floor($row['r']['lifeAge'] / 86400),
            ]);
        }
        return implode("\n", $out);
    }

    /**
     * Die Variablen, die in die Bewertung eines Geräts eingehen, mit Art und Erkennungsgrundlage.
     *
     * @return array Liste von ['variableId','variableName','instanceId','kind','basis']
     */
    private function sourceList(array $signals): array
    {
        $out = [];
        foreach ($signals as $kind => $list) {
            foreach ($list as $s) {
                $vid = (int)$s['vid'];
                if (!IPS_VariableExists($vid)) {
                    continue;
                }
                $out[] = [
                    'variableId'   => $vid,
                    'variableName' => IPS_GetName($vid),
                    'instanceId'   => (int)(IPS_GetObject($vid)['ParentID'] ?? 0),
                    'kind'         => (string)$kind,
                    'basis'        => (string)($s['basis'] ?? ''),
                ];
            }
        }
        usort($out, function ($a, $b) { return $a['variableId'] <=> $b['variableId']; });
        return $out;
    }

    /**
     * Die ausgewerteten Geräte als JSON, für eigene Skripte (z. B. eine tägliche Übersicht per Telegram). Rechnet bei
     * jedem Aufruf neu und speichert nichts. Die Felder bleiben stabil und werden nur erweitert. Ohne Schnittstelle zu
     * anderen Modulen: es ist eine Funktion für den eigenen Gebrauch.
     *
     * @param bool $OnlyProblems nur Geräte mit Handlungsbedarf (dieselben wie in der Tabelle „Handlungsbedarf“)
     */
    public function DeviceListJson(bool $OnlyProblems): string
    {
        $now = $this->now();
        $lowPct  = $this->ReadPropertyInteger('LowPercent');
        $critPct = $this->ReadPropertyInteger('CriticalLowPercent');
        $emptyPct = $this->ReadPropertyInteger('EmptyPercent');
        $out = ['version' => $this->installedVersion(), 'asOf' => date('c', $now), 'asOfTs' => $now, 'searched' => false,
            // 0.18.12: die eingestellten Grenzen, damit ein Diagramm Markierungslinien an denselben Stellen zieht wie die Bewertung
            'thresholds' => ['lowPercent' => $lowPct, 'emptyPercent' => $emptyPct, 'criticalLowPercent' => $critPct, 'soonDays' => $this->ReadPropertyInteger('SoonDays')],
            'devices' => []];
        $found = $this->found();
        if ($found !== null) {
            $out['searched'] = true;
            $rows = $this->evaluateAll($found);
            usort($rows, function ($a, $b) { return strcasecmp($a['place'] . "\0" . $a['name'], $b['place'] . "\0" . $b['name']); });
            foreach ($rows as $row) {
                $r = $row['r'];
                if ($OnlyProblems && $r['urgency'] <= 0) {
                    continue;
                }
                $out['devices'][] = [
                    'key'          => (string)$row['id'],
                    'name'         => $row['name'],
                    'place'        => $row['place'],
                    'module'       => $row['module'],
                    'status'       => $r['status'],
                    'percent'      => $r['percent'] === null ? null : round($r['percent'], 1),
                    'derived'      => !empty($r['derived']),
                    'voltage'      => $r['voltage'],
                    'funk'         => $r['funk'],
                    'unreachable'  => !empty($r['unreachSince']),
                    'unreachSince' => !empty($r['unreachSince']) ? (int)$r['unreachSince'] : null,
                    'critical'     => (bool)$r['critical'],
                    'urgency'      => (int)$r['urgency'],
                    'soon'         => !empty($r['soon']),
                    'finding'      => $this->headline($r),
                    'reasons'      => array_values($r['reasons']),
                    'valueAgeSec'  => $r['valueAge'],
                    'lifeAgeSec'   => $r['lifeAge'],
                    'cell'         => BWACHZelle::isKnown($row['cell']) ? (string)BWACHZelle::shopLabel($row['cell']) : '',
                    'cells'        => BWACHZelle::isKnown($row['cell']) ? (int)$row['cells'] : null,
                    'instance'     => (int)$row['inst'],
                    'members'      => $row['members'],
                    'sources'      => $row['sourceList'],
                    // 0.18.12: die für DIESES Gerät wirksamen Grenzen (kritische Geräte haben eine eigene „schwach“-Grenze)
                    'lowThreshold'   => $r['critical'] ? $critPct : $lowPct,
                    'emptyThreshold' => $emptyPct,
                    // 0.18.12: Prognose aus dem Verlauf seit dem letzten Wechsel (Theil-Sen); ohne Prozentwert gibt es keine
                    'forecast'       => $this->forecastJson($row['forecast'], $r['percent'] !== null),
                ];
            }
        }
        return (string)json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** Prognose eines Geräts für die JSON-Liste: Resttage, Sicherheit, Zeitpunkt „leer“, Steigung, Güte, Text und der ehrliche Grund. */
    private function forecastJson(array $f, bool $hasPercent): array
    {
        if (!$hasPercent) {
            return ['days' => null, 'confidence' => 'unbekannt', 'emptyAt' => null, 'slopePerDay' => null, 'quality' => null, 'text' => '', 'why' => BWACHText::t('no percentage value (the device only reports low/empty)')];
        }
        return [
            'days'        => $f['days'],
            'confidence'  => (string)$f['confidence'],
            'emptyAt'     => $f['emptyAt'],
            'slopePerDay' => $f['slope'],
            'quality'     => $f['r2'] ?? null,
            'text'        => BWACHPrognose::forecastText($f),
            'why'         => (string)$f['why'],
        ];
    }

    /**
     * Übernimmt die Einstellungen einer alten BY_BatterieMonitor-Instanz in die OFFENE Maske. Es wird nichts
     * gespeichert (Store-Review: keine Selbstpersistenz in Buttons): der Nutzer prüft und klickt „Übernehmen“.
     */
    public function ImportOld(): string
    {
        $old = $this->oldInstances();
        if (!$old) {
            return BWACHText::t('ℹ️ No BY_BatterieMonitor instance found — there is nothing to import.');
        }
        $id  = array_key_first($old);
        $cfg = json_decode((string)IPS_GetConfiguration($id), true);
        if (!is_array($cfg)) {
            return BWACHText::t('⛔ The settings of the old instance #%s cannot be read.', $id);
        }
        $res = BWACHLogik::importOldConfig($cfg, function (int $i) { return IPS_InstanceExists($i); });
        foreach ($res['fields'] as $field => $value) {
            $this->UpdateFormField($field, $field === 'PushTargets' ? 'values' : 'value', $value);
        }
        $msg = BWACHText::t('✅ Imported from "%s" (#%s) into the form: %s.', $old[$id], $id, implode(', ', $res['taken']))
            . "\n" . BWACHText::t('Not imported: %s.', implode('; ', $res['skipped']))
            . "\n" . BWACHText::t('The values are now in the form but NOT saved yet: please check them and click "Apply".')
            . (count($old) > 1 ? "\n" . BWACHText::t('Note: There are %s old instances, the first one was imported.', count($old)) : '')
            . "\n" . BWACHText::t('Afterwards the old instance can be removed. The monitor finds the batteries on its own anyway; the old device list is not needed.');
        $this->UpdateFormField('ImportStatus', 'caption', BWACHText::t('ℹ️ Last import from "%s" — still click "Apply" if the values are correct.', $old[$id]));
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
        if ($key === '') {
            return BWACHText::t('ℹ️ Please first select a device from the list "Device out of service".');
        }
        $retired = $this->loadJson('Retired');
        if (!isset($retired[$key])) {
            return BWACHText::t('ℹ️ Device "%s" was not on the "Out of service" list.', $key);
        }
        unset($retired[$key]);
        $this->saveJson('Retired', $retired);
        $this->Check();
        $this->refreshRetiredFields($this->found());
        return BWACHText::t('✅ Device put back into service.');
    }

    /**
     * Löscht alle AUTOMATISCH erkannten Wechsel aus dem Batterietagebuch (Art „erkannt“). Von Hand eingetragene
     * und nachgetragene bleiben. Gedacht, wenn eine frühere Version Spannungsrauschen für Wechsel gehalten hat.
     */
    public function DeleteDetectedChanges(): string
    {
        $diary = $this->loadJson('Diary');
        $keep  = array_values(array_filter($diary, function ($e) { return (string)($e['type'] ?? '') !== 'erkannt'; }));
        $n     = count($diary) - count($keep);
        if ($n === 0) {
            return BWACHText::t('ℹ️ There are no automatically detected replacements in the log.');
        }
        $this->saveJson('Diary', $keep);
        $this->saveJson('Undo', []);
        $this->saveJson('FcLog', []);   // der Vergleich mit der Prognose hing an den gelöschten Wechseln
        $this->Check();
        $msg = $n === 1
            ? BWACHText::t('✅ %s automatically detected replacement deleted. Manually entered ones remain.', $n)
            : BWACHText::t('✅ %s automatically detected replacements deleted. Manually entered ones remain.', $n);
        $this->UpdateFormField('AckStatus', 'caption', $msg);
        $this->UpdateFormField('DiaryLine', 'caption', $this->diaryLine());
        return $msg;
    }

    /** Nimmt alle außer Betrieb gesetzten Geräte wieder auf. */
    public function UnretireAll(): string
    {
        $n = count($this->loadJson('Retired'));
        $this->saveJson('Retired', []);
        $this->Check();
        $msg = $n === 0 ? BWACHText::t('ℹ️ No device was out of service.') : ($n === 1 ? BWACHText::t('✅ %s device put back into service.', $n) : BWACHText::t('✅ %s devices put back into service.', $n));
        $this->UpdateFormField('AckStatus', 'caption', $msg);
        $this->refreshRetiredFields($this->found());
        return $msg;
    }

    /** Schickt eine Testmeldung über die eingestellten Wege und sagt, was ankam. */
    /** Einkaufsliste und Tauschrunde als Text (zum Kopieren; auch für eigene Skripte). */
    public function ShoppingText(): string
    {
        $found = $this->found();
        if ($found === null) {
            return BWACHText::t('Not searched yet: first click "Search again now".');
        }
        $v = $this->views($this->evaluateAll($found), $this->now());
        return BWACHPrognose::shoppingText($v['shopping'], $v['round'], (int)$v['horizon'], $v['round']['until'] === null ? '' : date('d.m.Y', $v['round']['until']));
    }

    /** Schickt die Einkaufsliste jetzt über die eingestellten Wege (Push, E-Mail), unabhängig vom Schalter „Meldungen aktiv“. */
    public function SendShopping(): string
    {
        $channels = ['push' => $this->ReadPropertyBoolean('NotifyPush'), 'mail' => $this->ReadPropertyBoolean('NotifyMail')];
        if (!$channels['push'] && !$channels['mail']) {
            return BWACHText::t('ℹ️ No delivery channel selected (tick push or email under "Notifications").');
        }
        // Die erste Zeile des Textes ist die Überschrift, die schon im Titel steht: nicht doppelt senden
        $parts = explode("\n", $this->ShoppingText(), 2);
        $ok    = $this->deliver(BWACHText::t('🛒 Buy batteries'), $parts[1] ?? $parts[0], 'bell', $channels);
        return $ok > 0 ? BWACHText::t('✅ Shopping list sent') : ($this->lastMailError !== '' ? BWACHText::t('⚠️ The shopping list could not be delivered — %s.', $this->lastMailError) : BWACHText::t('⚠️ The shopping list could not be delivered.'));
    }

    public function SendTest(): string
    {
        $m = ['title' => BWACHText::t('🧪 Battery Monitor test'), 'text' => BWACHText::t('If this notification arrives, the delivery channel works. Not a real battery notification.'), 'sound' => 'bell'];
        $parts = [];
        if ($this->ReadPropertyBoolean('NotifyPush')) {
            $r = $this->sendPush($m['title'], $m['text'], $m['sound']);
            $parts[] = $r['targets'] === 0
                ? BWACHText::t('Push: ⚠️ no push targets found (neither tile visualization nor WebFront)')
                : ($r['ok'] === $r['targets'] ? BWACHText::t('Push: ✅ %s of %s targets', $r['ok'], $r['targets']) : BWACHText::t('Push: ⚠️ %s of %s targets', $r['ok'], $r['targets']));
        }
        if ($this->ReadPropertyBoolean('NotifyMail')) {
            $parts[] = $this->sendMail($m['title'], $m['text']) ? BWACHText::t('Email: ✅ sent') : BWACHText::t('Email: ⚠️ not sent — %s', $this->lastMailError);
        }
        if (strpos(implode(' ', $parts), '✅') !== false) {
            $this->notifyReset();   // der Zustellweg funktioniert wieder
        }
        $out = $parts ? implode(' · ', $parts) : BWACHText::t('ℹ️ No delivery channel selected (tick push or email under "Notifications").');
        $this->UpdateFormField('NotifyStatus', 'caption', $out);
        return $out;
    }

    /** Pause nach fehlgeschlagener Zustellung: 30 Minuten, dann verdoppelt, höchstens 6 Stunden. */
    private const RETRY_BASE_SEC = 1800;
    private const RETRY_MAX_SEC  = 21600;

    /** Läuft gerade die Pause nach einer fehlgeschlagenen Zustellung? */
    private function notifyBlocked(int $now): bool
    {
        return (int)($this->loadJson('Meta')['retryAfter'] ?? 0) > $now;
    }

    /** Merkt einen Fehlschlag und setzt die nächste Wartezeit (Limit erreicht, Ziel weg, SMTP aus …). */
    private function notifyFailed(int $now): void
    {
        $meta  = $this->loadJson('Meta');
        $fails = (int)($meta['retryFails'] ?? 0) + 1;
        $wait  = min(self::RETRY_MAX_SEC, self::RETRY_BASE_SEC * (2 ** min($fails - 1, 10)));
        $meta['retryFails'] = $fails;
        $meta['retryAfter'] = $now + $wait;
        $this->saveJson('Meta', $meta);
        $this->LogMessage(BWACHText::t('Delivery failed (%s failed attempts in a row). Next attempt at %s, notifications pause until then.', $fails, date('H:i', $now + $wait)), KL_WARNING);
    }

    /** Eine Meldung ist angekommen: Wartezeit zurücksetzen. */
    private function notifyReset(): void
    {
        $meta = $this->loadJson('Meta');
        if (isset($meta['retryAfter']) || isset($meta['retryFails'])) {
            unset($meta['retryAfter'], $meta['retryFails']);
            $this->saveJson('Meta', $meta);
        }
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
                $out[(string)$key] = BWACHText::t('💤 until %s', date('d.m.Y', (int)$s['snooze']));
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
        $mode  = $this->ReadPropertyInteger('ReplaceDetect');
        $fc    = $this->loadJson('FcLog');
        $fcChanged = false;
        $newLast = $last;
        $changed = false;

        foreach ($rows as $row) {
            $key = $row['id'];
            $cur = ['p' => $row['r']['percent'], 'f' => $row['r']['flagLow']];
            $prev = isset($last[$key]) ? ['p' => $last[$key]['p'] ?? null, 'f' => $last[$key]['f'] ?? null] : null;
            $desc = null;
            // Eigene Wahl des Geräts (oder seiner Gruppen-Regel) geht vor der globalen Einstellung
            $m = (int)($row['detect'] ?? -1) >= 0 ? (int)$row['detect'] : $mode;
            if ($m > 0 && $prev !== null) {
                // Nur Prozentsprung: das Flag zählt für die Erkennung nicht (gemerkt wird es trotzdem)
                $p = $m === 1 ? ['p' => $prev['p'], 'f' => null] : $prev;
                $c = $m === 1 ? ['p' => $cur['p'], 'f' => null] : $cur;
                $desc = BWACHMeldung::detectReplacement($p, $c, $jump, !empty($row['r']['derived']));
            }
            if ($desc !== null) {
                $entry = ['t' => $now, 'key' => $key, 'name' => $row['name'], 'type' => 'erkannt', 'note' => $desc];
                // Prognose gegen die Wirklichkeit: Stand vor dem Wechsel gegen das, was die Prognose Wochen vorher sagte
                if ($prev['p'] !== null && isset($fc[$key])) {
                    $acc = BWACHPrognose::accCompare($fc[$key], $now, (float)$prev['p']);
                    if ($acc !== null) {
                        $entry['acc'] = $acc;
                    }
                }
                unset($fc[$key]);
                $fcChanged = true;
                $diary = BWACHMeldung::diaryAdd($diary, $entry);
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
        if ($fcChanged) {
            $this->saveJson('FcLog', $fc);
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
            'confirmSec'  => max(0, $this->ReadPropertyInteger('ConfirmMinutes')) * 60,
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
            // In der Pause nach einem Fehlschlag wird nicht neu versucht (sonst jede Minute, z. B. bei erreichtem Push-Limit)
            $sent = $this->notifyBlocked($now) ? 0 : $this->deliver($m['title'], $m['text'], $m['sound'], $channels);
            if ($sent === 0) {
                if (!$this->notifyBlocked($now)) {
                    $this->notifyFailed($now);
                }
                // Nichts zugestellt: nicht als gemeldet verbuchen, nach der Pause erneut versuchen
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
        if ($this->notifyBlocked($now)) {
            return;
        }
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
            $nMiss = count($views['shopping']['missing']);
            $extra[] = BWACHText::t('🛒 Shopping for the next %s days: %s', $views['horizon'], implode(', ', $views['shopping']['lines'])) . ($nMiss ? ($nMiss === 1 ? BWACHText::t(' (cell type missing for %s device)', $nMiss) : BWACHText::t(' (cell type missing for %s devices)', $nMiss)) : '');
        }
        $d = BWACHMeldung::digest($sum, $list, $extra);
        if (!$d['anyProblem'] && !$this->ReadPropertyBoolean('DigestWhenOk')) {
            $meta['digest'] = $key;
            $this->saveJson('Meta', $meta);
            return;
        }
        $channels = ['push' => $this->ReadPropertyBoolean('NotifyPush'), 'mail' => $this->ReadPropertyBoolean('NotifyMail')];
        if ($this->deliver($d['title'], $d['text'], $d['sound'], $channels) > 0) {
            $meta = $this->loadJson('Meta');
            $meta['digest'] = $key;
            $this->saveJson('Meta', $meta);
        } else {
            $this->notifyFailed($now);
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
        if ($ok > 0) {
            $this->notifyReset();
        }
        if ($ok === 0) {
            $this->LogMessage(BWACHText::t('Notification "%s" could not be delivered on any channel (check push targets and email under "Notifications").', $title) . (!empty($channels['mail']) && $this->lastMailError !== '' ? BWACHText::t(' Email: %s', $this->lastMailError) : ''), KL_WARNING);
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
                $this->LogMessage(BWACHText::t('Push to instance #%s (%s) failed.', $id, $type), KL_WARNING);
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
            $this->lastMailError = BWACHText::t('no SMTP instance selected (or the instance no longer exists)');
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
                    $this->lastMailError = BWACHText::t('the function SMTP_SendMail does not exist (SMTP module missing?)');
                } else {
                    $sent = (bool)SMTP_SendMail($inst, $title, $text);
                }
            } elseif (!function_exists('SMTP_SendMailEx')) {
                $this->lastMailError = BWACHText::t('the function SMTP_SendMailEx does not exist (SMTP module missing?)');
            } else {
                $body = '<html><body>' . nl2br(htmlspecialchars($text)) . '</body></html>';
                foreach (array_filter(array_map('trim', preg_split('/[,;]+/', $to))) as $addr) {
                    if (SMTP_SendMailEx($inst, $addr, $title, $body)) {
                        $sent = true;
                    } else {
                        $this->LogMessage(BWACHText::t('Email to "%s" failed.', $addr), KL_WARNING);
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
            $this->LogMessage(BWACHText::t('Sending email failed: %s', $this->lastMailError), KL_WARNING);
        }
        return $sent;
    }

    private function renderDiary(): string
    {
        $diary = array_reverse($this->loadJson('Diary'));
        if (!$diary) {
            return '<div style="padding:8px">' . BWACHText::t('ℹ️ No battery replacements recorded yet. The monitor detects them itself, or you enter them under "Acknowledge".') . '</div>';
        }
        $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        $body = '';
        foreach (array_slice($diary, 0, self::MAX_DIARY_ROWS) as $d) {
            $body .= '<tr><td>' . $e(date('d.m.Y H:i', (int)$d['t'])) . '</td><td>' . $e($d['name']) . '</td><td>'
                . ($d['type'] === 'erkannt' ? BWACHText::t('🔎 detected') : BWACHText::t('✍️ entered')) . '</td><td>' . $e($d['note']) . '</td></tr>';
        }
        return '<style>.bw{border-collapse:collapse;width:100%;font-size:14px}.bw th,.bw td{padding:4px 10px;text-align:left;border-bottom:1px solid rgba(128,128,128,.35)}</style>'
            . '<table class="bw"><tr><th>' . BWACHText::t('When') . '</th><th>' . BWACHText::t('Device') . '</th><th>' . BWACHText::t('Entry type') . '</th><th>' . BWACHText::t('Note') . '</th></tr>' . $body . '</table>';
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
                'captions'         => ((int)($var['VariableType'] ?? -1) === 0) ? $this->boolCaptions($var) : [],
                'node'             => $inst['node'] ?? '',
                'group'            => $inst['group'] ?? '',
                'chan'             => $inst['chan'] ?? '',
                'updated'          => (int)($var['VariableUpdated'] ?? 0),
                // Nur für Spannungen nötig: damit Netzspannung (232 V) nicht als Batterie gilt
                'value'            => $this->voltValue($vid, $obj, $var),
                // Stufenanzeige (z. B. Netatmo 0 = leer … 5 = max): die Werte der benannten Stufen des Profils
                'stages'           => ($si = $this->stageInfo($obj, $var))['values'] ?? null,
                'stageUnknown'     => $si['unknown'] ?? [],
            ];
        }
        return $out;
    }

    /** Wert einer Variable, die nach Spannung aussieht (Float/Integer mit „volt“ im Ident oder Namen), sonst null. */
    private function voltValue(int $vid, array $obj, array $var): ?float
    {
        $type = (int)($var['VariableType'] ?? -1);
        if ($type !== 1 && $type !== 2) {
            return null;
        }
        if (!preg_match('/volt/i', (string)($obj['ObjectIdent'] ?? '') . ' ' . (string)($obj['ObjectName'] ?? ''))) {
            return null;
        }
        return (float)GetValue($vid);
    }

    /**
     * Beschriftungen für True und False aus der Darstellung einer Boolean-Variable (Wertanzeige mit Tabelle
     * Wert/Beschriftung). Ohne Darstellung oder ohne beide Werte: leer.
     *
     * @return array ['true'=>string,'false'=>string] oder []
     */
    private function boolCaptions(array $var): array
    {
        foreach (['VariableCustomPresentation', 'VariablePresentation'] as $key) {
            $pres = $var[$key] ?? null;
            if (!is_array($pres) || empty($pres['OPTIONS'])) {
                continue;
            }
            $opts = is_string($pres['OPTIONS']) ? json_decode($pres['OPTIONS'], true) : $pres['OPTIONS'];
            if (!is_array($opts)) {
                continue;
            }
            $out = [];
            foreach ($opts as $o) {
                if (is_array($o) && array_key_exists('Value', $o) && isset($o['Caption'])) {
                    $out[$o['Value'] ? 'true' : 'false'] = (string)$o['Caption'];
                }
            }
            if (isset($out['true'], $out['false'])) {
                return $out;
            }
        }
        return [];
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
        $h = $this->homematicChannel($parent, $info['module'], $info['name']);
        if ($h !== null) {
            $info['group'] = $h['group'];
            $info['chan']  = $h['chan'];
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
        // Eine gelöschte Instanz (z. B. nach der Umstellung auf „Matter Composite Device“ mit Symcon 9.1) still übergehen
        if ($inst <= 0 || !IPS_InstanceExists($inst)) {
            return null;
        }
        $module = $module ?? (string)(IPS_GetInstance($inst)['ModuleInfo']['ModuleName'] ?? '');
        if (!in_array($module, self::MATTER_MODULES, true)) {
            return null;
        }
        $cfg = json_decode((string)IPS_GetConfiguration($inst), true);
        if (!is_array($cfg) || !isset($cfg['NodeId'])) {
            return null;
        }
        // Composite: alle Endpunkte eines Geräts in einer Instanz, die Stromversorgung (E0_…) eingeschlossen
        $composite = $module === self::MATTER_COMPOSITE;
        return ['node' => (string)$cfg['NodeId'], 'endpoint' => $composite ? 0 : (int)($cfg['EndpointId'] ?? 0), 'composite' => $composite];
    }

    /**
     * HomeMatic: Gerät (Seriennummer) und Kanal einer Geräteinstanz. Die Adresse steht in der Eigenschaft „Address“
     * („LEQ0141683:1“), notfalls im Instanznamen. Ohne Kanal („:n“) gibt es nichts zusammenzufassen.
     *
     * @return array|null ['group'=>'HM:LEQ0141683','chan'=>'1']
     */
    private function homematicChannel(int $inst, string $module, string $name): ?array
    {
        if (stripos($module, 'homematic') === false) {
            return null;
        }
        $cfg  = json_decode((string)IPS_GetConfiguration($inst), true);
        $addr = is_array($cfg) ? (string)($cfg['Address'] ?? '') : '';
        if (!preg_match('/^([^:\s]+):(\d+)$/', $addr, $m) && !preg_match('/([A-Za-z0-9]{6,}):(\d+)\s*$/', $name, $m)) {
            return null;
        }
        return ['group' => 'HM:' . $m[1], 'chan' => $m[2]];
    }

    /** Die übrigen Instanzen desselben Matter-Knotens (ohne Endpunkt 0), nach Endpunkt sortiert. */
    private function matterSiblings(int $inst): array
    {
        $m = $this->matterNode($inst);
        if ($m === null || $m['composite']) {
            return [];   // Composite: Funktionen und Stromversorgung liegen schon in derselben Instanz
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
    /**
     * Die benannten Stufen eines eigenen Profils (3 bis 10 Assoziationen) an einer Integer-Variable, die nach Batterie
     * heißt; sonst null. So wird eine Stufenanzeige (0 = leer … 5 = max) nicht als Prozentwert gelesen.
     *
     * @return int[]|null aufsteigend sortierte Stufenwerte
     */
    /**
     * Stufenanzeige einer Batterievariable: die Werte der benannten Stufen und welche davon „unbekannt“ heißen.
     * Quelle: die Assoziationen des Profils oder, bei Symcon-Darstellungen ohne Profil, die Intervalle der Darstellung
     * (INTERVALS, ein JSON-String; nur mit INTERVALS_ACTIVE und ohne PERCENTAGE).
     *
     * @return array|null ['values'=>int[] aufsteigend,'unknown'=>int[]]
     */
    private function stageInfo(array $obj, array $var): ?array
    {
        if ((int)($var['VariableType'] ?? -1) !== 1 || !preg_match('/bat/i', (string)($obj['ObjectIdent'] ?? '') . ' ' . (string)($obj['ObjectName'] ?? ''))) {
            return null;
        }
        $named = [];   // Wert => Beschriftung
        $p = $this->resolveProfile($var);
        if ($p !== '') {
            if ($p[0] === '~' || !IPS_VariableProfileExists($p)) {
                return null;
            }
            foreach ((array)(IPS_GetVariableProfile($p)['Associations'] ?? []) as $a) {
                if (is_array($a) && isset($a['Value'])) {
                    $named[(int)$a['Value']] = (string)($a['Name'] ?? '');
                }
            }
        } else {
            foreach (['VariableCustomPresentation', 'VariablePresentation'] as $key) {
                $pres = $var[$key] ?? null;
                if (!is_array($pres) || empty($pres['INTERVALS_ACTIVE']) || !empty($pres['PERCENTAGE'])) {
                    continue;
                }
                $list = is_string($pres['INTERVALS'] ?? null) ? json_decode($pres['INTERVALS'], true) : ($pres['INTERVALS'] ?? null);
                if (is_array($list)) {
                    foreach ($list as $iv) {
                        if (is_array($iv) && isset($iv['IntervalMinValue']) && is_numeric($iv['IntervalMinValue'])) {
                            $named[(int)$iv['IntervalMinValue']] = !empty($iv['ConstantActive']) ? (string)($iv['ConstantValue'] ?? '') : '';
                        }
                    }
                    break;
                }
            }
        }
        ksort($named);
        $values = array_keys($named);
        if (count($values) < 3 || count($values) > 10) {
            return null;
        }
        $unknown = [];
        foreach ($named as $v => $name) {
            if (preg_match('/unbekannt|unknown/iu', $name)) {
                $unknown[] = (int)$v;
            }
        }
        return ['values' => $values, 'unknown' => $unknown];
    }

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
                $text = trim((string)($r['Text'] ?? ''));
                if ($vid > 0 && in_array($kind, [BWACHLogik::KIND_PERCENT, BWACHLogik::KIND_FLAG, BWACHLogik::KIND_FLAG_REV, BWACHLogik::KIND_VOLTAGE], true)) {
                    $out[] = ['vid' => $vid, 'kind' => $kind];
                } elseif ($vid > 0 && $kind === BWACHLogik::KIND_TEXT && $text !== '') {
                    $out[] = ['vid' => $vid, 'kind' => $kind, 'text' => $text];   // ohne Text gibt es nichts zu prüfen
                }
            }
        }
        return $out;
    }

    /** Macht die eigenen Zelltypen der Instanz in BWACHZelle bekannt (billig, bei jedem Einstieg aufrufbar). */
    private function initCells(): void
    {
        $rows = json_decode((string)$this->ReadPropertyString('OwnCells'), true);
        BWACHZelle::setCustom(is_array($rows) ? $rows : []);
    }

    /** Variablen, die der Nutzer von der Erkennung ausgenommen hat (Liste „Variablen ignorieren“). */
    private function ignoredVariables(): array
    {
        $rows = json_decode((string)$this->ReadPropertyString('IgnoredVariables'), true);
        $out  = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $vid = (int)($r['Variable'] ?? 0);
            if ($vid > 0) {
                $out[] = $vid;
            }
        }
        return array_values(array_unique($out));
    }

    /** Gruppen-Regeln aus der Instanzkonfiguration, von oben nach unten. */
    private function groupRules(): array
    {
        $this->initCells();
        $rows = json_decode((string)$this->ReadPropertyString('GroupRules'), true);
        $out  = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $out[] = [
                    'Active'   => (bool)($r['Active'] ?? true),
                    'Label'    => (string)($r['Label'] ?? ''),
                    'Kind'     => (string)($r['Kind'] ?? ''),
                    'Pattern'  => (string)($r['Pattern'] ?? ''),
                    'Cell'     => BWACHZelle::isKnown((string)($r['Cell'] ?? '')) ? (string)$r['Cell'] : BWACHZelle::UNKNOWN,
                    'Cells'    => max(1, min(12, (int)($r['Cells'] ?? 1))),
                    'Group'    => (string)($r['Group'] ?? ''),
                    'Critical' => (bool)($r['Critical'] ?? false),
                    'IgnoreAge' => (bool)($r['IgnoreAge'] ?? false),
                    'NoStill'  => (bool)($r['NoStill'] ?? false),
                    'Excluded' => (bool)($r['Excluded'] ?? false),
                    'Poll'     => (bool)($r['Poll'] ?? false),
                    'Detect'   => max(-1, min(2, (int)($r['Detect'] ?? -1))),
                    'StillDays' => max(0, min(3650, (int)($r['StillDays'] ?? 0))),
                    'PlaceSet'  => $this->cleanAlias((string)($r['PlaceSet'] ?? '')),
                ];
            }
        }
        return $out;
    }

    /**
     * Name und Ort eines Geräts, wie sie in Kachel, Tabellen und Gruppen-Regeln gelten. Bei Matter heißt das Gerät wie
     * seine Funktionsinstanz, bei geteilten Einträgen (mehrere Sensoren einer Instanz) hängt der Sensorname dran.
     *
     * @return array ['name'=>string,'place'=>string,'module'=>string]
     */
    private function deviceLabel(array $d, int $id, bool $isInstance, array $sibs): array
    {
        $name = (string)$d['name'];
        if ($isInstance) {
            $name = BWACHLogik::matterDeviceName(IPS_GetName($id), array_map('IPS_GetName', $sibs));
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
        // Sensor: nur bei Instanzen mit mehreren Sensoren (geteilte Einträge) der Name der Batterie-Variable
        $sensor = '';
        if ($isInstance && isset($d['parent'])) {
            foreach ($d['signals'] as $list) {
                $first = $list[0]['vid'] ?? null;
            }
            $sensor = ($first ?? null) !== null && IPS_VariableExists((int)$first) ? IPS_GetName((int)$first) : '';
        }
        return ['name' => $name, 'place' => $place, 'module' => (string)($d['module'] ?? ''), 'sensor' => $sensor];
    }

    /** Anzeigename je Gerät: ohne Zeilenumbruch und Steuerzeichen, höchstens 60 Zeichen, leer = Name der Instanz. */
    private function cleanAlias(string $s): string
    {
        $s = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s));
        return mb_substr($s, 0, 60);
    }

    /** Geräteeinstellungen nach Instanz-ID. */
    private function deviceSettings(): array
    {
        $this->initCells();
        $rows = json_decode((string)$this->ReadPropertyString('DeviceSettings'), true);
        $out  = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $id  = (int)($r['Instance'] ?? 0);
                $sen = max(0, (int)($r['Sensor'] ?? 0));   // 0 = die Instanz als Ganzes, sonst die Variable des Sensors
                if ($id > 0) {
                    $out[$sen > 0 ? $id . '-' . $sen : $id] = [
                        'group'     => (string)($r['Group'] ?? BWACHLogik::GROUP_STANDARD),
                        'critical'  => (bool)($r['Critical'] ?? false),
                        'ignoreAge' => (bool)($r['IgnoreAge'] ?? false),
                        'noStill'   => (bool)($r['NoStill'] ?? false),
                        'excluded'  => (bool)($r['Excluded'] ?? false),
                        'cell'      => BWACHZelle::isKnown((string)($r['Cell'] ?? '')) ? (string)$r['Cell'] : BWACHZelle::UNKNOWN,
                        'cells'     => max(1, min(12, (int)($r['Cells'] ?? 1))),
                        'poll'      => (bool)($r['Poll'] ?? false),
                        'detect'    => max(-1, min(2, (int)($r['Detect'] ?? -1))),
                        'stillDays' => max(0, min(3650, (int)($r['StillDays'] ?? 0))),
                        'alias'     => $this->cleanAlias((string)($r['Alias'] ?? '')),
                        'place'     => $this->cleanAlias((string)($r['PlaceOwn'] ?? '')),
                        'reach'     => max(0, (int)($r['ReachVar'] ?? 0)),
                        'reachInv'  => (bool)($r['ReachInv'] ?? false),
                    ];
                }
            }
        }
        // Nach der Matter-Umstellung: Einstellungen der gelöschten Instanz gelten für das neue Gerät, bis es eigene hat
        foreach ($this->loadJson('Meta')['adopted'] ?? [] as $newKey => $oldKey) {
            if (!isset($out[$newKey]) && isset($out[$oldKey])) {
                $out[$newKey] = $out[$oldKey];
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
        $this->initCells();
        $now      = $this->now();
        $settings = $this->deviceSettings();
        $rules    = $this->groupRules();
        $diaryAll = $this->loadJson('Diary');
        $retired  = $this->loadJson('Retired');
        $hist     = $this->loadJson('History');
        $lifeObs  = $this->loadJson('LifeObs');
        $pollSt   = $this->loadJson('Poll');
        $learn    = $this->ReadPropertyBoolean('LearnIntervals');
        $rows     = [];

        foreach ($found['devices'] as $key => $d) {
            // Geteilte Einträge (mehrere Sensoren einer Instanz) tragen die Instanz in 'parent'.
            $id = (int)($d['parent'] ?? $key);
            $isInstance = IPS_InstanceExists($id);
            // Matter: Batterie (Endpunkt 0) und Kontakt (Endpunkt 1) sind ein Gerät. Name und Lebenszeichen
            // kommen vom Funktionsendpunkt: die Stromversorgung meldet sich selten, der Kontakt bei jedem Öffnen.
            $sibs  = ($isInstance && isset($d['node'])) ? $this->matterSiblings($id) : [];
            $label = $this->deviceLabel($d, $id, $isInstance, $sibs);
            // Eigene Einstellung, dann die erste passende Gruppen-Regel
            $st = BWACHLogik::applyRules($settings[(string)$key] ?? $settings[$id] ?? ['group' => BWACHLogik::GROUP_STANDARD, 'critical' => false, 'ignoreAge' => false, 'excluded' => false, 'cell' => BWACHZelle::UNKNOWN, 'cells' => 1, 'poll' => false, 'detect' => -1, 'noStill' => false, 'place' => ''], $label, $rules);
            if ($st['excluded'] || isset($retired[(string)$key])) {
                continue;
            }
            $sig  = $this->readSignals($d['signals']);
            $life = $this->lifeSign($id, $isInstance, $d['signals'], (int)($st['reach'] ?? 0));
            foreach ($sibs as $sb) {
                $life = max($life, $this->lifeSign($sb, true, [], (int)($st['reach'] ?? 0)));
            }
            // HomeMatic: zusammengeführte Kanäle, das jüngste Lebenszeichen aller zählt
            foreach ($d['members'] ?? [] as $mb) {
                if ((int)$mb !== $id && IPS_InstanceExists((int)$mb)) {
                    $life = max($life, $this->lifeSign((int)$mb, true, [], (int)($st['reach'] ?? 0)));
                }
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

            $reach = $this->reachState($id, $isInstance, $sibs, $d['members'] ?? [], (int)($st['reach'] ?? 0), !empty($st['reachInv']), $life);
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
                'noStill'        => !empty($st['noStill']),
                'lowPct'         => $this->ReadPropertyInteger('LowPercent'),
                'emptyPct'       => $this->ReadPropertyInteger('EmptyPercent'),
                'critLowPct'     => $this->ReadPropertyInteger('CriticalLowPercent'),
                'valueOldDays'   => $this->ReadPropertyInteger('ValueOldDays'),
                'unreachSince'   => $reach['since'],
                'reachUp'        => $reach['up'],
                'reachExplicit'  => $reach['explicit'],
                'stillOverrideDays' => (int)($st['stillDays'] ?? 0),
                'stillDays'      => $this->ReadPropertyInteger('StillDays'),
                'stillDaysEvent' => $this->ReadPropertyInteger('StillDaysEvent'),
            ]);
            $name  = $label['name'];
            $place = trim((string)($st['place'] ?? '')) !== '' ? (string)$st['place'] : $label['place'];   // eigener Ort geht der Kategorie vor
            // Anzeigename aus „Geräte-Einstellungen“: gilt in Kachel, Tabellen, Meldungen, Tagebuch und Liste.
            // Die Gruppen-Regeln arbeiten weiter mit dem Namen der Instanz, damit eine Umbenennung nichts verstellt.
            $alias = (string)($st['alias'] ?? '');
            if ($alias !== '') {
                $name = isset($d['parent']) ? $alias . mb_substr($name, mb_strlen($label['name'])) : $alias;
            }
            // Prognose aus dem im Modul geführten Verlauf; „bald leer“ nur bei ausreichender Sicherheit
            $f = BWACHPrognose::forecast($hist[(string)$key] ?? [], (float)$this->ReadPropertyInteger('EmptyPercent'), $this->ReadPropertyInteger('ReplaceJumpPercent'));
            $r['soon'] = false;
            if ($r['status'] === BWACHLogik::ST_OK && $f['days'] !== null && in_array($f['confidence'], ['hoch', 'mittel'], true) && $f['days'] <= $this->ReadPropertyInteger('SoonDays')) {
                $r['soon'] = true;
                $r['reasons'][] = BWACHText::t('Battery empty soon: %s', BWACHPrognose::forecastText($f));
                $r['urgency'] = max($r['urgency'], 650 + ($r['critical'] ? 100 : 0));
            }
            // Vorsorglicher Wechsel: nur kritische Geräte, nur mit erfasstem letzten Wechsel (Tagebuch)
            $r['preventive'] = false;
            $pm = $this->ReadPropertyInteger('PreventiveMonths');
            if ($st['critical'] && $pm > 0) {
                $pv = BWACHMeldung::preventiveDue(BWACHMeldung::lastReplacement($diaryAll, (string)$key), $pm, $now);
                if ($pv['due']) {
                    $r['preventive'] = true;
                    $r['reasons'][]  = ($pm === 1 ? BWACHText::t('Preventive replacement due: last replacement %s months ago (interval %s month)', BWACHLogik::num($pv['months']), $pm) : BWACHText::t('Preventive replacement due: last replacement %s months ago (interval %s months)', BWACHLogik::num($pv['months']), $pm));
                    $r['urgency']    = max($r['urgency'], 450);
                }
            }
            // Abfrage schlafender Geräte: Stand der letzten Anfrage und ob das Gerät geantwortet hat
            $pollText = '';
            if (isset($pollSt[(string)$key])) {
                $ps = $pollSt[(string)$key];
                $when = date('d.m.Y', (int)$ps['t']);
                $miss = (int)($ps['miss'] ?? 0);
                if (!empty($ps['ans'])) {
                    $pollText = BWACHText::t('Query sent on %s, device responded', $when);
                } elseif ($ps['ans'] === false || $miss > 0) {
                    $since = $miss > 0 ? $miss : (int)$ps['t'];
                    $pollText = BWACHText::t('Queries without response since %s (last sent on %s)', date('d.m.Y', $since), $when);
                    $r['quality'][] = 'keine_antwort';
                    $r['reasons'][] = BWACHText::t('Does not respond to queries (no response since %s)', date('d.m.Y', $since));
                    $r['urgency'] = max($r['urgency'], 400 + ($r['critical'] ? 100 : 0));
                } else {
                    $pollText = BWACHText::t('Query sent on %s, response still pending (sleeping devices only respond when they wake up)', $when);
                }
            }
            $rows[] = ['id' => (string)$key, 'name' => $name, 'place' => $place, 'module' => (string)$d['module'], 'r' => $r,
                'forecast' => $f, 'cell' => $st['cell'], 'cells' => $st['cells'], 'life' => $life, 'inst' => $id, 'poll' => $pollText,
                'members' => array_values(array_map('intval', $d['members'] ?? [])), 'sigIds' => array_map(function ($list) { return array_values(array_map(function ($s) { return (int)$s['vid']; }, $list)); }, $d['signals']),
                'cellHint' => $cellHint, 'cellAuto' => $cellAuto, 'sourceList' => $this->sourceList($d['signals']),
                'change' => BWACHMeldung::changeInfo($diaryAll, (string)$key),
                'signal' => $isInstance ? $this->signalInfo($id) : null, 'pollOn' => $st['poll'], 'detect' => (int)($st['detect'] ?? -1)];
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
            $rows[$i]['r']['reasons'][] = BWACHText::t('Drains %s× faster than comparable devices (%s instead of the median %s percentage points per day, %s devices compared)', BWACHLogik::num($o['factor']), BWACHLogik::num($o['rate']), BWACHLogik::num($o['median']), $o['n'])
                . ($weak ? BWACHText::t('. The radio signal is weak, frequent retransmissions cost battery') : BWACHText::t('. Possible causes: defective device, poor radio connection, continuous transmitting'));
            $rows[$i]['r']['urgency'] = max($rows[$i]['r']['urgency'], 450 + ($rows[$i]['r']['critical'] ? 100 : 0));
            $rows[$i]['peer'] = $o;
        }
    }

    /**
     * Schickt schlafenden Z-Wave- und Matter-Geräten, deren Batteriewert alt ist, eine Statusanfrage — nur bei Geräten, für die der
     * Nutzer das eingeschaltet hat, höchstens alle N Tage und nur mit einer belegt vorhandenen Symcon-Funktion.
     * Ein schlafendes Gerät beantwortet die Anfrage erst beim nächsten Aufwachen; bis dahin liegt sie in der
     * Warteschlange der Z-Wave-Instanz.
     */
    /** Systeme, für die es eine Statusanfrage gibt: Modulname der Instanz => Symcon-Funktion. */
    private const POLL_FUNCTIONS = ['Z-Wave Module' => 'ZW_RequestStatus', 'Matter Device' => 'MATTER_RequestStatus', 'Matter Composite Device' => 'MATTER_RequestStatus', 'CometWiFiThermostat' => 'CWIFI_SendAction'];

    /** Matter-Module: ein Endpunkt je Instanz (bis Symcon 9.0) und ein Gerät je Instanz (ab Symcon 9.1). */
    private const MATTER_DEVICE    = 'Matter Device';
    private const MATTER_COMPOSITE = 'Matter Composite Device';
    private const MATTER_MODULES   = [self::MATTER_DEVICE, self::MATTER_COMPOSITE];

    /**
     * Zusätzliche Argumente nach der Instanz-ID. Comet: Der Batteriestand (Register A6) kommt nur mit „alle Felder“; das weckt
     * das Gerät spürbar, deshalb nur über den Haken je Gerät und höchstens im Abfrage-Abstand. CWIFI_SendAction liefert bool,
     * CWIFI_RequestAllFields nur Anzeigetext.
     */
    private const POLL_ARGS = ['CometWiFiThermostat' => ['AllFields']];

    /** Wie lange auf eine Antwort gewartet wird, bevor „keine Antwort“ gilt: schlafende Z-Wave-Geräte antworten erst beim Aufwachen. */
    private const POLL_WAIT = ['Z-Wave Module' => 14 * 86400, 'Matter Device' => 6 * 3600, 'Matter Composite Device' => 6 * 3600, 'CometWiFiThermostat' => 6 * 3600];

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
                $t = (int)$state[$key]['t'];
                // Antwort = der Batteriewert ist nach der Anfrage neu; bei einer Anfrage wegen Funkstille zählt jedes Lebenszeichen
                $byLife = ($state[$key]['why'] ?? 'alt') === 'still' && (int)$row['life'] > $t;
                if (($age !== null && $now - $age > $t) || $byLife) {
                    $state[$key]['ans']  = true;
                    $state[$key]['miss'] = 0;
                } elseif ($now - $t > (self::POLL_WAIT[$row['module']] ?? 14 * 86400)) {
                    $state[$key]['ans'] = false;
                }
            }
            $fn = self::POLL_FUNCTIONS[$row['module']] ?? null;
            // Anfrage, wenn der Batteriewert alt ist ODER das Gerät still ist (dann trennt die Antwort „schläft nur“ von „ausgefallen“)
            $old   = $age !== null && $age > $after;
            $still = ($row['r']['funk'] ?? '') === 'still';
            if (!$row['pollOn'] || $fn === null || (!$old && !$still)) {
                continue;
            }
            if (isset($state[$key]) && $now - (int)$state[$key]['t'] < $every) {
                continue;
            }
            if (!function_exists($fn) || !IPS_InstanceExists((int)$row['inst'])) {
                continue;
            }
            try {
                $ok = (bool)$fn((int)$row['inst'], ...(self::POLL_ARGS[$row['module']] ?? []));
            } catch (\Throwable $e) {
                $ok = false;
                $this->LogMessage(BWACHText::t('Query of "%s" failed: %s', $row['name'], $e->getMessage()), KL_WARNING);
            }
            if ($ok) {
                // Ein unbeantworteter Stand bleibt sichtbar, auch wenn erneut angefragt wird
                $prev = $state[$key] ?? null;
                $miss = 0;
                if ($prev !== null && $prev['ans'] !== true) {
                    $miss = (int)($prev['miss'] ?? 0) ?: ($prev['ans'] === false ? (int)$prev['t'] : 0);
                }
                $state[$key] = ['t' => $now, 'ans' => null, 'miss' => $miss, 'why' => $still && !$old ? 'still' : 'alt'];
            } else {
                $this->LogMessage(BWACHText::t('Query of "%s" was not accepted.', $row['name']), KL_WARNING);
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
                    $stage = null;
                    // Stufe „unbekannt“ (z. B. Blink 0): kein Batteriestand, ehrlich als unbekannt behandeln
                    if (!empty($s['stageUnknown']) && in_array((int)$val, array_map('intval', $s['stageUnknown']), true)) {
                        $sig['stageUnknown'] = true;
                        continue;
                    }
                    if (!empty($s['stages']) && is_array($s['stages'])) {
                        // Stufenanzeige: Rang der Stufe, gleichmäßig auf 0 bis 100 verteilt
                        $stage = BWACHLogik::stageOf((float)$val, array_map('intval', $s['stages']));
                        $val   = $stage['percent'];
                        $s['scale'] = 1.0;
                    }
                    $scaled = (float)$val * (float)$s['scale'];
                    if (!isset($sig['percent']) || $scaled < (float)$sig['percent']['value'] * (float)$sig['percent']['scale']) {
                        $sig['percent'] = ['value' => (float)$val, 'updated' => $upd, 'scale' => (float)$s['scale']] + ($stage !== null ? ['stage' => $stage] : []);
                    }
                } elseif ($kind === BWACHLogik::KIND_FLAG || $kind === BWACHLogik::KIND_FLAG_REV) {
                    if (isset($s['text']) && (string)$s['text'] !== '') {
                        // Text-Zustand: „schwach“, wenn der Text eines der Muster enthält
                        $val = BWACHLogik::textMatches((string)$val, (string)$s['text']);
                    }
                    $rev = $kind === BWACHLogik::KIND_FLAG_REV;
                    $low = $rev ? !(bool)$val : (bool)$val;
                    if (!isset($sig['flag']) || ($low && !$this->flagIsLow($sig['flag']))) {
                        $sig['flag'] = ['value' => (bool)$val, 'updated' => $upd, 'reversed' => $rev];
                    }
                } elseif ($kind === BWACHLogik::KIND_VOLTAGE && !isset($sig['voltage'])) {
                    // Matter meldet die Batteriespannung in Millivolt (z. B. 3000); Werte ab 400 gelten als Millivolt
                    $volt = (float)$val;
                    $sig['voltage'] = ['value' => $volt >= 400 ? $volt / 1000 : $volt, 'updated' => $upd];
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
    private function lifeSign(int $id, bool $isInstance, array $signals, int $skipVar = 0): int
    {
        $max = 0;
        if ($isInstance) {
            foreach (IPS_GetChildrenIDs($id) as $c) {
                $o = IPS_GetObject($c);
                if ((int)($o['ObjectType'] ?? 0) !== 2) {
                    continue;
                }
                // Erreichbarkeit (UNREACH, STICKY_UNREACH, UNREACH2 …, die gewählte Variable) ist kein Lebenszeichen: eine erneute
                // Offline-Meldung heißt nicht, dass das Gerät sendet
                if ($c === $skipVar || preg_match('/^(sticky_)?unreach\d*$/i', (string)($o['ObjectIdent'] ?? ''))) {
                    continue;
                }
                $var = IPS_GetVariable($c);
                // Variablen mit Aktion (Sollwert, Schalter) schreibt oft Symcon selbst, z. B. eine
                // Heizungssteuerung — das ist kein Lebenszeichen des Geräts. Live gefunden: ausgebaute
                // Thermostate meldeten täglich „Sollwert aktualisiert“.
                // Achtung: VariableCustomAction = 1 heißt „keine Aktion“ (so steht es bei Z-Wave-Sensorwerten),
                // nicht „hat eine Aktion“; sonst gälte jeder Z-Wave-Sensor als schweigend.
                if (BWACHLogik::hasAction((int)($var['VariableAction'] ?? 0), (int)($var['VariableCustomAction'] ?? 0))) {
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

    /**
     * Erreichbarkeit des Geräts. Quelle: die im Gerät gewählte Variable (true = nicht erreichbar, umkehrbar), sonst eine
     * Variable mit dem Ident UNREACH an der Instanz, ihren Matter-/HomeMatic-Geschwistern oder zusammengeführten Kanälen.
     * Eine ausdrücklich gewählte Variable geht in beide Richtungen vor der Zeit-Schwelle der Funkstille; das automatisch
     * erkannte UNREACH gilt nur, solange das Gerät seitdem nichts anderes gemeldet hat ($life = jüngstes Lebenszeichen).
     *
     * @return array ['since'=>?int (seit wann nicht erreichbar, nach Abzug der Wartezeit gegen Flackern),'up'=>bool (ausdrücklich erreichbar),'explicit'=>bool]
     */
    private function reachState(int $id, bool $isInstance, array $sibs, array $members, int $own, bool $inv, int $life): array
    {
        $res  = ['since' => null, 'up' => false, 'explicit' => false];
        $wait = $this->ReadPropertyInteger('UnreachMinutes') * 60;
        $vars = [];
        if ($own > 0) {
            if (IPS_VariableExists($own)) {
                $vars[] = [$own, $inv];
                $res['explicit'] = true;
            }
        } elseif ($wait > 0 && $isInstance) {
            foreach (array_unique(array_merge([$id], $sibs, array_map('intval', $members))) as $inst) {
                if ($inst > 0 && IPS_InstanceExists($inst)) {
                    $v = @IPS_GetObjectIDByIdent('UNREACH', $inst);
                    if ($v !== false && $v > 0 && IPS_VariableExists($v)) {
                        $vars[] = [$v, false];
                    }
                }
            }
        }
        foreach ($vars as [$vid, $invert]) {
            $info = IPS_GetVariable($vid);
            if ((int)($info['VariableType'] ?? -1) !== 0) {
                $res['explicit'] = false;
                continue;
            }
            $unreach = (bool)GetValue($vid) !== $invert;
            $changed = (int)($info['VariableChanged'] ?? 0);
            if ($unreach) {
                // Das automatisch erkannte UNREACH ist nur ein Hinweis: hat das Gerät seitdem etwas anderes gemeldet,
                // ist die Meldung überholt (CCU-Neustart, nie zurückgesetzt). Eine gewählte Variable gilt immer.
                if (!$res['explicit'] && $life > $changed) {
                    continue;
                }
                if ($wait > 0 && $changed > 0 && $this->now() - $changed >= $wait) {
                    $res['since'] = $res['since'] === null ? $changed : min($res['since'], $changed);
                }
            } elseif ($res['explicit']) {
                $res['up'] = true;
            }
        }
        return $res;
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

    /**
     * Die Objekt-IDs eines Geräts für die Fehlersuche: Instanz (bei zusammengefassten Kanälen alle) und die
     * Variablen, aus denen die Batteriewerte kommen.
     */
    private function idText(array $row): string
    {
        $inst = [(int)$row['inst']];
        foreach ($row['members'] ?? [] as $m) {
            if (!in_array($m, $inst, true)) {
                $inst[] = $m;
            }
        }
        $parts = [BWACHText::t(count($inst) > 1 ? 'Instances %s' : 'Instance %s', implode(', ', array_map(function ($i) { return '#' . $i; }, $inst)))];
        foreach ($row['sigIds'] ?? [] as $kind => $vids) {
            $parts[] = $this->kindLabel((string)$kind) . ' ' . implode(', ', array_map(function ($v) { return '#' . $v; }, $vids));
        }
        return implode(' · ', $parts);
    }

    private function kindLabel(string $kind): string
    {
        return [
            BWACHLogik::KIND_PERCENT  => BWACHText::t('Percent'),
            BWACHLogik::KIND_FLAG     => BWACHText::t('"low" flag'),
            BWACHLogik::KIND_FLAG_REV => BWACHText::t('"OK" flag'),
            BWACHLogik::KIND_VOLTAGE  => BWACHText::t('Voltage'),
        ][$kind] ?? $kind;
    }

    private function discoveryLine(array $found): string
    {
        $n = count($found['devices']);
        $t = $this->ReadAttributeInteger('LastDiscoveryTs');
        $when = date('H:i:s', $t > 0 ? $t : $this->now());
        $icon = $n > 0 ? '✅' : ($t > 0 ? '⚠️' : 'ℹ️');
        $tail = $n === 0 ? BWACHText::t(' A signal can be added manually under "More variables".') : '';
        return ($n === 1 ? BWACHText::t('%s %s device found (last at %s).', $icon, $n, $when) : BWACHText::t('%s %s devices found (last at %s).', $icon, $n, $when)) . $tail . $this->matterHint();
    }

    /**
     * Hinweis auf Matter-Geräte ohne Instanz für die Stromversorgung (Endpunkt 0). Symcon legt sie nicht von
     * allein an; ohne sie gibt es keinen Batteriewert. Ob das Gerät überhaupt eine Batterie hat, ist von hier aus
     * nicht zu sehen (netzbetriebene haben keine), deshalb steht der Hinweis als Frage.
     */
    /** Namen der Matter-Geräte, für die es keine Instanz der Stromversorgung (Endpunkt 0) gibt. */
    private function matterWithoutPower(): array
    {
        $insts = [];
        foreach (IPS_GetInstanceList() as $i) {
            $m = $this->matterNode((int)$i);
            if ($m !== null) {
                $insts[] = ['node' => $m['node'], 'endpoint' => $m['endpoint'], 'name' => IPS_GetName((int)$i)];
            }
        }
        return BWACHLogik::matterNodesWithoutPowerSource($insts);
    }

    private function matterHint(): string
    {
        $names = $this->matterWithoutPower();
        if (!$names) {
            return '';
        }
        $shown = array_slice($names, 0, 8);
        $more  = count($names) - count($shown);
        return "\n\n" . ($more === 0 && count($names) === 1 ? BWACHText::t('ℹ️ Matter: For %s device there is no power source instance (endpoint 0): ', count($names)) : BWACHText::t('ℹ️ Matter: For %s devices there is no power source instance (endpoint 0): ', count($names)))
            . implode(', ', $shown) . ($more > 0 ? BWACHText::t(' and %s more', $more) : '') . BWACHText::t('. If a device has a battery, the monitor lacks its value until a "Matter Device" instance with the device\'s node ID and endpoint 0 is created (object tree: add instance; the Matter configurator does not offer endpoint 0 and shows such instances in red). Mains-powered devices have no battery. Symcon has announced that the Matter configurator will offer endpoint 0 with version 9.1; until then it can only be done manually.');
    }

    private function summaryLine(array $sum, int $now): string
    {
        if ($sum['total'] === 0) {
            return BWACHText::t('ℹ️ No devices monitored (last checked %s).', date('d.m.Y H:i', $now));
        }
        $bad = [];
        if ($sum['empty'] > 0) { $bad[] = BWACHText::t('%s empty', $sum['empty']); }
        if ($sum['low'] > 0) { $bad[] = BWACHText::t('%s low', $sum['low']); }
        if (($sum['soon'] ?? 0) > 0) { $bad[] = BWACHText::t('%s empty soon', $sum['soon']); }
        if ($sum['silent'] > 0) { $bad[] = BWACHText::t('%s radio silence', $sum['silent']); }
        if ($sum['check'] > 0) { $bad[] = BWACHText::t('%s with doubtful data', $sum['check']); }
        if ($sum['unknown'] > 0) { $bad[] = BWACHText::t('%s unknown', $sum['unknown']); }
        $icon = ($sum['empty'] > 0 || $sum['low'] > 0 || $sum['silent'] > 0 || ($sum['soon'] ?? 0) > 0) ? '⚠️' : ($bad ? 'ℹ️' : '✅');
        return $sum['total'] === 1
            ? BWACHText::t('%s %s device monitored: %s (checked %s).', $icon, $sum['total'], $bad ? implode(', ', $bad) : BWACHText::t('all OK'), date('d.m.Y H:i', $now))
            : BWACHText::t('%s %s devices monitored: %s (checked %s).', $icon, $sum['total'], $bad ? implode(', ', $bad) : BWACHText::t('all OK'), date('d.m.Y H:i', $now));
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
                BWACHLogik::ST_OK      => BWACHText::t('✅ OK'),
                BWACHLogik::ST_LOW     => BWACHText::t('⚠️ low'),
                BWACHLogik::ST_EMPTY   => BWACHText::t('🪫 empty'),
                BWACHLogik::ST_UNKNOWN => BWACHText::t('❔ unknown'),
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
            $left = $f['days'] !== null ? '≈ ' . BWACHLogik::days((int)($f['days'] * 86400)) . ' (' . (['hoch' => BWACHText::t('confidence high'), 'mittel' => BWACHText::t('confidence medium'), 'niedrig' => BWACHText::t('confidence low')][$f['confidence']] ?? $f['confidence']) . ')' : ($f['confidence'] === 'keine' ? BWACHText::t('no discharge') : '—');
            $body .= '<tr><td>' . $e($row['name']) . $mark . '</td><td>' . $e($row['place']) . '</td><td>' . $e($bat) . '</td><td>'
                . $e($age) . '</td><td>' . $e($life) . '</td><td>' . $e($left) . '</td><td>' . $e(implode('; ', $r['reasons'])) . '</td></tr>';
        }
        if ($n === 0) {
            return '<div style="padding:8px">' . ($onlyProblems ? BWACHText::t('✅ Nothing to do.') : BWACHText::t('ℹ️ No devices.')) . '</div>';
        }
        $heads = [BWACHText::t('Device'), BWACHText::t('Location'), BWACHText::t('Battery'), BWACHText::t('Value age'), BWACHText::t('Last sign of life'), BWACHText::t('Days remaining'), BWACHText::t('Finding')];
        return '<style>.bw{border-collapse:collapse;width:100%;font-size:14px}.bw th,.bw td{padding:4px 10px;text-align:left;border-bottom:1px solid rgba(128,128,128,.35)}</style>'
            . '<table class="bw"><tr><th>' . implode('</th><th>', array_map($e, $heads)) . '</th></tr>' . $body . '</table>';
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
            'caption' => '👋  What is this module for?',
            'items' => [
                ['type' => 'Label', 'caption' => 'The Battery Monitor finds the batteries of your wireless devices (sensors, thermostats, window contacts and smoke detectors …) on its own and tells you which ones need replacing — before a device fails.'],
                ['type' => 'Label', 'caption' => 'The benefit over a simple "empty" list: it tells a low battery apart from a device that no longer sends anything at all, and it says honestly when a battery value is doubtful (ancient, contradictory or impossible). An "OK" from 2023 is not OK.'],
                ['type' => 'Label', 'caption' => 'First press "🔎 Search again now" and use "What would be found?" to check that the matches are right. After that everything runs on its own.'],
                ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'BWACH_AckPurposeIntro($id);'],
            ],
        ];
    }

    /** Die Änderungen je Version für das Panel „Neu“ (in Tests überschreibbar). */
    protected function newsVersions(): array
    {
        return self::NEWS_VERSIONS;
    }

    private function NewsBanner(): ?array
    {
        $seen = $this->ReadAttributeString('SeenNews');
        if ($seen === '') {
            $seen = '0';
        }
        $pending = [];
        foreach ($this->newsVersions() as $version => $lines) {
            if (version_compare((string)$version, $seen, '>')) {
                $pending[(string)$version] = $lines;
            }
        }
        if (count($pending) === 0) {
            return null;
        }
        uksort($pending, 'version_compare');
        // Eine neue Instanz (oder eine, die lange nichts bestätigt hat) bekommt nicht die ganze Entwicklungsgeschichte, nur die letzten drei Versionen
        $cut = count($pending) > 3;
        if ($cut) {
            $pending = array_slice($pending, -3, 3, true);
        }
        $items = [];
        foreach ($pending as $version => $lines) {
            $items[] = ['type' => 'Label', 'caption' => BWACHText::t('Version %s:', $version)];
            foreach ($lines as $line) {
                $items[] = ['type' => 'Label', 'caption' => $line];
            }
        }
        if ($cut) {
            $items[] = ['type' => 'Label', 'caption' => 'ℹ️ Older changes are listed in the file CHANGELOG.md in the repository.'];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'BWACH_AckNews($id);'];
        return [
            'type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true,
            'caption' => BWACHText::t('🆕  New up to version %s', array_key_last($pending)),
            'items' => $items,
        ];
    }

    private function DocPanel(): array
    {
        $lib    = @IPS_GetLibrary(self::LIBRARY_GUID);
        $verTxt = (is_array($lib) && isset($lib['Version']))
            ? BWACHText::t('ℹ️ Battery Monitor version %s (build %s)', $lib['Version'], $lib['Build'] ?? '?')
            : BWACHText::t('ℹ️ Battery Monitor');
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '📖  Documentation and help',
            'items' => [
                ['type' => 'Label', 'caption' => $verTxt],
                ['type' => 'Button', 'caption' => '💬 Forum thread', 'onClick' => "echo '" . self::FORUM_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'What is found: variables with the Symcon profiles ~Battery ("low" flag), ~Battery.Reversed ("OK" flag) and ~Battery.100 (percent) as well as variables with typical identifiers (e.g. battery, battery_low, LOWBAT, battery_percent, battery_voltage). Several signals of one device (e.g. percent and flag) are merged into ONE device.'],
                ['type' => 'Label', 'caption' => 'What is deliberately not found: home storage and vehicle batteries (module list "Excluded modules"), aggregate values such as the "weakest battery" of a room, and variables that belong to no device instance. The dry run gives the reason for every exclusion.'],
                ['type' => 'Label', 'caption' => 'The three times: (1) Age of the battery value — when the device last reported its battery level; some devices do this only every few months. (2) Sign of life — the latest update of a measurement variable of the device (variables with an action such as setpoints do not count, Symcon often writes them itself); if it stays away, that is radio silence, not a low battery. (3) The device itself with its status.'],
                ['type' => 'Label', 'caption' => 'Status: "empty" and "low" from the percentage (thresholds below) or the device flag; "unknown" if there is nothing to evaluate (e.g. only a voltage — with a cell type selected, the monitor converts it into a state of charge). If a device reports flag and percent and the two contradict each other, the monitor shows the contradiction and rates by the newer signal; for critical devices the worse statement applies.'],
                ['type' => 'Label', 'caption' => 'Radio silence: by default 7 days without a sign of life. Devices that only send on events (window contacts, smoke detectors) belong in the group "Event sensor" under "Device settings" (default 30 days).'],
                ['type' => 'Label', 'caption' => 'Result: key figure variables (empty, low, radio silence …) and two table variables ("Action needed", "All devices") that can be placed in the WebFront via a link.'],
                ['type' => 'Label', 'caption' => 'Notifications (under "🔔 Notifications", off by default): first notification, reminder after N days, quiet hours, weekly report, escalation for critical devices — via push (tile visualization and WebFront) and email. Under "✅ Acknowledge" you tell the monitor what is going on with a device.'],
                ['type' => 'Label', 'caption' => 'Forecast, shopping, replacement round: set under "🧮 Forecast and shopping". Enter the cell type per device under "Device settings", then the monitor can convert voltages and count the shopping list. The results are the variables "Shopping and replacement round" and "Lifetime and discharge" below the instance.'],
                ['type' => 'Label', 'caption' => 'Conspicuous devices, radio quality, cold: if a device discharges more than twice as fast as comparable ones (same system, same cell type, at least 4 devices with a known discharge rate), the monitor marks it and names possible causes (defective, poor radio connection, continuous transmitting). It shows the radio quality where the device provides it (e.g. Zigbee linkquality). The effect of cold needs an outdoor temperature variable and several weeks of history in cold AND warm weather.'],
                ['type' => 'Label', 'caption' => 'Querying sleeping devices: Z-Wave, Matter and Comet WiFi thermostats, only where switched on per device, only if the battery value is older than set. It also queries during radio silence if the query is switched on for the device. The monitor says whether the device answered; if no answer comes (for Z-Wave after 14 days, for Matter and Comet after 6 hours), that is shown as a finding.'],
                ['type' => 'Label', 'caption' => 'Tile: add the instance as a tile in the tile visualization. Tapping a row expands it (reasons, age, buttons to acknowledge); the filter row at the top shows only what matters right now.'],
                ['type' => 'Label', 'caption' => 'Battery log: the monitor detects a battery replacement by the jump of the percentage or by the reset "low" flag and records it with the date. The lifetime per device and cell type appears in the statistics once enough replacements have been collected; earlier replacements can be added later with a date.'],
                ['type' => 'Label', 'caption' => 'Scripts: BWACH_Search(<InstanceID>) searches again, BWACH_Check(<InstanceID>) evaluates, BWACH_Preview(<InstanceID>) returns the dry run as text, BWACH_Acknowledge(<InstanceID>, \'<key>\', \'getauscht\'|\'zurueckgestellt\'|\'ausser_betrieb\') acknowledges, BWACH_SendTest(<InstanceID>) sends a test notification, BWACH_ShoppingText and BWACH_SendShopping return or send the shopping list, BWACH_DeviceListCsv the device list, BWACH_AddReplacement(<InstanceID>, \'<key>\', \'DD.MM.YYYY\') adds an earlier replacement, BWACH_AcknowledgePlace(<InstanceID>, \'<location>\') acknowledges the replacement round of a location, BWACH_Diagnosis(<InstanceID>) returns the diagnostics for the forum.'],
            ],
        ];
    }

    private function DiscoveryPanel(): array
    {
        $found = $this->found();
        $line  = $found === null ? BWACHText::t('ℹ️ Not searched yet.') : $this->discoveryLine($found);
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🔎  Devices found',
            'items' => [
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '🔎 Search again now', 'onClick' => 'echo BWACH_Search($id);'],
                    ['type' => 'Button', 'caption' => '📋 What would be found?', 'onClick' => 'echo BWACH_Preview($id);'],
                    ['type' => 'Button', 'caption' => '🩺 Diagnostics for the forum', 'onClick' => 'echo BWACH_Diagnosis($id);'],
                ]],
                ['type' => 'Label', 'name' => 'DiscoveryStatus', 'caption' => $line],
                ['type' => 'CheckBox', 'name' => 'NameSearch', 'caption' => 'Also search by name (less precise — may produce false matches)'],
                ['type' => 'ValidationTextBox', 'name' => 'ExcludedModules', 'caption' => 'Excluded modules (comma-separated)'],
                ['type' => 'Label', 'caption' => 'ℹ️ Variables of these modules count as energy storage or vehicle battery and are not suggested as device batteries. The list is only a starting point — adjust as needed.'],
            ],
        ];
    }

    private function StatusPanel(): array
    {
        $found = $this->found();
        $line  = BWACHText::t('ℹ️ Not checked yet.');
        if ($found !== null && $this->ReadAttributeInteger('LastCheckTs') > 0) {
            $line = (string)$this->GetValue('StatusLine');
        }
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🔋  Status',
            'items' => [
                ['type' => 'Button', 'caption' => '🔄 Check now', 'onClick' => 'echo BWACH_Check($id);'],
                ['type' => 'Label', 'name' => 'CheckStatus', 'caption' => $line],
                ['type' => 'Label', 'name' => 'TimerStatus', 'caption' => $this->timerLine()],
                ['type' => 'Label', 'caption' => 'Tile: add this instance as a tile in the tile visualization. The tile shows the devices by urgency, with filter, battery log and the buttons to acknowledge — without a title of its own, the instance name provides it.'],
                ['type' => 'CheckBox', 'name' => 'LightTheme', 'caption' => 'Light tile appearance (light grey background with dark text)'],
                ['type' => 'CheckBox', 'name' => 'TileAllowAck', 'caption' => 'Allow acknowledging from the tile ("replaced", "later", "out of service")'],
                ['type' => 'Label', 'caption' => 'Anyone allowed to see the tile can then also acknowledge. Switch off if the tile should only display. The tables "Action needed", "All devices" and "Battery log" are also available as variables below the instance in the object tree (can be placed in the WebFront via a link).'],
            ],
        ];
    }

    private function importLine(): string
    {
        $old = $this->oldInstances();
        if (!$old) {
            return BWACHText::t('ℹ️ No old BY_BatterieMonitor instance found.');
        }
        $names = [];
        foreach ($old as $i => $n) {
            $names[] = BWACHText::t('"%s" (#%s)', $n, $i);
        }
        return BWACHText::t('ℹ️ Found: %s. Push, email and SMTP settings can be imported into the form (saved only with "Apply").', implode(', ', $names));
    }

    private function NotifyPanel(): array
    {
        $targets = $this->pushTargets();
        $nK = count(array_filter($targets, function ($x) { return $x === 'kachel'; }));
        $nW = count($targets) - $nK;
        $chosen = trim($this->ReadPropertyString('PushTargets')) !== '' && trim($this->ReadPropertyString('PushTargets')) !== '[]';
        $pushLine = count($targets) === 0
            ? BWACHText::t('ℹ️ No push targets found (neither tile visualization nor classic WebFront) — push has no effect, email still works.')
            : BWACHText::t($nK === 1 ? '✅ Push targets: %s tile visualization, %s WebFront (%s).' : '✅ Push targets: %s tile visualizations, %s WebFront (%s).', $nK, $nW,
                $chosen ? BWACHText::t('as selected below') : BWACHText::t('all found, as long as nothing is selected below'));
        $mi = $this->ReadPropertyInteger('MailInstance');
        if (!$this->ReadPropertyBoolean('NotifyMail')) {
            $mailLine = BWACHText::t('ℹ️ Email is off.');
        } elseif ($mi <= 0 || !IPS_InstanceExists($mi)) {
            $mailLine = BWACHText::t('⚠️ Email is switched on, but no SMTP instance is selected.');
        } else {
            $to = trim($this->ReadPropertyString('MailTo'));
            $mailLine = BWACHText::t('✅ Email via "%s" to %s.', IPS_GetName($mi), $to !== '' ? $to : BWACHText::t('the recipient set in the SMTP instance'));
        }
        $head = $this->ReadPropertyBoolean('NotificationsActive')
            ? BWACHText::t('🔔 Notifications are ON.')
            : BWACHText::t('ℹ️ Notifications are OFF — nothing is sent until you switch on "Notifications active".');

        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🔔  Notifications',
            'items' => [
                ['type' => 'CheckBox', 'name' => 'NotificationsActive', 'caption' => 'Notifications active'],
                ['type' => 'Label', 'name' => 'NotifyStatus', 'caption' => $head . ' ' . $pushLine . ' ' . $mailLine],
                ['type' => 'Label', 'caption' => 'Notified are "battery empty", "battery low" and "radio silence". Doubtful data (outdated, contradiction) only appear in the weekly report and in the tables. Several findings of one run arrive as ONE message.'],
                ['type' => 'CheckBox', 'name' => 'NotifyPush', 'caption' => 'Notify via push (tile visualization and WebFront)'],
                [
                    'type' => 'List', 'name' => 'PushTargets', 'caption' => 'Only these push targets (empty = all found)', 'rowCount' => 3, 'add' => true, 'delete' => true,
                    'columns' => [
                        ['caption' => 'Instance', 'name' => 'Instance', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'SelectInstance']],
                    ],
                ],
                ['type' => 'CheckBox', 'name' => 'NotifyMail', 'caption' => 'Notify via email'],
                ['type' => 'SelectInstance', 'name' => 'MailInstance', 'caption' => 'SMTP instance'],
                ['type' => 'ValidationTextBox', 'name' => 'MailTo', 'caption' => 'Recipients (several separated by commas; empty = the one set in the SMTP instance)'],
                ['type' => 'Button', 'caption' => '📨 Send test notification', 'onClick' => 'echo BWACH_SendTest($id);'],
                ['type' => 'Label', 'name' => 'ImportStatus', 'caption' => $this->importLine()],
                ['type' => 'Button', 'caption' => '📥 Import from BY_BatterieMonitor', 'onClick' => 'echo BWACH_ImportOld($id);'],
                ['type' => 'NumberSpinner', 'name' => 'ConfirmMinutes', 'caption' => 'First notification only once the finding has persisted this long (0 = immediately)', 'suffix' => BWACHText::t(' minutes'), 'minimum' => 0, 'maximum' => 1440],
                ['type' => 'Label', 'caption' => 'ℹ️ Filters out single outliers of a device (one bad value, then good again). Critical devices always notify immediately. Reminders and escalation are not affected.'],
                ['type' => 'NumberSpinner', 'name' => 'ReminderDays', 'caption' => 'Reminder while the finding persists, every', 'suffix' => BWACHText::t(' days'), 'minimum' => 1, 'maximum' => 90],
                ['type' => 'NumberSpinner', 'name' => 'CriticalReminderDays', 'caption' => 'Reminder for critical devices every', 'suffix' => BWACHText::t(' days'), 'minimum' => 1, 'maximum' => 30],
                ['type' => 'NumberSpinner', 'name' => 'SnoozeDays', 'caption' => '"Remind me later" postpones by', 'suffix' => BWACHText::t(' days'), 'minimum' => 1, 'maximum' => 90],
                ['type' => 'NumberSpinner', 'name' => 'EscalateHours', 'caption' => 'Escalation for critical devices if nobody reacts, after (0 = off)', 'suffix' => BWACHText::t(' hours'), 'minimum' => 0, 'maximum' => 720],
                ['type' => 'CheckBox', 'name' => 'EscalatePush', 'caption' => 'Escalation via push'],
                ['type' => 'CheckBox', 'name' => 'EscalateMail', 'caption' => 'Escalation via email'],
                ['type' => 'CheckBox', 'name' => 'QuietEnabled', 'caption' => 'Quiet hours: no notifications at night (the notification is sent afterwards)'],
                ['type' => 'NumberSpinner', 'name' => 'QuietFromHour', 'caption' => 'Quiet hours from', 'suffix' => BWACHText::t(':00'), 'minimum' => 0, 'maximum' => 23],
                ['type' => 'NumberSpinner', 'name' => 'QuietToHour', 'caption' => 'Quiet hours until', 'suffix' => BWACHText::t(':00'), 'minimum' => 0, 'maximum' => 23],
                ['type' => 'CheckBox', 'name' => 'CriticalIgnoresQuiet', 'caption' => 'Critical devices may break through quiet hours'],
                ['type' => 'CheckBox', 'name' => 'DigestEnabled', 'caption' => 'Send weekly report'],
                ['type' => 'Select', 'name' => 'DigestWeekday', 'caption' => 'Weekday', 'options' => [
                    ['caption' => 'Monday', 'value' => 1], ['caption' => 'Tuesday', 'value' => 2], ['caption' => 'Wednesday', 'value' => 3],
                    ['caption' => 'Thursday', 'value' => 4], ['caption' => 'Friday', 'value' => 5], ['caption' => 'Saturday', 'value' => 6], ['caption' => 'Sunday', 'value' => 7],
                ]],
                ['type' => 'NumberSpinner', 'name' => 'DigestHour', 'caption' => 'From', 'suffix' => BWACHText::t(':00'), 'minimum' => 0, 'maximum' => 23],
                ['type' => 'CheckBox', 'name' => 'DigestWhenOk', 'caption' => 'Send weekly report even if everything is OK'],
                ['type' => 'PopupButton', 'caption' => 'How do notifications work together?', 'width' => '480px', 'popup' => [
                    'caption' => 'Notifications',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'First notification: as soon as a device is "empty", "low" or "silent". After that only the reminder at the set interval while the finding persists. A NEW, worse finding (e.g. from "low" to "empty") is again a first notification.'],
                        ['type' => 'Label', 'caption' => 'Quiet hours: notifications are not discarded but sent after the quiet hours. Critical devices may break through them if that is ticked.'],
                        ['type' => 'Label', 'caption' => 'Escalation: only for critical devices. If nobody has acknowledged after the set time, a second notification goes out via the escalation channels.'],
                        ['type' => 'Label', 'caption' => 'Flapping values: a finding only counts as ended once it has been gone for 24 hours. So a device jumping between "empty" and "ok" does not notify anew every time.'],
                        ['type' => 'Label', 'caption' => 'Delivery: if a notification cannot be delivered via any channel, this is written to the Symcon message log, and the monitor tries again on the next run.'],
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
                $list[] = ['id' => (string)$d['id'], 'name' => $d['name'], 'need' => $cell !== null ? max(1, (int)$d['cells']) . '× ' . $cell : '', 'text' => $d['text']];
            }
            $places[] = ['place' => $place, 'devices' => $list];
        }
        $stats = [];
        foreach (['byCell', 'byModule'] as $k) {
            foreach ($v[$k] as $g => $s) {
                $stats[] = ['group' => BWACHText::t($k === 'byCell' ? 'Cell %s' : 'System %s', $g), 'text' => (int)$s['n'] === 1 ? BWACHText::t('%s days (%s interval)', BWACHLogik::num($s['median']), $s['n']) : BWACHText::t('%s days (%s intervals)', BWACHLogik::num($s['median']), $s['n'])];
            }
        }
        foreach ($v['peers'] as $pe) {
            $stats[] = ['group' => '👥 ' . $pe['name'], 'text' => $pe['text']];
        }
        $stats[] = ['group' => '🌡', 'text' => $v['cold']];
        $stats[] = ['group' => BWACHText::t('🎯 Forecast'), 'text' => $this->accuracyText($v['accuracy'])];
        foreach ($v['life'] as $l) {
            $stats[] = ['group' => $l['name'], 'text' => implode(', ', array_map(function ($d) { return BWACHText::t('%s days', BWACHLogik::num($d)); }, $l['days']))];
        }
        $tpl   = (string)$this->ReadPropertyString('ShopLink');
        $items = [];
        $i     = 0;
        foreach (array_keys($v['shopping']['counts']) as $label) {
            $items[] = ['text' => $v['shopping']['lines'][$i] ?? '', 'url' => (string)BWACHPrognose::shopUrl($tpl, (string)$label)];
            $i++;
        }
        return [
            'horizon' => $v['horizon'],
            'where'   => BWACHText::t('Open instance "%s", panel "Device settings", column "Cell type"', IPS_GetName($this->InstanceID)),
            'changes' => array_map(function ($c) { return ['label' => $c['label'], 'text' => BWACHPrognose::changeStatsText($c)]; }, $v['changes']),
            'lines'   => $v['shopping']['lines'],
            'items'   => $items,
            'covered' => $v['shopping']['covered'],
            'text'    => BWACHPrognose::shoppingText($v['shopping'], $v['round'], (int)$v['horizon'], $v['round']['until'] === null ? '' : date('d.m.Y', $v['round']['until'])),
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
        // Gesucht wird über die Textanfänge der Befunde, übersetzt wie diese (gleiche englische Anfänge)
        $want = [];
        if ($r['funk'] === 'still') {
            // Nicht erreichbar / Funkstille geht dem letzten Batteriestand vor
            $want = [BWACHText::t('Unreachable'), BWACHText::t('Radio silence')];
        }
        if ($r['status'] === BWACHLogik::ST_EMPTY) {
            $want[] = BWACHText::t('Battery empty');
        } elseif ($r['status'] === BWACHLogik::ST_LOW) {
            $want[] = BWACHText::t('Battery low');
        } elseif (!empty($r['soon'])) {
            $want[] = BWACHText::t('Battery empty soon');
        }
        foreach ($want as $prefix) {
            foreach ($r['reasons'] as $reason) {
                if (strpos($reason, $prefix) === 0) {
                    return $reason;
                }
            }
        }
        return $r['reasons'][0] ?? '';
    }

    /** Kurzer Befund hinter dem Gerätenamen in der Auswahl, z. B. „ (leer, Funkstille)“. */
    private function shortFinding(array $r): string
    {
        $parts = [];
        if ($r['status'] === BWACHLogik::ST_EMPTY) { $parts[] = BWACHText::t('empty'); }
        if ($r['status'] === BWACHLogik::ST_LOW) { $parts[] = BWACHText::t('low'); }
        if ($r['funk'] === 'still') { $parts[] = BWACHText::t('radio silence'); }
        if (!$parts && $r['urgency'] > 0) { $parts[] = BWACHText::t('check data'); }
        return $parts ? ' (' . implode(', ', $parts) . ')' : '';
    }

    /** Auswahl der Geräte, die außer Betrieb gesetzt sind (zum einzelnen Wiederaufnehmen). */
    private function retiredOptions(?array $found): array
    {
        $opts = [['caption' => '— Select device —', 'value' => '']];
        foreach (array_keys($this->loadJson('Retired')) as $k) {
            $opts[] = ['caption' => $found !== null && isset($found['devices'][$k]) ? (string)$found['devices'][$k]['name'] : (string)$k, 'value' => (string)$k];
        }
        return $opts;
    }

    /** Frischt Zeile und Auswahl „Außer Betrieb“ in beiden Panels (Quittieren, Geräte-Einstellungen) auf. */
    private function refreshRetiredFields(?array $found): void
    {
        $line = $this->retiredLine($found);
        $opts = json_encode($this->retiredOptions($found));
        foreach (['', 'Settings'] as $suffix) {
            $this->UpdateFormField('RetiredLine' . $suffix, 'caption', $line);
            $this->UpdateFormField('RetiredPick' . $suffix, 'options', $opts);
            $this->UpdateFormField('RetiredPick' . $suffix, 'value', '');
        }
    }

    /** Zeile, Auswahl und Schaltfläche zum Wiederaufnehmen; $suffix trennt die Feldnamen je Panel. */
    private function retiredItems(?array $found, string $suffix): array
    {
        return [
            ['type' => 'Label', 'name' => 'RetiredLine' . $suffix, 'caption' => $this->retiredLine($found)],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'Select', 'name' => 'RetiredPick' . $suffix, 'caption' => 'Device out of service', 'width' => '320px', 'options' => $this->retiredOptions($found)],
                ['type' => 'Button', 'caption' => '↩️ Put this device back into service', 'onClick' => 'echo BWACH_Unretire($id, $RetiredPick' . $suffix . ');'],
            ]],
        ];
    }

    private function retiredLine(?array $found): string
    {
        $retired = $this->loadJson('Retired');
        if (!$retired) {
            return BWACHText::t('ℹ️ No device is set out of service.');
        }
        $names = [];
        foreach (array_keys($retired) as $k) {
            $names[] = $found !== null && isset($found['devices'][$k]) ? (string)$found['devices'][$k]['name'] : (string)$k;
        }
        return BWACHText::t('💤 Out of service (%s): %s', count($names), implode(', ', array_slice($names, 0, 8)) . (count($names) > 8 ? ' …' : ''));
    }

    private function diaryLine(): string
    {
        $dl = [];
        foreach (array_slice(array_reverse($this->loadJson('Diary')), 0, 6) as $d) {
            $dl[] = date('d.m.Y', (int)$d['t']) . ' ' . $d['name'] . ' (' . ($d['type'] === 'erkannt' ? BWACHText::t('detected') : ($d['type'] === 'nachgetragen' ? BWACHText::t('added later') : BWACHText::t('entered'))) . ')';
        }
        return $dl
            ? BWACHText::t('📓 Latest in the log: %s', implode(' · ', $dl))
            : BWACHText::t('📓 The battery log is still empty. Replacements are detected (percentage jumps up, "low" flag is reset) or entered here. The full list is in the variable "Battery log".');
    }

    private function AckPanel(): array
    {
        $found = $this->found();
        $options = [['caption' => '— Select device —', 'value' => '']];
        $placeOptions = [['caption' => '— Select location —', 'value' => '']];
        if ($found !== null) {
            $rows = $this->evaluateAll($found);
            foreach ($this->views($rows, $this->now())['round']['places'] as $pl => $devs) {
                $placeOptions[] = ['caption' => $pl . ' (' . count($devs) . ')', 'value' => (string)$pl];
            }
            usort($rows, function ($a, $b) { return [$b['r']['urgency'], $a['name']] <=> [$a['r']['urgency'], $b['name']]; });
            foreach ($rows as $r) {
                $options[] = ['caption' => $r['name'] . $this->shortFinding($r['r']), 'value' => $r['id']];
            }
        }
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '✅  Acknowledge and battery log',
            'items' => [
                ['type' => 'Label', 'caption' => 'Select a device and say what is going on: "I replaced it" enters the replacement in the log (3 days of waiting until the device reports the new level), "Remind me later" postpones the notification, "Out of service" permanently removes the device from monitoring.'],
                ['type' => 'Select', 'name' => 'AckDevice', 'caption' => 'Device', 'options' => $options],
                ['type' => 'Select', 'name' => 'AckAction', 'caption' => 'What is going on?', 'options' => [
                    ['caption' => 'I replaced it', 'value' => BWACHMeldung::ACK_REPLACED],
                    ['caption' => 'Remind me later', 'value' => BWACHMeldung::ACK_SNOOZE],
                    ['caption' => 'Device is out of service', 'value' => BWACHMeldung::ACK_RETIRED],
                ]],
                ['type' => 'Button', 'caption' => '✔️ Execute', 'onClick' => 'echo BWACH_Acknowledge($id, $AckDevice, $AckAction);'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'AckDate', 'caption' => 'Date of an earlier replacement (DD.MM.YYYY)', 'width' => '320px'],
                    ['type' => 'Button', 'caption' => '📅 Add replacement later', 'onClick' => 'echo BWACH_AddReplacement($id, $AckDevice, $AckDate);'],
                ]],
                ['type' => 'Label', 'caption' => 'ℹ️ "Add replacement later" enters a dated replacement in the log that happened before the monitor existed. This improves lifetime and preventive replacement, but does not trigger a notification.'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Select', 'name' => 'AckPlace', 'caption' => 'Location from the replacement round', 'width' => '320px', 'options' => $placeOptions],
                    ['type' => 'Button', 'caption' => '✔️ All replaced there', 'onClick' => 'echo BWACH_AcknowledgePlace($id, $AckPlace);'],
                ]],
                ['type' => 'Label', 'name' => 'AckStatus', 'caption' => 'ℹ️ Nothing acknowledged yet.'],
                ['type' => 'Button', 'caption' => '↩️ Undo last acknowledgement', 'onClick' => 'echo BWACH_UndoAcknowledge($id);'],
                ...$this->retiredItems($found, ''),
                ['type' => 'PopupButton', 'caption' => 'Put all out-of-service devices back …', 'width' => '320px', 'popup' => [
                    'caption' => 'Put all back into service?',
                    'items' => [
                        ['type' => 'Label', 'caption' => '⚠️ This puts ALL devices of the list "Out of service" back into monitoring. If you removed them on purpose, you must set them out of service again one by one afterwards. For a single device use the selection above.'],
                        ['type' => 'Button', 'caption' => '↩️ Yes, put all back into service', 'onClick' => 'echo BWACH_UnretireAll($id);'],
                    ],
                ]],
                ['type' => 'Label', 'name' => 'DiaryLine', 'caption' => $this->diaryLine()],
                ['type' => 'PopupButton', 'caption' => 'Delete automatically detected replacements …', 'width' => '320px', 'popup' => [
                    'caption' => 'Delete detected replacements?',
                    'items' => [
                        ['type' => 'Label', 'caption' => '⚠️ This deletes all AUTOMATICALLY detected entries from the battery log (type "detected"). Replacements entered by hand or added later remain. Useful if the monitor mistook voltage fluctuations for battery replacements. Lifetime, statistics and forecast comparison are rebuilt afterwards.'],
                        ['type' => 'Button', 'caption' => '🗑️ Yes, delete detected replacements', 'onClick' => 'echo BWACH_DeleteDetectedChanges($id);'],
                    ],
                ]],
                ['type' => 'Select', 'name' => 'ReplaceDetect', 'caption' => 'Detect battery replacements automatically', 'options' => [
                    ['caption' => 'By percentage jump and "low" flag', 'value' => 2],
                    ['caption' => 'By percentage jump only (for flickering "low" flags, e.g. HomeMatic)', 'value' => 1],
                    ['caption' => 'Off (enter replacements by hand only)', 'value' => 0],
                ]],
                ['type' => 'NumberSpinner', 'name' => 'ReplaceJumpPercent', 'caption' => 'Detect a replacement if the percentage rises by at least this much', 'suffix' => BWACHText::t(' percentage points'), 'minimum' => 5, 'maximum' => 90],
            ],
        ];
    }

    private function ForecastPanel(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧮  Forecast and shopping',
            'items' => [
                ['type' => 'Label', 'caption' => 'The monitor records the history of every device (in the module itself, no Symcon archive needed) and estimates from it how long the battery will last. This only works where the device reports its state of charge finely and often enough; otherwise it honestly says "days remaining unknown" with a reason. A forecast needs at least 4 data points over 14 days since the last battery replacement.'],
                ['type' => 'NumberSpinner', 'name' => 'ForecastHorizonDays', 'caption' => 'Shopping list and replacement round for the next', 'suffix' => BWACHText::t(' days'), 'minimum' => 7, 'maximum' => 365],
                ['type' => 'ValidationTextBox', 'name' => 'ShopLink', 'caption' => 'Link for the shopping list (optional, with {Zelltyp})'],
                ['type' => 'Label', 'caption' => 'ℹ️ If you like, enter the search of your retailer here, e.g. https://www.example.org/search?q={Zelltyp}. {Zelltyp} is replaced by the designation (AAA, CR2032 …); the tile then shows a search link after every line of the shopping list. Empty = no link; the monitor names no retailer and sends nothing there.'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '🛒 Shopping list as text', 'onClick' => 'echo BWACH_ShoppingText($id);'],
                    ['type' => 'Button', 'caption' => '✉️ Send shopping list now', 'onClick' => 'echo BWACH_SendShopping($id);'],
                    ['type' => 'Button', 'caption' => '📄 Device list as CSV', 'onClick' => 'echo BWACH_DeviceListCsv($id);'],
                ]],
                ['type' => 'List', 'name' => 'Stock', 'caption' => 'Stock at home (subtracted from the shopping list)', 'rowCount' => 4, 'add' => true, 'delete' => true,
                    'columns' => [
                        ['caption' => 'Cell type', 'name' => 'Cell', 'width' => 'auto', 'add' => 'aaa_alkali', 'edit' => ['type' => 'Select', 'options' => array_values(array_filter(BWACHZelle::options(), function ($o) { return $o['value'] !== BWACHZelle::UNKNOWN; }))]],
                        ['caption' => 'Quantity on hand', 'name' => 'Count', 'width' => '110px', 'add' => 0, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 9999]],
                    ]],
                ['type' => 'NumberSpinner', 'name' => 'PreventiveMonths', 'caption' => 'Replace critical devices preventively every (0 = off)', 'suffix' => BWACHText::t(' months'), 'minimum' => 0, 'maximum' => 60],
                ['type' => 'Label', 'caption' => 'ℹ️ Preventive replacement applies only to devices marked as "critical" under "Device settings" or by a group rule (e.g. smoke detectors), and only if a last replacement is in the log (detected, entered or added later). A due replacement appears in the replacement round and is notified, even if the battery is still good.'],
                ['type' => 'NumberSpinner', 'name' => 'SoonDays', 'caption' => 'Notify "empty soon" if the forecast says the battery lasts at most', 'suffix' => BWACHText::t(' more days'), 'minimum' => 3, 'maximum' => 90],
                ['type' => 'Label', 'caption' => 'ℹ️ "Empty soon" is only notified with high or medium forecast confidence, never with low.'],
                ['type' => 'CheckBox', 'name' => 'LearnIntervals', 'caption' => 'Learn reporting behavior (detect radio silence earlier if a device otherwise sends very regularly)'],
                ['type' => 'SelectVariable', 'name' => 'OutdoorTempVar', 'caption' => 'Outdoor temperature (optional, for the effect of cold on discharge)'],
                ['type' => 'NumberSpinner', 'name' => 'ColdBelow', 'caption' => 'Counts as "cold" below', 'suffix' => ' °C', 'minimum' => -20, 'maximum' => 20],
                ['type' => 'Label', 'caption' => 'ℹ️ The effect of cold is only evaluated once at least 3 devices each have 3 periods in cold and in warm weather — so after one winter at the earliest. Until then it honestly says "not enough data yet".'],
                ['type' => 'NumberSpinner', 'name' => 'PollAfterDays', 'caption' => 'Query sleeping devices if the battery value is older than', 'suffix' => BWACHText::t(' days'), 'minimum' => 3, 'maximum' => 365],
                ['type' => 'NumberSpinner', 'name' => 'PollEveryDays', 'caption' => 'Query at most every', 'suffix' => BWACHText::t(' days'), 'minimum' => 1, 'maximum' => 90],
                ['type' => 'Label', 'caption' => 'ℹ️ The query is switched on per device under "Device settings" (column "Query"), OFF by default. It exists for Z-Wave and Matter devices and Comet WiFi thermostats. A sleeping Z-Wave device only answers it at its next wake-up; until then it waits in the queue of the Z-Wave instance; querying too often fills this queue. Matter devices answered within a few seconds in first tests. For Matter, the power source instance (endpoint 0) is queried, because the battery level lives there. A Comet thermostat gets the "all fields" request, because only that contains the battery level; it noticeably wakes the device, so only with the tick and at the set interval.'],
                ['type' => 'NumberSpinner', 'name' => 'OrphanDays', 'caption' => 'Suggest a device as "probably removed" if it has been silent this long (0 = off)', 'suffix' => BWACHText::t(' days'), 'minimum' => 0, 'maximum' => 720],
                ['type' => 'PopupButton', 'caption' => 'How reliable is the forecast?', 'width' => '500px', 'popup' => [
                    'caption' => 'How reliable is the forecast, and what does "calculated from voltage" mean?',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'The days remaining are an estimate from the history so far (robust straight line, insensitive to single outliers). High confidence: steady history over at least 60 days and 8 data points; medium: at least 30 days; otherwise low. Batteries do not always discharge evenly (cold, load, radio problems) — the number is a guide, not a promise.'],
                        ['type' => 'Label', 'caption' => 'If a device reports only a voltage, the monitor converts it into a state of charge using a typical discharge curve of the selected cell type. This is an approximation (no manufacturer datasheet values) and is always shown as "calculated from voltage". If the voltage does not fit the selected cell type, the monitor reports that instead of guessing.'],
                        ['type' => 'Label', 'caption' => 'The monitor cannot reliably detect the cell type from the voltage (3 V can be a coin cell or two alkaline cells). It names candidates but never chooses by itself.'],
                    ],
                ]],
            ],
        ];
    }

    private function ThresholdPanel(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '⚙️  Thresholds',
            'items' => [
                ['type' => 'NumberSpinner', 'name' => 'LowPercent', 'caption' => 'Battery low below', 'suffix' => ' %', 'minimum' => 1, 'maximum' => 99],
                ['type' => 'NumberSpinner', 'name' => 'EmptyPercent', 'caption' => 'Battery empty up to', 'suffix' => ' %', 'minimum' => 0, 'maximum' => 50],
                ['type' => 'NumberSpinner', 'name' => 'CriticalLowPercent', 'caption' => 'Low below — critical devices', 'suffix' => ' %', 'minimum' => 1, 'maximum' => 99],
                ['type' => 'NumberSpinner', 'name' => 'ValueOldDays', 'caption' => 'Battery value counts as outdated after', 'suffix' => BWACHText::t("\u{00A0}days"), 'minimum' => 1, 'maximum' => 3650],
                ['type' => 'NumberSpinner', 'name' => 'StillDays', 'caption' => 'Radio silence after (standard devices)', 'suffix' => BWACHText::t("\u{00A0}days"), 'minimum' => 1, 'maximum' => 365],
                ['type' => 'NumberSpinner', 'name' => 'StillDaysEvent', 'caption' => 'Radio silence after (event sensors)', 'suffix' => BWACHText::t("\u{00A0}days"), 'minimum' => 1, 'maximum' => 365],
                ['type' => 'NumberSpinner', 'name' => 'UnreachMinutes', 'caption' => 'If a device reports "unreachable" (e.g. HomeMatic UNREACH), this counts as radio silence after (0 = do not evaluate the report)', 'suffix' => BWACHText::t(' minutes'), 'minimum' => 0, 'maximum' => 10080],
                ['type' => 'NumberSpinner', 'name' => 'CheckIntervalMin', 'caption' => 'Check interval', 'suffix' => ' min', 'minimum' => 5, 'maximum' => 1440],
                ['type' => 'PopupButton', 'caption' => 'How do the thresholds work together?', 'width' => '400px', 'popup' => [
                    'caption' => 'Thresholds',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Percentage up to the "empty" limit = empty, below the "low" limit = low. Critical devices (marked under Device settings) report "low" earlier.'],
                        ['type' => 'Label', 'caption' => 'A battery value counts as outdated if the device has not reported it for this many days. This is not an alarm but a hint: the displayed level is then no longer reliable. Some devices report their battery level only every few months; for them the age check can be switched off individually.'],
                        ['type' => 'Label', 'caption' => 'Radio silence depends on the sign of life of the device (any update), not on the battery value. Choose the days generously if a device sends only rarely.'],
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
    /** Zelltyp, den das Gerät selbst als Ersatz nennt (Matter), sonst „unbekannt“. */
    private function cellFromDevice(int $id): string
    {
        if ($id <= 0 || !IPS_InstanceExists($id)) {
            return BWACHZelle::UNKNOWN;
        }
        return BWACHZelle::fromDescription($this->replacementText($id)) ?? BWACHZelle::UNKNOWN;
    }

    /** Eigenschaften je Gerät, die nur zum Anzeigen und Sortieren in der Liste dienen (nichts davon ist eine Einstellung). */
    private function deviceInfo(int $id, array $pcts, array $own, array $labels, array $rules, array $diary = [], array $retired = [], int $sensor = 0): array
    {
        $rowKey = $sensor > 0 ? $id . '-' . $sensor : (string)$id;
        if ($id <= 0 || !IPS_InstanceExists($id)) {
            // Verwaiste Zeile: Instanz gelöscht. Nach der Matter-Umstellung gelten die Werte für das neue Gerät weiter.
            $to = array_search($rowKey, array_map('strval', $this->loadJson('Meta')['adopted'] ?? []), true);
            $effect = $to !== false
                ? BWACHText::t('⚠️ Instance deleted; these settings apply to #%s until that row is saved', $to)
                : BWACHText::t('⚠️ Instance deleted: delete this row');
            return ['Name' => BWACHText::t('(instance missing)'), 'SensorName' => '', 'Place' => '', 'Module' => '', 'Percent' => '—', 'PercentSort' => 1000.0, 'Effect' => $effect, 'Change' => '', 'ChangeSort' => 0, 'effectiveCell' => BWACHZelle::UNKNOWN, 'effectiveExcluded' => true];
        }
        $label = $labels[$id] ?? ['name' => IPS_GetName($id), 'place' => '', 'module' => (string)(IPS_GetInstance($id)['ModuleInfo']['ModuleName'] ?? '')];
        if ($sensor > 0) {
            // Regeln sehen den Sensor wie die Auswertung: Name „Instanz › Sensor“ und das Kriterium „Sensor“
            $svn = IPS_VariableExists($sensor) ? IPS_GetName($sensor) : '';
            $label['name']   = $label['name'] . ' › ' . ($svn !== '' ? $svn : '#' . $sensor);
            $label['sensor'] = $svn;
        }
        $p     = $pcts[$sensor > 0 ? $rowKey : $id] ?? null;
        $chg   = null;   // jüngster erfasster Wechsel dieses Eintrags; bei der Instanz als Ganzes über alle ihre Sensoren
        foreach ($diary as $de) {
            $dk = (string)$de['key'];
            $mine = $sensor > 0 ? $dk === $rowKey : ($dk === (string)$id || strpos($dk, $id . '-') === 0);
            if ($mine && ($chg === null || (int)$de['t'] > $chg)) {
                $chg = (int)$de['t'];
            }
        }
        $eff   = BWACHLogik::applyRules([
            'group' => (string)$own['Group'], 'critical' => (bool)$own['Critical'], 'ignoreAge' => (bool)$own['IgnoreAge'],
            'excluded' => (bool)$own['Excluded'], 'cell' => (string)$own['Cell'], 'cells' => (int)$own['Cells'], 'poll' => (bool)$own['Poll'],
            'stillDays' => (int)($own['StillDays'] ?? 0), 'noStill' => (bool)($own['NoStill'] ?? false),
            'place' => (string)($own['PlaceOwn'] ?? ''), 'detect' => (int)($own['Detect'] ?? -1),
        ], $label, $rules);
        $parts = [BWACHZelle::isKnown($eff['cell']) ? ($eff['cells'] > 1 ? $eff['cells'] . '× ' : '') . (string)BWACHZelle::shopLabel($eff['cell']) : BWACHText::t('cell type not set')];
        if ($eff['group'] === BWACHLogik::GROUP_EVENT) { $parts[] = BWACHText::t('event sensor'); }
        if ($eff['critical']) { $parts[] = BWACHText::t('critical'); }
        if ($eff['ignoreAge']) { $parts[] = BWACHText::t('without age check'); }
        if (!empty($eff['noStill'])) { $parts[] = BWACHText::t('without radio silence check'); }
        foreach (array_keys($retired) as $rk) {
            $rks = (string)$rk;
            if ($sensor > 0 ? $rks === $rowKey : ($rks === (string)$id || strpos($rks, $id . '-') === 0)) {
                $parts[] = BWACHText::t('out of service');
                break;
            }
        }
        if ((int)($eff['stillDays'] ?? 0) > 0) { $parts[] = (int)$eff['stillDays'] === 1 ? BWACHText::t('radio silence after %s day', 1) : BWACHText::t('radio silence after %s days', (int)$eff['stillDays']); }
        if ($eff['excluded']) { $parts[] = BWACHText::t('excluded'); }
        $det = (int)($eff['detect'] ?? -1);
        if ($det === 1) { $parts[] = BWACHText::t('replacement detection by percentage jump only'); }
        if ($det === 0) { $parts[] = BWACHText::t('no automatic replacement detection'); }
        if ($det === 2 && $this->ReadPropertyInteger('ReplaceDetect') !== 2) { $parts[] = BWACHText::t('replacement detection by percentage jump and flag'); }
        if ($eff['rule'] !== null) {
            $rl = $rules[$eff['rule']];
            $parts[] = $rl['Label'] !== '' ? BWACHText::t('rule %s (%s)', $eff['rule'] + 1, $rl['Label']) : BWACHText::t('rule %s', $eff['rule'] + 1);
        }
        $sensorName = $sensor > 0 && IPS_VariableExists($sensor) ? IPS_GetName($sensor) : '';
        return [
            'Name'          => $sensor > 0 ? $label['name'] . ' › ' . ($sensorName !== '' ? $sensorName : '#' . $sensor) : $label['name'],
            'SensorName'    => $sensor > 0 ? ($sensorName !== '' ? $sensorName : '#' . $sensor) : '',
            'Place'         => trim((string)($eff['place'] ?? '')) !== '' ? (string)$eff['place'] : $label['place'],
            'Module'        => $label['module'],
            'Percent'       => $this->percentColumn($p, $pcts['v:' . $rowKey] ?? null),
            'PercentSort'   => $p === null ? 1000.0 : round($p, 1),   // Geräte ohne Wert landen bei „aufsteigend“ hinten
            'Effect'        => implode(' · ', $parts),
            'Change'        => $chg === null ? '—' : date('d.m.Y', $chg),
            'ChangeSort'    => $chg === null ? 0 : $chg,
            'effectiveCell' => $eff['cell'],
            'effectiveExcluded' => (bool)$eff['excluded'],
        ];
    }

    /** Spalte „Batteriestand“: Prozent und, wenn bekannt, die gemessene Spannung („40 % · 1,2 V“). */
    private function percentColumn(?float $percent, ?float $volt): string
    {
        $parts = [];
        if ($percent !== null) {
            $parts[] = BWACHLogik::num($percent) . ' %';
        }
        if ($volt !== null) {
            $parts[] = BWACHLogik::num($volt) . ' V';
        }
        return $parts ? implode(' · ', $parts) : '—';
    }

    /** Name, Ort und System je Geräteinstanz, wie sie in den Regeln gelten. */
    private function deviceLabels(?array $found): array
    {
        $out = [];
        foreach ($found['devices'] ?? [] as $key => $d) {
            $id = (int)($d['parent'] ?? $key);
            if (isset($out[$id]) || !IPS_InstanceExists($id)) {
                continue;
            }
            $sibs = isset($d['node']) ? $this->matterSiblings($id) : [];
            $out[$id] = $this->deviceLabel($d, $id, true, $sibs);
            if (isset($d['parent'])) {
                $out[$id]['name'] = IPS_GetName($id);   // geteilte Einträge: die Instanz als Ganzes
            }
        }
        return $out;
    }

    /** Aktueller Batteriestand in Prozent je Geräteinstanz (der niedrigste, wenn es mehrere gibt). */
    private function currentPercents(?array $found): array
    {
        $out = [];
        foreach ($found['devices'] ?? [] as $key => $d) {
            $sig = $this->readSignals($d['signals'] ?? []);
            $id  = (int)($d['parent'] ?? $key);
            $rk  = isset($d['parent']) ? (string)$key : (string)$id;
            if (!empty($sig['voltage'])) {
                $out['v:' . $rk] = (float)$sig['voltage']['value'];   // gemessene Spannung, nur zur Anzeige
            }
            if (empty($sig['percent'])) {
                continue;
            }
            $v  = (float)$sig['percent']['value'] * (float)($sig['percent']['scale'] ?? 1.0);
            $out[$id] = isset($out[$id]) ? min($out[$id], $v) : $v;
            if (isset($d['parent'])) {
                $out[(string)$key] = $v;   // der Wert genau dieses Sensors
            }
        }
        return $out;
    }

    /** Sortierung der Liste: Eigenschaft → Spalte. */
    private const SORT_COLUMNS = [
        'name' => 'Name', 'place' => 'Place', 'module' => 'Module', 'percent' => 'PercentSort',
        'cell' => 'Cell', 'group' => 'Group', 'critical' => 'Critical',
    ];

    private function deviceSort(): array
    {
        $by  = (string)$this->ReadPropertyString('DeviceSortBy');
        $dir = (string)$this->ReadPropertyString('DeviceSortDir');
        return $this->sortSpec($by, $dir);
    }

    private function sortSpec(string $by, string $dir): array
    {
        return [
            'column'    => self::SORT_COLUMNS[$by] ?? 'Name',
            'direction' => $dir === 'descending' ? 'descending' : 'ascending',
        ];
    }

    /** Sortiert die Liste „Geräte-Einstellungen“ im offenen Formular um, ohne ungespeicherte Eingaben anzufassen. */
    public function SetDeviceSort(string $By, string $Dir): void
    {
        $this->UpdateFormField('DeviceSettings', 'sort', json_encode($this->sortSpec($By, $Dir)));
    }

    private function deviceRows(): array
    {
        $this->initCells();
        $saved  = json_decode((string)$this->ReadPropertyString('DeviceSettings'), true);
        $rows   = [];
        $have   = [];
        $filled = 0;
        $found0 = $this->found();
        $pcts   = $this->currentPercents($found0);
        $labels = $this->deviceLabels($found0);
        $rules  = $this->groupRules();
        $diaryD = $this->loadJson('Diary');
        $retD   = $this->loadJson('Retired');
        $open   = [];
        $instRows = [];   // gespeicherte Zeilen „Instanz als Ganzes“: Vorbild für neue Sensor-Zeilen derselben Instanz
        $adopted  = $this->loadJson('Meta')['adopted'] ?? [];   // neues Matter-Composite-Gerät => gelöschte alte Instanz
        if (is_array($saved)) {
            foreach ($saved as $r) {
                $id  = (int)($r['Instance'] ?? 0);
                $sen = max(0, (int)($r['Sensor'] ?? 0));
                $hk  = $sen > 0 ? $id . '-' . $sen : (string)$id;
                if ($id > 0 && isset($have[$hk])) {
                    continue;   // doppelte Zeilen derselben Instanz (oder desselben Sensors): die erste gilt
                }
                if ($id > 0) {
                    $have[$hk] = true;
                }
                // Ein noch nicht gewählter Zelltyp wird mit dem vorbelegt, was das Gerät meldet; eine Wahl bleibt unberührt
                $cell = BWACHZelle::isKnown((string)($r['Cell'] ?? '')) ? (string)$r['Cell'] : BWACHZelle::UNKNOWN;
                if ($sen === 0 && $cell === BWACHZelle::UNKNOWN && ($fromDev = $this->cellFromDevice($id)) !== BWACHZelle::UNKNOWN) {
                    $cell = $fromDev;
                    $filled++;
                }
                $row = [
                    'Instance'  => $id,
                    'Group'     => (string)($r['Group'] ?? BWACHLogik::GROUP_STANDARD),
                    'Critical'  => (bool)($r['Critical'] ?? false),
                    'IgnoreAge' => (bool)($r['IgnoreAge'] ?? false),
                    'NoStill'   => (bool)($r['NoStill'] ?? false),
                    'Excluded'  => (bool)($r['Excluded'] ?? false),
                    'Cell'      => $cell,
                    'Cells'     => max(1, min(12, (int)($r['Cells'] ?? 1))),
                    'Poll'      => (bool)($r['Poll'] ?? false),
                    'Detect'    => max(-1, min(2, (int)($r['Detect'] ?? -1))),
                    'StillDays' => max(0, min(3650, (int)($r['StillDays'] ?? 0))),
                    'Alias'     => $this->cleanAlias((string)($r['Alias'] ?? '')),
                    'Sensor'    => $sen,
                    'PlaceOwn'  => $this->cleanAlias((string)($r['PlaceOwn'] ?? '')),
                    'ReachVar'  => max(0, (int)($r['ReachVar'] ?? 0)),
                    'ReachInv'  => (bool)($r['ReachInv'] ?? false),
                ];
                if ($sen === 0 && $id > 0) {
                    $instRows[$id] = $row;
                }
                $info   = $this->deviceInfo($id, $pcts, $row, $labels, $rules, $diaryD, $retD, $sen);
                if ($info['effectiveCell'] === BWACHZelle::UNKNOWN && !$info['effectiveExcluded'] && $id > 0 && IPS_InstanceExists($id)) { $open[] = $info['Name']; }
                unset($info['effectiveCell'], $info['effectiveExcluded']);
                $rows[] = $row + $info;
            }
        }
        $new = [];
        $found = $this->found();
        if ($found !== null) {
            foreach ($found['devices'] as $key => $d) {
                $id = (int)($d['parent'] ?? $key);
                if ($id <= 0 || !IPS_InstanceExists($id)) {
                    continue;
                }
                if (isset($d['parent'])) {
                    // Mehrere Sensoren an einer Instanz (z. B. ein Gateway mit vielen Funksensoren): eine Zeile je Sensor
                    $sen = (int)substr((string)$key, strlen((string)$id) + 1);
                    $hk  = $id . '-' . $sen;
                    if ($sen <= 0 || isset($have[$hk])) {
                        continue;
                    }
                    $have[$hk] = true;
                    $base = $instRows[$id] ?? ['Instance' => $id, 'Group' => BWACHLogik::GROUP_STANDARD, 'Critical' => false, 'IgnoreAge' => false,
                        'NoStill' => false, 'Excluded' => false, 'Cell' => BWACHZelle::UNKNOWN, 'Cells' => 1, 'Poll' => false, 'Detect' => -1, 'StillDays' => 0, 'Alias' => '', 'PlaceOwn' => '', 'ReachVar' => 0, 'ReachInv' => false];
                    // Die Einstellung der Instanz gilt bisher für alle ihre Sensoren: die neue Zeile übernimmt sie, ändert also nichts
                    $row = ['Instance' => $id] + array_merge($base, ['Alias' => '', 'Sensor' => $sen]);
                    $info = $this->deviceInfo($id, $pcts, $row, $labels, $rules, $diaryD, $retD, $sen);
                    if ($info['effectiveCell'] === BWACHZelle::UNKNOWN && !$info['effectiveExcluded']) { $open[] = $info['Name']; }
                    unset($info['effectiveCell'], $info['effectiveExcluded']);
                    $new[$hk] = $row + $info;
                    continue;
                }
                $hk = (string)$id;
                if (!isset($have[$hk])) {
                    $have[$hk] = true;
                    $cell = $this->cellFromDevice($id);
                    $filled += $cell !== BWACHZelle::UNKNOWN ? 1 : 0;
                    $row  = ['Instance' => $id, 'Group' => BWACHLogik::GROUP_STANDARD, 'Critical' => false, 'IgnoreAge' => false,
                        'NoStill' => false, 'Excluded' => false, 'Cell' => $cell, 'Cells' => 1, 'Poll' => false, 'Detect' => -1, 'StillDays' => 0, 'Alias' => '', 'Sensor' => 0, 'PlaceOwn' => '', 'ReachVar' => 0, 'ReachInv' => false];
                    // Nach der Matter-Umstellung: die Zeile der gelöschten Instanz als Vorlage, damit „Übernehmen“ nichts verliert
                    $from = (int)($adopted[$hk] ?? 0);
                    if ($from > 0 && isset($instRows[$from])) {
                        $row = array_merge($instRows[$from], ['Instance' => $id, 'Sensor' => 0]);
                    }
                    $info = $this->deviceInfo($id, $pcts, $row, $labels, $rules, $diaryD, $retD);
                    if ($info['effectiveCell'] === BWACHZelle::UNKNOWN && !$info['effectiveExcluded']) { $open[] = $info['Name']; }
                    unset($info['effectiveCell'], $info['effectiveExcluded']);
                    $new[$hk] = $row + $info;
                }
            }
            uasort($new, function ($a, $b) { return strcasecmp($a['Name'], $b['Name']); });
        }
        sort($open, SORT_NATURAL | SORT_FLAG_CASE);
        // Treffer je Regel: wie viele Geräte die Regel als erste trifft
        // (je erkanntem Eintrag, wie die Auswertung: bei Instanzen mit mehreren Sensoren also je Sensor)
        $hits = array_fill(0, count($rules), 0);
        foreach ($found0['devices'] ?? [] as $hk => $hd) {
            $hid = (int)($hd['parent'] ?? $hk);
            if ($hid <= 0 || !IPS_InstanceExists($hid)) {
                continue;
            }
            $hsibs = isset($hd['node']) ? $this->matterSiblings($hid) : [];
            $i = BWACHLogik::firstRule($rules, $this->deviceLabel($hd, $hid, true, $hsibs));
            if ($i !== null) { $hits[$i]++; }
        }
        return ['rows' => array_merge($rows, array_values($new)), 'added' => count($new), 'filled' => $filled, 'open' => $open, 'hits' => $hits, 'labels' => $labels];
    }

    /**
     * Liegt die Instanz (oder eine Verknüpfung darauf) unter dem Startobjekt einer Kachel-Visualisierung?
     * true/false; null, wenn es keine Kachel-Visualisierung gibt oder die Prüfung nicht möglich ist.
     */
    private function tileLinked(): ?bool
    {
        $visus = (array)IPS_GetInstanceListByModuleID(self::KACHEL_GUID);
        if (!$visus) {
            return null;
        }
        $cands = [$this->InstanceID];   // die Instanz selbst und Verknüpfungen auf sie
        if (function_exists('IPS_GetLinkList') && function_exists('IPS_GetLink')) {
            foreach ((array)IPS_GetLinkList() as $lid) {
                $l = IPS_GetLink((int)$lid);
                if (is_array($l) && (int)($l['TargetID'] ?? 0) === $this->InstanceID) {
                    $cands[] = (int)$lid;
                }
            }
        }
        foreach ($visus as $v) {
            $cfg = json_decode((string)IPS_GetConfiguration((int)$v), true);
            if (!is_array($cfg) || !array_key_exists('BaseID', $cfg)) {
                continue;
            }
            $base = (int)$cfg['BaseID'];
            if ($base === 0) {
                return true;   // Wurzel des Objektbaums: alles liegt darunter
            }
            foreach ($cands as $c) {
                $p = (int)$c;
                for ($n = 0; $p > 0 && $n < 64; $n++) {
                    if ($p === $base) {
                        return true;
                    }
                    $p = (int)IPS_GetParent($p);
                }
            }
        }
        return false;
    }

    private function FirstStepsPanel(array $dr): array
    {
        $found = $this->found();
        $steps = BWACHLogik::firstSteps([
            'found' => $found !== null, 'devices' => count($found['devices'] ?? []), 'open' => count($dr['open']),
            'notify' => $this->ReadPropertyBoolean('NotificationsActive'),
            'channel' => $this->ReadPropertyBoolean('NotifyPush') || $this->ReadPropertyBoolean('NotifyMail'),
            'matterNoEp0' => count($this->matterWithoutPower()),
            'tile' => $this->tileLinked(),
        ]);
        $allDone = count(array_filter($steps, function ($s) { return $s['done'] === false; })) === 0;
        $items = [];
        foreach ($steps as $s) {
            $items[] = ['type' => 'Label', 'caption' => ($s['done'] === true ? '✅ ' : ($s['done'] === false ? '⬜ ' : '▫️ ')) . $s['text']];
        }
        return [
            'type' => 'ExpansionPanel', 'expanded' => !$allDone,
            'caption' => '🚀  First steps',
            'items' => $items,
        ];
    }

    private function RulesPanel(array $dr): array
    {
        $rules = $this->groupRules();
        $rows  = [];
        foreach ($rules as $i => $r) {
            $rows[] = ['Nr' => $i + 1] + $r + ['Hits' => (int)($dr['hits'][$i] ?? 0)];
        }
        $open = $dr['open'];
        $line = count($rules) === 0
            ? BWACHText::t('ℹ️ No rule yet. With rules you enter cell type, number of cells and more for whole groups at once, e.g. all devices in the location "Opening contacts".')
            : (count($open) === 0
                ? BWACHText::t('✅ A cell type is known for every device.')
                : (count($open) > 8 ? BWACHText::t('⚠️ Still without cell type: %s and %s more.', implode(', ', array_slice($open, 0, 8)), count($open) - 8) : BWACHText::t('⚠️ Still without cell type: %s.', implode(', ', $open))));
        return [
            'type' => 'ExpansionPanel', 'expanded' => count($rules) === 0 && count($open) > 0,
            'caption' => '👥  Groups',
            'items' => [
                ['type' => 'Label', 'name' => 'RuleLine', 'caption' => $line],
                ['type' => 'Label', 'caption' => 'A rule applies to all devices its pattern matches: by location (category of the instance), system (module), name or, for instances with several sensors, by the sensor (name of the battery variable). Separate several patterns with commas (OR); upper and lower case do not matter. The first matching rule from the top applies, so exceptions belong at the top. A device\'s own setting in "Device settings" takes precedence as soon as it differs from the default (cell type selected, event sensor, critical, excluded). The column "Applies" there shows what finally counts for a device.'],
                [
                    'type' => 'List', 'name' => 'GroupRules', 'caption' => 'Rules', 'rowCount' => max(4, min(30, count($rules) + 1)), 'add' => true, 'delete' => true, 'changeOrder' => true,
                    'loadValuesFromConfiguration' => false,
                    'values' => $rows,
                    'columns' => [
                        ['caption' => 'No.', 'name' => 'Nr', 'width' => '45px', 'add' => 0],
                        ['caption' => 'On', 'name' => 'Active', 'width' => '50px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Label', 'name' => 'Label', 'width' => '160px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Criterion', 'name' => 'Kind', 'width' => '150px', 'add' => BWACHLogik::RULE_PLACE, 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => 'Location (category)', 'value' => BWACHLogik::RULE_PLACE],
                            ['caption' => 'System (module)', 'value' => BWACHLogik::RULE_MODULE],
                            ['caption' => 'Name contains', 'value' => BWACHLogik::RULE_NAME],
                            ['caption' => 'Sensor contains', 'value' => BWACHLogik::RULE_SENSOR],
                        ]]],
                        ['caption' => 'Pattern (comma = or)', 'name' => 'Pattern', 'width' => 'auto', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Cell type', 'name' => 'Cell', 'width' => '230px', 'add' => BWACHZelle::UNKNOWN, 'edit' => ['type' => 'Select', 'options' => BWACHZelle::options()]],
                        ['caption' => 'Cells', 'name' => 'Cells', 'width' => '80px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 12]],
                        ['caption' => 'Group', 'name' => 'Group', 'width' => '150px', 'add' => '', 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => 'do not change', 'value' => ''],
                            ['caption' => 'Event sensor', 'value' => BWACHLogik::GROUP_EVENT],
                        ]]],
                        ['caption' => 'Critical', 'name' => 'Critical', 'width' => '70px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Without age check', 'name' => 'IgnoreAge', 'width' => '150px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Without radio silence check', 'name' => 'NoStill', 'width' => '180px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Exclude', 'name' => 'Excluded', 'width' => '90px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Query', 'name' => 'Poll', 'width' => '80px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Replacement detection', 'name' => 'Detect', 'width' => '200px', 'add' => -1, 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => 'As set globally', 'value' => -1],
                            ['caption' => 'Percentage jump and flag', 'value' => 2],
                            ['caption' => 'Percentage jump only', 'value' => 1],
                            ['caption' => 'Off', 'value' => 0],
                        ]]],
                        ['caption' => 'Radio silence after (days)', 'name' => 'StillDays', 'width' => '160px', 'add' => 0, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 3650]],
                        ['caption' => 'Matches', 'name' => 'Hits', 'width' => '70px', 'add' => 0],
                    ],
                ],
            ],
        ];
    }

    /** Erklärung der Spalten in „Geräte-Einstellungen“ mit den Werten, die gerade eingestellt sind. */
    private function deviceHelp(): string
    {
        $low  = $this->ReadPropertyInteger('LowPercent');
        $crit = $this->ReadPropertyInteger('CriticalLowPercent');
        $old  = $this->ReadPropertyInteger('ValueOldDays');
        $still = $this->ReadPropertyInteger('StillDays');
        $stillE = $this->ReadPropertyInteger('StillDaysEvent');
        $rem  = $this->ReadPropertyInteger('ReminderDays');
        $remC = $this->ReadPropertyInteger('CriticalReminderDays');
        $quiet = $this->ReadPropertyBoolean('CriticalIgnoresQuiet');
        $pAfter = $this->ReadPropertyInteger('PollAfterDays');
        $pEvery = $this->ReadPropertyInteger('PollEveryDays');
        return implode("\n\n", [
            BWACHText::t('Group: "Standard" or "Event sensor". Window contacts and smoke detectors only report on events, so the monitor waits longer before "radio silence" for them (currently %s instead of %s days).', $stillE, $still),
            BWACHText::t('Critical: for devices where an empty battery hurts. The monitor warns earlier (low from %s %% instead of %s %%), sorts the device further up and reminds more often (every %s instead of %s days)%s. If percentage and "low" hint contradict each other, the worse statement counts.', $crit, $low, $remC, $rem, $quiet ? BWACHText::t('; quiet hours do not apply to them') : ''),
            BWACHText::t('Without age check: normally a battery value older than %s days counts as "outdated". With this tick that no longer applies, for devices that report their battery value only very rarely. The radio silence check is not affected.', $old),
            BWACHText::t('Exclude: the device is not monitored at all (no notification, not in tile and figures). The row stays so that the setting is kept. Something different from "Out of service" in the tile.'),
            BWACHText::t('Reachability (variable): if the device itself reports whether it is reachable, the monitor evaluates that (it detects HomeMatic "UNREACH" on its own). Here you can choose your own variable instead, e.g. a debounced one. True means "unreachable"; with "invert", true counts as "reachable". A variable chosen here takes precedence over the radio silence threshold in both directions: "unreachable" counts after the waiting time even with "Without radio silence check", "reachable" counts as active even if the device sends nothing for a long time. If the variable itself stops reporting, this goes unnoticed. How long the device must report this before it counts as radio silence is set under "Thresholds".'),
            BWACHText::t('Location (own): the location under which the device appears in tile, tables and replacement round (empty = name of the category containing the instance). A group rule can set it for many devices with "Set location"; the own location on the device takes precedence.'),
            BWACHText::t('Display name: a name of your own for the device in tile, tables, notifications and log (empty = name of the instance). Group rules keep working with the name of the instance.'),
            BWACHText::t('Cell type (own) and cells (own): these are the settings of this one device. If a group rule applies to the device, the column "Applies" shows the value that finally counts; the two columns stay as they are as long as the device has no value of its own.'),
            BWACHText::t('Cell type and number of cells: with these the monitor converts voltages into a state of charge and compiles the shopping list. If a Matter device reports its cell type itself, it is already filled in.'),
            BWACHText::t('Replacement detection: "As set globally" follows the setting under "Acknowledge and battery log". "Percentage jump only" ignores a flickering "low" flag (e.g. HomeMatic LOWBAT); devices that only report the flag then have no automatic detection. Percentages calculated from voltage only count from a jump of 40 points to at least 80 %.'),
            BWACHText::t('Query: for Z-Wave, Matter and Comet WiFi thermostats. If the battery value is older than %s days, the monitor sends the device a status request at most every %s days and shows whether it answers. A sleeping Z-Wave device only answers at its next wake-up; whether that delivers the battery value earlier is not proven there. Matter devices answered within a few seconds in first tests. A Comet thermostat is queried with "all fields", because only that delivers the battery level. For other systems the tick does nothing.', $pAfter, $pEvery),
        ]);
    }

    /** Zeilen der Geräte-Einstellungen, deren Instanz gelöscht ist. */
    private function orphanRowCount(array $rows): int
    {
        return count(array_filter($rows, function ($r) { return (int)($r['Instance'] ?? 0) > 0 && !IPS_InstanceExists((int)$r['Instance']); }));
    }

    /**
     * „Übernehmen“ der Konsole erscheint erst, wenn sich im Formular etwas geändert hat. Neue Zeilen, die das Modul selbst
     * in die Liste setzt, zählen dafür nicht. Diese Funktion setzt die Liste (alle erkannten Geräte, ohne Zeilen gelöschter
     * Instanzen) per UpdateFormField neu ins offene Formular; gespeichert wird erst mit „Übernehmen“ durch den Nutzer
     * (keine Selbstpersistenz, SUITE.md Formular-Regel 1). Ungespeicherte eigene Änderungen in der Liste werden dabei
     * durch den gespeicherten Stand ersetzt.
     */
    public function PrepareDeviceSettings(): string
    {
        $rows    = $this->deviceRows()['rows'];
        $orphans = $this->orphanRowCount($rows);
        $rows    = array_values(array_filter($rows, function ($r) { return (int)($r['Instance'] ?? 0) <= 0 || IPS_InstanceExists((int)$r['Instance']); }));
        $this->UpdateFormField('DeviceSettings', 'values', json_encode($rows));
        $msg = $orphans === 1
            ? BWACHText::t('💾 List prepared: %s devices, %s row of a deleted instance removed. Now click "Apply" below to save.', count($rows), $orphans)
            : BWACHText::t('💾 List prepared: %s devices, %s rows of deleted instances removed. Now click "Apply" below to save.', count($rows), $orphans);
        $this->UpdateFormField('DeviceRowsLine', 'caption', $msg);
        return $msg;
    }

    private function DevicesPanel(array $dr): array
    {
        $fill = $dr['filled'] > 0
            ? BWACHText::t($dr['filled'] === 1 ? 'For %s device the cell type is already filled in because the device reports it itself (for AA/AAA only the size, alkaline is assumed: please change for rechargeables).' : 'For %s devices the cell type is already filled in because the device reports it itself (for AA/AAA only the size, alkaline is assumed: please change for rechargeables).', $dr['filled'])
            : '';
        $line = $dr['added'] > 0
            ? BWACHText::t('✅ All detected devices are already in the list (%s new, not yet saved). Just select the cell type and number of cells per device and click "Apply" below; "Apply" appears as soon as something in the list has changed, "💾 Prepare list for saving" does that for you.', $dr['added']) . ($fill !== '' ? ' ' . $fill : '')
            : ($dr['filled'] > 0 ? BWACHText::t('✅ All detected devices are in the list, not yet saved. %s To save, click "💾 Prepare list for saving" and then "Apply" below.', $fill)
            : (count($dr['rows']) > 0 ? BWACHText::t('✅ All detected devices are in the list.') : BWACHText::t('ℹ️ No devices detected yet: first press "Search again now" above.')));
        return [
            'type' => 'ExpansionPanel', 'expanded' => $dr['added'] > 0 || $dr['filled'] > 0,
            'caption' => '🏷️  Device settings',
            'items' => [
                ['type' => 'Label', 'name' => 'DeviceRowsLine', 'caption' => $line],
                ['type' => 'Label', 'caption' => 'Every detected device is listed here with neutral defaults. Only change what differs. For many identical devices at once there is the panel "Groups". The column "Applies" shows what finally counts for a device. Click a column header to sort the list.'],
                ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '❔  What do the columns mean?', 'items' => [
                    ['type' => 'Label', 'caption' => $this->deviceHelp()],
                ]],
                ...$this->retiredItems($this->found(), 'Settings'),
                ['type' => 'Button', 'caption' => '💾 Prepare list for saving', 'visible' => $dr['added'] > 0 || $dr['filled'] > 0 || $this->orphanRowCount($dr['rows']) > 0,
                    'onClick' => 'echo BWACH_PrepareDeviceSettings($id);'],
                [
                    'type' => 'List', 'name' => 'DeviceSettings', 'caption' => 'Devices', 'rowCount' => max(5, min(60, count($dr['rows']))), 'add' => true, 'delete' => true,
                    'loadValuesFromConfiguration' => false,
                    'sort' => $this->deviceSort(),
                    'values' => $dr['rows'],
                    'columns' => [
                        ['caption' => 'Device instance', 'name' => 'Instance', 'width' => 'auto', 'add' => 0, 'sortColumn' => 'Name', 'edit' => ['type' => 'SelectInstance']],
                        ['caption' => 'Sensor', 'name' => 'SensorName', 'width' => '150px', 'add' => ''],
                        ['caption' => 'Sensor variable', 'name' => 'Sensor', 'width' => '0px', 'visible' => false, 'add' => 0, 'save' => true],
                        ['caption' => 'Location', 'name' => 'Place', 'width' => '140px', 'add' => ''],
                        ['caption' => 'System', 'name' => 'Module', 'width' => '120px', 'add' => ''],
                        ['caption' => 'Battery level', 'name' => 'Percent', 'width' => '110px', 'add' => '—', 'sortColumn' => 'PercentSort'],
                        ['caption' => 'Applies', 'name' => 'Effect', 'width' => '260px', 'add' => ''],
                        ['caption' => 'Last replacement', 'name' => 'Change', 'width' => '120px', 'add' => '—', 'sortColumn' => 'ChangeSort'],
                        ['caption' => 'Sort value replacement', 'name' => 'ChangeSort', 'width' => '0px', 'visible' => false, 'add' => 0],
                        ['caption' => 'Name', 'name' => 'Name', 'width' => '0px', 'visible' => false, 'add' => ''],
                        ['caption' => 'Sort value battery level', 'name' => 'PercentSort', 'width' => '0px', 'visible' => false, 'add' => 1000],
                        ['caption' => 'Group', 'name' => 'Group', 'width' => '170px', 'add' => BWACHLogik::GROUP_STANDARD, 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => 'Standard', 'value' => BWACHLogik::GROUP_STANDARD],
                            ['caption' => 'Event sensor', 'value' => BWACHLogik::GROUP_EVENT],
                        ]]],
                        ['caption' => 'Critical', 'name' => 'Critical', 'width' => '80px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Without age check', 'name' => 'IgnoreAge', 'width' => '150px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Without radio silence check', 'name' => 'NoStill', 'width' => '180px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Exclude', 'name' => 'Excluded', 'width' => '100px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Cell type (own)', 'name' => 'Cell', 'width' => '230px', 'add' => BWACHZelle::UNKNOWN, 'edit' => ['type' => 'Select', 'options' => BWACHZelle::options()]],
                        ['caption' => 'Cells (own)', 'name' => 'Cells', 'width' => '120px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 12]],
                        ['caption' => 'Location (own)', 'name' => 'PlaceOwn', 'width' => '160px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Reachability (variable)', 'name' => 'ReachVar', 'width' => '200px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => 'Invert reachability', 'name' => 'ReachInv', 'width' => '190px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Display name', 'name' => 'Alias', 'width' => '200px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Query (Z-Wave, Matter, Comet)', 'name' => 'Poll', 'width' => '190px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Replacement detection', 'name' => 'Detect', 'width' => '200px', 'add' => -1, 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => 'As set globally', 'value' => -1],
                            ['caption' => 'Percentage jump and flag', 'value' => 2],
                            ['caption' => 'Percentage jump only', 'value' => 1],
                            ['caption' => 'Off', 'value' => 0],
                        ]]],
                        ['caption' => 'Radio silence after (days, 0 = default)', 'name' => 'StillDays', 'width' => '230px', 'add' => 0, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 3650]],
                        ['caption' => 'Set location', 'name' => 'PlaceSet', 'width' => '160px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                    ],
                ],
            ],
        ];
    }

    private function ManualPanel(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '➕  More variables',
            'items' => [
                ['type' => 'Label', 'caption' => 'For battery variables that are not found automatically. The kind determines how the value is read: "Percent" (0–100), "Flag: low" (TRUE = battery low), "Flag: OK" (TRUE = battery OK) or "Voltage" (only displayed).'],
                [
                    'type' => 'List', 'name' => 'ManualVariables', 'caption' => 'Variables', 'rowCount' => 5, 'add' => true, 'delete' => true,
                    'columns' => [
                        ['caption' => 'Variable', 'name' => 'Variable', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                        ['caption' => 'Kind', 'name' => 'Kind', 'width' => '200px', 'add' => BWACHLogik::KIND_PERCENT, 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => 'Percent', 'value' => BWACHLogik::KIND_PERCENT],
                            ['caption' => 'Flag: low', 'value' => BWACHLogik::KIND_FLAG],
                            ['caption' => 'Flag: OK', 'value' => BWACHLogik::KIND_FLAG_REV],
                            ['caption' => 'Voltage', 'value' => BWACHLogik::KIND_VOLTAGE],
                            ['caption' => 'Text: low if it contains …', 'value' => BWACHLogik::KIND_TEXT],
                        ]]],
                        ['caption' => 'Text (comma = or)', 'name' => 'Text', 'width' => '220px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                    ],
                ],
                ['type' => 'Label', 'caption' => '"Text" is for variables that report a state as a word (e.g. "ok", "low", "critical"): if one of the words is in the value, the battery counts as low. Without an entered word the row is not used. Upper and lower case do not matter.'],
                [
                    'type' => 'List', 'name' => 'IgnoredVariables', 'caption' => 'Ignore variables', 'rowCount' => 3, 'add' => true, 'delete' => true,
                    'columns' => [
                        ['caption' => 'Variable', 'name' => 'Variable', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ],
                ],
                [
                    'type' => 'List', 'name' => 'OwnCells', 'caption' => 'Own cell types', 'rowCount' => 3, 'add' => true, 'delete' => true,
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'Name', 'width' => '160px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Name on shopping list (empty = name)', 'name' => 'Shop', 'width' => '230px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Voltage full (V)', 'name' => 'VFull', 'width' => '150px', 'add' => 0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 2, 'minimum' => 0, 'maximum' => 60]],
                        ['caption' => 'Voltage empty (V)', 'name' => 'VEmpty', 'width' => '150px', 'add' => 0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 2, 'minimum' => 0, 'maximum' => 60]],
                    ],
                ],
                ['type' => 'Label', 'caption' => '"Own cell types": if a battery type is missing from the selection, enter it here. It then appears under "Cell type" in "Device settings", "Groups" and "Stock" and goes into the shopping list. If "Voltage full" and "Voltage empty" (per cell) are filled in, the monitor calculates a state of charge linearly from the voltage in between (a rough approximation); otherwise the type only serves the shopping list. Changing a name or deleting the row sets the devices with this type to "unknown". The types are available after "Apply".'],
                ['type' => 'Label', 'caption' => '"Ignore variables" removes single variables from detection without excluding the whole device (e.g. the load of a UPS if the state of charge is next to it). They then appear in the dry run under "Excluded".'],
            ],
        ];
    }

    private function ForumHint(): ?array
    {
        if ($this->ReadAttributeString('ForumHintSeen') === self::FORUM_HINT_REV) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Feedback',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 The Battery Monitor is new — questions, wishes or bugs are welcome in the forum thread. Especially wanted: experience with Zigbee2MQTT, Matter and HomeMatic, which the developer cannot test himself. If you try them, press "Diagnostics for the forum" under "Devices found" and post the output there.'],
                ['type' => 'Button', 'caption' => 'To the forum thread', 'onClick' => "echo '" . self::FORUM_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'To the repository', 'onClick' => "echo '" . self::REPO_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Got it – do not show again', 'onClick' => 'BWACH_AckForumHint($id);'],
            ],
        ];
    }

    /** Lizenz-Hinweis — Wortlaut verbundweit identisch (SUITE.md "Einheitliche Formular-Optik", Variante A). */
    private function LicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  About this module',
            'items' => [
                ['type' => 'Label', 'caption' => 'Born from real enthusiasm for my own installation — and a few evenings of typing. Still: hobby software or not, this is intellectual property and real work went into it.'],
                ['type' => 'Label', 'caption' => 'License: PolyForm Noncommercial 1.0.0 — free for private and non-commercial use; commercial use requires a separate license from the rights holder.'],
                ['type' => 'Button', 'caption' => 'View license text', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Commercial use or questions about the license? Just get in touch: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Do you like the module and would still like to leave something? I am happy about a small donation — completely voluntary, nothing required in return.'],
                ['type' => 'Button', 'caption' => '☕  Donate via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    /**
     * Reihenfolge und Aufklapp-Zustand der Fachpanels aus der Einstellung „FormLayout“.
     * Unbekannte oder fehlende Einträge fallen auf die Standardreihenfolge und „Standard“ zurück.
     *
     * @param string[] $keys Schlüssel der Fachpanels in Standardreihenfolge
     * @return array key => ['open' => 'std'|'open'|'closed', 'pos' => int], in der anzuwendenden Reihenfolge
     */
    private function formLayout(array $keys): array
    {
        $saved = json_decode((string)$this->ReadPropertyString('FormLayout'), true);
        $by = [];
        foreach (is_array($saved) ? $saved : [] as $row) {
            if (is_array($row) && isset($row['Key'])) {
                $by[(string)$row['Key']] = $row;
            }
        }
        $out = [];
        foreach ($keys as $i => $k) {
            $r = $by[$k] ?? [];
            if (array_key_exists('Opened', $r) || array_key_exists('Closed', $r) || array_key_exists('Hidden', $r)) {
                // Kontrollkästchen; Vorrang bei Widerspruch: ausgeblendet vor zugeklappt vor aufgeklappt, keines = Standard
                $open = !empty($r['Hidden']) ? 'hidden' : (!empty($r['Closed']) ? 'closed' : (!empty($r['Opened']) ? 'open' : 'std'));
            } else {
                $open = (string)($r['Open'] ?? 'std');   // frühere Auswahlliste (bis 0.16.4)
            }
            $out[$k] = [
                'open' => in_array($open, ['open', 'closed', 'hidden'], true) ? $open : 'std',
                'pos'  => isset($r['Pos']) ? max(1, min(99, (int)$r['Pos'])) : $i + 1,
                'idx'  => $i,
            ];
        }
        uasort($out, function ($a, $b) { return [$a['pos'], $a['idx']] <=> [$b['pos'], $b['idx']]; });
        return $out;
    }

    /** Wählbarer Aufbau des Formulars: je Fachpanel Aufklapp-Zustand beim Öffnen und Position. */
    private function LayoutPanel(array $layout, array $free): array
    {
        $rows   = [];
        $hidden = [];
        foreach ($layout as $key => $l) {
            // Panel-Überschriften stehen englisch im Formular (die Konsole übersetzt sie); hier landen sie in Listenwerten, die die Konsole nicht übersetzt
            $name = trim((string)preg_replace('/\s*\(.*$/u', '', BWACHText::t((string)($free[$key]['caption'] ?? $key))));
            $rows[] = ['Key' => $key, 'Panel' => $name, 'Opened' => $l['open'] === 'open', 'Closed' => $l['open'] === 'closed',
                'Hidden' => $l['open'] === 'hidden', 'Pos' => $l['pos']];
            if ($l['open'] === 'hidden') {
                $hidden[] = trim((string)preg_replace('/^[^\p{L}\p{N}]+/u', '', $name));
            }
        }
        $hiddenLine = $hidden
            ? [['type' => 'Label', 'caption' => BWACHText::t('🙈 Hidden: %s — to show again, remove the tick at "Hidden". Hidden sections keep working, only the display is missing.', implode(', ', $hidden))]]
            : [];
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => $hidden ? BWACHText::t('🪟  Form: layout (%s hidden)', count($hidden)) : BWACHText::t('🪟  Form: layout'),
            'items' => array_merge($hiddenLine, [
                ['type' => 'Label', 'caption' => 'Here you decide which sections are expanded when the form opens and in which order they appear. Symcon does not remember expanding and collapsing itself, so the setting here applies every time the form opens. It takes effect after "Apply" the next time the form is opened.'],
                ['type' => 'Label', 'caption' => 'No tick means "default": as the module intends (e.g. "Device settings" expanded when there is something new). If you choose "Collapsed", you will not see new things there right away. The position is a number; with equal numbers the default order stays. "Hidden" removes a section from the display without losing anything: its settings are kept and keep working (meant for sections you rarely need). To show it again, remove the tick at "Hidden" or click "Reset to default". If several ticks are set, "Hidden" beats "Collapsed" beats "Expanded". The sections at the top (purpose, documentation, first steps) and at the bottom (feedback, about this module) stay where they are and cannot be hidden.'],
                [
                    'type' => 'List', 'name' => 'FormLayout', 'caption' => 'Sections', 'rowCount' => count($rows), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false,
                    'values' => $rows,
                    'columns' => [
                        ['caption' => 'Section', 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                        ['caption' => 'Key', 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                        ['caption' => 'Expanded', 'name' => 'Opened', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Collapsed', 'name' => 'Closed', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => '🙈 Hidden', 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Position', 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                    ],
                ],
                ['type' => 'Button', 'caption' => '↩️ Reset to default', 'onClick' => 'echo BWACH_ResetFormLayout($id);'],
            ]),
        ];
    }

    /** Setzt Aufklapp-Zustand und Reihenfolge der Fachpanels im offenen Formular auf den Standard (gilt nach „Übernehmen“). */
    public function ResetFormLayout(): string
    {
        $keys = ['discovery', 'devices', 'status', 'notify', 'ack', 'forecast', 'threshold', 'rules', 'manual'];
        $rows = [];
        foreach ($keys as $i => $k) {
            $rows[] = ['Key' => $k, 'Panel' => $this->panelCaption($k), 'Opened' => false, 'Closed' => false, 'Hidden' => false, 'Pos' => $i + 1];
        }
        $this->UpdateFormField('FormLayout', 'values', json_encode($rows, JSON_UNESCAPED_UNICODE));
        return BWACHText::t('ℹ️ Reset to default. To save, click "Apply" below.');
    }

    private function panelCaption(string $key): string
    {
        $dr  = $this->deviceRows();
        $map = [
            'discovery' => $this->DiscoveryPanel(), 'devices' => $this->DevicesPanel($dr), 'status' => $this->StatusPanel(),
            'notify' => $this->NotifyPanel(), 'ack' => $this->AckPanel(), 'forecast' => $this->ForecastPanel(),
            'threshold' => $this->ThresholdPanel(), 'rules' => $this->RulesPanel($dr), 'manual' => $this->ManualPanel(),
        ];
        return (string)($map[$key]['caption'] ?? $key);
    }

    public function GetConfigurationForm()
    {
        $this->initCells();
        $base   = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $status = $base['status'] ?? [];

        $dr = $this->deviceRows();
        // Oben und unten stehen die Panels der Verbund-Konvention fest; dazwischen liegen die Fachpanels,
        // deren Reihenfolge und Aufklapp-Zustand der Nutzer im Panel „Formular: Aufbau“ wählen kann.
        $free = [
            'discovery' => $this->DiscoveryPanel(), 'devices' => $this->DevicesPanel($dr), 'status' => $this->StatusPanel(),
            'notify' => $this->NotifyPanel(), 'ack' => $this->AckPanel(), 'forecast' => $this->ForecastPanel(),
            'threshold' => $this->ThresholdPanel(), 'rules' => $this->RulesPanel($dr), 'manual' => $this->ManualPanel(),
        ];
        $layout = $this->formLayout(array_keys($free));
        $middle = [];
        foreach ($layout as $key => $l) {
            $panel = $free[$key];
            if ($l['open'] === 'open') {
                $panel['expanded'] = true;
            } elseif ($l['open'] === 'closed') {
                $panel['expanded'] = false;
            } elseif ($l['open'] === 'hidden') {
                // Nicht weglassen: die Felder bleiben im Formular, gespeicherte Werte bleiben beim „Übernehmen“ erhalten
                $panel['visible'] = false;
            }
            $middle[] = $panel;
        }
        $elements = array_values(array_filter(array_merge(
            [$this->PurposeIntro(), $this->NewsBanner(), $this->DocPanel(), $this->FirstStepsPanel($dr)],
            $middle,
            [$this->LayoutPanel($layout, $free), $this->ForumHint(), $this->LicenseHint()]
        )));

        return json_encode(['elements' => $elements, 'actions' => [], 'status' => $status]);
    }
}
