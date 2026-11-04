<?php
/**
 * Prüft die Übersetzung:
 *  1. Jeder Literal-Text in BWACHText::t('…') steht in locale.json (sonst Fehler).
 *  2. Listet String-Literale im Code, die noch deutsch aussehen (Umlaute, „“, ß oder
 *     typische deutsche Wörter). Das ist ein Hinweis, kein Fehler: Idents, gespeicherte
 *     Werte und Aliase bleiben bewusst deutsch.
 * Aufruf: php .tools/check-locale.php [Datei …]   (ohne Argument: alle Moduldateien)
 * Rückgabe: 0 = alle t()-Texte übersetzt, 1 = mindestens einer fehlt.
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$loc = json_decode((string)file_get_contents($root . '/Batteriewaechter/locale.json'), true)['translations']['de'] ?? [];
$files = array_slice($argv, 1) ?: glob($root . '/Batteriewaechter/*.php');
$fehlt = 0;
$deutsch = 0;
$wortRx = '/[äöüÄÖÜß„“]|\b(und|oder|nicht|kein|keine|Gerät|Geräte|bitte|wird|werden|ist|sind|noch|seit|nach|für|mit|ohne|Batterie|Tage|Stunden|leer|schwach)\b/u';
foreach ($files as $file) {
    $tokens = token_get_all((string)file_get_contents($file));
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) {
            continue;
        }
        if ($t[0] === T_STRING && $t[1] === 't' && $i >= 2 && is_array($tokens[$i - 2]) && $tokens[$i - 2][1] === 'BWACHText') {
            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
            if ($j < $n && $tokens[$j] === '(') {
                $j++;
                while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
                if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $next = $j + 1;
                    while ($next < $n && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) { $next++; }
                    if ($next < $n && $tokens[$next] === '.') {
                        echo basename($file) . ':' . $tokens[$j][2] . "  t() mit verkettetem Text, bitte Platzhalter nutzen\n";
                        $fehlt++;
                        continue;
                    }
                    $lit = eval('return ' . $tokens[$j][1] . ';');
                    if (!array_key_exists($lit, $loc)) {
                        echo basename($file) . ':' . $tokens[$j][2] . "  FEHLT in locale.json: " . $lit . "\n";
                        $fehlt++;
                    }
                }
            }
            continue;
        }
        if ($t[0] === T_CONSTANT_ENCAPSED_STRING && preg_match($wortRx, $t[1])) {
            $prev = $i - 1;
            while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) { $prev--; }
            $p2 = $prev - 1;
            while ($p2 >= 0 && is_array($tokens[$p2]) && $tokens[$p2][0] === T_WHITESPACE) { $p2--; }
            $inT = $prev >= 0 && $tokens[$prev] === '(' && $p2 >= 0 && is_array($tokens[$p2]) && $tokens[$p2][1] === 't';
            if (!$inT) {
                echo basename($file) . ':' . $t[2] . "  deutsch? " . mb_substr($t[1], 0, 110) . "\n";
                $deutsch++;
            }
        }
    }
}
echo "Fehlende Übersetzungen: $fehlt, deutsch aussehende Literale außerhalb t(): $deutsch\n";
exit($fehlt > 0 ? 1 : 0);
