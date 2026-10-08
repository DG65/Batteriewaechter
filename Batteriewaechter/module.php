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
// Ereignisgesteuert: Änderungen der Batteriewerte werden sofort bewertet
// (kurze Verzögerung gegen Meldungsschwärme), Funkstille und Wertealter
// zeitgesteuert (Standard stündlich). Kein Archiv nötig.
//
// Eigenständig: setzt kein anderes Modul voraus und wird von keinem
// vorausgesetzt.
// ===========================================================================

require_once __DIR__ . '/BWACHLogik.php';

class Batteriewaechter extends IPSModule
{
    private const LIBRARY_GUID = '{68C5991B-8E85-23AC-C254-B446AF035AF8}';

    // Formular-Konvention (SUITE.md "Einheitliche Formular-Optik", NEWS_VERSIONS-Muster)
    private const NEWS_VERSIONS = [
        '0.1.0' => [
            '• Erstes Release: findet Batteriesignale automatisch (Profile ~Battery/~Battery.100/~Battery.Reversed, typische Idents) und führt sie je Gerät zusammen.',
            '• Status ok/schwach/leer/unbekannt mit Datenqualität: Widerspruch zwischen Flag und Prozent, unplausible Werte, veraltete Batteriewerte.',
            '• Funkstille als eigener Befund, getrennt von der Batterie (Lebenszeichen des Geräts statt Alter des Batteriewerts).',
            '• Trockenlauf „Was würde gefunden?“ mit Begründung für jeden Ausschluss; Ergebnis als Tabelle und Kennzahlen-Variablen.',
        ],
    ];
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
        $this->RegisterPropertyBoolean('NameSearch', false);
        $this->RegisterPropertyString('ManualVariables', '[]');
        $this->RegisterPropertyString('DeviceSettings', '[]');

        $this->RegisterAttributeString('Found', '');
        $this->RegisterAttributeInteger('LastDiscoveryTs', 0);
        $this->RegisterAttributeInteger('LastCheckTs', 0);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeBoolean('ForumHintGone', false);

        $this->RegisterTimer('Tick', 0, 'BWACH_Tick($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Debounce', 0, 'BWACH_Debounced($_IPS[\'TARGET\']);');

        $this->RegisterMessage(0, self::KERNEL_MSG);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->MaintainVariable('Total', 'Geräte überwacht', VARIABLETYPE_INTEGER, '', 10, true);
        $this->MaintainVariable('Empty', 'Batterie leer', VARIABLETYPE_INTEGER, '', 20, true);
        $this->MaintainVariable('Low', 'Batterie schwach', VARIABLETYPE_INTEGER, '', 30, true);
        $this->MaintainVariable('Silent', 'Funkstille', VARIABLETYPE_INTEGER, '', 40, true);
        $this->MaintainVariable('Check', 'Daten prüfen', VARIABLETYPE_INTEGER, '', 50, true);
        $this->MaintainVariable('Unknown', 'Status unbekannt', VARIABLETYPE_INTEGER, '', 60, true);
        $this->MaintainVariable('StatusLine', 'Zusammenfassung', VARIABLETYPE_STRING, '', 70, true);
        $this->MaintainVariable('TableProblems', 'Handlungsbedarf', VARIABLETYPE_STRING, '~HTMLBox', 80, true);
        $this->MaintainVariable('TableAll', 'Alle Geräte', VARIABLETYPE_STRING, '~HTMLBox', 90, true);

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
        $rows    = $this->evaluateAll($found);
        $results = array_column($rows, 'r');
        $sum     = BWACHLogik::summarize($results);

        $this->setIntIfChanged('Total', $sum['total']);
        $this->setIntIfChanged('Empty', $sum['empty']);
        $this->setIntIfChanged('Low', $sum['low']);
        $this->setIntIfChanged('Silent', $sum['silent']);
        $this->setIntIfChanged('Check', $sum['check']);
        $this->setIntIfChanged('Unknown', $sum['unknown']);

        $now  = $this->now();
        $line = $this->summaryLine($sum, $now);
        $this->setStringIfChanged('StatusLine', $line);
        $this->setStringIfChanged('TableAll', $this->renderTable($rows, false));
        $this->setStringIfChanged('TableProblems', $this->renderTable($rows, true));
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
            $out[] = '  • ' . $d['name'] . ' [' . ($d['module'] !== '' ? $d['module'] : 'Variable') . ']: ' . implode('; ', $parts);
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
            ];
        }
        return $out;
    }

    private function instanceInfo(int $parent): ?array
    {
        if ($parent <= 0 || !IPS_InstanceExists($parent)) {
            return null;
        }
        $i = IPS_GetInstance($parent);
        return ['module' => (string)($i['ModuleInfo']['ModuleName'] ?? ''), 'name' => IPS_GetName($parent)];
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
        $rows     = [];

        foreach ($found['devices'] as $key => $d) {
            // Geteilte Einträge (mehrere Sensoren einer Instanz) tragen die Instanz in 'parent'.
            $id = (int)($d['parent'] ?? $key);
            $st = $settings[$id] ?? ['group' => BWACHLogik::GROUP_STANDARD, 'critical' => false, 'ignoreAge' => false, 'excluded' => false];
            if ($st['excluded']) {
                continue;
            }
            $isInstance = IPS_InstanceExists($id);
            $sig  = $this->readSignals($d['signals']);
            $life = $this->lifeSign($id, $isInstance, $d['signals']);

            $r = BWACHLogik::evaluate($sig, $life, $now, [
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
                $name = IPS_GetName($id);
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
            $rows[] = ['id' => $key, 'name' => $name, 'place' => $place, 'module' => (string)$d['module'], 'r' => $r];
        }
        usort($rows, function ($a, $b) {
            return [$b['r']['urgency'], $a['name']] <=> [$a['r']['urgency'], $b['name']];
        });
        return $rows;
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
                    $sig['voltage'] = ['value' => (float)$val, 'updated' => $upd];
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
        if ($sum['silent'] > 0) { $bad[] = $sum['silent'] . ' Funkstille'; }
        if ($sum['check'] > 0) { $bad[] = $sum['check'] . ' mit zweifelhaften Daten'; }
        if ($sum['unknown'] > 0) { $bad[] = $sum['unknown'] . ' unbekannt'; }
        $icon = ($sum['empty'] > 0 || $sum['low'] > 0 || $sum['silent'] > 0) ? '⚠️' : ($bad ? 'ℹ️' : '✅');
        return $icon . ' ' . $sum['total'] . ' Geräte überwacht: ' . ($bad ? implode(', ', $bad) : 'alles in Ordnung')
            . ' (geprüft ' . date('d.m.Y H:i', $now) . ' Uhr).';
    }

    private function renderTable(array $rows, bool $onlyProblems): string
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
            $life = $r['lifeAge'] === null ? '—' : (($r['funk'] === 'still' ? '🔇 ' : '') . BWACHLogik::days($r['lifeAge']));
            $mark = $r['critical'] ? ' ❗' : '';
            $body .= '<tr><td>' . $e($row['name']) . $mark . '</td><td>' . $e($row['place']) . '</td><td>' . $e($bat) . '</td><td>'
                . $e($age) . '</td><td>' . $e($life) . '</td><td>' . $e(implode('; ', $r['reasons'])) . '</td></tr>';
        }
        if ($n === 0) {
            return '<div style="padding:8px">' . ($onlyProblems ? '✅ Kein Handlungsbedarf.' : 'ℹ️ Keine Geräte.') . '</div>';
        }
        return '<style>.bw{border-collapse:collapse;width:100%;font-size:14px}.bw th,.bw td{padding:4px 10px;text-align:left;border-bottom:1px solid rgba(128,128,128,.35)}</style>'
            . '<table class="bw"><tr><th>Gerät</th><th>Ort</th><th>Batterie</th><th>Wert-Alter</th><th>Lebenszeichen vor</th><th>Befund</th></tr>' . $body . '</table>';
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
                ['type' => 'Label', 'caption' => 'Skripte: BWACH_Search(<InstanzID>) sucht neu, BWACH_Check(<InstanzID>) bewertet, BWACH_Preview(<InstanzID>) liefert den Trockenlauf als Text.'],
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
                ['type' => 'Label', 'caption' => 'Die Tabellen „Handlungsbedarf“ und „Alle Geräte“ liegen als Variablen unter dieser Instanz.'],
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

    private function DevicesPanel(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🏷️  Geräte-Einstellungen',
            'items' => [
                ['type' => 'Label', 'caption' => 'Nur nötig für Geräte, die vom Standard abweichen: Ereignismelder (Fenster-, Rauchmelder), kritische Geräte (früher und dringlicher), Geräte ohne Altersprüfung oder Geräte, die ganz ausgenommen werden sollen.'],
                [
                    'type' => 'List', 'name' => 'DeviceSettings', 'caption' => 'Geräte', 'rowCount' => 8, 'add' => true, 'delete' => true,
                    'columns' => [
                        ['caption' => 'Geräteinstanz', 'name' => 'Instance', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'SelectInstance']],
                        ['caption' => 'Gruppe', 'name' => 'Group', 'width' => '170px', 'add' => BWACHLogik::GROUP_STANDARD, 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => 'Standard', 'value' => BWACHLogik::GROUP_STANDARD],
                            ['caption' => 'Ereignismelder', 'value' => BWACHLogik::GROUP_EVENT],
                        ]]],
                        ['caption' => 'Kritisch', 'name' => 'Critical', 'width' => '80px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Ohne Altersprüfung', 'name' => 'IgnoreAge', 'width' => '150px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Ausnehmen', 'name' => 'Excluded', 'width' => '100px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
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
            $this->DiscoveryPanel(), $this->StatusPanel(), $this->ThresholdPanel(),
            $this->DevicesPanel(), $this->ManualPanel(),
            $this->ForumHint(), $this->LicenseHint(),
        ]));

        return json_encode(['elements' => $elements, 'actions' => [], 'status' => $status]);
    }
}
