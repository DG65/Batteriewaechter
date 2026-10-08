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
// Bewusst NICHT aufgenommen: die Grenzfälle „Spannung genau am Kurvenende“ (die Zwischenrechnung liefert dort dasselbe)
// und Wegfall des Kurzschlusses in truncateBytes (gleiches Ergebnis).
// Bewusst NICHT aufgenommen (gleichwertige Mutanten, Verhalten bleibt identisch): Wegfall der Namenssperre
// „ladung“ (die Namenserkennung lässt „Batterieladung“ ohnehin nicht zu) und Wegfall von „$found === null“
// im Tick (LastDiscoveryTs = 0 löst dieselbe Suche aus).
$mutations = [
    ['BWACHLogik.php', '$pct <= $emptyThr ? self::ST_EMPTY', '$pct < $emptyThr ? self::ST_EMPTY', 'Grenze „leer“ ≤ → <'],
    ['BWACHLogik.php', '$pct < $lowThr ? self::ST_LOW', '$pct <= $lowThr ? self::ST_LOW', 'Grenze „schwach“ < → ≤'],
    ['BWACHLogik.php', '$lifeAge > $stillSec) {', '$lifeAge >= $stillSec) {', 'Funkstille > → ≥'],
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
    // --- Meilenstein 2: Meldungslogik
    ['BWACHMeldung.php', "if (\$now - \$s['notified'] >= \$interval) {", "if (\$now - \$s['notified'] > \$interval) {", 'Erinnerung: ≥ → >'],
    ['BWACHMeldung.php', '$isNew = count(array_diff($probs, $s[\'probs\'])) > 0;', '$isNew = false;', 'Neuer Befund wird nie als neu erkannt'],
    ['BWACHMeldung.php', '$quiet = $quietNow && !($ignoreQuiet && !empty($c[\'critical\']));', '$quiet = $quietNow;', 'Kritische Geräte durchbrechen die Ruhezeit nicht'],
    ['BWACHMeldung.php', '$quiet = $quietNow && !($ignoreQuiet && !empty($c[\'critical\']));', '$quiet = false;', 'Ruhezeit wirkungslos'],
    ['BWACHMeldung.php', 'if ($now >= (int)$s[\'snooze\'] && !$quiet) {', 'if (!$quiet) {', 'Zurückstellen wirkungslos'],
    ['BWACHMeldung.php', "&& \$now - (int)\$s['since'] >= \$escHours * 3600) {", "&& \$now - (int)\$s['since'] > \$escHours * 3600) {", 'Eskalation: ≥ → >'],
    ['BWACHMeldung.php', '!$s[\'esc\'] && $s[\'notified\'] > 0', '$s[\'notified\'] > 0', 'Eskalation wiederholt sich'],
    ['BWACHMeldung.php', '} elseif ($now - $s[\'gone\'] >= $clearHours * 3600 && $now >= (int)$s[\'snooze\']) {', '} elseif ($now - $s[\'gone\'] >= 0) {', 'Karenzzeit für wackelnde Werte entfällt'],
    ['BWACHMeldung.php', 'return $fromHour < $toHour ? ($h >= $fromHour && $h < $toHour) : ($h >= $fromHour || $h < $toHour);', 'return $fromHour < $toHour ? ($h >= $fromHour && $h <= $toHour) : ($h >= $fromHour || $h <= $toHour);', 'Ruhezeit-Ende einschließlich'],
    ['BWACHMeldung.php', 'if (!$enabled || $fromHour === $toHour) {', 'if (!$enabled) {', 'Ruhezeit mit gleicher Start-/Endstunde'],
    ['BWACHMeldung.php', "\$cur['p'] - \$prev['p'] >= \$jumpPct", "\$cur['p'] - \$prev['p'] > \$jumpPct", 'Wechselerkennung: ≥ → >'],
    ['BWACHMeldung.php', "\$prev['f'] === true && \$cur['f'] === false", "\$prev['f'] === true", 'Flag-Wechsel ohne „jetzt ok“'],
    ['BWACHMeldung.php', "abs(\$entry['t'] - \$e['t']) < self::DIARY_DEDUPE_DAYS * 86400", "abs(\$entry['t'] - \$e['t']) <= self::DIARY_DEDUPE_DAYS * 86400", 'Tagebuch-Doppelte: < → ≤'],
    ['BWACHMeldung.php', '$e[\'key\'] === $entry[\'key\'] && ', '', 'Tagebuch fasst verschiedene Geräte zusammen'],
    ['BWACHMeldung.php', 'if (count($diary) > self::DIARY_CAP) {', 'if (false) {', 'Tagebuch unbegrenzt'],
    ['BWACHMeldung.php', "(int)date('G', \$now) < \$hour", "(int)date('G', \$now) <= \$hour", 'Wochenbericht: Stunde ≥ → >'],
    ['BWACHMeldung.php', 'if ($key === $lastKey) {', 'if (false) {', 'Wochenbericht mehrfach pro Woche'],
    ['BWACHMeldung.php', 'if ($n > 5) {', 'if ($n > 6) {', 'Bündelung: „und N weitere“ zu spät'],
    ['BWACHMeldung.php', 'if ($bytes + $cb > $maxBytes) {', 'if ($bytes + $cb >= $maxBytes) {', 'Byte-Kürzung zu knapp'],
    ['BWACHMeldung.php', 'self::REPLACE_GRACE_DAYS * 86400', '0', 'Wartezeit nach „getauscht“ entfällt'],
    ['BWACHMeldung.php', "\$title = \$n === 1 ? '🔔 Erinnerung'", "\$title = \$n === 1 ? '🔔 Erinnerung: ' . \$first['name']", 'Titel mit Gerätenamen sprengt 32 Byte'],
    // --- Meilenstein 2: Modul
    ['module.php', "if (!\$this->ReadPropertyBoolean('NotificationsActive')) {\n            return;\n        }", '', 'Meldungen laufen auch bei „aus“'],
    ['module.php', "\$state[\$k]['notified'] = (int)(\$old[\$k]['notified'] ?? 0);", '', 'Fehlgeschlagene Zustellung wird als gemeldet verbucht'],
    ['module.php', "return \$chosen ? array_intersect_key(\$all, \$chosen) : \$all;", 'return $all;', 'Push-Auswahl wirkungslos'],
    ['module.php', "|| isset(\$retired[(string)\$key])) {", ") {", 'Außer-Betrieb-Geräte werden weiter überwacht'],
    ['module.php', "'neu' => \$normal, 'erinnerung' => \$normal, 'eskalation' => \$esc", "'neu' => \$normal, 'erinnerung' => \$normal, 'eskalation' => \$normal", 'Eskalation nutzt den normalen Weg'],
    ['module.php', "if (\$this->deliver(\$d['title'], \$d['text'], \$d['sound'], \$channels) > 0) {", "if (true) {\n            \$this->deliver(\$d['title'], \$d['text'], \$d['sound'], \$channels);", 'Wochenbericht gilt auch bei Fehlschlag als gesendet'],
    ['module.php', "BWACHMeldung::truncateBytes(\$title, 32), BWACHMeldung::truncateBytes(\$text, 256), 'Alert'", "\$title, \$text, 'Alert'", 'Kachel-Push ohne Byte-Kürzung'],
    ['module.php', "unset(\$state[\$key]);\n                \$changed = true;", "\$changed = true;", 'Wechsel erledigt den alten Befund nicht'],
    ['module.php', "if (\$prev === null || \$prev['p'] !== \$cur['p'] || \$prev['f'] !== \$cur['f']) {", "if (\$prev === null) {", 'Zuletzt-gesehen wird nie aktualisiert'],
    ['module.php', "IPS_SetHidden(\$id, true);", '', 'Zustandsvariablen bleiben sichtbar'],
    // --- Meilenstein 3: Kachel
    ['module.php', "} elseif (is_array(\$d) && isset(\$d['key'], \$d['action'])) {", "} elseif (true) {\n                \$d = \$d ?: ['key' => '', 'action' => ''];", 'Ungültige Kachel-Anfrage wird nicht abgefangen'],
    ['module.php', "if (!\$this->ReadPropertyBoolean('TileAllowAck')) {", "if (false) {", 'Quittieren aus der Kachel nicht abschaltbar'],
    ['module.php', "JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS", "JSON_UNESCAPED_UNICODE", 'Gerätename kann die Kachel aufbrechen'],
    ['module.php', "\$now - (int)\$meta['ack']['t'] <= 30", "true", 'Rückmeldung bleibt ewig stehen'],
    ['module.php', "if (\$want !== '' && strpos(\$reason, \$want) === 0) {", "if (false) {", 'Unterzeile zeigt nie den Hauptbefund'],
    ['BWACHLogik.php', "preg_replace('/ Tage\$/', ' Tagen', \$s)", "\$s", 'Dativ „seit 16 Tagen“ entfällt'],
    // --- 0.3.1: Fehlerursache beim Mailversand
    ['BWACHMeldung.php', "strpos(\$h, 'login denied') !== false || ", "", 'Login denied wird nicht erkannt'],
    ['BWACHMeldung.php', "strpos(\$h, 'timed out') !== false || strpos(\$h, 'timeout') !== false", "false", 'Zeitüberschreitung wird nicht erkannt'],
    ['BWACHMeldung.php', "if (\$raw === '') {", "if (false) {", 'Leere Meldung ohne ehrlichen Hinweis'],
    ['module.php', "restore_error_handler();", "", 'Fehler-Handler bleibt hängen'],
    ['module.php', "\$warnings[] = (string)\$str;", "", 'Warnungen des SMTP-Moduls werden nicht gesammelt'],
    ['module.php', "'⚠️ nicht gesendet — ' . \$this->lastMailError", "'⚠️ nicht gesendet'", 'Testmeldung ohne Ursache'],
    ['module.php', "IPS_LogMessage('Batteriewächter', 'E-Mail-Versand fehlgeschlagen: ' . \$this->lastMailError);", "", 'Ursache nicht im Meldungslog'],
    ['module.php', "\$this->UpdateFormField('NotifyStatus', 'caption', \$out);", "", 'Testmeldung frischt die Statuszeile nicht auf'],
    // --- 0.4.0: Zelltypen, Prognose, Einkauf, Statistik
    ['BWACHZelle.php', 'if ($v > $top * 1.12 || $v < $low * 0.6) {', 'if (false) {', 'Spannung, die nicht zum Zelltyp passt, wird umgerechnet'],
    ['BWACHZelle.php', "\$volt / \$cells", "\$volt", 'Zellenzahl wird ignoriert'],
    ['BWACHPrognose.php', 'if ($n < self::FORECAST_MIN_POINTS || $span < self::FORECAST_MIN_SPAN) {', 'if ($n < 2) {', 'Prognose schon mit 2 Punkten'],
    ['BWACHPrognose.php', '$span < self::FORECAST_MIN_SPAN', '$span < 0', 'Prognose ohne Mindestzeitraum'],
    ['BWACHPrognose.php', 'if ($distinct < self::FORECAST_MIN_DISTINCT) {', 'if (false) {', 'Prognose aus groben Stufen'],
    ['BWACHPrognose.php', 'if ($slope >= self::NO_DISCHARGE_SLOPE) {', 'if (false) {', 'Keine-Entladung-Erkennung entfällt'],
    ['BWACHPrognose.php', 'if ($r2 >= 0.85 && $span >= 60 * 86400 && $n >= 8) {', 'if (true) {', 'Immer hohe Sicherheit'],
    ['BWACHPrognose.php', '} elseif ($r2 >= 0.6 && $span >= 30 * 86400) {', '} elseif (true) {', 'Immer mindestens mittlere Sicherheit'],
    ['BWACHPrognose.php', "if (\$series[\$i][1] - \$series[\$i - 1][1] >= \$jump) {", "if (\$series[\$i][1] - \$series[\$i - 1][1] > \$jump) {", 'Wechselsprung: ≥ → >'],
    ['BWACHPrognose.php', "return array_slice(\$series, \$start);", "return \$series;", 'Alter Abschnitt vor dem Wechsel zählt mit'],
    ['BWACHPrognose.php', 'if (abs($pct - $last[1]) < self::HISTORY_MIN_DELTA && $t - $last[0] < self::HISTORY_MIN_GAP) {', 'if (false) {', 'Verlauf speichert jeden Wert'],
    ['BWACHPrognose.php', 'if ($t <= $last[0]) {', 'if (false) {', 'Verlauf nimmt Zeit rückwärts an'],
    ['BWACHPrognose.php', 'if (count($series) > self::HISTORY_CAP) {', 'if (false) {', 'Verlauf unbegrenzt'],
    ['BWACHPrognose.php', '$soon = $i[\'days\'] !== null && $i[\'days\'] <= $horizonDays;', '$soon = $i[\'days\'] !== null && $i[\'days\'] < $horizonDays;', 'Einkauf: Horizont ≤ → <'],
    ['BWACHPrognose.php', "if (in_array(\$i['status'], ['leer', 'schwach'], true) || \$soon) {", "if (\$soon) {", 'Einkauf ignoriert akut schwache Geräte'],
    ['BWACHPrognose.php', "\$count[\$label] = (\$count[\$label] ?? 0) + max(1, (int)\$i['cells']);", "\$count[\$label] = (\$count[\$label] ?? 0) + 1;", 'Einkauf zählt Zellen je Gerät nicht'],
    ['BWACHPrognose.php', "\$t = max(\$now, \$t);", "", 'Tauschrunde darf in der Vergangenheit liegen'],
    ['BWACHPrognose.php', "- \$marginDays * 86400;", ";", 'Tauschrunde ohne Reserve'],
    ['BWACHPrognose.php', "if (count(\$gaps) < self::GAPS_MIN) {", "if (false) {", 'Gelernte Schwelle schon bei wenigen Beobachtungen'],
    ['BWACHPrognose.php', "max(6 * 3600, min(\$default, 3 * \$p90))", "min(\$default, 3 * \$p90)", 'Gelernte Schwelle ohne Untergrenze'],
    ['BWACHPrognose.php', "max(6 * 3600, min(\$default, 3 * \$p90))", "max(6 * 3600, 3 * \$p90)", 'Gelernte Schwelle über der eingestellten'],
    ['BWACHPrognose.php', "\$obs['gaps'] = array_slice(\$obs['gaps'], -self::GAPS_CAP);", "", 'Abstände unbegrenzt'],
    ['BWACHPrognose.php', "if (\$obs['last'] > 0 && \$life > \$obs['last']) {", "if (\$life > 0) {", 'Erste Beobachtung erzeugt einen Abstand'],
    ['BWACHLogik.php', "if (\$orphanDays > 0 && \$lifeAge > \$orphanDays * 86400) {", "if (\$orphanDays > 0 && \$lifeAge > 0) {", 'Verwaist-Vorschlag sofort'],
    ['BWACHLogik.php', "\$stillSec  = isset(\$p['stillSec']) && (int)\$p['stillSec'] > 0 ? (int)\$p['stillSec'] : \$stillDays * 86400;", "\$stillSec  = \$stillDays * 86400;", 'Gelernte Schwelle wirkungslos'],
    ['module.php', "in_array(\$f['confidence'], ['hoch', 'mittel'], true) && \$f['days'] <= \$this->ReadPropertyInteger('SoonDays')", "\$f['days'] <= \$this->ReadPropertyInteger('SoonDays')", '„Bald leer“ auch bei geringer Sicherheit'],
    ['module.php', "\$f['days'] <= \$this->ReadPropertyInteger('SoonDays')", "\$f['days'] < 0", '„Bald leer“ nie'],
    ['module.php', "\$stillSec < \$defaultStill ? \$stillSec : 0", "0", 'Gelernte Schwelle kommt nicht an'],
    ['module.php', "if (\$series) {\n                \$hist[\$key] = \$series;\n            }", "\$hist[\$key] = \$series;", 'Leere Verläufe werden gespeichert'],
    ['BWACHMeldung.php', "if (!empty(\$r['soon'])) {\n            \$p[] = self::PROB_SOON;\n        }", "", '„Bald leer“ löst keine Meldung aus'],
    ['module.php', '$this->SetTimerInterval(\'Debounce\', self::DEBOUNCE_MS);', '', 'Sammelfenster wird nie gestartet'],
    ['module.php', '$this->UnregisterMessage($sender, self::VM_UPDATE_MSG);', '', 'Abmeldung weggefallener Variablen entfällt'],
    ['module.php', 'htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\')', '(string)$s', 'HTML-Maskierung entfällt'],
    ['module.php', "foreach (['VariableCustomPresentation', 'VariablePresentation'] as \$key)", 'foreach ([] as $key)', 'Darstellungs-Profil wird nie gelesen'],
    ['module.php', 'if ($st[\'excluded\'] ||', 'if (false ||', 'Ausnehmen wirkungslos'],
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
    if (strpos($code, $search) === false) { $skipped++; echo "  ⏭  nicht anwendbar: $desc\n"; continue; }   // Variante trifft nicht zu (z. B. andere Anführungszeichen)
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
