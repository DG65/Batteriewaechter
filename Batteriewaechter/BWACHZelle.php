<?php

// ===========================================================================
// BWACHZelle — Zelltypen und Entladekurven des Batteriewächters (DG65-Toolkit).
//
// Rechnet eine gemessene Spannung in einen Ladezustand um. Das ist eine
// NÄHERUNG: Die Kurven sind typische, vereinfachte Entladekurven je Zellchemie
// (stückweise linear), keine Datenblattwerte eines bestimmten Herstellers. Sie
// taugen, um „frisch / halb / bald leer“ zu unterscheiden, nicht für Prozentgenauigkeit.
// Deshalb wird ein so berechneter Wert in der Anzeige immer als „aus Spannung
// berechnet“ gekennzeichnet.
//
// Der Zelltyp lässt sich aus der Spannung nicht sicher ableiten (3 V können eine
// CR2032 oder zwei Alkali-Zellen sein), er wird deshalb vom Nutzer je Gerät
// gewählt. suggest() nennt nur Kandidaten, es wählt nie selbst.
// ===========================================================================

final class BWACHZelle
{
    public const UNKNOWN = 'unbekannt';

    /**
     * Typ => Anzeigename, Einkaufsbezeichnung, Kurve [[Volt je Zelle, Prozent], …] absteigend.
     * 'shop' = was man kaufen geht; 'curve' = null, wenn für diese Bauart keine Umrechnung sinnvoll ist.
     */
    private const TYPES = [
        'cr2032' => ['label' => 'CR2032 (Knopfzelle, 3 V)', 'shop' => 'CR2032',
            'curve' => [[3.00, 100], [2.90, 90], [2.80, 70], [2.70, 40], [2.50, 15], [2.20, 5], [2.00, 0]]],
        'cr2450' => ['label' => 'CR2450 (Knopfzelle, 3 V)', 'shop' => 'CR2450',
            'curve' => [[3.00, 100], [2.90, 90], [2.80, 70], [2.70, 40], [2.50, 15], [2.20, 5], [2.00, 0]]],
        'cr2025' => ['label' => 'CR2025 (Knopfzelle, 3 V)', 'shop' => 'CR2025', 'curve' => [[3.00, 100], [2.90, 90], [2.80, 70], [2.70, 40], [2.50, 15], [2.20, 5], [2.00, 0]]],
        'cr2016' => ['label' => 'CR2016 (Knopfzelle, 3 V)', 'shop' => 'CR2016', 'curve' => [[3.00, 100], [2.90, 90], [2.80, 70], [2.70, 40], [2.50, 15], [2.20, 5], [2.00, 0]]],
        'cr1632' => ['label' => 'CR1632 (Knopfzelle, 3 V)', 'shop' => 'CR1632', 'curve' => [[3.00, 100], [2.90, 90], [2.80, 70], [2.70, 40], [2.50, 15], [2.20, 5], [2.00, 0]]],
        'cr1220' => ['label' => 'CR1220 (Knopfzelle, 3 V)', 'shop' => 'CR1220', 'curve' => [[3.00, 100], [2.90, 90], [2.80, 70], [2.70, 40], [2.50, 15], [2.20, 5], [2.00, 0]]],
        'cr2477' => ['label' => 'CR2477 (Knopfzelle, 3 V)', 'shop' => 'CR2477', 'curve' => [[3.00, 100], [2.90, 90], [2.80, 70], [2.70, 40], [2.50, 15], [2.20, 5], [2.00, 0]]],
        'cr123a' => ['label' => 'CR123A Batterie (Lithium, 3 V, nicht wiederaufladbar)', 'shop' => 'CR123A',
            'curve' => [[3.00, 100], [2.90, 90], [2.80, 70], [2.70, 40], [2.50, 15], [2.20, 5], [2.00, 0]]],
        'rcr123a' => ['label' => 'RCR123A / 16340 Akku (Li-Ion, 3,7 V)', 'shop' => 'RCR123A (Akku)',
            'curve' => [[4.20, 100], [4.10, 90], [3.95, 70], [3.80, 50], [3.70, 35], [3.60, 20], [3.50, 10], [3.30, 3], [3.00, 0]]],
        'aa_alkali' => ['label' => 'AA Alkali (1,5 V)', 'shop' => 'AA',
            'curve' => [[1.60, 100], [1.50, 85], [1.40, 65], [1.30, 40], [1.20, 20], [1.10, 8], [1.00, 0]]],
        'aaa_alkali' => ['label' => 'AAA Alkali (1,5 V)', 'shop' => 'AAA',
            'curve' => [[1.60, 100], [1.50, 85], [1.40, 65], [1.30, 40], [1.20, 20], [1.10, 8], [1.00, 0]]],
        'lr44' => ['label' => 'LR44 / AG13 (Alkali-Knopfzelle, 1,5 V)', 'shop' => 'LR44',
            'curve' => [[1.60, 100], [1.50, 85], [1.40, 65], [1.30, 40], [1.20, 20], [1.10, 8], [1.00, 0]]],
        // Silberoxid hält die Spannung lange flach und fällt zum Ende steil: aus der Spannung nur eine grobe Näherung
        'sr44' => ['label' => 'SR44 (Silberoxid-Knopfzelle, 1,55 V)', 'shop' => 'SR44',
            'curve' => [[1.62, 100], [1.55, 90], [1.50, 60], [1.40, 20], [1.30, 5], [1.20, 0]]],
        'cr2' => ['label' => 'CR2 (Lithium, 3 V)', 'shop' => 'CR2',
            'curve' => [[3.00, 100], [2.90, 90], [2.80, 70], [2.70, 40], [2.50, 15], [2.20, 5], [2.00, 0]]],
        'a23' => ['label' => 'A23 / 23A (12-V-Batterie)', 'shop' => 'A23',
            'curve' => [[12.50, 100], [11.50, 75], [10.50, 40], [9.50, 10], [8.00, 0]]],
        'aa_nimh' => ['label' => 'AA Akku NiMH (1,2 V)', 'shop' => 'AA (Akku)',
            'curve' => [[1.40, 100], [1.30, 90], [1.25, 60], [1.20, 40], [1.15, 15], [1.10, 5], [1.00, 0]]],
        'aaa_nimh' => ['label' => 'AAA Akku NiMH (1,2 V)', 'shop' => 'AAA (Akku)',
            'curve' => [[1.40, 100], [1.30, 90], [1.25, 60], [1.20, 40], [1.15, 15], [1.10, 5], [1.00, 0]]],
        'aa_lithium' => ['label' => 'AA Lithium (1,5 V)', 'shop' => 'AA (Lithium)',
            'curve' => [[1.80, 100], [1.70, 90], [1.60, 60], [1.50, 30], [1.40, 10], [1.20, 0]]],
        'block9v' => ['label' => '9-V-Block', 'shop' => '9-V-Block',
            'curve' => [[9.60, 100], [8.40, 60], [7.50, 30], [6.60, 10], [5.40, 0]]],
        // Fest eingebauter LTO-Akku (Lithium-Titanat, 2,4 V), z. B. in einer Wetterstation mit Solarzelle. Die Kurve folgt den
        // dokumentierten Schwellen eines solchen Geräts (Voll ca. 2,8 V, Sparmodus ab 2,455 V, „niedrig“ bei 2,355 V), nicht
        // der allgemeinen Entladekurve einer LTO-Zelle (die bis etwa 1,5 V reicht): sie soll rechtzeitig warnen.
        'lto' => ['label' => 'LTO-Akku (Lithium-Titanat, fest eingebaut, 2,4 V)', 'shop' => 'LTO-Akku (fest eingebaut)',
            'curve' => [[2.80, 100], [2.70, 85], [2.60, 65], [2.455, 40], [2.40, 20], [2.355, 8], [2.30, 0]]],
        'liion' => ['label' => 'Li-Ion/LiPo-Akku (fest eingebaut, 3,7 V)', 'shop' => 'Akku (Li-Ion/LiPo)',
            'curve' => [[4.20, 100], [4.10, 90], [3.95, 70], [3.80, 50], [3.70, 35], [3.60, 20], [3.50, 10], [3.30, 3], [3.00, 0]]],
    ];

    /**
     * Zelltyp aus der Ersatz-Beschreibung, die ein Gerät selbst meldet (Matter: „AAA“, „CR2032“).
     * AA/AAA nennen nur die Bauform: es wird Alkali angenommen (für Einkauf und Anzeige gleich, die
     * Entladekurve wird bei diesen Geräten nicht gebraucht, weil sie einen Prozentwert melden).
     */
    public static function fromDescription(string $text): ?string
    {
        $t = strtoupper((string)preg_replace('/[\s\-_.]+/', '', $text));
        $map = ['CR2032' => 'cr2032', 'CR2450' => 'cr2450', 'CR123A' => 'cr123a', 'RCR123A' => 'rcr123a', '16340' => 'rcr123a',
            'AA' => 'aa_alkali', 'AAA' => 'aaa_alkali', '9V' => 'block9v', '6LR61' => 'block9v',
            'CR2025' => 'cr2025', 'CR2016' => 'cr2016', 'CR1632' => 'cr1632', 'CR1220' => 'cr1220', 'CR2477' => 'cr2477',
            'LR44' => 'lr44', 'AG13' => 'lr44', 'SR44' => 'sr44', 'CR2' => 'cr2', 'A23' => 'a23', '23A' => 'a23'];
        return $map[$t] ?? null;
    }

    /** Auswahlliste fürs Formular. */
    public static function options(): array
    {
        $o = [['caption' => 'unbekannt', 'value' => self::UNKNOWN]];
        foreach (self::TYPES as $id => $t) {
            $o[] = ['caption' => $t['label'], 'value' => $id];
        }
        return $o;
    }

    public static function isKnown(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function label(string $type): string
    {
        return self::TYPES[$type]['label'] ?? 'unbekannt';
    }

    /** Einkaufsbezeichnung („CR2032“, „AAA“), null bei unbekanntem Typ. */
    public static function shopLabel(string $type): ?string
    {
        return self::TYPES[$type]['shop'] ?? null;
    }

    /**
     * Ladezustand aus der Gesamtspannung.
     *
     * @return float|null Prozent 0–100; null, wenn der Zelltyp unbekannt ist oder die Spannung nicht zum
     *                    Typ passt (z. B. 4,7 V an einer CR2032)
     */
    public static function percentFromVoltage(string $type, int $cells, float $volt): ?float
    {
        if (!isset(self::TYPES[$type]) || $cells < 1) {
            return null;
        }
        $curve = self::TYPES[$type]['curve'];
        $v     = $volt / $cells;
        $top   = $curve[0][0];
        $low   = $curve[count($curve) - 1][0];
        if ($v > $top * 1.12 || $v < $low * 0.6) {
            return null;   // passt nicht zum Zelltyp (oder Messfehler)
        }
        if ($v >= $top) {
            return 100.0;
        }
        if ($v <= $low) {
            return 0.0;
        }
        for ($i = 0; $i < count($curve) - 1; $i++) {
            [$v1, $p1] = $curve[$i];
            [$v2, $p2] = $curve[$i + 1];
            if ($v <= $v1 && $v >= $v2) {
                return round($p2 + ($p1 - $p2) * ($v - $v2) / ($v1 - $v2), 1);
            }
        }
        return null;
    }

    /** Nennt Kandidaten für die Zelle hinter einer Spannung; wählt nie selbst. */
    public static function suggest(float $volt): string
    {
        $cand = [];
        foreach (self::TYPES as $id => $t) {
            foreach ([1, 2, 3, 4] as $n) {
                // Knopfzellen, 9-V-Block und fest eingebaute Akkus kommen einzeln vor; CR123A/RCR123A auch zu zweit oder zu dritt
                $maxCells = in_array($id, ['cr123a', 'rcr123a', 'cr2'], true) ? 3 : (in_array($id, ['cr2032', 'cr2450', 'cr2025', 'cr2016', 'cr1632', 'cr1220', 'cr2477', 'lr44', 'sr44', 'a23', 'block9v', 'liion', 'lto'], true) ? 1 : 4);
                if ($n <= $maxCells && self::percentFromVoltage($id, $n, $volt) !== null) {
                    $cand[] = ($n > 1 ? $n . '× ' : '') . $t['shop'];
                }
            }
        }
        return $cand ? 'passt zu: ' . implode(', ', array_unique($cand)) : 'passt zu keinem bekannten Zelltyp';
    }
}
