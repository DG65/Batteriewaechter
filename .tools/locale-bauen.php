<?php
/**
 * Baut Batteriewaechter/locale.json aus .tools/locale-teile/*.json.
 * Jede Teildatei ist ein JSON-Objekt {"Englischer Quelltext": "Deutscher Text"}.
 * Gleicher Schlüssel mit verschiedenem Deutsch in zwei Teilen = Fehler (Rückgabe 1).
 * Aufruf: php .tools/locale-bauen.php
 */
declare(strict_types=1);
$de = [];
$fehler = 0;
$herkunft = [];
$teile = glob(__DIR__ . '/locale-teile/*.json');
sort($teile);
foreach ($teile as $f) {
    $j = json_decode((string)file_get_contents($f), true);
    if (!is_array($j)) {
        fwrite(STDERR, "Kein gültiges JSON-Objekt: " . basename($f) . "\n");
        $fehler++;
        continue;
    }
    foreach ($j as $en => $txt) {
        if (isset($de[$en]) && $de[$en] !== $txt) {
            fwrite(STDERR, "Widerspruch für \"$en\": " . $herkunft[$en] . " ≠ " . basename($f) . "\n");
            $fehler++;
            continue;
        }
        $de[$en] = (string)$txt;
        $herkunft[$en] = basename($f);
    }
}
ksort($de, SORT_STRING);
$json = json_encode(['translations' => ['de' => $de]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
file_put_contents(dirname(__DIR__) . '/Batteriewaechter/locale.json', $json);
echo count($de) . " Einträge in Batteriewaechter/locale.json\n";
exit($fehler > 0 ? 1 : 0);
