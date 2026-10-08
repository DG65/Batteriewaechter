<?php

// ===========================================================================
// BWACHPrognose — Verlauf, Restlaufzeit, Einkaufsliste, Tauschrunde und
// Statistik des Batteriewächters (DG65 Toolkit). Reine Rechenlogik ohne IPS.
//
// Grundsatz: lieber ehrlich „unbekannt“ als eine Zahl, die nur so aussieht, als
// wüsste man etwas. Ein Verlauf braucht Messpunkte über Wochen; viele Geräte
// melden aber nur grobe Stufen (100 % bis kurz vor leer) oder selten. Dann gibt
// es keine Prognose, und die Anzeige sagt warum.
//
// Verlauf: je Gerät eine Reihe [Zeitstempel, Prozent, Außentemperatur|null],
// im Modul selbst geführt (kein Symcon-Archiv nötig). Ein Batteriewechsel (der
// Wert springt hoch) beendet den alten Abschnitt: nur der Abschnitt seit dem
// letzten Wechsel zählt für die Prognose.
// ===========================================================================

final class BWACHPrognose
{
    public const HISTORY_CAP        = 90;          // Punkte je Gerät
    public const HISTORY_MIN_GAP    = 3 * 86400;   // auch ohne Änderung alle 3 Tage ein Punkt
    public const HISTORY_MIN_DELTA  = 0.5;         // Prozentpunkte, ab denen sofort ein Punkt entsteht

    public const FORECAST_MIN_POINTS = 4;
    public const FORECAST_MIN_SPAN   = 14 * 86400;
    public const FORECAST_MIN_DISTINCT = 3;
    public const NO_DISCHARGE_SLOPE  = -0.005;     // Prozentpunkte pro Tag: flacher gilt als „keine Entladung erkennbar“
    public const MAX_DAYS            = 3650;

    // =====================================================================
    //  Verlauf
    // =====================================================================

    /**
     * Fügt einen Messpunkt hinzu, wenn er etwas Neues sagt (Wert geändert oder lange nichts aufgezeichnet).
     *
     * @param array $series Liste von [t, pct, temp]
     */
    public static function historyAdd(array $series, int $t, ?float $pct, ?float $temp = null): array
    {
        if ($pct === null) {
            return $series;
        }
        $pct = round($pct, 1);
        if ($series) {
            $last = $series[count($series) - 1];
            if ($t <= $last[0]) {
                return $series;
            }
            if (abs($pct - $last[1]) < self::HISTORY_MIN_DELTA && $t - $last[0] < self::HISTORY_MIN_GAP) {
                return $series;
            }
        }
        $series[] = [$t, $pct, $temp === null ? null : round($temp, 1)];
        if (count($series) > self::HISTORY_CAP) {
            $series = array_slice($series, -self::HISTORY_CAP);
        }
        return $series;
    }

    /** Nur der Abschnitt seit dem letzten Batteriewechsel (Sprung nach oben um mindestens $jump Punkte). */
    public static function segmentSinceReplacement(array $series, int $jump): array
    {
        $start = 0;
        for ($i = 1; $i < count($series); $i++) {
            if ($series[$i][1] - $series[$i - 1][1] >= $jump) {
                $start = $i;
            }
        }
        return array_slice($series, $start);
    }

    // =====================================================================
    //  Restlaufzeit
    // =====================================================================

    /**
     * @param array $series Verlauf (siehe historyAdd)
     * @return array ['days'=>float|null, 'confidence'=>'hoch'|'mittel'|'niedrig'|'unbekannt'|'keine',
     *                'slope'=>float|null (Prozentpunkte/Tag), 'emptyAt'=>int|null, 'points'=>int, 'spanDays'=>int, 'why'=>string]
     */
    public static function forecast(array $series, float $emptyPct, int $jump = 25): array
    {
        $seg    = self::segmentSinceReplacement($series, $jump);
        $n      = count($seg);
        $span   = $n >= 2 ? $seg[$n - 1][0] - $seg[0][0] : 0;
        $spanD  = (int)floor($span / 86400);
        $base   = ['days' => null, 'confidence' => 'unbekannt', 'slope' => null, 'emptyAt' => null, 'points' => $n, 'spanDays' => $spanD, 'why' => ''];

        if ($n < self::FORECAST_MIN_POINTS || $span < self::FORECAST_MIN_SPAN) {
            $base['why'] = 'zu wenig Verlauf (' . $n . ' Messpunkte über ' . $spanD . ' Tage, nötig sind mindestens ' . self::FORECAST_MIN_POINTS . ' über 14 Tage)';
            return $base;
        }
        $distinct = count(array_unique(array_map(function ($p) { return (string)$p[1]; }, $seg)));
        if ($distinct < self::FORECAST_MIN_DISTINCT) {
            $base['why'] = 'der Wert ändert sich zu selten (nur ' . $distinct . ' verschiedene Werte, das Gerät zeigt offenbar nur grobe Stufen)';
            return $base;
        }

        // Theil-Sen: Median aller Paar-Steigungen, unempfindlich gegen Ausreißer
        $slopes = [];
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $dt = $seg[$j][0] - $seg[$i][0];
                if ($dt >= 86400) {
                    $slopes[] = ($seg[$j][1] - $seg[$i][1]) / ($dt / 86400);
                }
            }
        }
        if (!$slopes) {
            $base['why'] = 'Messpunkte liegen zu dicht beieinander';
            return $base;
        }
        $slope = self::median($slopes);
        $base['slope'] = round($slope, 4);
        $last = $seg[$n - 1];

        if ($slope >= self::NO_DISCHARGE_SLOPE) {
            $base['confidence'] = 'keine';
            $base['why'] = 'keine Entladung erkennbar';
            return $base;
        }

        // Güte: Bestimmtheitsmaß der Geraden mit Median-Achsenabschnitt
        $t0 = $seg[0][0];
        $res = [];
        foreach ($seg as $p) { $res[] = $p[1] - $slope * (($p[0] - $t0) / 86400); }
        $b = self::median($res);
        $mean = array_sum(array_column($seg, 1)) / $n;
        $ssTot = 0.0; $ssRes = 0.0;
        foreach ($seg as $p) {
            $fit = $b + $slope * (($p[0] - $t0) / 86400);
            $ssRes += ($p[1] - $fit) ** 2;
            $ssTot += ($p[1] - $mean) ** 2;
        }
        $r2 = $ssTot > 0 ? max(0.0, 1 - $ssRes / $ssTot) : 0.0;

        $remaining = max(0.0, $last[1] - $emptyPct);
        $days = $remaining / (-$slope);
        $conf = 'niedrig';
        if ($r2 >= 0.85 && $span >= 60 * 86400 && $n >= 8) {
            $conf = 'hoch';
        } elseif ($r2 >= 0.6 && $span >= 30 * 86400) {
            $conf = 'mittel';
        }
        $base['days'] = round(min($days, self::MAX_DAYS), 1);
        $base['confidence'] = $conf;
        $base['emptyAt'] = (int)($last[0] + $days * 86400);
        $base['why'] = $days > self::MAX_DAYS ? 'mehr als 10 Jahre' : '';
        $base['r2'] = round($r2, 2);
        return $base;
    }

    /** „reicht noch etwa 23 Tage (mittlere Sicherheit)“ bzw. der ehrliche Grund. */
    public static function forecastText(array $f): string
    {
        if ($f['confidence'] === 'keine') {
            return 'keine Entladung erkennbar';
        }
        if ($f['days'] === null) {
            return 'Restlaufzeit unbekannt: ' . $f['why'];
        }
        $sec = ['hoch' => 'hohe', 'mittel' => 'mittlere', 'niedrig' => 'geringe'][$f['confidence']];
        $d = (int)round($f['days']);
        if ($f['days'] >= 365) {
            $y = round($f['days'] / 365, 1);
            return 'reicht noch etwa ' . BWACHLogik::num($y) . ' Jahre (' . $sec . ' Sicherheit)';
        }
        return 'reicht noch etwa ' . $d . ($d === 1 ? ' Tag' : ' Tage') . ' (' . $sec . ' Sicherheit)';
    }

    // =====================================================================
    //  Einkaufsliste und Tauschrunde
    // =====================================================================

    /**
     * Was jetzt oder in absehbarer Zeit getauscht werden muss.
     *
     * @param array $items Liste von ['name','place','cell','cells','status','days'(float|null)]
     * @return array ['need'=>[Gerät…], 'lines'=>['4× CR2032', …], 'missing'=>[Namen ohne Zelltyp]]
     */
    public static function due(array $items, int $horizonDays): array
    {
        $need = [];
        foreach ($items as $i) {
            $soon = $i['days'] !== null && $i['days'] <= $horizonDays;
            if (in_array($i['status'], ['leer', 'schwach'], true) || $soon) {
                $need[] = $i;
            }
        }
        return $need;
    }

    public static function shopping(array $items, int $horizonDays): array
    {
        $need  = self::due($items, $horizonDays);
        $count = [];
        $missing = [];
        foreach ($need as $i) {
            $label = BWACHZelle::shopLabel((string)$i['cell']);
            if ($label === null) {
                $missing[] = $i['name'];
                continue;
            }
            $count[$label] = ($count[$label] ?? 0) + max(1, (int)$i['cells']);
        }
        arsort($count);
        $lines = [];
        foreach ($count as $label => $n) {
            $lines[] = $n . '× ' . $label;
        }
        return ['need' => $need, 'lines' => $lines, 'missing' => $missing];
    }

    /**
     * Tauschrunde: alle Geräte, die jetzt oder bald dran sind, nach Ort gebündelt, mit einem Vorschlag, bis wann.
     *
     * @return array ['places'=>[Ort=>[Gerät…]], 'until'=>int|null (Zeitstempel), 'count'=>int]
     */
    public static function tauschrunde(array $items, int $horizonDays, int $now, int $marginDays = 3): array
    {
        $need   = self::due($items, $horizonDays);
        $places = [];
        $until  = null;
        foreach ($need as $i) {
            $places[$i['place'] !== '' ? $i['place'] : 'ohne Ort'][] = $i;
            $t = in_array($i['status'], ['leer', 'schwach'], true) ? $now : $now + (int)($i['days'] * 86400) - $marginDays * 86400;
            $t = max($now, $t);
            $until = $until === null ? $t : min($until, $t);
        }
        ksort($places);
        return ['places' => $places, 'until' => $until, 'count' => count($need)];
    }

    // =====================================================================
    //  Lebensdauer
    // =====================================================================

    /**
     * Zeitspannen zwischen aufeinanderfolgenden Batteriewechseln je Gerät, in Tagen.
     *
     * @param array $diary Einträge ['t','key','name',…]
     * @return array key => ['name'=>string,'days'=>float[]]
     */
    public static function lifetimes(array $diary): array
    {
        $by = [];
        foreach ($diary as $e) {
            $by[$e['key']]['name'] = $e['name'];
            $by[$e['key']]['t'][]  = (int)$e['t'];
        }
        $out = [];
        foreach ($by as $key => $d) {
            sort($d['t']);
            $days = [];
            for ($i = 1; $i < count($d['t']); $i++) {
                $days[] = round(($d['t'][$i] - $d['t'][$i - 1]) / 86400, 1);
            }
            if ($days) {
                $out[$key] = ['name' => $d['name'], 'days' => $days];
            }
        }
        return $out;
    }

    /**
     * Mittlere Lebensdauer je Gruppe (Zelltyp oder Modul).
     *
     * @param array $life  Ergebnis von lifetimes()
     * @param array $group key => Gruppenname
     * @return array Gruppe => ['median'=>float,'n'=>int,'devices'=>int]
     */
    public static function lifetimeByGroup(array $life, array $group): array
    {
        $acc = [];
        foreach ($life as $key => $l) {
            $g = $group[$key] ?? '';
            if ($g === '') {
                continue;
            }
            foreach ($l['days'] as $d) {
                $acc[$g]['d'][] = $d;
            }
            $acc[$g]['k'][$key] = true;
        }
        $out = [];
        foreach ($acc as $g => $a) {
            $out[$g] = ['median' => round(self::median($a['d']), 0), 'n' => count($a['d']), 'devices' => count($a['k'])];
        }
        ksort($out);
        return $out;
    }

    // =====================================================================
    //  Gelernte Meldeintervalle
    // =====================================================================

    public const GAPS_CAP = 20;
    public const GAPS_MIN = 8;

    /**
     * Merkt sich, wie oft ein Gerät ein neues Lebenszeichen zeigt: bei jeder neuen Beobachtung wird der Abstand
     * zum vorigen Lebenszeichen aufgezeichnet.
     *
     * @param array $obs ['last'=>int,'gaps'=>int[]]
     */
    public static function observeLife(array $obs, int $life): array
    {
        $obs += ['last' => 0, 'gaps' => []];
        if ($life <= 0) {
            return $obs;
        }
        if ($obs['last'] > 0 && $life > $obs['last']) {
            $obs['gaps'][] = $life - $obs['last'];
            $obs['gaps'] = array_slice($obs['gaps'], -self::GAPS_CAP);
        }
        if ($life > $obs['last']) {
            $obs['last'] = $life;
        }
        return $obs;
    }

    /**
     * Funkstille-Schwelle aus dem gelernten Rhythmus: 3× das 90. Perzentil der Abstände, aber nie weniger als
     * 6 Stunden und nie mehr als die eingestellte Schwelle (die gelernte Schwelle macht nur empfindlicher).
     *
     * @return int Sekunden; $default, solange zu wenig Beobachtungen vorliegen
     */
    public static function learnedThreshold(array $obs, int $default): int
    {
        $gaps = $obs['gaps'] ?? [];
        if (count($gaps) < self::GAPS_MIN) {
            return $default;
        }
        sort($gaps);
        $p90 = $gaps[(int)floor((count($gaps) - 1) * 0.9)];
        return (int)max(6 * 3600, min($default, 3 * $p90));
    }

    // =====================================================================
    //  Hilfen
    // =====================================================================

    public static function median(array $v): float
    {
        sort($v);
        $n = count($v);
        if ($n === 0) {
            return 0.0;
        }
        return $n % 2 ? (float)$v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }
}
