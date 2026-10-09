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
        return ['need' => $need, 'lines' => $lines, 'missing' => $missing, 'counts' => $count];
    }

    /**
     * Suchlink für eine Einkaufsbezeichnung aus der Vorlage des Nutzers. Die Vorlage muss mit http:// oder https://
     * beginnen und {Zelltyp} enthalten; sonst gibt es keinen Link (null). Die Bezeichnung wird URL-kodiert eingesetzt.
     */
    public static function shopUrl(string $template, string $label): ?string
    {
        $template = trim($template);
        if ($template === '' || mb_strlen($template) > 300 || !preg_match('#^https?://#i', $template) || strpos($template, '{Zelltyp}') === false || preg_match('/[\s<>"\']/', $template)) {
            return null;
        }
        return str_replace('{Zelltyp}', rawurlencode($label), $template);
    }

    /**
     * Einkaufsliste und Tauschrunde als Text zum Kopieren oder Senden.
     *
     * @param array $shopping Ergebnis von shopping()
     * @param array $round    Ergebnis von tauschrunde()
     */
    public static function shoppingText(array $shopping, array $round, int $horizonDays, string $until): string
    {
        $out = ['🛒 Batterien einkaufen (nächste ' . $horizonDays . ' Tage)'];
        if (!$shopping['lines']) {
            $out[] = $shopping['need'] ? 'Keine Zelltypen bekannt.' : 'Nichts zu besorgen.';
        }
        foreach ($shopping['lines'] as $l) {
            $out[] = '• ' . $l;
        }
        if ($shopping['missing']) {
            $out[] = 'Zelltyp fehlt bei: ' . implode(', ', $shopping['missing']);
        }
        if ($round['count'] > 0) {
            $out[] = '';
            $out[] = '🔧 Tauschrunde (' . $round['count'] . ($round['count'] === 1 ? ' Gerät' : ' Geräte') . ')' . ($until !== '' ? ', am besten bis ' . $until : '');
            foreach ($round['places'] as $place => $devs) {
                $items = [];
                foreach ($devs as $d) {
                    $cell = BWACHZelle::shopLabel((string)$d['cell']);
                    $items[] = $d['name'] . ($cell !== null ? ' (' . max(1, (int)$d['cells']) . '× ' . $cell . ')' : '');
                }
                $out[] = $place . ': ' . implode(', ', $items);
            }
        }
        return implode("\n", $out);
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
    //  Vergleich mit Gleichartigen
    // =====================================================================

    /**
     * Findet Geräte, die deutlich schneller entladen als vergleichbare (gleiches System, gleicher Zelltyp).
     * Eine Gruppe braucht mindestens $minGroup Geräte mit bekannter Entladerate, sonst wäre der Median Zufall.
     *
     * @param array $rates key => ['rate'=>float (Prozentpunkte/Tag, positiv = Entladung), 'group'=>string]
     * @return array key => ['rate','median','factor','n','group']
     */
    public static function peerOutliers(array $rates, int $minGroup = 4, float $factor = 2.0, float $minRate = 0.05): array
    {
        $groups = [];
        foreach ($rates as $key => $r) {
            if ($r['rate'] > 0) {
                $groups[$r['group']][$key] = $r['rate'];
            }
        }
        $out = [];
        foreach ($groups as $g => $members) {
            if (count($members) < $minGroup) {
                continue;
            }
            $med = self::median(array_values($members));
            if ($med <= 0) {
                continue;
            }
            foreach ($members as $key => $rate) {
                if ($rate >= $factor * $med && $rate >= $minRate) {
                    $out[$key] = ['rate' => round($rate, 3), 'median' => round($med, 3), 'factor' => round($rate / $med, 1), 'n' => count($members), 'group' => $g];
                }
            }
        }
        return $out;
    }

    // =====================================================================
    //  Kälteeinfluss
    // =====================================================================

    public const COLD_MIN_INTERVALS = 3;   // je Gerät und Seite (kalt/warm)
    public const COLD_MIN_DEVICES   = 3;

    /**
     * Entladen sich die Batterien bei Kälte schneller? Aus den Verläufen mit Außentemperatur: je Zeitabschnitt
     * zwischen zwei Messpunkten (mindestens 2 Tage) die Entladerate, nach mittlerer Außentemperatur in kalt und
     * warm getrennt. Ein Gerät zählt nur, wenn beide Seiten genug Abschnitte haben; das Ergebnis nur, wenn
     * genug Geräte beitragen. Sonst heißt es ehrlich „noch nicht genug Daten“.
     *
     * @param array $histories key => Verlauf [[t,pct,temp],…]
     * @return array ['ok'=>bool,'factor'=>float|null,'devices'=>int,'cold'=>int,'warm'=>int,'below'=>float,'text'=>string]
     */
    public static function coldEffect(array $histories, float $below = 5.0, int $jump = 25): array
    {
        $ratios = [];
        $nCold = 0;
        $nWarm = 0;
        foreach ($histories as $series) {
            $cold = [];
            $warm = [];
            for ($i = 1; $i < count($series); $i++) {
                [$t0, $p0, $c0] = $series[$i - 1];
                [$t1, $p1, $c1] = $series[$i];
                if ($c0 === null || $c1 === null || $t1 - $t0 < 2 * 86400 || $p1 - $p0 >= $jump) {
                    continue;
                }
                $rate = max(0.0, ($p0 - $p1) / (($t1 - $t0) / 86400));
                if (($c0 + $c1) / 2 < $below) {
                    $cold[] = $rate;
                } else {
                    $warm[] = $rate;
                }
            }
            if (count($cold) >= self::COLD_MIN_INTERVALS && count($warm) >= self::COLD_MIN_INTERVALS) {
                $mw = self::median($warm);
                if ($mw > 0) {
                    $ratios[] = self::median($cold) / $mw;
                    $nCold += count($cold);
                    $nWarm += count($warm);
                }
            }
        }
        $base = ['ok' => false, 'factor' => null, 'devices' => count($ratios), 'cold' => $nCold, 'warm' => $nWarm, 'below' => $below, 'text' => ''];
        if (count($ratios) < self::COLD_MIN_DEVICES) {
            $base['text'] = 'Kälteeinfluss: noch nicht genug Daten (nötig: mindestens ' . self::COLD_MIN_DEVICES . ' Geräte mit je ' . self::COLD_MIN_INTERVALS . ' Zeitabschnitten bei unter und über ' . BWACHLogik::num($below) . ' °C; bisher ' . count($ratios) . ')';
            return $base;
        }
        $f = round(self::median($ratios), 1);
        $base['ok'] = true;
        $base['factor'] = $f;
        $base['text'] = $f >= 1.2
            ? 'Bei unter ' . BWACHLogik::num($below) . ' °C entladen sich die Batterien im Median ' . BWACHLogik::num($f) . '× schneller (aus ' . count($ratios) . ' Geräten, ' . $nCold . ' kalten und ' . $nWarm . ' warmen Zeitabschnitten)'
            : 'Kein deutlicher Kälteeinfluss erkennbar (Faktor ' . BWACHLogik::num($f) . ', aus ' . count($ratios) . ' Geräten)';
        return $base;
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
