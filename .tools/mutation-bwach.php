<?php
/**
 * mutation-bwach.php — Mutationstest für den Batteriewächter.
 *
 * Verändert je Durchlauf EINE Entscheidungsstelle im Modul (Grenzwert, Vorzeichen,
 * Ausschluss …) in einer temporären Kopie und lässt den Prüfstand laufen. Ein
 * Mutant gilt als „getötet“, wenn der Prüfstand fehlschlägt. Überlebt einer, hat
 * der Prüfstand dort eine Lücke (oder die Mutation ist wirkungslos).
 *
 * Aufruf:   php .tools/mutation-bwach.php
 * Rückgabe: 0 = alle Mutanten getötet, 1 = mindestens einer überlebt
 */

$root = dirname(__DIR__);
$src  = $root . '/Batteriewaechter';

// [Datei, Suchtext, Ersatz, Beschreibung]
// Bewusst NICHT aufgenommen (gleichwertige Mutanten, Verhalten bleibt identisch): Wegfall der Namenssperre
// „ladung“ (die Namenserkennung lässt „Batterieladung“ ohnehin nicht zu) und Wegfall von „$found === null“
// im Tick (LastDiscoveryTs = 0 löst dieselbe Suche aus).
$mutations = [
    ['BWACHLogik.php', '$pct <= $emptyThr ? self::ST_EMPTY', '$pct < $emptyThr ? self::ST_EMPTY', 'Grenze „leer“ ≤ → <'],
    ['BWACHLogik.php', '$pct < $lowThr ? self::ST_LOW', '$pct <= $lowThr ? self::ST_LOW', 'Grenze „schwach“ < → ≤'],
    ['BWACHLogik.php', '$lifeAge > $stillDays * 86400', '$lifeAge >= $stillDays * 86400', 'Funkstille > → ≥'],
    ['BWACHLogik.php', '$valueAge > $oldDays * 86400', '$valueAge >= $oldDays * 86400', 'Wertalter > → ≥'],
    ['BWACHLogik.php', '$score += 100', '$score += 50', 'Kritisch-Zuschlag'],
    ['BWACHLogik.php', '($flagLow && $pct >= $lowThr)', '($flagLow && $pct > $lowThr)', 'Widerspruch: ≥ → >'],
    ['BWACHLogik.php', '(!$flagLow && $pct <= $emptyThr)', '(!$flagLow && $pct < $emptyThr)', 'Widerspruch leer: ≤ → <'],
    ['BWACHLogik.php', '$status = $flagUpdated > $pctUpdated ? $stFlag : $stPct;', '$status = $flagUpdated > $pctUpdated ? $stPct : $stFlag;', 'Widerspruch: älteres statt neueres Signal'],
    ['BWACHLogik.php', '$status = self::worse($stPct, $stFlag);', '$status = $stPct;', 'Kritisch: schlechtere Aussage entfällt'],
    ['BWACHLogik.php', '!empty($sig[\'flag\'][\'reversed\']) ? !(bool)$sig[\'flag\'][\'value\']', '!empty($sig[\'flag\'][\'reversed\']) ? (bool)$sig[\'flag\'][\'value\']', 'umgekehrtes Flag nicht umgekehrt'],
    ['BWACHLogik.php', '(float)$sig[\'percent\'][\'value\'] * (float)($sig[\'percent\'][\'scale\'] ?? 1.0)', '(float)$sig[\'percent\'][\'value\']', 'Matter-Skala entfällt'],
    ['BWACHLogik.php', 'schwächste|niedrigste', 'niedrigste', 'Sammelwert „schwächste“ entfällt'],
    ['BWACHLogik.php', 'if (in_array(mb_strtolower((string)$v[\'moduleName\']), $excludedModules, true)) {', 'if (false) {', 'Modulausschluss aus'],
    ['BWACHLogik.php', 'if (!$v[\'parentIsInstance\']) {', 'if (false) {', 'Ausschluss „keine Geräteinstanz“ aus'],
    ['BWACHLogik.php', 'if ($s[\'basis\'] === self::BASIS_NAME && !$nameSearch) {', 'if (false) {', 'Namensvorschlag wird sofort aufgenommen'],
    ['BWACHLogik.php', "\$profile === '~Battery.Reversed'", "\$profile === '~Battery.ReversedX'", 'Profil ~Battery.Reversed nicht erkannt'],
    ['BWACHLogik.php', "if (\$funk === 'still') { \$score = max(\$score, 600); }", "if (\$funk === 'still') { \$score = max(\$score, 100); }", 'Dringlichkeit Funkstille zu niedrig'],
    ['BWACHLogik.php', "if (\$status === self::ST_UNKNOWN) { \$score = max(\$score, 200); }", '', 'Dringlichkeit „unbekannt“ entfällt'],
    ['BWACHLogik.php', 'empty($p[\'ignoreAge\'])', 'true', 'Altersprüfung nie abschaltbar'],
    ['BWACHLogik.php', '$group === self::GROUP_EVENT ? ($p[\'stillDaysEvent\'] ?? 30) : ($p[\'stillDays\'] ?? 7)', '($p[\'stillDays\'] ?? 7)', 'Ereignismelder-Gruppe wirkungslos'],
    ['BWACHLogik.php', '$aggregateInstances[(int)$v[\'parentId\']] = true;', '', 'Sammelgerät-Regel (Raum-Flag) entfällt'],
    ['BWACHLogik.php', 'if (count($list) < 2) {', 'if (true) {', 'Mehrere Sensoren einer Instanz werden nicht geteilt'],
    ['module.php', 'if ((int)($var[\'VariableAction\'] ?? 0) > 0 || (int)($var[\'VariableCustomAction\'] ?? 0) > 0) {', 'if (false) {', 'Variablen mit Aktion zählen als Lebenszeichen'],
    ['module.php', '$this->SetTimerInterval(\'Debounce\', self::DEBOUNCE_MS);', '', 'Sammelfenster wird nie gestartet'],
    ['module.php', '$this->UnregisterMessage($sender, self::VM_UPDATE_MSG);', '', 'Abmeldung weggefallener Variablen entfällt'],
    ['module.php', 'htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\')', '(string)$s', 'HTML-Maskierung entfällt'],
    ['module.php', "foreach (['VariableCustomPresentation', 'VariablePresentation'] as \$key)", 'foreach ([] as $key)', 'Darstellungs-Profil wird nie gelesen'],
    ['module.php', 'if ($st[\'excluded\']) {', 'if (false) {', 'Ausnehmen wirkungslos'],
    ['module.php', '> self::REDISCOVER_SEC', '> 99999999', 'tägliche Neusuche entfällt'],
    ['module.php', '$max = max($max, (int)($var[\'VariableUpdated\'] ?? 0));', '', 'Lebenszeichen immer 0'],
    ['module.php', "\$this->UpdateFormField('CheckStatus', 'caption', \$line);", '', 'Zustandszeile wird nicht aufgefrischt'],
    ['module.php', "\$this->UpdateFormField('DiscoveryStatus', 'caption', \$line);", '', 'Kopfzeile wird nicht aufgefrischt'],
    ['module.php', "\$this->WriteAttributeString('SeenNews', \$this->installedVersion());", '', '„Verstanden“ speichert nichts'],
];

$tmpBase = sys_get_temp_dir() . '/bwach-mut-' . getmypid();
@mkdir($tmpBase);
$survivors = [];
$killed = 0;
$skipped = 0;

foreach ($mutations as [$file, $search, $replace, $desc]) {
    $code = file_get_contents($src . '/' . $file);
    if (strpos($code, $search) === false) { $skipped++; continue; }   // Variante trifft nicht zu (z. B. andere Anführungszeichen)
    $dir = $tmpBase . '/m';
    @mkdir($dir);
    foreach (glob($src . '/*') as $f) { copy($f, $dir . '/' . basename($f)); }
    file_put_contents($dir . '/' . $file, str_replace($search, $replace, $code));
    $out = [];
    exec('BWACH_MODULE_DIR=' . escapeshellarg($dir) . ' php ' . escapeshellarg($root . '/.tools/test-bwach.php') . ' 2>&1', $out, $rc);
    foreach (glob($dir . '/*') as $f) { unlink($f); }
    @rmdir($dir);
    if ($rc !== 0) { $killed++; echo "  ☠️  getötet:   $desc\n"; }
    else { $survivors[] = $desc; echo "  🧟 ÜBERLEBT:  $desc\n"; }
}
@rmdir($tmpBase);

echo "\n$killed getötet, " . count($survivors) . " überlebt, $skipped nicht anwendbar.\n";
exit(count($survivors) === 0 ? 0 : 1);
