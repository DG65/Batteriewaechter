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
    /**
     * Änderungen je Version für das Panel „Neu“ (Version => Zeilen). Bewusst leer: Das Panel erscheint erst nach der ersten
     * veröffentlichten Version, ab dann werden hier die Änderungen seitdem gepflegt (Verbund-Konvention). Bis dahin steht
     * die Entwicklungsgeschichte nur im CHANGELOG.md.
     */
    private const NEWS_VERSIONS = [];
    // Push-Ziele (SUITE.md Stolperstein 22): klassisches WebFront UND Kachel-Visualisierung, je eigene Funktion
    private const WEBFRONT_GUID = '{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}';
    private const KACHEL_GUID   = '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}';
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
        $this->RegisterPropertyBoolean('TileAllowAck', true);
        $this->RegisterPropertyBoolean('NameSearch', false);
        $this->RegisterPropertyString('ManualVariables', '[]');
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
        foreach (['NotifyState' => 'Meldezustand', 'Diary' => 'Tagebuch-Daten', 'LastSeen' => 'Zuletzt gesehen', 'Retired' => 'Außer Betrieb', 'Meta' => 'Sonstiges', 'History' => 'Verlauf', 'LifeObs' => 'Meldeverhalten', 'Poll' => 'Abfragen', 'FcLog' => 'Prognose-Protokoll'] as $ident => $name) {
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
            return 'Noch kein Wechsel mit Vergleich: Der Wächter vergleicht bei einem erkannten Wechsel, was die Prognose 14 bis 150 Tage vorher sagte, mit dem tatsächlichen Stand. Das braucht Wochen bis Monate Verlauf.';
        }
        $dir = abs($a['bias']) < 1 ? 'ohne erkennbare Richtung' : ($a['bias'] > 0 ? 'die Batterien hielten im Schnitt länger als vorhergesagt' : 'die Batterien entluden sich im Schnitt schneller als vorhergesagt');
        return $a['n'] . ($a['n'] === 1 ? ' Wechsel' : ' Wechsel') . ' verglichen: im Mittel ' . BWACHLogik::num($a['meanAbs']) . ' Prozentpunkte daneben, ' . $dir . '.';
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
                'id'     => $row['id'],
                'name'   => $row['name'],
                'place'  => $row['place'],
                'cell'   => $row['cell'],
                'cells'  => $row['cells'],
                'status' => $row['r']['status'],
                'preventive' => !empty($row['r']['preventive']),
                'days'   => ($f['days'] !== null && $f['confidence'] !== 'keine') ? $f['days'] : null,
                'text'   => !empty($row['r']['preventive']) && $row['r']['status'] === BWACHLogik::ST_OK ? 'Vorsorglicher Wechsel fällig' : ($row['r']['status'] === BWACHLogik::ST_OK ? BWACHPrognose::forecastText($f) : implode('; ', array_slice($row['r']['reasons'], 0, 1))),
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
            'shopping' => BWACHPrognose::shopping($items, $horizon, $this->stockByLabel()),
            'round'    => BWACHPrognose::tauschrunde($items, $horizon, $now),
            'life'     => $life,
            'byCell'   => BWACHPrognose::lifetimeByGroup($life, $cellOf),
            'byModule' => BWACHPrognose::lifetimeByGroup($life, $modOf),
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
        $out .= '<div style="padding:10px 2px 4px"><b>🎯 Genauigkeit der Prognose</b></div><div style="padding:4px 2px;opacity:.9">' . $e($this->accuracyText($v['accuracy'])) . '</div>';
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
        } elseif ($Ident === 'ack_place') {
            $d = json_decode((string)$Value, true);
            if (!$this->ReadPropertyBoolean('TileAllowAck')) {
                $msg = '⛔ Das Quittieren aus der Kachel ist in den Einstellungen ausgeschaltet.';
            } elseif (is_array($d) && isset($d['place'])) {
                $msg = $this->AcknowledgePlace((string)$d['place']);
            } else {
                $msg = '⛔ Ungültige Anfrage aus der Kachel.';
            }
            $meta['ack'] = ['m' => $msg, 't' => $now];
            $this->saveJson('Meta', $meta);
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
                'valueAgeSec'  => $r['valueAge'],
                'lifeAgeSec'   => $r['lifeAge'],
                'cellKey'      => BWACHZelle::isKnown($row['cell']) ? (string)BWACHZelle::shopLabel($row['cell']) : '',
                'valueAgeText' => $r['valueAge'] === null ? '—' : 'vor ' . BWACHLogik::daysDat($r['valueAge']),
                'lifeText'     => $r['lifeAge'] === null ? '—' : 'vor ' . BWACHLogik::daysDat($r['lifeAge']),
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
        $msg = $this->ackApply((string)$key, $action, $name);
        $this->Check();
        $this->UpdateFormField('AckStatus', 'caption', $msg);
        $this->UpdateFormField('DiaryLine', 'caption', $this->diaryLine());
        $this->UpdateFormField('RetiredLine', 'caption', $this->retiredLine($found));
        return $msg;
    }

    /** Führt eine Quittierung aus (Tagebuch, Außer-Betrieb-Liste, Meldezustand), ohne neu zu bewerten. */
    private function ackApply(string $key, string $action, string $name): string
    {
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
            return 'ℹ️ Noch nicht gesucht: zuerst „Jetzt neu suchen“.';
        }
        $place = $place === '' ? 'ohne Ort' : $place;
        $now   = $this->now();
        $v     = $this->views($this->evaluateAll($found), $now);
        $devs  = $v['round']['places'][$place] ?? [];
        if (!$devs) {
            return 'ℹ️ In der Tauschrunde steht für „' . $place . '“ nichts.';
        }
        $n = 0;
        foreach ($devs as $d) {
            $this->ackApply((string)$d['id'], BWACHMeldung::ACK_REPLACED, (string)$d['name']);
            $n++;
        }
        $this->Check();
        $msg = '✅ ' . $n . ($n === 1 ? ' Gerät' : ' Geräte') . ' in „' . $place . '“ als getauscht eingetragen. Der Wächter wartet ' . BWACHMeldung::REPLACE_GRACE_DAYS . ' Tage, bis die Geräte den neuen Stand melden.';
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
            return 'ℹ️ Bitte zuerst ein Gerät wählen.';
        }
        $now = $this->now();
        $t   = BWACHMeldung::parseDate($date, $now);
        if ($t === null) {
            return '⛔ Datum nicht lesbar oder in der Zukunft: bitte TT.MM.JJJJ eingeben (z. B. ' . date('d.m.Y', $now - 86400 * 30) . ').';
        }
        $found = $this->found();
        if ($found === null || !isset($found['devices'][$key])) {
            return '⛔ Gerät „' . $key . '“ nicht gefunden.';
        }
        $name = $key;
        foreach ($this->evaluateAll($found) as $r) {
            if ($r['id'] === $key) {
                $name = $r['name'];
                break;
            }
        }
        $diary = $this->loadJson('Diary');
        $new   = BWACHMeldung::diaryAdd($diary, ['t' => $t, 'key' => $key, 'name' => $name, 'type' => 'nachgetragen', 'note' => 'von Hand nachgetragen']);
        if (count($new) === count($diary)) {
            return 'ℹ️ Für „' . $name . '“ gibt es schon einen Eintrag innerhalb von ' . BWACHMeldung::DIARY_DEDUPE_DAYS . ' Tagen um dieses Datum. Nichts geändert.';
        }
        usort($new, function ($a, $b) { return (int)$a['t'] <=> (int)$b['t']; });
        $this->saveJson('Diary', $new);
        $this->Check();
        $msg = '✅ Wechsel bei „' . $name . '“ am ' . date('d.m.Y', $t) . ' nachgetragen.';
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
        ]);
        $kernel = function_exists('IPS_GetKernelVersion') ? (string)IPS_GetKernelVersion() : '?';
        return BWACHLogik::diagnosis($vars, $result, $this->installedVersion(), $kernel, $this->excludedModules());
    }

    /** Geräteliste als CSV (Semikolon, UTF-8): zum Kopieren oder Ausdrucken. */
    public function DeviceListCsv(): string
    {
        $found = $this->found();
        if ($found === null) {
            return 'Noch nicht gesucht: zuerst „Jetzt neu suchen“.';
        }
        $now   = $this->now();
        $diary = $this->loadJson('Diary');
        $out   = [BWACHLogik::csvRow(['Name', 'Ort', 'System', 'Zelltyp', 'Zellen', 'Batteriestand %', 'Status', 'Funk', 'Letzter Wechsel', 'Lebenszeichen vor Tagen'])];
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
    /** Einkaufsliste und Tauschrunde als Text (zum Kopieren; auch für eigene Skripte). */
    public function ShoppingText(): string
    {
        $found = $this->found();
        if ($found === null) {
            return 'Noch nicht gesucht: zuerst „Jetzt neu suchen“.';
        }
        $v = $this->views($this->evaluateAll($found), $this->now());
        return BWACHPrognose::shoppingText($v['shopping'], $v['round'], (int)$v['horizon'], $v['round']['until'] === null ? '' : date('d.m.Y', $v['round']['until']));
    }

    /** Schickt die Einkaufsliste jetzt über die eingestellten Wege (Push, E-Mail), unabhängig vom Schalter „Meldungen aktiv“. */
    public function SendShopping(): string
    {
        $channels = ['push' => $this->ReadPropertyBoolean('NotifyPush'), 'mail' => $this->ReadPropertyBoolean('NotifyMail')];
        if (!$channels['push'] && !$channels['mail']) {
            return 'ℹ️ Kein Zustellweg ausgewählt (Push oder E-Mail unter „Meldungen“ ankreuzen).';
        }
        // Die erste Zeile des Textes ist die Überschrift, die schon im Titel steht: nicht doppelt senden
        $parts = explode("\n", $this->ShoppingText(), 2);
        $ok    = $this->deliver('🛒 Batterien einkaufen', $parts[1] ?? $parts[0], 'bell', $channels);
        return $ok > 0 ? '✅ Einkaufsliste gesendet' : '⚠️ Die Einkaufsliste konnte nicht zugestellt werden' . ($this->lastMailError !== '' ? ' — ' . $this->lastMailError : '') . '.';
    }

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
        $fc    = $this->loadJson('FcLog');
        $fcChanged = false;
        $newLast = $last;
        $changed = false;

        foreach ($rows as $row) {
            $key = $row['id'];
            $cur = ['p' => $row['r']['percent'], 'f' => $row['r']['flagLow']];
            $prev = isset($last[$key]) ? ['p' => $last[$key]['p'] ?? null, 'f' => $last[$key]['f'] ?? null] : null;
            $desc = BWACHMeldung::detectReplacement($prev, $cur, $jump);
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
            return '<div style="padding:8px">ℹ️ Noch keine Batteriewechsel erfasst. Der Wächter erkennt sie selbst oder du trägst sie unter „Quittieren“ ein.</div>';
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

    /** Gruppen-Regeln aus der Instanzkonfiguration, von oben nach unten. */
    private function groupRules(): array
    {
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
                    'Excluded' => (bool)($r['Excluded'] ?? false),
                    'Poll'     => (bool)($r['Poll'] ?? false),
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
        return ['name' => $name, 'place' => $place, 'module' => (string)($d['module'] ?? '')];
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
            $st = BWACHLogik::applyRules($settings[$id] ?? ['group' => BWACHLogik::GROUP_STANDARD, 'critical' => false, 'ignoreAge' => false, 'excluded' => false, 'cell' => BWACHZelle::UNKNOWN, 'cells' => 1, 'poll' => false], $label, $rules);
            if ($st['excluded'] || isset($retired[(string)$key])) {
                continue;
            }
            $sig  = $this->readSignals($d['signals']);
            $life = $this->lifeSign($id, $isInstance, $d['signals']);
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
            $name  = $label['name'];
            $place = $label['place'];
            // Prognose aus dem im Modul geführten Verlauf; „bald leer“ nur bei ausreichender Sicherheit
            $f = BWACHPrognose::forecast($hist[(string)$key] ?? [], (float)$this->ReadPropertyInteger('EmptyPercent'), $this->ReadPropertyInteger('ReplaceJumpPercent'));
            $r['soon'] = false;
            if ($r['status'] === BWACHLogik::ST_OK && $f['days'] !== null && in_array($f['confidence'], ['hoch', 'mittel'], true) && $f['days'] <= $this->ReadPropertyInteger('SoonDays')) {
                $r['soon'] = true;
                $r['reasons'][] = 'Batterie bald leer: ' . BWACHPrognose::forecastText($f);
                $r['urgency'] = max($r['urgency'], 650 + ($r['critical'] ? 100 : 0));
            }
            // Vorsorglicher Wechsel: nur kritische Geräte, nur mit erfasstem letzten Wechsel (Tagebuch)
            $r['preventive'] = false;
            $pm = $this->ReadPropertyInteger('PreventiveMonths');
            if ($st['critical'] && $pm > 0) {
                $pv = BWACHMeldung::preventiveDue(BWACHMeldung::lastReplacement($diaryAll, (string)$key), $pm, $now);
                if ($pv['due']) {
                    $r['preventive'] = true;
                    $r['reasons'][]  = 'Vorsorglicher Wechsel fällig: letzter Wechsel vor ' . BWACHLogik::num($pv['months']) . ' Monaten (Intervall ' . $pm . ' ' . ($pm === 1 ? 'Monat' : 'Monate') . ')';
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
     * Schickt schlafenden Z-Wave- und Matter-Geräten, deren Batteriewert alt ist, eine Statusanfrage — nur bei Geräten, für die der
     * Nutzer das eingeschaltet hat, höchstens alle N Tage und nur mit einer belegt vorhandenen Symcon-Funktion.
     * Ein schlafendes Gerät beantwortet die Anfrage erst beim nächsten Aufwachen; bis dahin liegt sie in der
     * Warteschlange der Z-Wave-Instanz.
     */
    /** Systeme, für die es eine Statusanfrage gibt: Modulname der Instanz => Symcon-Funktion. */
    private const POLL_FUNCTIONS = ['Z-Wave Module' => 'ZW_RequestStatus', 'Matter Device' => 'MATTER_RequestStatus'];

    /** Wie lange auf eine Antwort gewartet wird, bevor „keine Antwort“ gilt: schlafende Z-Wave-Geräte antworten erst beim Aufwachen. */
    private const POLL_WAIT = ['Z-Wave Module' => 14 * 86400, 'Matter Device' => 6 * 3600];

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
                $ok = (bool)$fn((int)$row['inst']);
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
                $state[$key] = ['t' => $now, 'ans' => null, 'miss' => $miss, 'why' => $still && !$old ? 'still' : 'alt'];
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
        return $icon . ' ' . $n . ' ' . ($n === 1 ? 'Gerät' : 'Geräte') . ' gefunden (zuletzt ' . $when . ' Uhr).' . $tail . $this->matterHint();
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
        return "\n\nℹ️ Matter: Für " . count($names) . ($more === 0 && count($names) === 1 ? ' Gerät gibt es' : ' Geräte gibt es') . ' keine Instanz für die Stromversorgung (Endpunkt 0): '
            . implode(', ', $shown) . ($more > 0 ? ' und ' . $more . ' weitere' : '') . '. Hat ein Gerät eine Batterie, fehlt dem Wächter ihr Wert, bis dafür eine Instanz „Matter Gerät“ mit der Knoten-ID des Geräts und dem Endpunkt 0 angelegt ist (Objektbaum: Instanz hinzufügen; der Matter Konfigurator bietet den Endpunkt 0 nicht an und zeigt solche Instanzen rot). Netzbetriebene Geräte haben keine Batterie.';
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
                ['type' => 'Label', 'caption' => 'Der Batteriewächter findet die Batterien deiner Funkgeräte (Sensoren, Thermostate, Fenster- und Rauchmelder …) von selbst und sagt dir, welche getauscht werden müssen — bevor ein Gerät ausfällt.'],
                ['type' => 'Label', 'caption' => 'Der Nutzen gegenüber einer einfachen „leer“-Liste: Er unterscheidet eine schwache Batterie von einem Gerät, das gar nichts mehr sendet, und er sagt ehrlich dazu, wenn ein Batteriewert zweifelhaft ist (uralt, widersprüchlich oder außerhalb des Möglichen). Ein „OK“ aus dem Jahr 2023 ist kein OK.'],
                ['type' => 'Label', 'caption' => 'Zuerst „🔎 Jetzt neu suchen“ drücken und mit „Was würde gefunden?“ prüfen, ob die Treffer stimmen. Danach läuft alles von selbst.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'BWACH_AckPurposeIntro($id);'],
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
            $items[] = ['type' => 'Label', 'caption' => 'Version ' . $version . ':'];
            foreach ($lines as $line) {
                $items[] = ['type' => 'Label', 'caption' => $line];
            }
        }
        if ($cut) {
            $items[] = ['type' => 'Label', 'caption' => 'ℹ️ Ältere Änderungen stehen in der Datei CHANGELOG.md im Repository.'];
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
                ['type' => 'Button', 'caption' => '💬 Forum-Thread', 'onClick' => "echo '" . self::FORUM_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Was gefunden wird: Variablen mit den Symcon-Profilen ~Battery („schwach“-Flag), ~Battery.Reversed („in Ordnung“-Flag) und ~Battery.100 (Prozent) sowie Variablen mit typischen Bezeichnern (z. B. battery, battery_low, LOWBAT, battery_percent, battery_voltage). Mehrere Signale eines Geräts (z. B. Prozent und Flag) werden zu EINEM Gerät zusammengeführt.'],
                ['type' => 'Label', 'caption' => 'Was bewusst nicht gefunden wird: Heimspeicher und Fahrzeugakkus (Modulliste „Ausgeschlossene Module“), Sammelwerte wie „Schwächste Batterie“ eines Raums und Variablen, die zu keiner Geräteinstanz gehören. Der Trockenlauf nennt zu jedem Ausschluss den Grund.'],
                ['type' => 'Label', 'caption' => 'Die drei Zeiten: (1) Alter des Batteriewerts — wann das Gerät seinen Batteriestand zuletzt gemeldet hat; manche Geräte tun das nur alle paar Monate. (2) Lebenszeichen — die jüngste Aktualisierung einer Messwert-Variable des Geräts (Variablen mit Aktion wie Sollwerte zählen nicht, die schreibt oft Symcon selbst); bleibt sie aus, ist es Funkstille, keine schwache Batterie. (3) Das Gerät selbst mit seinem Status.'],
                ['type' => 'Label', 'caption' => 'Status: „leer“ und „schwach“ aus dem Prozentwert (Schwellen unten) oder dem Flag des Geräts; „unbekannt“, wenn nichts Auswertbares da ist (z. B. nur eine Spannung — mit gewähltem Zelltyp rechnet der Wächter sie in einen Ladezustand um). Meldet ein Gerät Flag und Prozent und beide widersprechen sich, zeigt der Wächter den Widerspruch und bewertet nach dem neueren Signal; bei kritischen Geräten gilt die schlechtere Aussage.'],
                ['type' => 'Label', 'caption' => 'Funkstille: Standard 7 Tage ohne Lebenszeichen. Geräte, die nur bei Ereignissen senden (Fenster-, Rauchmelder), gehören unter „Geräte-Einstellungen“ in die Gruppe „Ereignismelder“ (Standard 30 Tage).'],
                ['type' => 'Label', 'caption' => 'Ergebnis: Kennzahlen-Variablen (leer, schwach, Funkstille …) und zwei Tabellen-Variablen („Handlungsbedarf“, „Alle Geräte“), die sich per Verknüpfung ins WebFront legen lassen.'],
                ['type' => 'Label', 'caption' => 'Meldungen (unter „🔔 Meldungen“, standardmäßig aus): erste Meldung, Erinnerung nach N Tagen, Ruhezeit, Wochenbericht, Eskalation für kritische Geräte — per Push (Kachel-Visualisierung und WebFront) und E-Mail. Unter „✅ Quittieren“ sagst du dem Wächter, was mit einem Gerät ist.'],
                ['type' => 'Label', 'caption' => 'Prognose, Einkauf, Tauschrunde: Unter „🧮 Prognose und Einkauf“ einstellbar. Je Gerät den Zelltyp unter „Geräte-Einstellungen“ eintragen, dann kann der Wächter Spannungen umrechnen und die Einkaufsliste zählen. Die Ergebnisse stehen als Variablen „Einkauf und Tauschrunde“ und „Lebensdauer und Entladung“ unter der Instanz.'],
                ['type' => 'Label', 'caption' => 'Auffällige Geräte, Funkqualität, Kälte: Entlädt ein Gerät mehr als doppelt so schnell wie vergleichbare (gleiches System, gleicher Zelltyp, mindestens 4 Geräte mit bekannter Entladerate), markiert der Wächter es und nennt mögliche Ursachen (defekt, schlechte Funkverbindung, Dauersenden). Die Funkqualität zeigt er an, wo das Gerät sie liefert (z. B. Zigbee linkquality). Der Kälteeinfluss braucht eine Außentemperatur-Variable und mehrere Wochen Verlauf bei Kälte UND Wärme.'],
                ['type' => 'Label', 'caption' => 'Abfrage schlafender Geräte: Z-Wave und Matter, nur wo je Gerät eingeschaltet, nur wenn der Batteriewert älter als eingestellt ist. Auch bei Funkstille fragt er nach, wenn die Abfrage für das Gerät eingeschaltet ist. Der Wächter sagt, ob das Gerät geantwortet hat; bleibt die Antwort aus (bei Z-Wave nach 14 Tagen, bei Matter nach 6 Stunden), steht das als Befund da.'],
                ['type' => 'Label', 'caption' => 'Kachel: Instanz in der Kachel-Visualisierung als Kachel hinzufügen. Antippen einer Zeile klappt sie auf (Gründe, Alter, Schaltflächen zum Quittieren), die Filterzeile oben zeigt nur, was gerade wichtig ist.'],
                ['type' => 'Label', 'caption' => 'Batterietagebuch: Der Wächter erkennt einen Batteriewechsel am Sprung des Prozentwerts oder am zurückgesetzten „schwach“-Flag und hält ihn mit Datum fest. Die Lebensdauer je Gerät und Zelltyp steht in der Statistik, sobald genug Wechsel gesammelt sind; frühere Wechsel lassen sich mit Datum nachtragen.'],
                ['type' => 'Label', 'caption' => 'Skripte: BWACH_Search(<InstanzID>) sucht neu, BWACH_Check(<InstanzID>) bewertet, BWACH_Preview(<InstanzID>) liefert den Trockenlauf als Text, BWACH_Acknowledge(<InstanzID>, \'<Schlüssel>\', \'getauscht\'|\'zurueckgestellt\'|\'ausser_betrieb\') quittiert, BWACH_SendTest(<InstanzID>) schickt eine Testmeldung, BWACH_ShoppingText und BWACH_SendShopping liefern oder senden die Einkaufsliste, BWACH_DeviceListCsv die Geräteliste, BWACH_AddReplacement(<InstanzID>, \'<Schlüssel>\', \'TT.MM.JJJJ\') trägt einen früheren Wechsel nach, BWACH_AcknowledgePlace(<InstanzID>, \'<Ort>\') quittiert die Tauschrunde eines Ortes, BWACH_Diagnosis(<InstanzID>) liefert die Diagnose fürs Forum.'],
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
                    ['type' => 'Button', 'caption' => '🩺 Diagnose fürs Forum', 'onClick' => 'echo BWACH_Diagnosis($id);'],
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
            : '✅ Push-Ziele: ' . $nK . ' Kachel-Visualisierung' . ($nK === 1 ? '' : 'en') . ', ' . $nW . ' WebFront' . ($chosen ? ' (nach deiner Auswahl unten)' : ' (alle gefundenen, solange unten nichts ausgewählt ist)') . '.';
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
            : 'ℹ️ Meldungen sind AUS — es wird nichts verschickt, bis du „Meldungen aktiv“ einschaltest.';

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
                ['type' => 'Button', 'caption' => '📥 Aus BY_BatterieMonitor übernehmen', 'onClick' => 'echo BWACH_ImportOld($id);'],
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
                ['type' => 'PopupButton', 'caption' => 'Wie arbeiten Meldungen zusammen?', 'width' => '480px', 'popup' => [
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
                $list[] = ['id' => (string)$d['id'], 'name' => $d['name'], 'need' => $cell !== null ? max(1, (int)$d['cells']) . '× ' . $cell : '', 'text' => $d['text']];
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
        $stats[] = ['group' => '🎯 Prognose', 'text' => $this->accuracyText($v['accuracy'])];
        foreach ($v['life'] as $l) {
            $stats[] = ['group' => $l['name'], 'text' => implode(', ', array_map(function ($d) { return BWACHLogik::num($d) . ' Tage'; }, $l['days']))];
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
            'where'   => 'Instanz „' . IPS_GetName($this->InstanceID) . '“ öffnen, Panel „Geräte-Einstellungen“, Spalte „Zelltyp“',
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
            $dl[] = date('d.m.Y', (int)$d['t']) . ' ' . $d['name'] . ' (' . ($d['type'] === 'erkannt' ? 'erkannt' : ($d['type'] === 'nachgetragen' ? 'nachgetragen' : 'eingetragen')) . ')';
        }
        return $dl
            ? '📓 Zuletzt im Tagebuch: ' . implode(' · ', $dl)
            : '📓 Das Batterietagebuch ist noch leer. Wechsel werden erkannt (Prozentwert springt hoch, „schwach“-Flag wird zurückgesetzt) oder hier eingetragen. Die ganze Liste steht in der Variable „Batterietagebuch“.';
    }

    private function AckPanel(): array
    {
        $found = $this->found();
        $options = [['caption' => '— Gerät wählen —', 'value' => '']];
        $placeOptions = [['caption' => '— Ort wählen —', 'value' => '']];
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
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'AckDate', 'caption' => 'Datum eines früheren Wechsels (TT.MM.JJJJ)', 'width' => '320px'],
                    ['type' => 'Button', 'caption' => '📅 Wechsel nachtragen', 'onClick' => 'echo BWACH_AddReplacement($id, $AckDevice, $AckDate);'],
                ]],
                ['type' => 'Label', 'caption' => 'ℹ️ „Wechsel nachtragen“ trägt einen Wechsel mit Datum ins Tagebuch ein, der vor dem Wächter stattfand. Das verbessert Lebensdauer und Vorsorge, löst aber keine Meldung aus.'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Select', 'name' => 'AckPlace', 'caption' => 'Ort aus der Tauschrunde', 'width' => '320px', 'options' => $placeOptions],
                    ['type' => 'Button', 'caption' => '✔️ Alle dort getauscht', 'onClick' => 'echo BWACH_AcknowledgePlace($id, $AckPlace);'],
                ]],
                ['type' => 'Label', 'name' => 'AckStatus', 'caption' => 'ℹ️ Noch nichts quittiert.'],
                ['type' => 'Label', 'name' => 'RetiredLine', 'caption' => $this->retiredLine($found)],
                ['type' => 'Button', 'caption' => '↩️ Außer-Betrieb-Geräte aufnehmen', 'onClick' => 'echo BWACH_UnretireAll($id);'],
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
                ['type' => 'ValidationTextBox', 'name' => 'ShopLink', 'caption' => 'Link für die Einkaufsliste (optional, mit {Zelltyp})'],
                ['type' => 'Label', 'caption' => 'ℹ️ Wer will, trägt hier die Suche seines Händlers ein, z. B. https://www.example.org/suche?q={Zelltyp}. {Zelltyp} wird durch die Bezeichnung ersetzt (AAA, CR2032 …); die Kachel zeigt dann hinter jeder Zeile der Einkaufsliste einen Suchlink. Leer = kein Link, der Wächter nennt keinen Händler und sendet nichts dorthin.'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '🛒 Einkaufsliste als Text', 'onClick' => 'echo BWACH_ShoppingText($id);'],
                    ['type' => 'Button', 'caption' => '✉️ Einkaufsliste jetzt senden', 'onClick' => 'echo BWACH_SendShopping($id);'],
                    ['type' => 'Button', 'caption' => '📄 Geräteliste als CSV', 'onClick' => 'echo BWACH_DeviceListCsv($id);'],
                ]],
                ['type' => 'List', 'name' => 'Stock', 'caption' => 'Vorrat zu Hause (wird von der Einkaufsliste abgezogen)', 'rowCount' => 4, 'add' => true, 'delete' => true,
                    'columns' => [
                        ['caption' => 'Zelltyp', 'name' => 'Cell', 'width' => 'auto', 'add' => 'aaa_alkali', 'edit' => ['type' => 'Select', 'options' => array_values(array_filter(BWACHZelle::options(), function ($o) { return $o['value'] !== BWACHZelle::UNKNOWN; }))]],
                        ['caption' => 'Stück da', 'name' => 'Count', 'width' => '110px', 'add' => 0, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 9999]],
                    ]],
                ['type' => 'NumberSpinner', 'name' => 'PreventiveMonths', 'caption' => 'Kritische Geräte vorsorglich tauschen alle (0 = aus)', 'suffix' => ' Monate', 'minimum' => 0, 'maximum' => 60],
                ['type' => 'Label', 'caption' => 'ℹ️ Vorsorge gilt nur für Geräte, die unter „Geräte-Einstellungen“ oder per Gruppen-Regel als „kritisch“ markiert sind (z. B. Rauchmelder), und nur, wenn ein letzter Wechsel im Tagebuch steht (erkannt, eingetragen oder nachgetragen). Ein fälliger Wechsel steht in der Tauschrunde und wird gemeldet, auch wenn die Batterie noch gut ist.'],
                ['type' => 'NumberSpinner', 'name' => 'SoonDays', 'caption' => '„Bald leer“ melden, wenn die Batterie laut Prognose noch höchstens', 'suffix' => ' Tage reicht', 'minimum' => 3, 'maximum' => 90],
                ['type' => 'Label', 'caption' => 'ℹ️ „Bald leer“ wird nur bei hoher oder mittlerer Sicherheit der Prognose gemeldet, nie bei geringer.'],
                ['type' => 'CheckBox', 'name' => 'LearnIntervals', 'caption' => 'Meldeverhalten lernen (Funkstille früher erkennen, wenn ein Gerät sonst sehr regelmäßig sendet)'],
                ['type' => 'SelectVariable', 'name' => 'OutdoorTempVar', 'caption' => 'Außentemperatur (optional, für den Kälteeinfluss auf die Entladung)'],
                ['type' => 'NumberSpinner', 'name' => 'ColdBelow', 'caption' => 'Als „kalt“ gilt unter', 'suffix' => ' °C', 'minimum' => -20, 'maximum' => 20],
                ['type' => 'Label', 'caption' => 'ℹ️ Der Kälteeinfluss wird erst ausgewertet, wenn mindestens 3 Geräte je 3 Zeitabschnitte bei Kälte und bei Wärme haben — also frühestens nach einem Winter. Bis dahin steht ehrlich „noch nicht genug Daten“.'],
                ['type' => 'NumberSpinner', 'name' => 'PollAfterDays', 'caption' => 'Abfrage schlafender Geräte, wenn der Batteriewert älter ist als', 'suffix' => ' Tage', 'minimum' => 3, 'maximum' => 365],
                ['type' => 'NumberSpinner', 'name' => 'PollEveryDays', 'caption' => 'Abfrage höchstens alle', 'suffix' => ' Tage', 'minimum' => 1, 'maximum' => 90],
                ['type' => 'Label', 'caption' => 'ℹ️ Die Abfrage ist je Gerät unter „Geräte-Einstellungen“ (Spalte „Abfragen“) einzuschalten, standardmäßig AUS. Sie gibt es für Z-Wave- und Matter-Geräte. Ein schlafendes Z-Wave-Gerät beantwortet sie erst beim nächsten Aufwachen, bis dahin liegt sie in der Warteschlange der Z-Wave-Instanz; zu häufiges Abfragen füllt diese Warteschlange. Matter-Geräte haben in ersten Tests nach wenigen Sekunden geantwortet. Bei Matter wird die Instanz der Stromversorgung (Endpunkt 0) abgefragt, denn dort liegt der Batteriestand.'],
                ['type' => 'NumberSpinner', 'name' => 'OrphanDays', 'caption' => 'Gerät als „vermutlich ausgebaut“ vorschlagen, wenn es so lange still ist (0 = aus)', 'suffix' => ' Tage', 'minimum' => 0, 'maximum' => 720],
                ['type' => 'PopupButton', 'caption' => 'Wie sicher ist die Prognose?', 'width' => '500px', 'popup' => [
                    'caption' => 'Wie sicher ist die Prognose, und was heißt „aus Spannung berechnet“?',
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
                        ['type' => 'Label', 'caption' => 'Funkstille hängt am Lebenszeichen des Geräts (irgendeine Aktualisierung), nicht am Batteriewert. Wähle die Tage großzügig, wenn ein Gerät nur selten etwas sendet.'],
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
    private function deviceInfo(int $id, array $pcts, array $own, array $labels, array $rules): array
    {
        if ($id <= 0 || !IPS_InstanceExists($id)) {
            return ['Name' => '(Instanz fehlt)', 'Place' => '', 'Module' => '', 'Percent' => '—', 'PercentSort' => 1000.0, 'Effect' => '', 'effectiveCell' => BWACHZelle::UNKNOWN, 'effectiveExcluded' => true];
        }
        $label = $labels[$id] ?? ['name' => IPS_GetName($id), 'place' => '', 'module' => (string)(IPS_GetInstance($id)['ModuleInfo']['ModuleName'] ?? '')];
        $p     = $pcts[$id] ?? null;
        $eff   = BWACHLogik::applyRules([
            'group' => (string)$own['Group'], 'critical' => (bool)$own['Critical'], 'ignoreAge' => (bool)$own['IgnoreAge'],
            'excluded' => (bool)$own['Excluded'], 'cell' => (string)$own['Cell'], 'cells' => (int)$own['Cells'], 'poll' => (bool)$own['Poll'],
        ], $label, $rules);
        $parts = [BWACHZelle::isKnown($eff['cell']) ? ($eff['cells'] > 1 ? $eff['cells'] . '× ' : '') . (string)BWACHZelle::shopLabel($eff['cell']) : 'Zelltyp offen'];
        if ($eff['group'] === BWACHLogik::GROUP_EVENT) { $parts[] = 'Ereignismelder'; }
        if ($eff['critical']) { $parts[] = 'kritisch'; }
        if ($eff['excluded']) { $parts[] = 'ausgenommen'; }
        if ($eff['rule'] !== null) {
            $rl = $rules[$eff['rule']];
            $parts[] = 'Regel ' . ($eff['rule'] + 1) . ($rl['Label'] !== '' ? ' (' . $rl['Label'] . ')' : '');
        }
        return [
            'Name'          => $label['name'],
            'Place'         => $label['place'],
            'Module'        => $label['module'],
            'Percent'       => $p === null ? '—' : BWACHLogik::num($p) . ' %',
            'PercentSort'   => $p === null ? 1000.0 : round($p, 1),   // Geräte ohne Wert landen bei „aufsteigend“ hinten
            'Effect'        => implode(' · ', $parts),
            'effectiveCell' => $eff['cell'],
            'effectiveExcluded' => (bool)$eff['excluded'],
        ];
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
            if (empty($sig['percent'])) {
                continue;
            }
            $v  = (float)$sig['percent']['value'] * (float)($sig['percent']['scale'] ?? 1.0);
            $id = (int)($d['parent'] ?? $key);
            $out[$id] = isset($out[$id]) ? min($out[$id], $v) : $v;
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
        $saved  = json_decode((string)$this->ReadPropertyString('DeviceSettings'), true);
        $rows   = [];
        $have   = [];
        $filled = 0;
        $found0 = $this->found();
        $pcts   = $this->currentPercents($found0);
        $labels = $this->deviceLabels($found0);
        $rules  = $this->groupRules();
        $open   = [];
        if (is_array($saved)) {
            foreach ($saved as $r) {
                $id = (int)($r['Instance'] ?? 0);
                if ($id > 0 && isset($have[$id])) {
                    continue;   // doppelte Zeilen derselben Instanz: die erste gilt, wie bei der Auswertung
                }
                if ($id > 0) {
                    $have[$id] = true;
                }
                // Ein noch nicht gewählter Zelltyp wird mit dem vorbelegt, was das Gerät meldet; eine Wahl bleibt unberührt
                $cell = BWACHZelle::isKnown((string)($r['Cell'] ?? '')) ? (string)$r['Cell'] : BWACHZelle::UNKNOWN;
                if ($cell === BWACHZelle::UNKNOWN && ($fromDev = $this->cellFromDevice($id)) !== BWACHZelle::UNKNOWN) {
                    $cell = $fromDev;
                    $filled++;
                }
                $row = [
                    'Instance'  => $id,
                    'Group'     => (string)($r['Group'] ?? BWACHLogik::GROUP_STANDARD),
                    'Critical'  => (bool)($r['Critical'] ?? false),
                    'IgnoreAge' => (bool)($r['IgnoreAge'] ?? false),
                    'Excluded'  => (bool)($r['Excluded'] ?? false),
                    'Cell'      => $cell,
                    'Cells'     => max(1, min(12, (int)($r['Cells'] ?? 1))),
                    'Poll'      => (bool)($r['Poll'] ?? false),
                ];
                $info   = $this->deviceInfo($id, $pcts, $row, $labels, $rules);
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
                if ($id > 0 && !isset($have[$id]) && IPS_InstanceExists($id)) {
                    $have[$id] = true;
                    $cell = $this->cellFromDevice($id);
                    $filled += $cell !== BWACHZelle::UNKNOWN ? 1 : 0;
                    $row  = ['Instance' => $id, 'Group' => BWACHLogik::GROUP_STANDARD, 'Critical' => false, 'IgnoreAge' => false,
                        'Excluded' => false, 'Cell' => $cell, 'Cells' => 1, 'Poll' => false];
                    $info = $this->deviceInfo($id, $pcts, $row, $labels, $rules);
                    if ($info['effectiveCell'] === BWACHZelle::UNKNOWN && !$info['effectiveExcluded']) { $open[] = $info['Name']; }
                    unset($info['effectiveCell'], $info['effectiveExcluded']);
                    $new[$id] = $row + $info;
                }
            }
            uasort($new, function ($a, $b) { return strcasecmp($a['Name'], $b['Name']); });
        }
        sort($open, SORT_NATURAL | SORT_FLAG_CASE);
        // Treffer je Regel: wie viele Geräte die Regel als erste trifft
        $hits = array_fill(0, count($rules), 0);
        foreach ($labels as $lb) {
            $i = BWACHLogik::firstRule($rules, $lb);
            if ($i !== null) { $hits[$i]++; }
        }
        return ['rows' => array_merge($rows, array_values($new)), 'added' => count($new), 'filled' => $filled, 'open' => $open, 'hits' => $hits, 'labels' => $labels];
    }

    private function FirstStepsPanel(array $dr): array
    {
        $found = $this->found();
        $steps = BWACHLogik::firstSteps([
            'found' => $found !== null, 'devices' => count($found['devices'] ?? []), 'open' => count($dr['open']),
            'notify' => $this->ReadPropertyBoolean('NotificationsActive'),
            'channel' => $this->ReadPropertyBoolean('NotifyPush') || $this->ReadPropertyBoolean('NotifyMail'),
            'matterNoEp0' => count($this->matterWithoutPower()),
        ]);
        $allDone = count(array_filter($steps, function ($s) { return $s['done'] === false; })) === 0;
        $items = [];
        foreach ($steps as $s) {
            $items[] = ['type' => 'Label', 'caption' => ($s['done'] === true ? '✅ ' : ($s['done'] === false ? '⬜ ' : '▫️ ')) . $s['text']];
        }
        return [
            'type' => 'ExpansionPanel', 'expanded' => !$allDone,
            'caption' => '🚀  Erste Schritte',
            'items' => $items,
        ];
    }

    private function RulesPanel(array $dr): array
    {
        $rules = $this->groupRules();
        $rows  = [];
        foreach ($rules as $i => $r) {
            $rows[] = $r + ['Hits' => (int)($dr['hits'][$i] ?? 0)];
        }
        $open = $dr['open'];
        $line = count($rules) === 0
            ? 'ℹ️ Noch keine Regel. Mit Regeln trägst du Zelltyp, Anzahl Zellen und mehr für ganze Gruppen auf einmal ein, z. B. alle Geräte im Ort „Öffnungskontakte“.'
            : (count($open) === 0
                ? '✅ Für jedes Gerät ist ein Zelltyp bekannt.'
                : '⚠️ Noch ohne Zelltyp: ' . implode(', ', array_slice($open, 0, 8)) . (count($open) > 8 ? ' und ' . (count($open) - 8) . ' weitere' : '') . '.');
        return [
            'type' => 'ExpansionPanel', 'expanded' => count($rules) === 0 && count($open) > 0,
            'caption' => '👥  Gruppen',
            'items' => [
                ['type' => 'Label', 'name' => 'RuleLine', 'caption' => $line],
                ['type' => 'Label', 'caption' => 'Eine Regel gilt für alle Geräte, auf die ihr Muster passt: nach Ort (Kategorie der Instanz), System (Modul) oder Name. Mehrere Muster trennst du mit Komma (ODER), Groß- und Kleinschreibung ist egal. Es gilt die erste passende Regel von oben, Ausnahmen gehören also nach oben. Eine eigene Einstellung in „Geräte-Einstellungen“ geht vor, sobald sie vom Standard abweicht (Zelltyp gewählt, Ereignismelder, kritisch, ausgenommen). Die Spalte „Gilt“ dort zeigt, was am Ende für ein Gerät zählt.'],
                [
                    'type' => 'List', 'name' => 'GroupRules', 'caption' => 'Regeln', 'rowCount' => 6, 'add' => true, 'delete' => true, 'changeOrder' => true,
                    'loadValuesFromConfiguration' => false,
                    'values' => $rows,
                    'columns' => [
                        ['caption' => 'An', 'name' => 'Active', 'width' => '50px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Bezeichnung', 'name' => 'Label', 'width' => '160px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Kriterium', 'name' => 'Kind', 'width' => '150px', 'add' => BWACHLogik::RULE_PLACE, 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => 'Ort (Kategorie)', 'value' => BWACHLogik::RULE_PLACE],
                            ['caption' => 'System (Modul)', 'value' => BWACHLogik::RULE_MODULE],
                            ['caption' => 'Name enthält', 'value' => BWACHLogik::RULE_NAME],
                        ]]],
                        ['caption' => 'Muster (Komma = oder)', 'name' => 'Pattern', 'width' => 'auto', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Zelltyp', 'name' => 'Cell', 'width' => '230px', 'add' => BWACHZelle::UNKNOWN, 'edit' => ['type' => 'Select', 'options' => BWACHZelle::options()]],
                        ['caption' => 'Zellen', 'name' => 'Cells', 'width' => '80px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 12]],
                        ['caption' => 'Gruppe', 'name' => 'Group', 'width' => '150px', 'add' => '', 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => 'nicht ändern', 'value' => ''],
                            ['caption' => 'Ereignismelder', 'value' => BWACHLogik::GROUP_EVENT],
                        ]]],
                        ['caption' => 'Kritisch', 'name' => 'Critical', 'width' => '70px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Ausnehmen', 'name' => 'Excluded', 'width' => '90px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Abfragen', 'name' => 'Poll', 'width' => '80px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Treffer', 'name' => 'Hits', 'width' => '70px', 'add' => 0],
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
        return "Gruppe: „Standard“ oder „Ereignismelder“. Fenster- und Rauchmelder melden nur bei Ereignissen, deshalb wartet der Wächter bei ihnen länger mit „Funkstille“ (jetzt $stillE statt $still Tage).\n\n"
            . "Kritisch: für Geräte, bei denen eine leere Batterie weh tut. Der Wächter warnt früher (schwach ab $crit % statt $low %), sortiert das Gerät weiter oben und erinnert öfter (alle $remC statt $rem Tage)" . ($quiet ? '; die Ruhezeit gilt dafür nicht' : '') . ". Bei einem Widerspruch zwischen Prozentwert und Hinweis „schwach“ zählt die schlechtere Aussage.\n\n"
            . "Ohne Altersprüfung: normal gilt ein Batteriewert, der älter als $old Tage ist, als „veraltet“. Mit diesem Haken entfällt das, für Geräte, die ihren Batteriewert nur sehr selten melden. Die Funkstille-Prüfung bleibt davon unberührt.\n\n"
            . "Ausnehmen: das Gerät wird gar nicht überwacht (keine Meldung, nicht in Kachel und Zahlen). Die Zeile bleibt stehen, damit die Einstellung erhalten bleibt. Etwas anderes als „Außer Betrieb“ in der Kachel.\n\n"
            . "Zelltyp und Anzahl Zellen: damit rechnet der Wächter Spannungen in einen Ladezustand um und stellt die Einkaufsliste zusammen. Meldet ein Matter-Gerät seinen Zelltyp selbst, steht er schon drin.\n\n"
            . "Abfragen: bei Z-Wave und Matter. Ist der Batteriewert älter als $pAfter Tage, schickt der Wächter dem Gerät höchstens alle $pEvery Tage eine Statusanfrage und zeigt, ob es antwortet. Ein schlafendes Z-Wave-Gerät antwortet erst beim nächsten Aufwachen, ob das den Batteriewert früher liefert, ist dort nicht belegt. Matter-Geräte haben in ersten Tests nach wenigen Sekunden geantwortet. Bei anderen Systemen tut der Haken nichts.";
    }

    private function DevicesPanel(array $dr): array
    {
        $fill = $dr['filled'] > 0
            ? ' Bei ' . $dr['filled'] . ' ' . ($dr['filled'] === 1 ? 'Gerät' : 'Geräten') . ' steht der Zelltyp schon drin, weil das Gerät ihn selbst meldet (bei AA/AAA nur die Bauform, Alkali ist angenommen: bei Akkus bitte ändern).'
            : '';
        $line = $dr['added'] > 0
            ? '✅ Alle erkannten Geräte stehen schon in der Liste (' . $dr['added'] . ' neu, noch nicht gespeichert). Je Gerät nur den Zelltyp und die Anzahl Zellen wählen, dann unten „Übernehmen“ klicken.' . $fill
            : ($dr['filled'] > 0 ? '✅ Alle erkannten Geräte stehen in der Liste, noch nicht gespeichert.' . $fill . ' Zum Speichern unten „Übernehmen“ klicken.'
            : (count($dr['rows']) > 0 ? '✅ Alle erkannten Geräte stehen in der Liste.' : 'ℹ️ Noch keine Geräte erkannt: zuerst oben „Jetzt neu suchen“ drücken.'));
        return [
            'type' => 'ExpansionPanel', 'expanded' => $dr['added'] > 0 || $dr['filled'] > 0,
            'caption' => '🏷️  Geräte-Einstellungen',
            'items' => [
                ['type' => 'Label', 'name' => 'DeviceRowsLine', 'caption' => $line],
                ['type' => 'Label', 'caption' => 'Hier steht jedes erkannte Gerät mit neutralen Standardwerten. Nur ändern, was abweicht. Für viele gleiche Geräte auf einmal gibt es das Panel „Gruppen“. Die Spalte „Gilt“ zeigt, was am Ende für ein Gerät zählt.'],
                ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '❔  Was bedeuten die Spalten?', 'items' => [
                    ['type' => 'Label', 'caption' => $this->deviceHelp()],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Select', 'name' => 'DeviceSortBy', 'caption' => 'Sortieren nach', 'width' => '230px', 'options' => [
                        ['caption' => 'Name', 'value' => 'name'],
                        ['caption' => 'Ort', 'value' => 'place'],
                        ['caption' => 'System', 'value' => 'module'],
                        ['caption' => 'Batteriestand', 'value' => 'percent'],
                        ['caption' => 'Zelltyp', 'value' => 'cell'],
                        ['caption' => 'Gruppe', 'value' => 'group'],
                        ['caption' => 'Kritisch', 'value' => 'critical'],
                    ], 'onChange' => 'BWACH_SetDeviceSort($id, $DeviceSortBy, $DeviceSortDir);'],
                    ['type' => 'Select', 'name' => 'DeviceSortDir', 'caption' => 'Reihenfolge', 'width' => '200px', 'options' => [
                        ['caption' => 'aufsteigend (A–Z)', 'value' => 'ascending'],
                        ['caption' => 'absteigend (Z–A)', 'value' => 'descending'],
                    ], 'onChange' => 'BWACH_SetDeviceSort($id, $DeviceSortBy, $DeviceSortDir);'],
                ]],
                [
                    'type' => 'List', 'name' => 'DeviceSettings', 'caption' => 'Geräte', 'rowCount' => 12, 'add' => true, 'delete' => true,
                    'loadValuesFromConfiguration' => false,
                    'sort' => $this->deviceSort(),
                    'values' => $dr['rows'],
                    'columns' => [
                        ['caption' => 'Geräteinstanz', 'name' => 'Instance', 'width' => 'auto', 'add' => 0, 'sortColumn' => 'Name', 'edit' => ['type' => 'SelectInstance']],
                        ['caption' => 'Ort', 'name' => 'Place', 'width' => '140px', 'add' => ''],
                        ['caption' => 'System', 'name' => 'Module', 'width' => '120px', 'add' => ''],
                        ['caption' => 'Batteriestand', 'name' => 'Percent', 'width' => '110px', 'add' => '—', 'sortColumn' => 'PercentSort'],
                        ['caption' => 'Gilt', 'name' => 'Effect', 'width' => '260px', 'add' => ''],
                        ['caption' => 'Name', 'name' => 'Name', 'width' => '0px', 'visible' => false, 'add' => ''],
                        ['caption' => 'Sortierwert Batteriestand', 'name' => 'PercentSort', 'width' => '0px', 'visible' => false, 'add' => 1000],
                        ['caption' => 'Gruppe', 'name' => 'Group', 'width' => '170px', 'add' => BWACHLogik::GROUP_STANDARD, 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => 'Standard', 'value' => BWACHLogik::GROUP_STANDARD],
                            ['caption' => 'Ereignismelder', 'value' => BWACHLogik::GROUP_EVENT],
                        ]]],
                        ['caption' => 'Kritisch', 'name' => 'Critical', 'width' => '80px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Ohne Altersprüfung', 'name' => 'IgnoreAge', 'width' => '150px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Ausnehmen', 'name' => 'Excluded', 'width' => '100px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Zelltyp', 'name' => 'Cell', 'width' => '230px', 'add' => BWACHZelle::UNKNOWN, 'edit' => ['type' => 'Select', 'options' => BWACHZelle::options()]],
                        ['caption' => 'Anzahl Zellen', 'name' => 'Cells', 'width' => '120px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 12]],
                        ['caption' => 'Abfragen (Z-Wave, Matter)', 'name' => 'Poll', 'width' => '190px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
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
        if ($this->ReadAttributeString('ForumHintSeen') === self::FORUM_HINT_REV) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Rückmeldungen',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 Der Batteriewächter ist neu — Fragen, Wünsche oder Fehler sind im Forum-Thread willkommen. Besonders gefragt: Erfahrungen mit Zigbee2MQTT, Matter und HomeMatic, die der Entwickler selbst nicht testen kann. Wer sie ausprobiert, drückt unter „Gefundene Geräte“ auf „Diagnose fürs Forum“ und stellt die Ausgabe dort ein.'],
                ['type' => 'Button', 'caption' => 'Zum Forum-Thread', 'onClick' => "echo '" . self::FORUM_URL . "';", 'link' => true],
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

        $dr = $this->deviceRows();
        $elements = array_values(array_filter([
            $this->PurposeIntro(), $this->NewsBanner(), $this->DocPanel(), $this->FirstStepsPanel($dr),
            $this->DiscoveryPanel(), $this->StatusPanel(), $this->NotifyPanel(), $this->AckPanel(), $this->ForecastPanel(), $this->ThresholdPanel(),
            $this->RulesPanel($dr), $this->DevicesPanel($dr), $this->ManualPanel(),
            $this->ForumHint(), $this->LicenseHint(),
        ]));

        return json_encode(['elements' => $elements, 'actions' => [], 'status' => $status]);
    }
}
