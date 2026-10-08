<?php

// ===========================================================================
// BWACHLogik — reine Entscheidungslogik des Batteriewächters (DG65 Toolkit).
//
// Keine IPS-Aufrufe: alles geht über Arrays rein und raus. Dadurch läuft der
// Prüfstand (.tools/test-bwach.php) ohne Symcon, und jede Regel ist einzeln
// prüfbar. module.php sammelt nur die Rohdaten und zeigt die Ergebnisse an.
//
// Zwei Aufgaben:
//   classify() — aus einer Liste von Variablen die Batteriesignale finden,
//                je Geräteinstanz zusammenführen, Heimspeicher/Sammelwerte/
//                Fremdobjekte ausschließen und jeden Ausschluss begründen.
//   evaluate() — aus den Signalen eines Geräts Status, Datenqualität und
//                Funkstille ableiten (das „Drei-Zeiten-Modell":
//                Alter des Batteriewerts, Lebenszeichen des Geräts, Gerät).
//
// Der Klassenname trägt den Modul-Präfix: Symcon lädt alle Module in einen
// gemeinsamen PHP-Namensraum, ein allgemeiner Name könnte mit einem anderen
// Modul kollidieren (SUITE.md, ModbusTcpClient-Vorfall).
// ===========================================================================

require_once __DIR__ . '/BWACHZelle.php';

final class BWACHLogik
{
    // Art des Signals
    public const KIND_PERCENT  = 'percent';        // Ladezustand in Prozent
    public const KIND_FLAG     = 'flag';           // Bool, true = Batterie schwach (Symcon ~Battery, HomeMatic LOWBAT)
    public const KIND_FLAG_REV = 'flag_reversed';  // Bool, true = Batterie in Ordnung (~Battery.Reversed)
    public const KIND_VOLTAGE  = 'voltage';        // Spannung in Volt (Zelltyp-Auswertung folgt in Stufe 2)

    // Herkunft der Erkennung: je weiter hinten, desto unsicherer
    public const BASIS_MANUAL  = 'manuell';
    public const BASIS_PROFILE = 'profil';
    public const BASIS_IDENT   = 'ident';
    public const BASIS_NAME    = 'name';           // nur Vorschlag, wird nur auf Wunsch mitgenommen

    // Status der Batterie
    public const ST_OK      = 'ok';
    public const ST_LOW     = 'schwach';
    public const ST_EMPTY   = 'leer';
    public const ST_UNKNOWN = 'unbekannt';

    // Gerätegruppen für die Funkstille-Schwelle
    public const GROUP_STANDARD = 'standard';
    public const GROUP_EVENT    = 'ereignis';      // sendet nur bei Ereignissen (Fenster-/Rauchmelder)

    // Variablentypen von Symcon
    private const T_BOOL   = 0;
    private const T_INT    = 1;
    private const T_FLOAT  = 2;

    /**
     * Module, deren „Batterie“ ein Energiespeicher oder ein Fahrzeugakku ist,
     * kein Gerät mit Wegwerf- oder Wechselbatterie. Wird als Standard für die
     * Einstellung „Ausgeschlossene Module“ verwendet und ist dort änderbar.
     */
    public const DEFAULT_EXCLUDED_MODULES = [
        'InverterHub', 'InverterHubDiscovery', 'MeterHub', 'MeterHubVirtual', 'ChargerHub',
        'EMS', 'Energy Manager', 'Energiefluss', 'Energiebilanz',
        'TessieVehicle', 'TessieConfigurator', 'ModBus Device', 'ModBus Address',
        'BatterieMonitor',
    ];

    // =====================================================================
    //  Erkennung
    // =====================================================================

    /**
     * Erkennt, ob eine einzelne Variable ein Batteriesignal ist.
     *
     * @param array $v ['ident','name','type','profile']  (profile = aufgelöstes Profil, '' wenn keines)
     * @return array|null ['kind','basis','scale','unverified'] oder null
     */
    public static function detectSignal(array $v): ?array
    {
        $type    = (int)($v['type'] ?? -1);
        $ident   = (string)($v['ident'] ?? '');
        $name    = (string)($v['name'] ?? '');
        $profile = (string)($v['profile'] ?? '');

        // 1. Variablenprofil — die sicherste Quelle
        if ($type === self::T_BOOL && $profile === '~Battery') {
            return self::sig(self::KIND_FLAG, self::BASIS_PROFILE);
        }
        if ($type === self::T_BOOL && $profile === '~Battery.Reversed') {
            return self::sig(self::KIND_FLAG_REV, self::BASIS_PROFILE);
        }
        if (($type === self::T_INT || $type === self::T_FLOAT) && $profile === '~Battery.100') {
            return self::sig(self::KIND_PERCENT, self::BASIS_PROFILE);
        }

        // 2. Ident — hersteller- und gatewaytypische Bezeichner
        if ($type === self::T_BOOL) {
            // HomeMatic LOWBAT/LOW_BAT (Dokumentation), Zigbee2MQTT battery_low, Z-Wave BatteryLowVariable
            if (preg_match('/(^|_)(low_?bat(tery)?|battery_?low)(variable)?($|_)/i', $ident)) {
                return self::sig(self::KIND_FLAG, self::BASIS_IDENT);
            }
            if (preg_match('/(^|_)battery_?ok($|_)/i', $ident)) {
                return self::sig(self::KIND_FLAG_REV, self::BASIS_IDENT);
            }
        }
        if ($type === self::T_INT || $type === self::T_FLOAT) {
            if (preg_match('/batpercentremaining/i', $ident)) {
                // Matter: Halbprozent-Skala (0–200). Aus der Spezifikation, bei Dietmar nicht live gesehen.
                return self::sig(self::KIND_PERCENT, self::BASIS_IDENT, 0.5, true);
            }
            if (preg_match('/^(battery|bat|batt)(_?(percent(age)?|level|variable|status))?$/i', $ident)
                || preg_match('/battery_?percent(age)?$/i', $ident)) {
                return self::sig(self::KIND_PERCENT, self::BASIS_IDENT);
            }
            if (preg_match('/(battery_?v(olt(age)?)?|operating_?voltage|bat_?volt(age)?)$/i', $ident)) {
                return self::sig(self::KIND_VOLTAGE, self::BASIS_IDENT);
            }
        }

        // 3. Name — nur als Vorschlag. Wörter, die auf einen Heimspeicher, Preise
        //    oder Leistungswerte hindeuten, sperren die Namenssuche.
        if (preg_match('/(speicher|entladung|ladung|leistung|energie|einstand|preis|kosten|modul|protokoll|strom|kategorie|heiz|ziel|soc|kapazit|zyklen|nachtladen|günstig|priorität|modus|bedingung)/iu', $name)) {
            return null;
        }
        if ($type === self::T_BOOL && preg_match('/^batterie\s*(schwach|leer)$/iu', trim($name))) {
            return self::sig(self::KIND_FLAG, self::BASIS_NAME);
        }
        if (($type === self::T_INT || $type === self::T_FLOAT) && preg_match('/^batterie(stand|zustand|status)?$/iu', trim($name))) {
            return self::sig(self::KIND_PERCENT, self::BASIS_NAME);
        }
        if (($type === self::T_FLOAT || $type === self::T_INT) && preg_match('/^batteriespannung$/iu', trim($name))) {
            return self::sig(self::KIND_VOLTAGE, self::BASIS_NAME);
        }
        return null;
    }

    /** Namen, die einen Sammelwert über mehrere Geräte beschreiben („Schwächste Batterie“ eines Raums). */
    public static function isAggregateName(string $name): bool
    {
        return (bool)preg_match('/(schwächste|niedrigste|geringste|minimum|\bmin\b|gesamt|summe|\balle\b)/iu', $name);
    }

    private static function sig(string $kind, string $basis, float $scale = 1.0, bool $unverified = false): array
    {
        return ['kind' => $kind, 'basis' => $basis, 'scale' => $scale, 'unverified' => $unverified];
    }

    /**
     * Sucht in einer Variablenliste alle Batteriesignale und führt sie je
     * Geräteinstanz zusammen.
     *
     * @param array $vars Liste von ['vid','ident','name','type','profile','parentId','parentIsInstance','moduleName','instanceName']
     * @param array $opt  ['excludedModules'=>string[], 'nameSearch'=>bool, 'manual'=>[['vid'=>int,'kind'=>string],…]]
     * @return array ['devices'=>[instId|'instId-vid'=>['name','module','parent'?,'signals'=>[kind=>[['vid','basis','scale','unverified']]]]], 'excluded'=>[['vid','name','reason']], 'suggestions'=>[['vid','name','kind']]]
     */
    public static function classify(array $vars, array $opt): array
    {
        $excludedModules = array_map('mb_strtolower', (array)($opt['excludedModules'] ?? self::DEFAULT_EXCLUDED_MODULES));
        $nameSearch      = (bool)($opt['nameSearch'] ?? false);
        $manual          = [];
        foreach ((array)($opt['manual'] ?? []) as $m) {
            $manual[(int)$m['vid']] = (string)$m['kind'];
        }

        $devices     = [];
        $excluded    = [];
        $suggestions = [];
        $aggregateInstances = [];

        foreach ($vars as $v) {
            $vid = (int)$v['vid'];
            if (isset($manual[$vid])) {
                $s = self::sig($manual[$vid], self::BASIS_MANUAL);
            } else {
                $s = self::detectSignal($v);
            }
            if ($s === null) {
                continue;
            }
            $name = (string)$v['name'];

            if ($s['basis'] !== self::BASIS_MANUAL) {
                if (!$v['parentIsInstance']) {
                    $excluded[] = ['vid' => $vid, 'name' => $name, 'reason' => 'gehört zu keiner Geräteinstanz (Skript- oder Kategorievariable)'];
                    continue;
                }
                if (in_array(mb_strtolower((string)$v['moduleName']), $excludedModules, true)) {
                    $excluded[] = ['vid' => $vid, 'name' => $name, 'reason' => 'Modul „' . $v['moduleName'] . '“ steht auf der Ausschlussliste (Energiespeicher o. ä.)'];
                    continue;
                }
                if (self::isAggregateName($name)) {
                    $aggregateInstances[(int)$v['parentId']] = true;
                    $excluded[] = ['vid' => $vid, 'name' => $name, 'reason' => 'Sammelwert über mehrere Geräte'];
                    continue;
                }
                if ($s['basis'] === self::BASIS_NAME && !$nameSearch) {
                    $suggestions[] = ['vid' => $vid, 'name' => $name, 'kind' => $s['kind']];
                    continue;
                }
            }

            $key = $v['parentIsInstance'] ? (int)$v['parentId'] : $vid;
            if (!isset($devices[$key])) {
                $devices[$key] = [
                    'name'    => $v['parentIsInstance'] ? (string)$v['instanceName'] : $name,
                    'module'  => (string)($v['moduleName'] ?? ''),
                    'signals' => [],
                ];
            }
            $kind = $s['kind'];
            $devices[$key]['signals'][$kind][] = ['vid' => $vid, 'basis' => $s['basis'], 'scale' => $s['scale'], 'unverified' => $s['unverified'], 'label' => $name];
        }

        // Eine Instanz, die selbst einen Sammelwert liefert (Raum, Gruppe), ist ein Sammelgerät:
        // auch ihre übrigen Batteriesignale (z. B. ein „schwach“-Flag des Raums) beschreiben
        // nur die Summe der Geräte darunter und dürfen nicht als eigenes Gerät zählen.
        foreach (array_keys($aggregateInstances) as $inst) {
            if (!isset($devices[$inst])) {
                continue;
            }
            foreach ($devices[$inst]['signals'] as $list) {
                foreach ($list as $sg) {
                    $excluded[] = ['vid' => $sg['vid'], 'name' => $sg['label'], 'reason' => 'Sammelwert über mehrere Geräte'];
                }
            }
            unset($devices[$inst]);
        }

        // Mehrere Signale GLEICHER Art an einer Instanz (z. B. neun Bodenfeuchtesensoren an einer
        // Wetterstation) sind mehrere Geräte, kein Gerät mit mehreren Werten: jedes bekommt einen
        // eigenen Eintrag, sonst überdeckt ein unplausibler Wert alle übrigen.
        foreach ($devices as $key => $d) {
            foreach ($d['signals'] as $kind => $list) {
                if (count($list) < 2) {
                    continue;
                }
                foreach ($list as $sg) {
                    $devices[$key . '-' . $sg['vid']] = [
                        'name'    => $d['name'] . ' › ' . $sg['label'],
                        'module'  => $d['module'],
                        'parent'  => (int)$key,
                        'signals' => [$kind => [$sg]],
                    ];
                }
                unset($devices[$key]['signals'][$kind]);
            }
            if (isset($devices[$key]) && !$devices[$key]['signals']) {
                unset($devices[$key]);
            }
        }
        ksort($devices);
        return ['devices' => $devices, 'excluded' => $excluded, 'suggestions' => $suggestions];
    }

    // =====================================================================
    //  Bewertung
    // =====================================================================

    /**
     * Bewertet ein Gerät.
     *
     * @param array $sig ['percent'=>['value'=>float,'updated'=>int,'scale'=>float]|null,
     *                    'flag'=>['value'=>bool,'updated'=>int,'reversed'=>bool]|null,
     *                    'voltage'=>['value'=>float,'updated'=>int]|null]
     * @param int   $life  Zeitstempel des jüngsten Lebenszeichens (0 = unbekannt)
     * @param int   $now
     * @param array $p     ['critical','group','ignoreAge','lowPct','emptyPct','critLowPct','valueOldDays','stillDays','stillDaysEvent']
     */
    public static function evaluate(array $sig, int $life, int $now, array $p): array
    {
        $critical = (bool)($p['critical'] ?? false);
        $lowThr   = (int)($critical ? ($p['critLowPct'] ?? 30) : ($p['lowPct'] ?? 20));
        $emptyThr = (int)($p['emptyPct'] ?? 5);

        $reasons = [];
        $quality = [];

        // --- Prozentwert aufbereiten ---------------------------------------
        $pct = null;
        $pctUpdated = 0;
        if (!empty($sig['percent'])) {
            $raw = (float)$sig['percent']['value'] * (float)($sig['percent']['scale'] ?? 1.0);
            $pctUpdated = (int)$sig['percent']['updated'];
            if ($raw < 0 || $raw > 100) {
                $quality[] = 'unplausibel';
                $reasons[] = 'Prozentwert außerhalb von 0–100 % (' . self::num($raw) . ' %), wird nicht bewertet';
            } else {
                $pct = $raw;
            }
        }
        // Nur Spannung, aber ein Zelltyp gewählt: Ladezustand aus der Entladekurve (Näherung)
        $derived  = false;
        $suggest  = '';
        $cell     = (string)($p['cell'] ?? BWACHZelle::UNKNOWN);
        $cells    = max(1, (int)($p['cells'] ?? 1));
        $voltOnly = empty($sig['percent']) && !empty($sig['voltage']);
        if ($voltOnly) {
            $volt = (float)$sig['voltage']['value'];
            if (BWACHZelle::isKnown($cell)) {
                $d = BWACHZelle::percentFromVoltage($cell, $cells, $volt);
                if ($d === null) {
                    $quality[] = 'zelltyp_passt_nicht';
                    $reasons[] = 'Spannung ' . self::num($volt) . ' V passt nicht zum gewählten Zelltyp (' . ($cells > 1 ? $cells . '× ' : '') . BWACHZelle::label($cell) . ')';
                } else {
                    $pct = $d;
                    $pctUpdated = (int)$sig['voltage']['updated'];
                    $derived = true;
                }
            } else {
                $suggest = BWACHZelle::suggest($volt);
            }
        }

        $flagLow = null;
        $flagUpdated = 0;
        if (!empty($sig['flag'])) {
            $flagLow = !empty($sig['flag']['reversed']) ? !(bool)$sig['flag']['value'] : (bool)$sig['flag']['value'];
            $flagUpdated = (int)$sig['flag']['updated'];
        }

        // --- Status je Signal -----------------------------------------------
        $stPct = null;
        if ($pct !== null) {
            $stPct = $pct <= $emptyThr ? self::ST_EMPTY : ($pct < $lowThr ? self::ST_LOW : self::ST_OK);
        }
        $stFlag = $flagLow === null ? null : ($flagLow ? self::ST_LOW : self::ST_OK);

        // --- Widerspruch zwischen Flag und Prozent ---------------------------
        $conflict = false;
        if ($stPct !== null && $stFlag !== null) {
            $conflict = ($flagLow && $pct >= $lowThr) || (!$flagLow && $pct <= $emptyThr);
        }

        $status = self::ST_UNKNOWN;
        if ($conflict) {
            $quality[] = 'widerspruch';
            $newer = $flagUpdated > $pctUpdated ? 'Flag' : 'Prozentwert';
            $reasons[] = 'Widerspruch: ' . ($flagLow ? 'Flag meldet „Batterie schwach“' : 'Flag meldet „in Ordnung“')
                . ', Prozentwert ' . self::num($pct) . ' % (neuer: ' . $newer . ')';
            if ($critical) {
                $status = self::worse($stPct, $stFlag);
            } else {
                $status = $flagUpdated > $pctUpdated ? $stFlag : $stPct;
            }
        } elseif ($stPct !== null || $stFlag !== null) {
            $status = self::worse($stPct ?? self::ST_OK, $stFlag ?? self::ST_OK);
            if ($stPct === null) {
                $status = $stFlag;
            } elseif ($stFlag === null) {
                $status = $stPct;
            }
        } elseif (!empty($sig['voltage'])) {
            if (!in_array('zelltyp_passt_nicht', $quality, true)) {
                $reasons[] = 'nur Spannung vorhanden (' . self::num((float)$sig['voltage']['value']) . ' V): Zelltyp wählen, dann rechnet der Wächter den Ladezustand aus' . ($suggest !== '' ? ' (' . $suggest . ')' : '');
                $quality[] = 'nur_spannung';
            }
        } else {
            $reasons[] = 'kein auswertbares Batteriesignal';
        }

        // --- Alter des Batteriewerts ----------------------------------------
        $newest = max($pctUpdated, $flagUpdated, (int)($sig['voltage']['updated'] ?? 0));
        $valueAge = $newest > 0 ? max(0, $now - $newest) : null;
        $oldDays = (int)($p['valueOldDays'] ?? 90);
        if ($valueAge !== null && empty($p['ignoreAge']) && $status !== self::ST_UNKNOWN && $valueAge > $oldDays * 86400) {
            $quality[] = 'veraltet';
            $reasons[] = 'Batteriewert ist ' . self::days($valueAge) . ' alt';
        }

        // --- Funkstille -------------------------------------------------------
        $group     = (string)($p['group'] ?? self::GROUP_STANDARD);
        $stillDays = (int)($group === self::GROUP_EVENT ? ($p['stillDaysEvent'] ?? 30) : ($p['stillDays'] ?? 7));
        $stillSec  = isset($p['stillSec']) && (int)$p['stillSec'] > 0 ? (int)$p['stillSec'] : $stillDays * 86400;   // gelernte Schwelle, wenn vorhanden
        $lifeAge   = $life > 0 ? max(0, $now - $life) : null;
        $orphan    = false;
        if ($lifeAge === null) {
            $funk = 'unbekannt';
        } elseif ($lifeAge > $stillSec) {
            $funk = 'still';
            $reasons[] = 'Funkstille: seit ' . self::daysDat($lifeAge) . ' kein Lebenszeichen (Schwelle ' . self::days($stillSec) . (isset($p['stillSec']) && (int)$p['stillSec'] > 0 ? ', aus dem Meldeverhalten gelernt' : '') . ')';
            $orphanDays = (int)($p['orphanDays'] ?? 60);
            if ($orphanDays > 0 && $lifeAge > $orphanDays * 86400) {
                $orphan = true;
                $reasons[] = 'Seit über ' . $orphanDays . ' Tagen still: vermutlich ausgebaut oder defekt. Falls ausgebaut, „Außer Betrieb“ wählen';
            }
        } else {
            $funk = 'aktiv';
        }

        $pctText = $pct !== null ? ' (' . ($derived ? '≈' : '') . self::num($pct) . ' %' . ($derived ? ', aus Spannung berechnet' : '') . ')' : '';
        if ($status === self::ST_EMPTY) {
            array_unshift($reasons, 'Batterie leer' . $pctText);
        } elseif ($status === self::ST_LOW) {
            array_unshift($reasons, 'Batterie schwach' . $pctText);
        }

        // --- Dringlichkeit für die Sortierung ---------------------------------
        $score = 0;
        if ($status === self::ST_EMPTY) { $score = max($score, 1000); }
        if ($status === self::ST_LOW) { $score = max($score, 700); }
        if ($funk === 'still') { $score = max($score, 600); }
        if (in_array('widerspruch', $quality, true)) { $score = max($score, 500); }
        if (in_array('unplausibel', $quality, true) || in_array('zelltyp_passt_nicht', $quality, true)) { $score = max($score, 400); }
        if (in_array('veraltet', $quality, true)) { $score = max($score, 300); }
        if ($status === self::ST_UNKNOWN) { $score = max($score, 200); }
        if ($critical && $score > 0) { $score += 100; }

        return [
            'status'   => $status,
            'percent'  => $pct,
            'flagLow'  => $flagLow,
            'voltage'  => isset($sig['voltage']) ? (float)$sig['voltage']['value'] : null,
            'quality'  => array_values(array_unique($quality)),
            'funk'     => $funk,
            'valueAge' => $valueAge,
            'lifeAge'  => $lifeAge,
            'critical' => $critical,
            'derived'  => $derived,
            'orphan'   => $orphan,
            'cell'     => $cell,
            'urgency'  => $score,
            'reasons'  => $reasons,
        ];
    }

    /** Zählt die Ergebnisse für die Kennzahlen-Variablen. */
    public static function summarize(array $results): array
    {
        $s = ['total' => 0, 'empty' => 0, 'low' => 0, 'silent' => 0, 'unknown' => 0, 'check' => 0, 'ok' => 0];
        foreach ($results as $r) {
            $s['total']++;
            if ($r['status'] === self::ST_EMPTY) { $s['empty']++; }
            if ($r['status'] === self::ST_LOW) { $s['low']++; }
            if ($r['funk'] === 'still') { $s['silent']++; }
            if ($r['status'] === self::ST_UNKNOWN) { $s['unknown']++; }
            if (array_intersect(['widerspruch', 'unplausibel', 'veraltet'], $r['quality'])) { $s['check']++; }
            if ($r['urgency'] === 0) { $s['ok']++; }
        }
        return $s;
    }

    // =====================================================================
    //  Hilfen
    // =====================================================================

    private static function worse(string $a, string $b): string
    {
        $rank = [self::ST_OK => 0, self::ST_UNKNOWN => 0, self::ST_LOW => 1, self::ST_EMPTY => 2];
        return ($rank[$a] ?? 0) >= ($rank[$b] ?? 0) ? $a : $b;
    }

    /** Zahl mit deutschem Komma, ohne überflüssige Nullen. */
    public static function num(float $v): string
    {
        $s = rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
        return $s === '' || $s === '-' ? '0' : $s;
    }

    /** Wie days(), aber nach „vor“/„seit“ (Dativ): „16 Tagen“, sonst unverändert. */
    public static function daysDat(int $seconds): string
    {
        $s = self::days($seconds);
        return preg_replace('/ Tage$/', ' Tagen', $s);
    }

    /** „3 Tage“, „5 Stunden“, „40 Minuten“ — ganze Einheiten, deutsch. */
    public static function days(int $seconds): string
    {
        if ($seconds >= 86400) {
            $d = (int)floor($seconds / 86400);
            return $d . ($d === 1 ? ' Tag' : ' Tage');
        }
        if ($seconds >= 3600) {
            $h = (int)floor($seconds / 3600);
            return $h . ($h === 1 ? ' Stunde' : ' Stunden');
        }
        $m = (int)floor($seconds / 60);
        return $m . ($m === 1 ? ' Minute' : ' Minuten');
    }
}
