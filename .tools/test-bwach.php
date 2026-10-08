<?php
/**
 * test-bwach.php — Prüfstand für den Batteriewächter OHNE laufendes Symcon.
 *
 * Block 1  Erkennung einzelner Variablen (Profile, Idents, Namen, Matter-Skala)
 * Block 2  Zusammenführen und Ausschließen (Fixture nach dem Privat-Symcon vom 07.10.2026)
 * Block 3  Bewertung (Schwellen, Widerspruch, unplausibel, veraltet, Funkstille, kritisch)
 * Block 4  Modul gegen simuliertes IPS: Suche, Prüfung, Kennzahlen, Tabellen, Nachrichten
 * Block 5  Formular- und Dateihygiene (Panels, Rückmeldungen, UTF-8, Datumsformat)
 *
 * Aufruf:   php .tools/test-bwach.php
 * Rückgabe: 0 = alles bestanden, 1 = mindestens ein Fehlschlag
 *
 * Für Mutationstests: Umgebungsvariable BWACH_MODULE_DIR zeigt auf eine
 * (mutierte) Kopie des Modulordners, siehe mutation-bwach.php.
 */

date_default_timezone_set('Europe/Berlin');

const VM_UPDATE = 10603;
const KR_READY = 10103;
const IPS_KERNELMESSAGE = 10100;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;

$ROOT = dirname(__DIR__);
$MODDIR = getenv('BWACH_MODULE_DIR') ?: $ROOT . '/Batteriewaechter';

// ---------------------------------------------------------------------------
// Simuliertes IPS: Objektbaum
// ---------------------------------------------------------------------------
$GLOBALS['OBJ'] = [];      // id => ['type'=>0 Kat|1 Inst|2 Var, 'parent','ident','name','module', 'var'=>[...]]
$GLOBALS['NEXT_ID'] = 50000;
$GLOBALS['CLOCK'] = 0;

function mkcat(int $id, string $name, int $parent = 0): void { $GLOBALS['OBJ'][$id] = ['type' => 0, 'parent' => $parent, 'ident' => '', 'name' => $name]; }
function mkinst(int $id, string $name, string $module, int $parent = 0): void { $GLOBALS['OBJ'][$id] = ['type' => 1, 'parent' => $parent, 'ident' => '', 'name' => $name, 'module' => $module]; }
function mkvar(int $id, int $parent, string $ident, string $name, int $type, $value, int $updated, string $profile = '', array $extra = []): void
{
    $GLOBALS['OBJ'][$id] = ['type' => 2, 'parent' => $parent, 'ident' => $ident, 'name' => $name,
        'var' => array_merge(['VariableType' => $type, 'VariableProfile' => $profile, 'VariableCustomProfile' => '', 'VariableUpdated' => $updated, 'VariableChanged' => $updated, 'VariableAction' => 0, 'VariableCustomAction' => 0, 'value' => $value], $extra)];
}
function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_GetVariableList(): array { $o = []; foreach ($GLOBALS['OBJ'] as $id => $x) { if ($x['type'] === 2) { $o[] = $id; } } return $o; }
function IPS_GetObject(int $id): array { $x = $GLOBALS['OBJ'][$id]; return ['ObjectType' => $x['type'], 'ParentID' => $x['parent'], 'ObjectIdent' => $x['ident'], 'ObjectName' => $x['name']]; }
function IPS_GetVariable(int $id): array { $v = $GLOBALS['OBJ'][$id]['var']; unset($v['value']); return $v; }
function IPS_GetInstance(int $id): array { return ['ModuleInfo' => ['ModuleName' => $GLOBALS['OBJ'][$id]['module'], 'ModuleID' => 'GUID-' . $GLOBALS['OBJ'][$id]['module']], 'InstanceStatus' => 102]; }
function IPS_GetInstanceList(): array { $o = []; foreach ($GLOBALS['OBJ'] as $id => $x) { if ($x['type'] === 1) { $o[] = $id; } } return $o; }
function IPS_GetConfiguration(int $id): string { return json_encode($GLOBALS['OBJ'][$id]['config'] ?? []); }
function IPS_InstanceExists($id): bool { return isset($GLOBALS['OBJ'][(int)$id]) && $GLOBALS['OBJ'][(int)$id]['type'] === 1; }
function IPS_VariableExists($id): bool { return isset($GLOBALS['OBJ'][(int)$id]) && $GLOBALS['OBJ'][(int)$id]['type'] === 2; }
function IPS_GetName(int $id): string { return $GLOBALS['OBJ'][$id]['name'] ?? ''; }
function IPS_GetParent(int $id): int { return $GLOBALS['OBJ'][$id]['parent'] ?? 0; }
function IPS_GetChildrenIDs(int $p): array { $o = []; foreach ($GLOBALS['OBJ'] as $id => $x) { if ($x['parent'] === $p) { $o[] = $id; } } return $o; }
function GetValue($id) { return $GLOBALS['OBJ'][(int)$id]['var']['value']; }
function IPS_GetObjectIDByIdent(string $ident, int $parent) { foreach ($GLOBALS['OBJ'] as $id => $x) { if ($x['ident'] === $ident && $x['parent'] === $parent) { return $id; } } return false; }
$GLOBALS['INSTS'] = []; $GLOBALS['SENT'] = []; $GLOBALS['SEND_OK'] = true; $GLOBALS['LOG'] = [];
$GLOBALS['ZW'] = []; $GLOBALS['ZW_OK'] = true;
function ZW_RequestStatus(int $id) { $GLOBALS['ZW'][] = $id; return $GLOBALS['ZW_OK']; }
function IPS_SetHidden(int $id, bool $h): bool { $GLOBALS['OBJ'][$id]['hidden'] = $h; return true; }
function IPS_GetInstanceListByModuleID(string $g): array { return $GLOBALS['INSTS'][$g] ?? []; }
function IPS_LogMessage(string $s, string $m): bool { $GLOBALS['LOG'][] = "$s: $m"; return true; }
function VISU_PostNotificationEx($id, $t, $x, $icon, $sound, $target) { $GLOBALS['SENT'][] = ['visu', $id, $t, $x, $sound]; return $GLOBALS['SEND_OK']; }
function WFC_PushNotification($id, $t, $x, $sound, $target) { $GLOBALS['SENT'][] = ['wfc', $id, $t, $x, $sound]; return $GLOBALS['SEND_OK']; }
function SMTP_SendMailEx($id, $to, $subj, $body) { if (!empty($GLOBALS['SMTP_WARN'])) { trigger_error($GLOBALS['SMTP_WARN'], E_USER_WARNING); } if (!empty($GLOBALS['SMTP_THROW'])) { throw new RuntimeException($GLOBALS['SMTP_THROW']); } $GLOBALS['SENT'][] = ['mailex', $id, $subj, $body, $to]; return $GLOBALS['SEND_OK']; }
function SMTP_SendMail($id, $subj, $text) { $GLOBALS['SENT'][] = ['mail', $id, $subj, $text]; return $GLOBALS['SEND_OK']; }
function IPS_GetLibrary(string $guid): array { $lib = json_decode(file_get_contents($GLOBALS['ROOT'] . '/library.json'), true); return ['Version' => $lib['version'] . '-beta.1', 'Build' => (int)$lib['build']]; }

class IPSModule
{
    public int $InstanceID = 12345;
    public array $props = [];
    public array $attrs = [];
    public int $status = 0;
    public array $timers = [];
    public array $messages = [];
    public array $fieldUpdates = [];

    public function __construct() { mkinst($this->InstanceID, 'Batteriewächter', 'Batteriewaechter'); }
    public function Create() {}
    public function ApplyChanges() {}
    public function RegisterPropertyString(string $n, string $d): void { $this->props[$n] ??= $d; }
    public function RegisterPropertyInteger(string $n, int $d): void { $this->props[$n] ??= $d; }
    public function RegisterPropertyBoolean(string $n, bool $d): void { $this->props[$n] ??= $d; }
    public function ReadPropertyString(string $n): string { return $this->props[$n]; }
    public function ReadPropertyInteger(string $n): int { return $this->props[$n]; }
    public function ReadPropertyBoolean(string $n): bool { return $this->props[$n]; }
    public function RegisterAttributeString(string $n, string $d): void { $this->attrs[$n] ??= $d; }
    public function RegisterAttributeInteger(string $n, int $d): void { $this->attrs[$n] ??= $d; }
    public function RegisterAttributeBoolean(string $n, bool $d): void { $this->attrs[$n] ??= $d; }
    public function ReadAttributeString(string $n): string { return $this->attrs[$n]; }
    public function ReadAttributeInteger(string $n): int { return $this->attrs[$n]; }
    public function ReadAttributeBoolean(string $n): bool { return $this->attrs[$n]; }
    public function WriteAttributeString(string $n, string $v): void { $this->attrs[$n] = $v; }
    public function WriteAttributeInteger(string $n, int $v): void { $this->attrs[$n] = $v; }
    public function WriteAttributeBoolean(string $n, bool $v): void { $this->attrs[$n] = $v; }
    public function SetStatus(int $s): void { $this->status = $s; }
    public function RegisterTimer(string $n, int $ms, string $script): void { $this->timers[$n] = $ms; }
    public function SetTimerInterval(string $n, int $ms): void { $this->timers[$n] = $ms; }
    public function RegisterMessage(int $sender, int $msg): void { $this->messages[$sender][$msg] = true; }
    public function UnregisterMessage(int $sender, int $msg): void { unset($this->messages[$sender][$msg]); if (empty($this->messages[$sender])) { unset($this->messages[$sender]); } }
    public function GetMessageList(): array { $o = []; foreach ($this->messages as $s => $m) { $o[$s] = array_keys($m); } return $o; }
    public function UpdateFormField(string $n, string $p, $v): void { $this->fieldUpdates[] = [$n, $p, $v]; }
    public int $visType = 0; public array $visUpdates = [];
    public function SetVisualizationType(int $t): void { $this->visType = $t; }
    public function UpdateVisualizationValue(string $v): void { $this->visUpdates[] = $v; }
    public function MaintainVariable(string $ident, string $name, int $type, string $profile, int $pos, bool $keep): void
    {
        if ($keep && IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false) {
            $def = [VARIABLETYPE_BOOLEAN => false, VARIABLETYPE_INTEGER => 0, VARIABLETYPE_FLOAT => 0.0, VARIABLETYPE_STRING => ''][$type];
            mkvar($GLOBALS['NEXT_ID']++, $this->InstanceID, $ident, $name, $type, $def, 0, $profile);
        }
    }
    public function SetValue(string $ident, $v): void { $id = IPS_GetObjectIDByIdent($ident, $this->InstanceID); $GLOBALS['OBJ'][$id]['var']['value'] = $v; }
    public function GetValue(string $ident) { $id = IPS_GetObjectIDByIdent($ident, $this->InstanceID); return $GLOBALS['OBJ'][$id]['var']['value']; }
    /** Simuliert MC_DeleteModule()+MC_CreateModule(): Attribute weg, Create()/ApplyChanges() laufen neu. */
    public function simulateResync(): void { $this->attrs = []; $this->Create(); $this->ApplyChanges(); }
}

$GLOBALS['ROOT'] = $ROOT;
require $MODDIR . '/module.php';

class BWTest extends Batteriewaechter
{
    protected function now(): int { return $GLOBALS['CLOCK']; }
}

$fails = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $fails;
    echo ($ok ? '  ✅ ' : '  ❌ ') . $what . ($ok || $detail === '' ? '' : ' — ' . $detail) . "\n";
    if (!$ok) { $fails++; }
}
function heading(string $t): void { echo "\n[$t]\n"; }
function ts(string $s): int { return strtotime($s); }
function clock(string $s): void { $GLOBALS['CLOCK'] = ts($s); }
function S(string $x): string { return json_encode($x, JSON_UNESCAPED_UNICODE); }

// ===========================================================================
heading('1 Erkennung einzelner Variablen');
// ===========================================================================
function det(string $ident, string $name, int $type, string $profile = ''): ?array { return BWACHLogik::detectSignal(['ident' => $ident, 'name' => $name, 'type' => $type, 'profile' => $profile]); }
function kindOf(?array $s): string { return $s === null ? '-' : $s['kind'] . '/' . $s['basis']; }

check('~Battery (Bool) → Flag „schwach“ per Profil', kindOf(det('x', 'y', 0, '~Battery')) === 'flag/profil');
check('~Battery.Reversed → Flag „in Ordnung“', kindOf(det('x', 'y', 0, '~Battery.Reversed')) === 'flag_reversed/profil');
check('~Battery.100 (Integer) → Prozent', kindOf(det('x', 'y', 1, '~Battery.100')) === 'percent/profil');
check('~Battery.100 an Bool-Variable → kein Treffer (falscher Typ)', det('x', 'y', 0, '~Battery.100') === null);
check('Z-Wave BatteryVariable (Integer) → Prozent per Ident', kindOf(det('BatteryVariable', 'Batterie', 1)) === 'percent/ident');
check('Z-Wave BatteryLowVariable (Bool) → Flag per Ident', kindOf(det('BatteryLowVariable', 'Batteriestatus', 0)) === 'flag/ident');
check('Comet Battery/BatteryLow', kindOf(det('Battery', 'Batteriestand', 1)) === 'percent/ident' && kindOf(det('BatteryLow', 'Batterie schwach', 0)) === 'flag/ident');
check('Botvac BATTERY (Großschreibung)', kindOf(det('BATTERY', 'Batterie', 1)) === 'percent/ident');
check('Shelly devicepower_0_battery_percent', kindOf(det('devicepower_0_battery_percent', 'Batteriesstatus', 1)) === 'percent/ident');
check('Shelly devicepower_0_battery_V → Spannung', kindOf(det('devicepower_0_battery_V', 'Batteriespannung', 2)) === 'voltage/ident');
check('HomeMatic LOWBAT / LOW_BAT → Flag (Dokumentation)', kindOf(det('LOWBAT', 'x', 0)) === 'flag/ident' && kindOf(det('LOW_BAT', 'x', 0)) === 'flag/ident');
check('HomeMatic OPERATING_VOLTAGE → Spannung (Dokumentation)', kindOf(det('OPERATING_VOLTAGE', 'x', 2)) === 'voltage/ident');
check('Zigbee2MQTT battery_low → Flag', kindOf(det('battery_low', 'x', 0)) === 'flag/ident');
check('Zigbee2MQTT battery (Integer) → Prozent', kindOf(det('battery', 'x', 1)) === 'percent/ident');
$matter = det('BatPercentRemaining', 'x', 1);
check('Matter BatPercentRemaining: Halbprozent-Skala, live bestätigt (nicht mehr „ungetestet“)', $matter !== null && $matter['scale'] === 0.5 && $matter['unverified'] === false);
check('Froggit-Ident soilbatt1 nur über Profil', det('soilbatt1', 'Batterie Bodenfeuchtesensor', 1) === null && kindOf(det('soilbatt1', 'x', 1, '~Battery.100')) === 'percent/profil');
check('Name „Batterie schwach“ → nur Namensvorschlag', kindOf(det('foo', 'Batterie schwach', 0)) === 'flag/name');
check('Name „Batteriestand“ → Prozent (Name)', kindOf(det('foo', 'Batteriestand', 1)) === 'percent/name');
foreach (['Batterieladung', 'Batterieentladung Gesamt', 'Batterieleistung (W)', 'Batterie SOC (%)', 'Batterie: Einstandspreis (ct/kWh)', 'Batteriemodule', 'Kategorie Batterie - Strom L2', 'Batterie (Nachtladen)', 'Batterie Aktoren - Leer', 'Ziel-SOC für die Autobeladung'] as $n) {
    check('Heimspeicher-Name wird nie erkannt: ' . $n, det('foo', $n, 1) === null && det('foo', $n, 2) === null && det('foo', $n, 0) === null);
}
check('Sammelwert-Namen erkannt', BWACHLogik::isAggregateName('Schwächste Batterie') && BWACHLogik::isAggregateName('Batterie Aktoren - Gesamt') && !BWACHLogik::isAggregateName('Batteriestand'));
check('Zahlenformat deutsch: 4,71 / 100 / −20', BWACHLogik::num(4.71) === '4,71' && BWACHLogik::num(100.0) === '100' && BWACHLogik::num(-20.0) === '-20');
check('Dativ nach „vor“/„seit“: 1 Tag, 16 Tagen, 5 Stunden, 40 Minuten', BWACHLogik::daysDat(86400) === '1 Tag' && BWACHLogik::daysDat(16 * 86400) === '16 Tagen' && BWACHLogik::daysDat(5 * 3600) === '5 Stunden' && BWACHLogik::daysDat(2400) === '40 Minuten');
check('Dauer: 1 Tag / 16 Tage / 5 Stunden / 40 Minuten', BWACHLogik::days(86400) === '1 Tag' && BWACHLogik::days(16 * 86400) === '16 Tage' && BWACHLogik::days(5 * 3600) === '5 Stunden' && BWACHLogik::days(2400) === '40 Minuten');

// ===========================================================================
heading('2 Zusammenführen und Ausschließen (Fixture nach Privat-Symcon 07.10.2026)');
// ===========================================================================
clock('2026-10-07 17:30');
$NOW = $GLOBALS['CLOCK'];

function buildWorld(int $now): void
{
    $GLOBALS['OBJ'] = [];
    $d = 86400;
    mkcat(900, 'Sensoren'); mkcat(901, 'Thermostate'); mkcat(902, 'Heizung'); mkcat(903, 'Skripte');

    // A: Z-Wave-Sensor, alles in Ordnung (Prozent 05.10., Flag seit 2024)
    mkinst(101, 'Sensor Schlafzimmer', 'Z-Wave Module', 900);
    mkvar(1011, 101, 'BatteryVariable', 'Batterie', 1, 100, $now - 2 * $d, '~Battery.100');
    mkvar(1012, 101, 'BatteryLowVariable', 'Batteriestatus', 0, false, $now - 700 * $d, '~Battery');
    mkvar(1013, 101, 'SensorMultilevel01Variable', 'Temperatur', 2, 21.5, $now - 600);

    // B: ausgebautes Z-Wave-Thermostat: Batteriewert drei Jahre alt, nur Symcon schreibt noch den Sollwert
    mkinst(102, 'Thermostat Esszimmer', 'Z-Wave Module', 901);
    mkvar(1021, 102, 'BatteryVariable', 'Batterie', 1, 82, ts('2024-01-07 07:22'), '~Battery.100');
    // Sollwert hat eine Aktion und wird täglich von Symcon (Heizungssteuerung) geschrieben: KEIN Lebenszeichen
    mkvar(1022, 102, 'ThermostatSetPoint1', 'Soll', 2, 21.0, $now - 600, '', ['VariableAction' => 102]);

    // C: Funkstille seit 16 Tagen
    mkinst(103, 'Sensor Heizung', 'Z-Wave Module', 902);
    mkvar(1031, 103, 'BatteryVariable', 'Batterie', 1, 100, ts('2026-09-20 19:37'), '~Battery.100');
    mkvar(1032, 103, 'SensorBinaryVariable', 'Sensor', 0, false, ts('2026-09-21 01:23'));

    // D: Widerspruch — Flag „schwach“ (19.09.), Prozent 100 (06.10., neuer)
    mkinst(104, 'Sensor Vorrat', 'Z-Wave Module', 900);
    mkvar(1041, 104, 'BatteryVariable', 'Batterie', 1, 100, ts('2026-10-06 10:00'), '~Battery.100');
    mkvar(1042, 104, 'BatteryLowVariable', 'Batteriestatus', 0, true, ts('2026-09-19 01:06'), '~Battery');
    mkvar(1043, 104, 'SensorMultilevel03Variable', 'Temperatur', 2, 18.0, $now - 300);

    // E: Froggit, −20 %
    mkinst(105, 'Bodenfeuchte 2', 'Froggit', 903);
    mkvar(1051, 105, 'soilbatt2', 'Batterie Bodenfeuchtesensor (2)', 1, -20, $now - 3600, '~Battery.100');
    mkvar(1052, 105, 'soil2', 'Feuchte', 1, 40, $now - 3600);
    mkvar(1053, 105, 'soilbatt1', 'Batterie Bodenfeuchtesensor', 1, 40, $now - 3600, '~Battery.100');

    // F: Shelly mit Prozent UND Spannung (EIN Gerät)
    mkinst(106, 'Shelly H&T', 'ShellyDevice', 900);
    mkvar(1061, 106, 'devicepower_0_battery_percent', 'Batteriesstatus', 1, 35, $now - 3600);
    mkvar(1062, 106, 'devicepower_0_battery_V', 'Batteriespannung', 2, 4.71, $now - 3600);

    // G: Comet-Thermostat und Comet-Raum mit Sammelwert „Schwächste Batterie“
    mkinst(107, 'Comet Wohnzimmer', 'CometWiFiThermostat', 901);
    mkvar(1071, 107, 'Battery', 'Batteriestand', 1, 65, $now - 3 * $d);
    mkvar(1072, 107, 'BatteryLow', 'Batterie schwach', 0, false, $now - 3 * $d);
    mkvar(1073, 107, 'Temp', 'Temperatur', 2, 20.0, $now - 120);
    mkinst(108, 'Raum Wohnzimmer', 'CometWiFiRoom', 901);
    mkvar(1081, 108, 'Battery', 'Schwächste Batterie', 1, 65, $now - 3 * $d);
    mkvar(1082, 108, 'BatteryLow', 'Batterie schwach', 0, false, $now - 3 * $d);

    // H: Heimspeicher & Skriptvariablen (dürfen nie als Gerät erscheinen)
    mkinst(109, 'Wechselrichter', 'InverterHub', 0);
    mkvar(1091, 109, 'Battery', 'Batterie', 1, 95, $now - 60);
    mkvar(1092, 109, 'bat1_soc', 'Bat.1 SOC', 1, 95, $now - 60, '~Battery.100');
    mkvar(1093, 903, 'soc', 'SOC', 1, 95, $now - 60, '~Battery.100');
    mkinst(110, 'Speicher Modbus', 'ModBus Device', 0);
    mkvar(1101, 110, 'A_2_3_35212', 'Batteriemodule', 1, 1, $now - 60);

    // I: Wetterstation, Bool-Profil ~Battery
    mkinst(111, 'Wetterstation', 'Froggit', 903);
    mkvar(1111, 111, 'wh65batt', 'Batterie Wetterstation', 0, false, $now - 600, '~Battery');

    // J: Staubsauger, Wert 328 Tage alt
    mkinst(112, 'Staubsauger', 'BotvacRobot', 0);
    mkvar(1121, 112, 'BATTERY', 'Batterie', 1, 73, ts('2025-11-13 03:37'), '~Battery.100');
    mkvar(1122, 112, 'STATE', 'Status', 1, 1, $now - 900);

    // K: Dummy mit „Batterie“ nur im Namen → nur Vorschlag
    mkinst(113, 'Dummy', 'Dummy Module', 0);
    mkvar(1131, 113, 'Akku', 'Batteriestand', 1, 55, $now - 600);

    // L: Rauchmelder mit 25 % (kritisch: schwach, normal: ok)
    mkinst(114, 'Rauchmelder Heizung', 'Z-Wave Module', 902);
    mkvar(1141, 114, 'BatteryVariable', 'Batterie', 1, 25, $now - 3600, '~Battery.100');
    mkvar(1142, 114, 'SensorMultilevel01Variable', 'Temperatur', 2, 19.0, $now - 3600);

    // M: Variable des Batteriewächters selbst darf nie erkannt werden (Eigenschutz)
    mkinst(12345, 'Batteriewächter', 'Batteriewaechter');
    mkvar(1201, 12345, 'Low', 'Batterie schwach', 1, 0, $now);
}

buildWorld($NOW);
$mod0 = new BWTest();
$vars = (function () { return $this->collectVariables(); })->call($mod0);
$res = BWACHLogik::classify($vars, ['excludedModules' => BWACHLogik::DEFAULT_EXCLUDED_MODULES, 'nameSearch' => false, 'manual' => []]);
$dev = $res['devices'];

check('Z-Wave-Sensor: Prozent und Flag sind EIN Gerät', isset($dev[101]) && isset($dev[101]['signals']['percent']) && isset($dev[101]['signals']['flag']));
check('Shelly: Prozent und Spannung sind EIN Gerät', isset($dev[106]['signals']['percent'], $dev[106]['signals']['voltage']));
check('Comet-Thermostat gefunden, Comet-Raum (Sammelwert) NICHT', isset($dev[107]) && !isset($dev[108]));
check('Raum-Flag „Batterie schwach“ zählt nicht als Gerät (Instanz mit Sammelwert ist ein Sammelgerät)', count(array_filter($res['excluded'], function ($e) { return $e['vid'] === 1082; })) === 1);
check('Neun Bodenfeuchtesensoren einer Wetterstation: jeder ein eigenes Gerät (hier zwei)', isset($dev['105-1051'], $dev['105-1053']) && !isset($dev[105]) && $dev['105-1051']['parent'] === 105);
check('Geteilte Geräte tragen den Variablennamen', strpos($dev['105-1053']['name'], 'Bodenfeuchtesensor') !== false);
check('Sammelwert „Schwächste Batterie“ steht mit Grund bei den Ausschlüssen', in_array('Sammelwert über mehrere Geräte', array_column(array_filter($res['excluded'], function ($e) { return $e['vid'] === 1081; }), 'reason'), true));
check('InverterHub-Variablen (Heimspeicher) ausgeschlossen', !isset($dev[109]) && count(array_filter($res['excluded'], function ($e) { return in_array($e['vid'], [1091, 1092], true); })) === 2);
check('Skriptvariable ohne Geräteinstanz ausgeschlossen', !isset($dev[903]) && count(array_filter($res['excluded'], function ($e) { return $e['vid'] === 1093; })) === 1);
check('ModBus-Heimspeicher nicht erkannt', !isset($dev[110]));
check('Variable des Batteriewächters selbst nie erkannt', !isset($dev[12345]));
check('Name „Batteriestand“ im Dummy → nur Vorschlag, kein Gerät', !isset($dev[113]) && count($res['suggestions']) === 1 && $res['suggestions'][0]['vid'] === 1131);
$resNames = BWACHLogik::classify($vars, ['excludedModules' => BWACHLogik::DEFAULT_EXCLUDED_MODULES, 'nameSearch' => true, 'manual' => []]);
check('Mit „Auch nach Namen suchen“ kommt der Dummy dazu', isset($resNames['devices'][113]) && count($resNames['suggestions']) === 0);
check('Insgesamt 11 Geräte (A–G mit zwei Bodenfeuchtesensoren, I, J, L)', count($dev) === 11, 'gefunden: ' . count($dev) . ' (' . implode(',', array_keys($dev)) . ')');
$resMan = BWACHLogik::classify($vars, ['excludedModules' => [], 'nameSearch' => false, 'manual' => [['vid' => 1131, 'kind' => 'percent']]]);
check('Manuell ergänzte Variable wird aufgenommen (auch ohne Muster)', isset($resMan['devices'][113]) && $resMan['devices'][113]['signals']['percent'][0]['basis'] === 'manuell');
check('Leere Ausschlussliste lässt Modulausschluss entfallen', isset($resMan['devices']['109-1091']) && isset($resMan['devices']['109-1092']));

// ===========================================================================
heading('3 Bewertung');
// ===========================================================================
$P = ['critical' => false, 'group' => 'standard', 'ignoreAge' => false, 'lowPct' => 20, 'emptyPct' => 5, 'critLowPct' => 30, 'valueOldDays' => 90, 'stillDays' => 7, 'stillDaysEvent' => 30];
$n = $NOW; $d = 86400;
function pctSig(float $v, int $upd, float $scale = 1.0): array { return ['percent' => ['value' => $v, 'updated' => $upd, 'scale' => $scale]]; }
function flagSig(bool $v, int $upd, bool $rev = false): array { return ['flag' => ['value' => $v, 'updated' => $upd, 'reversed' => $rev]]; }
function ev(array $sig, int $life, array $p = [], ?int $now = null): array { global $P, $NOW; return BWACHLogik::evaluate($sig, $life, $now ?? $NOW, array_merge($P, $p)); }

$r = ev(pctSig(100, $n - $d), $n - 60);
check('100 % frisch, Gerät lebt → ok, Dringlichkeit 0', $r['status'] === 'ok' && $r['urgency'] === 0 && $r['funk'] === 'aktiv' && $r['quality'] === []);
check('Grenze schwach: 20 % ist ok, 19 % ist schwach', ev(pctSig(20, $n), $n)['status'] === 'ok' && ev(pctSig(19, $n), $n)['status'] === 'schwach');
check('Grenze leer: 5 % ist leer, 6 % ist schwach', ev(pctSig(5, $n), $n)['status'] === 'leer' && ev(pctSig(6, $n), $n)['status'] === 'schwach');
check('Kritisch: 25 % schwach (Schwelle 30), nicht kritisch ok', ev(pctSig(25, $n), $n, ['critical' => true])['status'] === 'schwach' && ev(pctSig(25, $n), $n)['status'] === 'ok');
check('Kritisch erhöht die Dringlichkeit um 100', ev(pctSig(25, $n), $n, ['critical' => true])['urgency'] === 800 && ev(pctSig(19, $n), $n)['urgency'] === 700);
check('leer ist dringender als schwach', ev(pctSig(2, $n), $n)['urgency'] > ev(pctSig(15, $n), $n)['urgency']);
check('Flag „schwach“ (true) → schwach', ev(flagSig(true, $n), $n)['status'] === 'schwach');
check('Flag false → ok', ev(flagSig(false, $n), $n)['status'] === 'ok');
check('Umgekehrtes Flag: true = ok, false = schwach', ev(flagSig(true, $n, true), $n)['status'] === 'ok' && ev(flagSig(false, $n, true), $n)['status'] === 'schwach');
$r = ev(pctSig(-20, $n - $d), $n - 60);
check('−20 %: unplausibel, Status unbekannt, Grund genannt', $r['status'] === 'unbekannt' && in_array('unplausibel', $r['quality'], true) && strpos(implode(' ', $r['reasons']), '-20') !== false, S(implode(' | ', $r['reasons'])));
check('130 % ebenfalls unplausibel', in_array('unplausibel', ev(pctSig(130, $n), $n)['quality'], true));
$r = ev(pctSig(82, ts('2024-01-07 07:22')), $n - 60);
check('Drei Jahre alter Wert: Status ok, aber „veraltet“ (Dringlichkeit 300)', $r['status'] === 'ok' && in_array('veraltet', $r['quality'], true) && $r['urgency'] === 300);
check('Ohne Altersprüfung: nicht veraltet', !in_array('veraltet', ev(pctSig(82, ts('2024-01-07 07:22')), $n - 60, ['ignoreAge' => true])['quality'], true));
check('Alter genau an der Schwelle (90 Tage) noch nicht veraltet, 91 Tage veraltet', !in_array('veraltet', ev(pctSig(50, $n - 90 * $d), $n)['quality'], true) && in_array('veraltet', ev(pctSig(50, $n - 91 * $d), $n)['quality'], true));
// Funkstille
$r = ev(pctSig(100, ts('2026-09-20 19:37')), ts('2026-09-21 01:23'));
check('Funkstille 16 Tage (Standard 7): still, Dringlichkeit 600', $r['funk'] === 'still' && $r['urgency'] === 600 && $r['status'] === 'ok' && strpos(implode(' ', $r['reasons']), 'seit 16 Tagen kein Lebenszeichen') !== false);
check('Funkstille Grenze: 7 Tage aktiv, 8 Tage still', ev(pctSig(100, $n), $n - 7 * $d)['funk'] === 'aktiv' && ev(pctSig(100, $n), $n - 8 * $d)['funk'] === 'still');
check('Ereignismelder: 20 Tage still-frei, 31 Tage still', ev(pctSig(100, $n), $n - 20 * $d, ['group' => 'ereignis'])['funk'] === 'aktiv' && ev(pctSig(100, $n), $n - 31 * $d, ['group' => 'ereignis'])['funk'] === 'still');
check('Kein Lebenszeichen bekannt → „unbekannt“, nie „still“', ev(pctSig(100, $n), 0)['funk'] === 'unbekannt');
check('Leere Batterie UND Funkstille: beides sichtbar, leer dominiert', ($r2 = ev(pctSig(2, $n - $d), $n - 30 * $d))['status'] === 'leer' && $r2['funk'] === 'still' && $r2['urgency'] === 1000);
// Widerspruch
$r = ev(array_merge(pctSig(100, ts('2026-10-06 10:00')), flagSig(true, ts('2026-09-19 01:06'))), $n - 300);
check('Widerspruch Flag „schwach“ / 100 %: erkannt, neueres Signal (Prozent) entscheidet → ok', in_array('widerspruch', $r['quality'], true) && $r['status'] === 'ok' && $r['urgency'] === 500, implode(' | ', $r['reasons']));
$r = ev(array_merge(pctSig(100, ts('2026-09-01 10:00')), flagSig(true, ts('2026-10-06 01:06'))), $n - 300);
check('Widerspruch, Flag neuer → Flag entscheidet → schwach', in_array('widerspruch', $r['quality'], true) && $r['status'] === 'schwach');
$r = ev(array_merge(pctSig(100, ts('2026-10-06 10:00')), flagSig(true, ts('2026-09-19 01:06'))), $n - 300, ['critical' => true]);
check('Widerspruch bei kritischem Gerät: die schlechtere Aussage gilt', $r['status'] === 'schwach');
$r = ev(array_merge(pctSig(80, $n - 3600), flagSig(false, $n - 3600)), $n - 60);
check('Übereinstimmung Flag/Prozent: kein Widerspruch', !in_array('widerspruch', $r['quality'], true) && $r['status'] === 'ok');
$r = ev(array_merge(pctSig(15, $n - 3600), flagSig(false, $n - 3600)), $n - 60);
check('15 % und Flag „in Ordnung“: schwach, kein Widerspruch (Flag schlägt erst bei Schwelle an)', $r['status'] === 'schwach' && !in_array('widerspruch', $r['quality'], true));
$r = ev(array_merge(pctSig(3, $n - 3600), flagSig(false, $n - 7200)), $n - 60);
check('3 % aber Flag „in Ordnung“: Widerspruch', in_array('widerspruch', $r['quality'], true));
$r = ev(array_merge(pctSig(20, $n - 3600), flagSig(true, $n - 7200)), $n - 60);
check('Widerspruch genau an der Grenze: 20 % und Flag „schwach“', in_array('widerspruch', $r['quality'], true));
$r = ev(array_merge(pctSig(5, $n - 3600), flagSig(false, $n - 7200)), $n - 60);
check('Widerspruch genau an der Grenze: 5 % und Flag „in Ordnung“', in_array('widerspruch', $r['quality'], true));
// nur Spannung / nichts
$r = ev(['voltage' => ['value' => 4.71, 'updated' => $n - 60]], $n - 60);
check('Nur Spannung: Status unbekannt, ehrlicher Hinweis', $r['status'] === 'unbekannt' && in_array('nur_spannung', $r['quality'], true) && $r['voltage'] === 4.71);
$r = ev([], $n - 60);
check('Kein Signal: unbekannt, Hinweis, Dringlichkeit 200', $r['status'] === 'unbekannt' && $r['reasons'] !== [] && $r['urgency'] === 200);
check('Matter-Halbprozent: 150 → 75 %, 30 → 15 % schwach', ev(pctSig(150, $n, 0.5), $n)['percent'] === 75.0 && ev(pctSig(30, $n, 0.5), $n)['status'] === 'schwach');
check('Wert zum Zeitpunkt „nie aktualisiert“ (0): kein Alter, nicht veraltet', ev(pctSig(50, 0), $n)['valueAge'] === null && !in_array('veraltet', ev(pctSig(50, 0), $n)['quality'], true));
// summarize
$sum = BWACHLogik::summarize([ev(pctSig(2, $n), $n), ev(pctSig(15, $n), $n), ev(pctSig(100, $n), $n - 30 * $d), ev(pctSig(100, $n - 200 * $d), $n), ev(pctSig(100, $n), $n)]);
check('Kennzahlen: 5 gesamt, 1 leer, 1 schwach, 1 Funkstille, 1 prüfen, 1 ok', $sum === ['total' => 5, 'empty' => 1, 'low' => 1, 'silent' => 1, 'unknown' => 0, 'check' => 1, 'ok' => 1], json_encode($sum));

// ===========================================================================
heading('4 Modul gegen simuliertes IPS');
// ===========================================================================
clock('2026-10-07 17:30');
buildWorld($GLOBALS['CLOCK']);
$m = new BWTest();
$m->Create();
$m->ApplyChanges();
$m->props['DeviceSettings'] = json_encode([['Instance' => 114, 'Group' => 'standard', 'Critical' => true, 'IgnoreAge' => false, 'Excluded' => false]]);
$m->ApplyChanges();

$val = function (string $ident) use ($m) { return $m->GetValue($ident); };
check('Status 102 gesetzt', $m->status === 102);
check('Nach ApplyChanges ist bereits gesucht und geprüft', $m->ReadAttributeInteger('LastDiscoveryTs') > 0 && $m->ReadAttributeInteger('LastCheckTs') > 0);
check('Geräte überwacht: 11', $val('Total') === 11, (string)$val('Total'));
check('Batterie leer: 0', $val('Empty') === 0);
check('Batterie schwach: 1 (kritischer Rauchmelder mit 25 %)', $val('Low') === 1, (string)$val('Low'));
check('Funkstille: 2 (Sensor Heizung und das ausgebaute Thermostat, dessen Sollwert nur Symcon schreibt)', $val('Silent') === 2, (string)$val('Silent'));
check('Daten prüfen: 4 (Thermostat veraltet, Widerspruch, −20 %, Staubsauger veraltet)', $val('Check') === 4, (string)$val('Check'));
check('Status unbekannt: 1 (−20 %)', $val('Unknown') === 1, (string)$val('Unknown'));

$all = $val('TableAll'); $prob = $val('TableProblems');
check('Tabelle „Alle“ enthält alle 11 Geräte', substr_count($all, '<tr><td>') === 11, (string)substr_count($all, '<tr><td>'));
check('Tabelle „Handlungsbedarf“ enthält keine Geräte ohne Befund', strpos($prob, 'Sensor Schlafzimmer') === false && strpos($prob, 'Sensor Heizung') !== false);
$firstRow = preg_match('/<tr><td>([^<]*)</', $prob, $mm) ? $mm[1] : '';
check('Dringlichste Zeile zuerst (kritischer Rauchmelder, schwach)', strpos($firstRow, 'Rauchmelder Heizung') === 0, $firstRow);
check('Kritisches Gerät trägt ❗', strpos($prob, 'Rauchmelder Heizung ❗') !== false);
check('Umlaute bleiben echte Umlaute (kein Mojibake)', strpos($prob, 'Ã') === false && strpos($m->GetValue('StatusLine'), 'Ã') === false);
check('Ort (übergeordnetes Objekt) in der Tabelle', strpos($all, '<td>Thermostate</td>') !== false);
check('Befund nennt Dauer in Tagen', strpos($prob, 'seit 16 Tagen') !== false || strpos($prob, 'seit 16 Tage ') !== false);
$statusLine = $val('StatusLine');
check('Zusammenfassung nennt Anzahl und Zeitstempel TT.MM.JJJJ', strpos($statusLine, '11 Geräte überwacht') !== false && strpos($statusLine, '07.10.2026 17:30') !== false, $statusLine);
check('Kein Datum im Format JJJJ-MM-TT in sichtbaren Texten', !preg_match('/\d{4}-\d{2}-\d{2}/', $statusLine . $all . $prob . $m->Preview()));

// HTML-Escaping
$GLOBALS['OBJ'][101]['name'] = 'Sensor <b>"X"</b> & Co';
$m->Check();
check('Gerätename wird HTML-maskiert', strpos($val('TableAll'), '<b>"X"</b>') === false && strpos($val('TableAll'), '&lt;b&gt;') !== false);
$GLOBALS['OBJ'][101]['name'] = 'Sensor Schlafzimmer';

// Nachrichten
$msgs = $m->GetMessageList();
$expected = [1011, 1012, 1021, 1031, 1041, 1042, 1051, 1061, 1062, 1071, 1072, 1111, 1121, 1141];
$missing = array_diff($expected, array_keys($msgs));
check('VM_UPDATE auf alle Batterievariablen angemeldet', $missing === [], 'fehlt: ' . implode(',', $missing));
check('Keine Anmeldung auf Heimspeicher oder Sammelwert', !isset($msgs[1091]) && !isset($msgs[1081]) && !isset($msgs[1092]));
$m->timers['Debounce'] = 0;
$m->MessageSink($GLOBALS['CLOCK'], 1011, VM_UPDATE, [50, true, 100, 0]);
check('Batteriemeldung startet das Sammelfenster (5 s)', $m->timers['Debounce'] === 5000);
$m->MessageSink($GLOBALS['CLOCK'], 1011, VM_UPDATE, [50, true, 100, 0]);
check('Zweite Meldung startet kein zweites Fenster (Timer bleibt 5000)', $m->timers['Debounce'] === 5000);
$GLOBALS['OBJ'][1011]['var']['value'] = 4;
$m->Debounced();
check('Nach dem Sammelfenster: Timer aus, neuer Wert bewertet (4 % → leer)', $m->timers['Debounce'] === 0 && $val('Empty') === 1, 'Empty=' . $val('Empty'));
$GLOBALS['OBJ'][1011]['var']['value'] = 100;
$m->Check();
check('Wert zurück auf 100 % → leer wieder 0', $val('Empty') === 0);

// Eigenständigkeit: Variable verschwindet
unset($GLOBALS['OBJ'][1051]);
$ok = true; try { $m->Check(); } catch (\Throwable $e) { $ok = false; echo '    ' . $e->getMessage() . "\n"; }
check('Gelöschte Batterievariable bringt die Prüfung nicht zum Absturz', $ok);
$s = $m->Search();
check('Neue Suche meldet danach nur noch vorhandene Variablen', !isset($m->GetMessageList()[1051]));
// ganze Instanz verschwindet
unset($GLOBALS['OBJ'][1121], $GLOBALS['OBJ'][1122], $GLOBALS['OBJ'][112]);
$ok = true; try { $m->Check(); } catch (\Throwable $e) { $ok = false; echo '    ' . $e->getMessage() . "\n"; }
check('Gelöschte Geräteinstanz bringt die Prüfung nicht zum Absturz', $ok);

// Ausnehmen
buildWorld($GLOBALS['CLOCK']);
$m2 = new BWTest(); $m2->Create();
$m2->props['DeviceSettings'] = json_encode([['Instance' => 105, 'Group' => 'standard', 'Critical' => false, 'IgnoreAge' => false, 'Excluded' => true]]);
$m2->ApplyChanges();
check('Ausgenommenes Gerät wird nicht mitgezählt (9 statt 11, beide Bodenfeuchtesensoren der Instanz)', $m2->GetValue('Total') === 9, (string)$m2->GetValue('Total'));

// Ereignismelder-Gruppe
buildWorld($GLOBALS['CLOCK']);
$m3 = new BWTest(); $m3->Create();
$m3->props['DeviceSettings'] = json_encode([['Instance' => 103, 'Group' => 'ereignis', 'Critical' => false, 'IgnoreAge' => false, 'Excluded' => false]]);
$m3->ApplyChanges();
check('Ereignismelder-Gruppe: 16 Tage ohne Lebenszeichen sind keine Funkstille (nur das ausgebaute Thermostat bleibt still)', $m3->GetValue('Silent') === 1, (string)$m3->GetValue('Silent'));

// Resync (Modulverwaltung löscht Attribute)
buildWorld($GLOBALS['CLOCK']);
$m4 = new BWTest(); $m4->Create(); $m4->ApplyChanges();
$m4->simulateResync();
check('Nach MC_DeleteModule/CreateModule (Attribute weg) sucht das Modul selbst neu', $m4->GetValue('Total') === 11 && $m4->ReadAttributeInteger('LastDiscoveryTs') > 0);

// Tick-Logik
check('Tick-Intervall = Prüfintervall (60 min)', $m4->timers['Tick'] === 3600000);
$last = $m4->ReadAttributeInteger('LastDiscoveryTs');
$GLOBALS['CLOCK'] += 3600; $m4->Tick();
check('Stündlicher Tick bewertet nur, sucht nicht neu', $m4->ReadAttributeInteger('LastDiscoveryTs') === $last);
$GLOBALS['CLOCK'] += 86400; $m4->Tick();
check('Nach über 24 Stunden sucht der Tick neu', $m4->ReadAttributeInteger('LastDiscoveryTs') > $last);

// Suche ohne Treffer
$GLOBALS['OBJ'] = []; mkinst(12345, 'Batteriewächter', 'Batteriewaechter');
$m5 = new BWTest(); $m5->Create(); $m5->ApplyChanges();
check('Leere Anlage: ehrliche Meldung statt Absturz', strpos($m5->Search(), '0 Geräte gefunden') !== false && $m5->GetValue('Total') === 0);
check('Leere Anlage: Hinweis auf manuelles Ergänzen', strpos($m5->Search(), 'Weitere Variablen') !== false);

// Neue Darstellungen (Presentation) tragen leere Profilfelder (SUITE Stolperstein 21)
$GLOBALS['OBJ'] = []; mkinst(12345, 'Batteriewächter', 'Batteriewaechter');
mkinst(130, 'Neues Gerät', 'Z-Wave Module', 0);
mkvar(1301, 130, 'irgendwas', 'Zustand', 1, 77, $GLOBALS['CLOCK'] - 60, '', ['VariableCustomPresentation' => ['PROFILE' => '~Battery.100', 'PRESENTATION' => '{GUID}']]);
mkinst(131, 'Gerät mit Suffix-Darstellung', 'Z-Wave Module', 0);
mkvar(1311, 131, 'watt', 'Leistung', 1, 5, $GLOBALS['CLOCK'] - 60, '', ['VariableCustomPresentation' => ['SUFFIX' => ' W', 'PRESENTATION' => '{GUID}']]);
$m8 = new BWTest(); $m8->Create(); $m8->ApplyChanges();
check('Profil nur in der Darstellung (leere Profilfelder) wird gefunden', $m8->GetValue('Total') === 1 && strpos($m8->GetValue('TableAll'), '77 %') !== false);

// Preview
buildWorld($GLOBALS['CLOCK']);
$m6 = new BWTest(); $m6->Create(); $m6->ApplyChanges();
$pv = $m6->Preview();
check('Trockenlauf nennt den Geräteschlüssel (für BWACH_Acknowledge)', strpos($pv, 'Schlüssel 114') !== false && strpos($pv, 'Schlüssel 105-1051') !== false);
check('Trockenlauf nennt Geräte, Ausschlüsse mit Grund und Namensvorschläge', strpos($pv, 'GERÄTE (11)') !== false && strpos($pv, 'AUSGESCHLOSSEN') !== false && strpos($pv, 'Sammelwert über mehrere Geräte') !== false && strpos($pv, 'NUR NACH NAMEN VORGESCHLAGEN (1)') !== false);
$GLOBALS['OBJ'] = []; mkinst(12345, 'Batteriewächter', 'Batteriewaechter');
mkinst(120, 'Matter Sensor', 'Matter Device', 0);
mkvar(1201, 120, 'BatPercentRemaining', 'Batterie', 1, 150, $GLOBALS['CLOCK'] - 60);
$m7 = new BWTest(); $m7->Create(); $m7->ApplyChanges();
check('Trockenlauf: Matter-Prozentwert ist live bestätigt und nicht mehr als ungetestet gekennzeichnet', strpos($m7->Preview(), 'BatPercentRemaining') === false && strpos($m7->Preview(), 'ungetestet') === false);
check('Matter 150 (Halbprozent) wird als 75 % bewertet', strpos($m7->GetValue('TableAll'), '75 %') !== false);

// ===========================================================================
heading('5 Formular- und Dateihygiene');
// ===========================================================================
$form = json_decode($m6->GetConfigurationForm(), true);
check('Formular ist gültiges JSON', is_array($form) && isset($form['elements']));
$caps = array_map(function ($e) { return $e['caption']; }, $form['elements']);
$order = ['👋  Wozu dieses Modul?', '🆕  Neu bis Version 0.6.0', '📖  Dokumentation & Hilfe', '🔎  Gefundene Geräte', '🔋  Zustand', '🔔  Meldungen', '✅  Quittieren und Batterietagebuch', '🧮  Prognose und Einkauf', '⚙️  Schwellen', '🏷️  Geräte-Einstellungen', '➕  Weitere Variablen', '💬  Rückmeldungen', '🧡  Über dieses Modul'];
check('Panel-Reihenfolge nach Verbund-Konvention (Zweck → Neu → Doku → Fachpanels → Forum → Lizenz)', $caps === $order, implode(' | ', $caps));
check('Zweck-, Neu- und Doku-Panel stehen in der richtigen Aufklapp-Lage', $form['elements'][0]['expanded'] === true && $form['elements'][1]['expanded'] === true && $form['elements'][2]['expanded'] === false);
check('Lizenz-Panel nicht wegklickbar (kein name) und eingeklappt', !isset(end($form['elements'])['name']) && end($form['elements'])['expanded'] === false);
$json = json_encode($form, JSON_UNESCAPED_UNICODE);
check('Jeder Button hat eine sichtbare Rückmeldung (echo oder Ack/Update)', (function () use ($form) {
    $bad = [];
    $walk = function ($items) use (&$walk, &$bad) {
        foreach ($items as $it) {
            if (($it['type'] ?? '') === 'Button' && !preg_match('/^(echo |BWACH_Ack)/', $it['onClick'])) { $bad[] = $it['caption']; }
            foreach (['items', 'popup'] as $k) { if (isset($it[$k])) { $walk($k === 'popup' ? ($it[$k]['items'] ?? []) : $it[$k]); } }
        }
    };
    $walk($form['elements']);
    return $bad === [] ? true : implode(',', $bad);
})() === true);
check('Link-Buttons: URL in echo, link=true (kein URL-String in link)', strpos($json, '"link":true') !== false && strpos($json, '"link":"http') === false);
check('Statuszeilen-Labels tragen Namen für UpdateFormField', strpos($json, '"name":"DiscoveryStatus"') !== false && strpos($json, '"name":"CheckStatus"') !== false);
$m6->fieldUpdates = [];
$m6->Search();
$upd = array_column($m6->fieldUpdates, 0);
check('Suche aktualisiert Kopfzeile UND Zustandszeile gemeinsam', in_array('DiscoveryStatus', $upd, true) && in_array('CheckStatus', $upd, true));
check('Kopfzeile im Muster „✅ N Geräte gefunden (zuletzt HH:MM:SS Uhr).“', (bool)preg_match('/✅ 11 Geräte gefunden \(zuletzt \d\d:\d\d:\d\d Uhr\)\./u', json_encode($form, JSON_UNESCAPED_UNICODE)));
$m6->AckNews();
check('„Verstanden“ speichert die installierte Version und blendet das Panel aus', $m6->ReadAttributeString('SeenNews') === '0.6.0' && in_array(['NewsPanel', 'visible', false], $m6->fieldUpdates, true));
$form2 = json_decode($m6->GetConfigurationForm(), true);
check('News-Panel erscheint danach nicht mehr', !in_array('🆕  Neu bis Version 0.6.0', array_map(function ($e) { return $e['caption']; }, $form2['elements']), true));
$m6->AckPurposeIntro(); $m6->AckForumHint();
check('Zweck- und Forum-Hinweis einmalig wegklickbar', count(json_decode($m6->GetConfigurationForm(), true)['elements']) === 10);
check('Listen: jede Spalte hat eine edit-Definition (kein Verlust beim Speichern)', (function () use ($form) {
    foreach ($form['elements'] as $p) { foreach ($p['items'] ?? [] as $it) { if (($it['type'] ?? '') === 'List') { foreach ($it['columns'] as $c) { if (!isset($c['edit']) && empty($c['save'])) { return false; } } } } }
    return true;
})());
$captions = [];
array_walk_recursive($form, function ($v, $k) use (&$captions) { if ($k === 'caption' && is_string($v)) { $captions[] = $v; } });
check('Keine vermeidbaren Anglizismen in sichtbaren Formulartexten (Warning, Alert, Notification, Dashboard)', !preg_match('/(Warning|Alert|Notification|Dashboard)/', implode(' ', $captions)));

// Dateien
$files = array_merge(glob($ROOT . '/*.json'), glob($MODDIR . '/*.json'));
$badEnc = [];
foreach ($files as $f) {
    $c = file_get_contents($f);
    if (substr($c, 0, 3) === "\xEF\xBB\xBF" || !mb_check_encoding($c, 'UTF-8') || json_decode($c) === null) { $badEnc[] = basename($f); }
}
check('Alle JSON-Dateien: UTF-8 ohne BOM und gültig (Lehre aus BY_BatterieMonitor)', $badEnc === [], implode(',', $badEnc));
foreach (glob($MODDIR . '/*.php') as $f) {
    check(basename($f) . ': UTF-8, kein BOM', mb_check_encoding(file_get_contents($f), 'UTF-8') && substr(file_get_contents($f), 0, 3) !== "\xEF\xBB\xBF");
}
$lib = json_decode(file_get_contents($ROOT . '/library.json'), true);
check('library.json: nur erlaubte Schlüssel (id, author, name, url, compatibility, version, build, date)', array_diff(array_keys($lib), ['id', 'author', 'name', 'url', 'compatibility', 'version', 'build', 'date']) === [] && isset($lib['compatibility']['version']) && !isset($lib['compatibility']['minimum']));
check('Bibliotheksname „DG65 Toolkit Batteriewächter“ ohne Zusatz', $lib['name'] === 'DG65 Toolkit Batteriewächter');
$mod = json_decode(file_get_contents($MODDIR . '/module.json'), true);
check('module.json: Präfix BWACH, vendor leer, Bibliothek passt', $mod['prefix'] === 'BWACH' && $mod['vendor'] === '' && $mod['library'] === $lib['id']);
$php = file_get_contents($MODDIR . '/module.php') . file_get_contents($MODDIR . '/BWACHLogik.php') . file_get_contents($MODDIR . '/BWACHMeldung.php');
check('Kein @ vor IPS-Funktionen; erlaubt nur bei IPS_GetLibrary und IPS_GetObjectIDByIdent (erwartbarer Fehlschlag)', preg_match_all('/@IPS_(?!GetLibrary|GetObjectIDByIdent)/', $php) === 0);
check('Kein count() auf Rückgaben ohne Array-Absicherung (Vorgänger-Fehler): count nur auf eigene Arrays', preg_match_all('/count\(\s*IPS_/', $php) === 0);
check('Datumsausgabe nur TT.MM.JJJJ (kein Y-m-d in date())', preg_match('/date\(\s*\'Y-m-d/', $php) === 0);
check('Keine PHP-Standardwerte an öffentlichen Funktionen (SUITE Stolperstein 8)', (function () use ($php) {
    preg_match_all('/public function (\w+)\(([^)]*)\)/', $php, $mm);
    foreach ($mm[2] as $args) { if (strpos($args, '=') !== false) { return false; } }
    return true;
})());
check('Alle öffentlichen Funktionen mit Parametern sind typisiert (SUITE Stolperstein 20)', (function () use ($php) {
    preg_match_all('/public function \w+\(([^)]*)\)/', $php, $mm);
    foreach ($mm[1] as $args) { if (trim($args) === '') { continue; } foreach (explode(',', $args) as $a) { if (!preg_match('/^\s*(int|string|bool|float|array)\s+\$/', $a) && strpos($a, '$') !== false && !preg_match('/\$(TimeStamp|SenderID|Message|Data|Ident|Value)\b/', $a)) { return false; } } }
    return true;
})());


// ===========================================================================
heading('6 Meldungslogik (rein)');
// ===========================================================================
$base = ts('2026-10-07 10:00');   // Mittwoch, außerhalb der Ruhezeit
$D = 86400;
$P0 = ['remDays' => 7, 'critRemDays' => 2, 'escHours' => 24, 'clearHours' => 24, 'quiet' => false, 'ignoreQuiet' => true];
$cur = function (array $items) { $o = []; foreach ($items as $k => [$pr, $crit]) { $o[$k] = ['probs' => $pr, 'critical' => $crit]; } return $o; };

$r = BWACHMeldung::decide([], $cur(['a' => [['leer'], false]]), $base, $P0);
check('Erster Befund → eine „neu“-Meldung', $r['events']['neu'] === ['a'] && $r['events']['erinnerung'] === []);
$s = $r['state'];
$r = BWACHMeldung::decide($s, $cur(['a' => [['leer'], false]]), $base + 3600, $P0);
check('Zweite Prüfung kurz danach: keine Wiederholung', $r['events'] === ['neu' => [], 'erinnerung' => [], 'eskalation' => []]);
$r = BWACHMeldung::decide($s, $cur(['a' => [['leer'], false]]), $base + 7 * $D - 1, $P0);
check('Einen Tag vor Ablauf der 7 Tage: noch keine Erinnerung', $r['events']['erinnerung'] === []);
$r = BWACHMeldung::decide($s, $cur(['a' => [['leer'], false]]), $base + 7 * $D, $P0);
check('Nach genau 7 Tagen: Erinnerung', $r['events']['erinnerung'] === ['a']);
$s2 = $r['state'];
$r = BWACHMeldung::decide($s2, $cur(['a' => [['leer'], false]]), $base + 8 * $D, $P0);
check('Nach der Erinnerung wieder Ruhe', $r['events']['erinnerung'] === []);
// kritisch
$r = BWACHMeldung::decide([], $cur(['k' => [['schwach'], true]]), $base, $P0); $sk = $r['state'];
check('Kritisches Gerät: Erinnerung schon nach 2 Tagen', BWACHMeldung::decide($sk, $cur(['k' => [['schwach'], true]]), $base + 2 * $D, $P0)['events']['erinnerung'] === ['k']
    && BWACHMeldung::decide($sk, $cur(['k' => [['schwach'], true]]), $base + 2 * $D - 1, $P0)['events']['erinnerung'] === []);
// Eskalation
$r = BWACHMeldung::decide($sk, $cur(['k' => [['schwach'], true]]), $base + 24 * 3600 - 1, $P0);
check('Eskalation: vor 24 Stunden noch nicht', $r['events']['eskalation'] === []);
$r = BWACHMeldung::decide($sk, $cur(['k' => [['schwach'], true]]), $base + 24 * 3600, $P0);
check('Eskalation: nach 24 Stunden genau einmal', $r['events']['eskalation'] === ['k']);
$r2 = BWACHMeldung::decide($r['state'], $cur(['k' => [['schwach'], true]]), $base + 25 * 3600, $P0);
check('Eskalation wiederholt sich nicht', $r2['events']['eskalation'] === []);
check('Nicht kritische Geräte eskalieren nie', BWACHMeldung::decide($s, $cur(['a' => [['leer'], false]]), $base + 30 * $D, $P0)['events']['eskalation'] === []);
check('Eskalation 0 = aus', BWACHMeldung::decide($sk, $cur(['k' => [['schwach'], true]]), $base + 5 * 3600, array_merge($P0, ['escHours' => 0]))['events']['eskalation'] === []);
// neuer, schlimmerer Befund
$r = BWACHMeldung::decide($s, $cur(['a' => [['leer', 'still'], false]]), $base + 3600, $P0);
check('Neuer Befund (zusätzlich „still“) ist wieder eine erste Meldung', $r['events']['neu'] === ['a']);
$s3 = BWACHMeldung::decide([], $cur(['a' => [['leer', 'still'], false]]), $base, $P0)['state'];
$r = BWACHMeldung::decide($s3, $cur(['a' => [['still'], false]]), $base + 3600, $P0);
check('Befund wird kleiner (leer+still → still): keine neue Meldung', $r['events']['neu'] === []);
// Ruhezeit
$Q = array_merge($P0, ['quiet' => true]);
$r = BWACHMeldung::decide([], $cur(['a' => [['leer'], false], 'k' => [['leer'], true]]), $base, $Q);
check('Ruhezeit: normales Gerät wird zurückgehalten, kritisches durchbricht', $r['events']['neu'] === ['k']);
$r2 = BWACHMeldung::decide($r['state'], $cur(['a' => [['leer'], false], 'k' => [['leer'], true]]), $base + 3600, $P0);
check('Nach der Ruhezeit wird die zurückgehaltene Meldung nachgeholt (nur sie)', $r2['events']['neu'] === ['a']);
$r = BWACHMeldung::decide([], $cur(['k' => [['leer'], true]]), $base, array_merge($Q, ['ignoreQuiet' => false]));
check('Ruhezeit ohne Durchbruch-Erlaubnis hält auch kritische zurück', $r['events']['neu'] === []);
check('Ruhezeit 22–7: 23 Uhr und 3 Uhr ruhig, 7 und 21 Uhr nicht', BWACHMeldung::isQuiet(ts('2026-10-07 23:00'), true, 22, 7) && BWACHMeldung::isQuiet(ts('2026-10-08 03:00'), true, 22, 7)
    && !BWACHMeldung::isQuiet(ts('2026-10-08 07:00'), true, 22, 7) && !BWACHMeldung::isQuiet(ts('2026-10-07 21:59'), true, 22, 7));
check('Ruhezeit 22 Uhr einschließlich: 22:00 ruhig', BWACHMeldung::isQuiet(ts('2026-10-07 22:00'), true, 22, 7));
check('Ruhezeit mit gleicher Start- und Endstunde gilt als aus', !BWACHMeldung::isQuiet(ts('2026-10-07 05:30'), true, 5, 5) && !BWACHMeldung::isQuiet(ts('2026-10-07 12:00'), true, 0, 0));
check('Ruhezeit am selben Tag (13–15): 14 Uhr ruhig, 15 Uhr nicht; aus = nie', BWACHMeldung::isQuiet(ts('2026-10-07 14:00'), true, 13, 15) && !BWACHMeldung::isQuiet(ts('2026-10-07 15:00'), true, 13, 15) && !BWACHMeldung::isQuiet(ts('2026-10-07 23:00'), false, 22, 7));
// Wackelwerte
$cleared = BWACHMeldung::decide($s, [], $base + 3600, $P0);
check('Befund weg: Zustand bleibt in der Karenzzeit erhalten', isset($cleared['state']['a']) && $cleared['state']['a']['gone'] === $base + 3600);
check('Befund weg: nach 1 weiteren Stunde ist der Zustand immer noch da (Karenz 24 h)', isset(BWACHMeldung::decide($cleared['state'], [], $base + 7200, $P0)['state']['a']));
$back = BWACHMeldung::decide($cleared['state'], $cur(['a' => [['leer'], false]]), $base + 7200, $P0);
check('Befund kommt nach 1 Stunde wieder: KEINE neue Meldung (Wackeln)', $back['events']['neu'] === [] && $back['state']['a']['gone'] === 0);
$gone = BWACHMeldung::decide($cleared['state'], [], $base + 3600 + 24 * 3600, $P0);
check('Nach 24 Stunden ohne Befund wird der Zustand vergessen', !isset($gone['state']['a']));
$again = BWACHMeldung::decide($gone['state'], $cur(['a' => [['leer'], false]]), $base + 3 * $D, $P0);
check('Danach ist ein erneuter Befund wieder eine erste Meldung', $again['events']['neu'] === ['a']);
// Quittieren
$ack = BWACHMeldung::acknowledge($s, 'a', 'zurueckgestellt', $base, 7);
check('Zurückstellen: 7 Tage still, danach Erinnerung', BWACHMeldung::decide($ack, $cur(['a' => [['leer'], false]]), $base + 7 * $D - 1, $P0)['events']['erinnerung'] === []
    && BWACHMeldung::decide($ack, $cur(['a' => [['leer'], false]]), $base + 7 * $D, $P0)['events']['erinnerung'] === ['a']);
$ack = BWACHMeldung::acknowledge($s, 'a', 'getauscht', $base, 7);
check('Getauscht: 3 Tage Wartezeit, kein Alarm sofort', BWACHMeldung::decide($ack, $cur(['a' => [['leer'], false]]), $base + 2 * $D, $P0)['events'] === ['neu' => [], 'erinnerung' => [], 'eskalation' => []]);
check('Getauscht: bleibt der Befund nach 3 Tagen, kommt eine neue Meldung', BWACHMeldung::decide($ack, $cur(['a' => [['leer'], false]]), $base + 3 * $D, $P0)['events']['neu'] === ['a']);
check('Außer Betrieb löscht den Meldezustand', !isset(BWACHMeldung::acknowledge($s, 'a', 'ausser_betrieb', $base, 7)['a']));
check('Quittieren eines unbekannten Geräts legt keinen Dauerzustand an', isset(BWACHMeldung::acknowledge([], 'x', 'zurueckgestellt', $base, 7)['x']));
// Befunde
check('Befunde: leer / schwach / still, Datenqualität löst nichts aus', BWACHMeldung::problems(['status' => 'leer', 'funk' => 'still']) === ['leer', 'still']
    && BWACHMeldung::problems(['status' => 'schwach', 'funk' => 'aktiv']) === ['schwach'] && BWACHMeldung::problems(['status' => 'ok', 'funk' => 'aktiv', 'quality' => ['veraltet']]) === []);
// Wechselerkennung
check('Wechsel: 15 % → 100 % erkannt', BWACHMeldung::detectReplacement(['p' => 15.0, 'f' => null], ['p' => 100.0, 'f' => null], 25) !== null);
check('Wechsel: Sprung genau 25 Punkte erkannt, 24 nicht', BWACHMeldung::detectReplacement(['p' => 40.0, 'f' => null], ['p' => 65.0, 'f' => null], 25) !== null && BWACHMeldung::detectReplacement(['p' => 40.0, 'f' => null], ['p' => 64.0, 'f' => null], 25) === null);
check('Wechsel: Flag „schwach“ → „ok“ erkannt', BWACHMeldung::detectReplacement(['p' => null, 'f' => true], ['p' => null, 'f' => false], 25) !== null);
check('Kein Wechsel: erste Beobachtung, sinkender Wert, Flag false → true', BWACHMeldung::detectReplacement(null, ['p' => 100.0, 'f' => null], 25) === null
    && BWACHMeldung::detectReplacement(['p' => 80.0, 'f' => null], ['p' => 60.0, 'f' => null], 25) === null && BWACHMeldung::detectReplacement(['p' => null, 'f' => false], ['p' => null, 'f' => true], 25) === null);
check('Kein Wechsel bei unplausiblem Wert (null) auf einer Seite', BWACHMeldung::detectReplacement(['p' => null, 'f' => null], ['p' => 100.0, 'f' => null], 25) === null);
// Tagebuch
$dia = BWACHMeldung::diaryAdd([], ['t' => $base, 'key' => 'a', 'name' => 'A', 'type' => 'erkannt', 'note' => 'x']);
$dia = BWACHMeldung::diaryAdd($dia, ['t' => $base + 2 * $D, 'key' => 'a', 'name' => 'A', 'type' => 'manuell', 'note' => 'y']);
check('Tagebuch: zwei Einträge desselben Geräts binnen 3 Tagen sind EIN Wechsel', count($dia) === 1);
$dia = BWACHMeldung::diaryAdd($dia, ['t' => $base + 3 * $D, 'key' => 'a', 'name' => 'A', 'type' => 'manuell', 'note' => 'z']);
check('Tagebuch: nach 3 Tagen ein neuer Eintrag; anderes Gerät immer', count($dia) === 2 && count(BWACHMeldung::diaryAdd($dia, ['t' => $base, 'key' => 'b', 'name' => 'B', 'type' => 'erkannt', 'note' => '']) ) === 3);
$big = []; for ($i = 0; $i < 600; $i++) { $big = BWACHMeldung::diaryAdd($big, ['t' => $base + $i * 10 * $D, 'key' => 'k' . $i, 'name' => 'n', 'type' => 'erkannt', 'note' => '']); }
check('Tagebuch ist auf 500 Einträge begrenzt (die neuesten bleiben)', count($big) === 500 && $big[499]['key'] === 'k599');
// Wochenbericht
$mon = ts('2026-10-12 08:00');
check('Wochenbericht: Montag 08:00 fällig, einmal pro Woche', BWACHMeldung::digestDue($mon, true, 1, 8, '') === '2026-W42' && BWACHMeldung::digestDue($mon, true, 1, 8, '2026-W42') === null);
check('Wochenbericht: Montag 07:59 und Dienstag nicht, aus = nie', BWACHMeldung::digestDue(ts('2026-10-12 07:59'), true, 1, 8, '') === null && BWACHMeldung::digestDue(ts('2026-10-13 09:00'), true, 1, 8, '') === null && BWACHMeldung::digestDue($mon, false, 1, 8, '') === null);
check('Wochenbericht: Montag 11 Uhr (Nachholen nach Neustart) noch fällig', BWACHMeldung::digestDue(ts('2026-10-12 11:00'), true, 1, 8, '2026-W41') === '2026-W42');
// Texte
$items = []; for ($i = 1; $i <= 8; $i++) { $items[] = ['name' => 'Gerät ' . $i, 'place' => 'Flur', 'probs' => ['leer'], 'text' => 'Batterie leer (3 %)', 'critical' => false]; }
$six = BWACHMeldung::message('neu', array_slice($items, 0, 6));
check('Bündelung: genau 6 Geräte → 5 Zeilen und „… und 1 weitere“; genau 5 → ohne', strpos($six['text'], '… und 1 weitere') !== false && strpos(BWACHMeldung::message('neu', array_slice($items, 0, 5))['text'], 'weitere') === false);
$m = BWACHMeldung::message('neu', $items);
check('Bündelung: 8 Geräte → eine Nachricht mit 5 Zeilen + „und 3 weitere“', substr_count($m['text'], '•') === 5 && strpos($m['text'], '… und 3 weitere') !== false && $m['title'] === '🔋 8 Batteriemeldungen');
$m = BWACHMeldung::message('neu', [$items[0]]);
check('Einzelmeldung „leer“: Titel und Zeile', $m['title'] === '🪫 Batterie leer' && strpos($m['text'], 'Gerät 1 (Flur): Batterie leer (3 %)') !== false);
$longName = array_merge($items[0], ['name' => 'Rauchmelder Heizung im Obergeschoss links', 'critical' => true]);
$tooLong = [];
foreach (['neu', 'erinnerung', 'eskalation'] as $kd) { foreach ([[$longName], array_fill(0, 12, $longName)] as $set) { $tt = BWACHMeldung::message($kd, $set)['title']; if (strlen($tt) > 32) { $tooLong[] = $kd . ':' . $tt; } } }
check('Alle Titel passen in 32 Byte (Push-Grenze), auch mit langem Gerätenamen und 12 Geräten', $tooLong === [], implode(' | ', $tooLong));
check('Wochenbericht-Titel passt in 32 Byte', strlen(BWACHMeldung::digest(['total' => 1, 'empty' => 0, 'low' => 0, 'silent' => 0, 'check' => 0], [])['title']) <= 32);
check('Eskalation klingt dringend, kritisch+leer ebenfalls', BWACHMeldung::message('eskalation', [$items[0]])['sound'] === 'alarm' && BWACHMeldung::message('neu', [array_merge($items[0], ['critical' => true])])['sound'] === 'alarm' && BWACHMeldung::message('neu', [$items[0]])['sound'] === 'bell');
check('Erinnerung trägt „Erinnerung“ im Titel', strpos(BWACHMeldung::message('erinnerung', [$items[0]])['title'], 'Erinnerung') !== false);
$tb = BWACHMeldung::truncateBytes('🪫🪫🪫🪫🪫🪫🪫🪫🪫', 32);
check('Byte-Kürzung schneidet nie mitten im Zeichen (8 Emoji = 32 Byte)', strlen($tb) === 32 && mb_check_encoding($tb, 'UTF-8') && mb_strlen($tb) === 8);
check('Byte-Kürzung: Umlaute 31 Byte', mb_check_encoding(BWACHMeldung::truncateBytes(str_repeat('ä', 30), 31), 'UTF-8') && strlen(BWACHMeldung::truncateBytes(str_repeat('ä', 30), 31)) === 30);
$dg = BWACHMeldung::digest(['total' => 11, 'empty' => 1, 'low' => 1, 'silent' => 2, 'check' => 3], [['name' => 'A', 'place' => 'Flur', 'text' => 'leer', 'urgency' => 1000], ['name' => 'B', 'place' => '', 'text' => 'ok', 'urgency' => 0]]);
check('Wochenbericht nennt Kennzahlen und nur Geräte mit Befund', strpos($dg['text'], '11 Geräte überwacht: 1 leer, 1 schwach, 2 Funkstille, 3 mit zweifelhaften Daten.') !== false && strpos($dg['text'], '• A (Flur): leer') !== false && strpos($dg['text'], 'B') === false && $dg['anyProblem']);
check('Wochenbericht ohne Befund: „Alles in Ordnung“', BWACHMeldung::digest(['total' => 2, 'empty' => 0, 'low' => 0, 'silent' => 0, 'check' => 0], [])['anyProblem'] === false);

// ===========================================================================
heading('7 Meldungen im Modul (simulierte Zustellung)');
// ===========================================================================
function shiftWorld(int $secs): void { foreach ($GLOBALS['OBJ'] as $id => $x) { if ($x['type'] === 2 && $x['parent'] !== 12345) { $GLOBALS['OBJ'][$id]['var']['VariableUpdated'] += $secs; } } $GLOBALS['CLOCK'] += $secs; }
function freshModule(array $props = [], bool $sendOk = true): BWTest
{
    clock('2026-10-07 10:00');   // Mittwoch
    buildWorld($GLOBALS['CLOCK']);
    $GLOBALS['SENT'] = []; $GLOBALS['LOG'] = []; $GLOBALS['SEND_OK'] = $sendOk;
    $GLOBALS['INSTS'] = ['{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}' => [701, 702, 703, 704], '{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}' => [705]];
    $m = new BWTest(); $m->Create();
    foreach ($props as $k => $v) { $m->props[$k] = $v; }
    $m->ApplyChanges();
    return $m;
}
function sentTo(string $kind): array { return array_values(array_filter($GLOBALS['SENT'], function ($s) use ($kind) { return $s[0] === $kind; })); }
$crit = json_encode([['Instance' => 114, 'Group' => 'standard', 'Critical' => true, 'IgnoreAge' => false, 'Excluded' => false]]);

$m = freshModule(['NotificationsActive' => false, 'DeviceSettings' => $crit]);
check('Meldungen aus (Standard): nichts verschickt, kein Zustand angelegt', $GLOBALS['SENT'] === [] && $m->GetValue('NotifyState') === '' || $m->GetValue('NotifyState') === '[]' || $m->GetValue('NotifyState') === '');
check('Standard: „Meldungen aktiv“ ist aus', (new BWTest())->props === [] && (function () { $x = new BWTest(); $x->Create(); return $x->props['NotificationsActive'] === false; })());

$m = freshModule(['NotificationsActive' => true, 'DeviceSettings' => $crit]);
$visu = sentTo('visu'); $wfc = sentTo('wfc');
check('Erste Prüfung: EINE gebündelte Nachricht an 4 Kachel- und 1 WebFront-Ziel', count($visu) === 4 && count($wfc) === 1, count($visu) . '/' . count($wfc));
check('Gebündelter Titel „N Batteriemeldungen“', strpos($visu[0][2], 'Batteriemeldungen') !== false, $visu[0][2] ?? '');
check('Titel ≤ 32 Byte, Text ≤ 256 Byte', strlen($visu[0][2]) <= 32 && strlen($visu[0][3]) <= 256);
check('Kritisches leeres Gerät → Ton „alarm“ oder „bell“ gültig', in_array($visu[0][4], ['alarm', 'bell'], true));
check('Text nennt das kritische Gerät mit ❗', strpos($visu[0][3], 'Rauchmelder Heizung') !== false && strpos($visu[0][3], '❗') !== false, $visu[0][3]);
$n = count($GLOBALS['SENT']);
$m->Check(); $m->Check();
check('Weitere Prüfungen am selben Tag: keine Wiederholung', count($GLOBALS['SENT']) === $n);
shiftWorld(2 * 86400); $m->Check();
$after2 = array_slice($GLOBALS['SENT'], $n);
$rem = array_values(array_filter($after2, function ($s) { return $s[0] === 'visu' && strpos($s[2], 'Erinnerung') !== false; }));
check('Nach 2 Tagen: Erinnerung nur für das kritische Gerät, an alle 4 Kachel-Ziele', count($rem) === 4 && substr_count($rem[0][3], '•') === 1 && strpos($rem[0][3], 'Rauchmelder Heizung') !== false, json_encode($rem[0] ?? null, JSON_UNESCAPED_UNICODE));
check('Nach 2 Tagen zusätzlich die Eskalation (kritisch, 24 h unbeachtet), Titel „Unbeachtet“', count(array_filter($after2, function ($s) { return $s[0] === 'visu' && strpos($s[2], 'Unbeachtet') !== false; })) === 4);
$n2 = count($GLOBALS['SENT']);
shiftWorld(5 * 86400); $m->Check();
check('Nach 7 Tagen: Erinnerung für die übrigen Geräte (gebündelt)', count($GLOBALS['SENT']) > $n2);

// Eskalation per E-Mail (nur kritisch, nur Eskalationsweg)
$m = freshModule(['NotificationsActive' => true, 'DeviceSettings' => $crit, 'NotifyPush' => true, 'NotifyMail' => false, 'EscalatePush' => false, 'EscalateMail' => true, 'MailInstance' => 14223, 'MailTo' => 'a@example.org; b@example.org']);
mkinst(14223, 'SMTP', 'SMTP');
$GLOBALS['SENT'] = [];
shiftWorld(25 * 3600); $m->Check();
$mails = sentTo('mailex');
check('Eskalation nach 24 h über den Eskalationsweg: E-Mail an beide Adressen', count($mails) === 2 && $mails[0][4] === 'a@example.org' && $mails[1][4] === 'b@example.org', json_encode(array_column($mails, 4)));
check('Eskalations-Mail: Betreff „Unbeachtet“, Text als HTML maskiert', strpos($mails[0][2], 'Unbeachtet') !== false && strpos($mails[0][3], 'Rauchmelder Heizung') !== false && strpos($mails[0][3], '<html><body>') === 0);
$GLOBALS['SENT'] = []; shiftWorld(3600); $m->Check();
check('Eskalation nur einmal', sentTo('mailex') === []);

// Wochenbericht, dessen Zustellung scheitert, wird nachgeholt
$mw = freshModule(['NotificationsActive' => true, 'DeviceSettings' => $crit]);
clock('2026-10-12 08:05'); $GLOBALS['SENT'] = []; $GLOBALS['SEND_OK'] = false; $mw->Check();
$GLOBALS['SEND_OK'] = true; $GLOBALS['SENT'] = []; $mw->Check();
check('Wochenbericht schlägt fehl → beim nächsten Lauf erneut versucht und zugestellt', count(array_filter($GLOBALS['SENT'], function ($s) { return $s[0] === 'visu' && strpos($s[2], 'Wochenbericht') !== false; })) === 4);

// Zustellung schlägt fehl → nicht als gemeldet verbuchen
$m2 = freshModule(['NotificationsActive' => true, 'DeviceSettings' => $crit], false);
check('Zustellung fehlgeschlagen: Meldungslog nennt es', (bool)array_filter($GLOBALS['LOG'], function ($l) { return strpos($l, 'zugestellt') !== false; }) && (bool)array_filter($GLOBALS['LOG'], function ($l) { return strpos($l, 'fehlgeschlagen') !== false; }));
$GLOBALS['SEND_OK'] = true; $GLOBALS['SENT'] = [];
$m2->Check();
check('Beim nächsten Lauf wird die Meldung nachgeholt (nicht als gemeldet verbucht)', count(sentTo('visu')) === 4, (string)count(sentTo('visu')));

// Ruhezeit im Modul
$m = freshModule(['NotificationsActive' => true, 'DeviceSettings' => $crit]);
$GLOBALS['SENT'] = [];
$m3 = (function () { clock('2026-10-07 23:30'); buildWorld($GLOBALS['CLOCK']); $GLOBALS['INSTS'] = ['{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}' => [701]]; $x = new BWTest(); $x->Create(); $x->props['NotificationsActive'] = true; $x->props['DeviceSettings'] = json_encode([['Instance' => 114, 'Group' => 'standard', 'Critical' => true, 'IgnoreAge' => false, 'Excluded' => false]]); return $x; })();
$GLOBALS['SENT'] = []; $m3->ApplyChanges();
$at23 = $GLOBALS['SENT'];
check('23:30 (Ruhezeit): nur das kritische Gerät wird gemeldet', count($at23) === 1 && strpos($at23[0][3], 'Rauchmelder Heizung') !== false && strpos($at23[0][3], 'Sensor Heizung') === false, json_encode($at23, JSON_UNESCAPED_UNICODE));
$GLOBALS['SENT'] = []; shiftWorld(8 * 3600); $m3->Check();
check('Am Morgen (07:30) werden die zurückgehaltenen Meldungen nachgeholt', count($GLOBALS['SENT']) === 1 && strpos($GLOBALS['SENT'][0][3], 'Sensor Heizung') !== false, json_encode($GLOBALS['SENT'], JSON_UNESCAPED_UNICODE));

// Testmeldung
$m = freshModule(['NotificationsActive' => false, 'NotifyPush' => true, 'NotifyMail' => true, 'MailInstance' => 14223, 'MailTo' => '']);
mkinst(14223, 'SMTP', 'SMTP');
$GLOBALS['SENT'] = [];
$res = $m->SendTest();
check('Testmeldung geht auch bei „Meldungen aus“ und meldet das Ergebnis', strpos($res, 'Push: ✅ 5 von 5') !== false && strpos($res, 'E-Mail: ✅ gesendet') !== false && count(sentTo('mail')) === 1, $res);
$GLOBALS['INSTS'] = []; $m->props['NotifyMail'] = false;
check('Testmeldung ohne Push-Ziele sagt das ehrlich', strpos($m->SendTest(), 'keine Push-Ziele gefunden') !== false);
$m->props['NotifyPush'] = false;
check('Testmeldung ohne gewählten Weg sagt das ehrlich', strpos($m->SendTest(), 'Kein Zustellweg') !== false);
$m->props['NotifyMail'] = true; $m->props['MailInstance'] = 0;
check('E-Mail ohne SMTP-Instanz: nicht gesendet, kein Absturz', strpos($m->SendTest(), 'nicht gesendet') !== false);

// Push-Ziele einschränken
$m = freshModule(['NotificationsActive' => true, 'PushTargets' => json_encode([['Instance' => 702]])]);
check('Push-Ziele eingeschränkt: nur die gewählte Instanz', count(sentTo('visu')) === 1 && sentTo('visu')[0][1] === 702 && sentTo('wfc') === []);

// Quittieren im Modul
$m = freshModule(['NotificationsActive' => true, 'DeviceSettings' => $crit]);
$tot = $m->GetValue('Total');
$r = $m->Acknowledge('', 'getauscht');
check('Quittieren ohne Gerät: freundlicher Hinweis', strpos($r, 'Bitte zuerst ein Gerät wählen') !== false);
$r = $m->Acknowledge('9999', 'getauscht');
check('Quittieren eines unbekannten Geräts: ehrliche Meldung', strpos($r, 'nicht gefunden') !== false);
$r = $m->Acknowledge('114', 'unsinn');
check('Unbekannte Aktion wird abgelehnt', strpos($r, 'Unbekannte Aktion') !== false);
$GLOBALS['SENT'] = [];
$r = $m->Acknowledge('114', 'getauscht');
check('„Habe ich getauscht“: Bestätigung nennt Wartezeit, Tagebuch hat den Eintrag', strpos($r, '✅') === 0 && strpos($r, '3 Tage') !== false && strpos($m->GetValue('TableDiary'), 'Rauchmelder Heizung') !== false && strpos($m->GetValue('TableDiary'), 'eingetragen') !== false, $r);
shiftWorld(2 * 86400); $m->Check();
check('Während der Wartezeit keine Meldung für das getauschte Gerät', !array_filter($GLOBALS['SENT'], function ($s) { return strpos($s[3], 'Rauchmelder Heizung') !== false; }));
$r = $m->Acknowledge('114', 'zurueckgestellt');
check('„Erinnere mich später“: nennt das Datum (TT.MM.JJJJ)', (bool)preg_match('/zurückgestellt bis \d\d\.\d\d\.\d{4}/u', $r), $r);
check('Zurückgestelltes Gerät trägt 💤 in der Tabelle', strpos($m->GetValue('TableAll'), '💤 bis') !== false);
$r = $m->Acknowledge('103', 'ausser_betrieb');
check('„Außer Betrieb“: Gerät verschwindet aus Zählung und Tabelle', $m->GetValue('Total') === $tot - 1 && strpos($m->GetValue('TableAll'), 'Sensor Heizung') === false, $r);
check('Außer-Betrieb-Gerät taucht in der Quittieren-Liste auf', strpos(json_encode(json_decode($m->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE), 'Außer Betrieb (1)') !== false);
check('Quittieren-Auswahl nennt Geräte mit Befund zuerst und den Befund', (function () use ($m) {
    $f = json_decode($m->GetConfigurationForm(), true);
    foreach ($f['elements'] as $p) { foreach ($p['items'] ?? [] as $it) { if (($it['name'] ?? '') === 'AckDevice') { return $it['options'][0]['value'] === '' && preg_match('/ \((leer|schwach|Funkstille|Daten prüfen)/u', $it['options'][1]['caption']) === 1; } } }
    return false;
})());
$r = $m->Unretire('103');
check('Wieder aufnehmen stellt das Gerät zurück', strpos($r, '✅') === 0 && $m->GetValue('Total') === $tot);
check('Wieder aufnehmen eines nicht ausgesetzten Geräts: ehrlicher Hinweis', strpos($m->Unretire('103'), 'stand nicht') !== false);
$m->Acknowledge('103', 'ausser_betrieb'); $m->Acknowledge('105-1051', 'ausser_betrieb');
check('Alle wieder aufnehmen nennt die Zahl', strpos($m->UnretireAll(), '2 Geräte wieder aufgenommen') !== false && $m->GetValue('Total') === $tot);

// Wechselerkennung im Modul
$m = freshModule(['NotificationsActive' => true]);
$GLOBALS['OBJ'][1141]['var']['value'] = 12;   // Rauchmelder fällt auf 12 %
$m->Check();
$GLOBALS['OBJ'][1141]['var']['value'] = 100;  // Batterie getauscht
$m->Check();
$diary = json_decode($m->GetValue('Diary'), true);
check('Sprung 12 % → 100 % ergibt einen erkannten Wechsel im Tagebuch', count($diary) === 1 && $diary[0]['type'] === 'erkannt' && strpos($diary[0]['note'], '12 % auf 100 %') !== false, json_encode($diary, JSON_UNESCAPED_UNICODE));
check('Tagebuch-Tabelle zeigt Datum TT.MM.JJJJ, Gerät, „erkannt“', (bool)preg_match('/\d\d\.\d\d\.\d{4} \d\d:\d\d<\/td><td>Rauchmelder Heizung<\/td><td>🔎 erkannt/u', $m->GetValue('TableDiary')));
$m->Check();
check('Nach dem Wechsel ist der alte Meldezustand des Geräts gelöscht', !isset(json_decode($m->GetValue('NotifyState'), true)['114']), $m->GetValue('NotifyState'));
check('Weitere Prüfung erzeugt keinen zweiten Eintrag', count(json_decode($m->GetValue('Diary'), true)) === 1);
// Flag-Wechsel (Sensor Vorrat: Flag schwach → ok)
$GLOBALS['OBJ'][1042]['var']['value'] = true; $m->Check();
$GLOBALS['OBJ'][1042]['var']['value'] = false; $m->Check();
check('„schwach“-Flag zurückgesetzt ergibt einen Wechsel', count(json_decode($m->GetValue('Diary'), true)) === 2);

// Versteckte Zustandsvariablen
$hidden = [];
foreach (['NotifyState', 'Diary', 'LastSeen', 'Retired', 'Meta'] as $ident) { $id = IPS_GetObjectIDByIdent($ident, 12345); $hidden[$ident] = $id !== false && !empty($GLOBALS['OBJ'][$id]['hidden']); }
check('Zustandsspeicher liegt in versteckten Variablen (übersteht Neu-Registrieren)', !in_array(false, $hidden, true), json_encode($hidden));
$m->simulateResync();
check('Nach Neu-Registrieren bleiben Tagebuch und Meldezustand erhalten', count(json_decode($m->GetValue('Diary'), true)) === 2);

// Wochenbericht im Modul
$m = freshModule(['NotificationsActive' => true, 'DeviceSettings' => $crit]);
clock('2026-10-12 07:00');   // Montag vor 08:00
$GLOBALS['SENT'] = []; $m->Check();
$beforeDigest = array_filter($GLOBALS['SENT'], function ($s) { return strpos($s[2], 'Wochenbericht') !== false; });
clock('2026-10-12 08:05'); $m->Check();
$digest = array_values(array_filter($GLOBALS['SENT'], function ($s) { return $s[0] === 'visu' && strpos($s[2], 'Wochenbericht') !== false; }));
check('Wochenbericht: vor 08:00 nichts, danach genau einmal an alle Push-Ziele', count($beforeDigest) === 0 && count($digest) === 4, (string)count($digest));
check('Wochenbericht nennt Kennzahlen', strpos($digest[0][3], 'Geräte überwacht') !== false);
$cnt = count($GLOBALS['SENT']); $m->Check();
check('Wochenbericht nicht noch einmal in derselben Woche', count(array_filter(array_slice($GLOBALS['SENT'], $cnt), function ($s) { return strpos($s[2], 'Wochenbericht') !== false; })) === 0);

// Zweifelhafte Daten lösen keine Einzelmeldung aus
$m = freshModule(['NotificationsActive' => true]);
check('Zweifelhafte Daten allein (Staubsauger: Wert 328 Tage alt, Gerät aktiv) lösen keine Meldung aus', !array_filter($GLOBALS['SENT'], function ($s) { return strpos($s[3], 'Staubsauger') !== false; }));
check('…tauchen aber im Wochenbericht-Zähler auf', $m->GetValue('Check') >= 1);


// ===========================================================================
heading('8 Kachel');
// ===========================================================================
$m = freshModule(['NotificationsActive' => false, 'DeviceSettings' => $crit]);
check('Instanz ist als Kachel-Visualisierung angemeldet (Typ 1)', $m->visType === 1);
check('Jede Prüfung schickt neue Kachel-Daten', count($m->visUpdates) >= 1);
if (getenv('BWACH_DUMP_TILE')) { file_put_contents(getenv('BWACH_DUMP_TILE'), end($m->visUpdates)); }   // für die Sichtprüfung im Browser
$pl = json_decode(end($m->visUpdates), true);
check('Kachel-Daten: Kennzahlen, Geräteliste, Tagebuch, Stand', isset($pl['summary']['total'], $pl['devices'], $pl['diary'], $pl['asOf']) && $pl['summary']['total'] === 11 && count($pl['devices']) === 11);
check('Geräte nach Dringlichkeit sortiert, kritisches schwaches Gerät zuerst', $pl['devices'][0]['name'] === 'Rauchmelder Heizung' && $pl['devices'][0]['urgency'] >= $pl['devices'][1]['urgency']);
$ok0 = array_values(array_filter($pl['devices'], function ($d) { return $d['name'] === 'Sensor Schlafzimmer'; }))[0];
$est = array_values(array_filter($pl['devices'], function ($d) { return $d['name'] === 'Thermostat Esszimmer'; }))[0];
check('Unterzeile nennt den Hauptbefund (Funkstille), nicht den nachrangigen (veralteter Wert)', strpos($est['headline'], 'Funkstille') === 0 && strpos($est['headline'], 'Tagen') !== false, $est['headline']);
check('Unterzeile bei leerer Batterie: „Batterie leer“ vor allem anderen', (function () { $r = BWACHLogik::evaluate(['percent' => ['value' => 2, 'updated' => 1, 'scale' => 1.0]], 1, 5000000, ['valueOldDays' => 1]); return strpos($r['reasons'][0], 'Batterie leer') === 0; })());
check('Gerät ohne Befund: Prozent als Zahl und Text, Alter und Lebenszeichen formuliert', $ok0['percent'] == 100 && $ok0['percentText'] === '100 %' && strpos($ok0['valueAgeText'], 'vor 2 Tagen') === 0 && strpos($ok0['lifeText'], 'vor ') === 0 && $ok0['urgency'] === 0);
$shel = array_values(array_filter($pl['devices'], function ($d) { return $d['name'] === 'Shelly H&T'; }))[0];
check('Prozentgerät mit Spannung zeigt Prozent; Gerät ohne Prozent zeigt Spannung', $shel['percentText'] === '35 %' && $shel['place'] === 'Sensoren');
check('Kachel liefert KEINE fertige Überschrift (Titel liefert der Instanzname)', !isset($pl['title']) && strpos(file_get_contents($MODDIR . '/module.html'), '<h1') === false && strpos(file_get_contents($MODDIR . '/module.html'), '<h2') === false);

$GLOBALS['OBJ'][101]['name'] = 'Evil </script><!--<script>alert(1)</script> & "x"';
$m->Check();
$tile = $m->GetVisualizationTile();
$after = substr($tile, strrpos($tile, '<script>handleMessage('));
$payloadPart = substr($after, strlen('<script>handleMessage('));
check('Gerätename mit </script> und <!--<script> bricht die Kachel nicht auf (nichts davon roh im Skript)', substr_count($after, '</script>') === 1 && strpos($payloadPart, '<!--') === false && substr_count($payloadPart, '<script') === 0 && strpos($payloadPart, 'alert(1)') !== false);
check('Tile-HTML enthält module.html und den ersten handleMessage-Aufruf mit Daten', strpos($tile, 'function handleMessage') !== false && preg_match('/handleMessage\("\{.*devices/s', $after) === 1, substr($after, 0, 120));
$GLOBALS['OBJ'][101]['name'] = 'Sensor Schlafzimmer';

// Rückkanal
$m->visUpdates = [];
$m->RequestAction('ack', json_encode(['key' => '114', 'action' => 'getauscht']));
$pl = json_decode(end($m->visUpdates), true);
check('Quittieren aus der Kachel: Rückmeldung steht in den Kachel-Daten', strpos($pl['message'], 'Batteriewechsel bei „Rauchmelder Heizung“ eingetragen') !== false && count($pl['diary']) === 1, $pl['message']);
check('Kachel-Tagebuch: Datum TT.MM.JJJJ, Art', preg_match('/^\d\d\.\d\d\.\d{4}$/', $pl['diary'][0]['when']) === 1 && $pl['diary'][0]['type'] === 'eingetragen');
$GLOBALS['CLOCK'] += 31; $m->RequestAction('refresh', '');
check('Rückmeldung verschwindet nach 30 Sekunden', json_decode(end($m->visUpdates), true)['message'] === '');
$m->RequestAction('ack', 'kein json');
check('Ungültige Anfrage aus der Kachel: freundliche Rückmeldung statt Absturz', strpos(json_decode(end($m->visUpdates), true)['message'], 'Ungültige Anfrage') !== false);
$m->RequestAction('ack', json_encode(['key' => '../../x', 'action' => 'getauscht']));
check('Unbekannter Schlüssel aus der Kachel wird abgelehnt', strpos(json_decode(end($m->visUpdates), true)['message'], 'nicht gefunden') !== false);
$m->RequestAction('ack', json_encode(['key' => '114', 'action' => 'loeschen']));
check('Unbekannte Aktion aus der Kachel wird abgelehnt', strpos(json_decode(end($m->visUpdates), true)['message'], 'Unbekannte Aktion') !== false);
$m->props['TileAllowAck'] = false;
$cnt = count(json_decode($m->GetValue('Diary'), true));
$m->RequestAction('ack', json_encode(['key' => '103', 'action' => 'ausser_betrieb']));
$pl = json_decode(end($m->visUpdates), true);
check('Quittieren aus der Kachel ausgeschaltet: nichts passiert, Kachel zeigt keine Schaltflächen', strpos($pl['message'], 'ausgeschaltet') !== false && $pl['allowAck'] === false && count($pl['devices']) === 11);
$m->props['TileAllowAck'] = true;
$m->RequestAction('ack', json_encode(['key' => '103', 'action' => 'ausser_betrieb']));
check('Außer Betrieb aus der Kachel: Gerät verschwindet aus der Kachel-Liste', count(json_decode(end($m->visUpdates), true)['devices']) === 10);
$m->RequestAction('ack', json_encode(['key' => '105-1051', 'action' => 'zurueckgestellt']));
check('Zurückgestellt aus der Kachel: Gerät trägt 💤', (bool)array_filter(json_decode(end($m->visUpdates), true)['devices'], function ($d) { return $d['id'] === '105-1051' && strpos($d['note'], '💤 bis') === 0; }));

// Formular-Aufwertungen
$form = json_decode($m->GetConfigurationForm(), true); $fj = json_encode($form, JSON_UNESCAPED_UNICODE);
check('Gerätewahl nennt den Befund kurz (z. B. „(schwach)“) statt eines langen Satzes', strpos($fj, 'Rauchmelder Heizung (schwach)') !== false && strpos($fj, 'Batterie schwach (25') === false);
$m->fieldUpdates = []; $m->Acknowledge('114', 'getauscht');
$upd = array_column($m->fieldUpdates, 0);
check('Nach dem Quittieren frischen sich Tagebuch- und Außer-Betrieb-Zeile im offenen Formular auf', in_array('DiaryLine', $upd, true) && in_array('RetiredLine', $upd, true) && in_array('AckStatus', $upd, true));
check('Tagebuch-Zeile zeigt den neuen Eintrag', strpos($m->GetConfigurationForm(), 'Zuletzt im Tagebuch') !== false);

// JavaScript der Kachel: Syntax und Verhalten mit einem Minimal-DOM
$html = file_get_contents($MODDIR . '/module.html');
preg_match('/<script>(.*)<\/script>/s', $html, $mjs);
$tmp = sys_get_temp_dir() . '/bw-tile-' . getmypid() . '.js';
$domJs = <<<'JS'
function El(tag){ this.tag=tag; this.children=[]; this.className=''; this.textContent=''; this.style={}; this.onclick=null; this.type=''; }
El.prototype.appendChild=function(c){ this.children.push(c); c.parent=this; return c; };
El.prototype.removeChild=function(c){ this.children.splice(this.children.indexOf(c),1); };
Object.defineProperty(El.prototype,'firstChild',{get:function(){ return this.children[0]||null; }});
global.document={ createElement:function(t){return new El(t);}, createTextNode:function(s){var e=new El('#text'); e.textContent=s; return e;}, getElementById:function(){ return global.ROOT; } };
global.ROOT=new El('div'); global.sent=[]; global.requestAction=function(i,v){ global.sent.push([i,v]); };
function all(e,out){ out=out||[]; out.push(e); e.children.forEach(function(c){all(c,out);}); return out; }
function texts(e){ return all(e).map(function(x){return x.textContent;}).filter(Boolean).join(' | '); }
function click(pred){ var t=all(global.ROOT).filter(pred)[0]; if(!t||!t.onclick) throw new Error('kein Klickziel'); t.onclick(); }
JS;
$testJs = <<<'JS'
var P = {summary:{total:3},asOf:'10:00 Uhr',allowAck:true,message:'',diary:[{when:'07.10.2026',name:'A',type:'erkannt',note:'x'}],
 shopping:{horizon:30,lines:['3× AAA','1× CR2032'],missing:['Ohne Typ'],count:2,until:'12.10.2026',places:[{place:'Flur',devices:[{name:'Alpha <b>',need:'1× CR2032',text:'Batterie leer'}]}],stats:[{group:'Zelle CR2032',text:'395 Tage (3 Intervalle)'}]},
 devices:[
 {id:'1',name:'Alpha <b>',place:'Flur',module:'Z',status:'leer',funk:'aktiv',percent:3,percentText:'3 %',voltageText:'',valueAgeText:'vor 1 Tag',lifeText:'vor 5 Minuten',critical:true,quality:[],urgency:1100,reasons:['Batterie leer (3 %)'],note:'',soon:false,forecastText:'reicht noch etwa 4 Tage (mittlere Sicherheit)',cellText:'CR2032 (Knopfzelle, 3 V)',derived:true},
 {id:'2',name:'Beta',place:'',module:'',status:'ok',funk:'still',percent:80,percentText:'80 %',voltageText:'',valueAgeText:'—',lifeText:'vor 9 Tage',critical:false,quality:['veraltet'],urgency:600,reasons:['Funkstille'],note:'💤 bis 15.10.2026',soon:true,forecastText:'Restlaufzeit unbekannt: zu wenig Verlauf (1 Messpunkte über 0 Tage)',cellText:'',derived:false},
 {id:'3',name:'Gamma',place:'Bad',module:'',status:'ok',funk:'aktiv',percent:100,percentText:'100 %',voltageText:'',valueAgeText:'vor 1 Tag',lifeText:'vor 1 Minute',critical:false,quality:[],urgency:0,reasons:[],note:'',soon:false,forecastText:'',cellText:'',derived:false}]};
handleMessage(JSON.stringify(P));
var ok = true; function chk(n,c){ if(!c){ ok=false; console.log('FAIL '+n); } }
var t = texts(global.ROOT);
chk('Standardfilter zeigt Handlungsbedarf (2 Geräte, nicht Gamma)', t.indexOf('Alpha <b>')>=0 && t.indexOf('Beta')>=0 && t.indexOf('Gamma')<0);
chk('Name wird als Text gesetzt (kein HTML)', all(global.ROOT).every(function(e){ return e.tag!=='b'; }));
chk('Zähler an den Filtern', t.indexOf('Handlungsbedarf')>=0 && t.indexOf('Alle')>=0);
click(function(e){ return e.className.indexOf('bw-chip')===0 && e.children[0] && e.children[0].textContent==='Alle'; });
chk('Filter Alle zeigt auch Gamma', texts(global.ROOT).indexOf('Gamma')>=0);
click(function(e){ return e.className==='bw-top' && texts(e).indexOf('Alpha')>=0; });
t = texts(global.ROOT);
chk('Aufklappen zeigt Prognose, Zelltyp und den Hinweis „aus Spannung berechnet“', texts(global.ROOT).indexOf('📈 reicht noch etwa 4 Tage')>=0 && texts(global.ROOT).indexOf('CR2032 (Knopfzelle, 3 V) · Ladezustand aus der Spannung berechnet')>=0);
chk('Aufklappen zeigt Gründe, Alter und Schaltflächen', t.indexOf('Batterie leer (3 %)')>=0 && t.indexOf('Batteriewert gemeldet: vor 1 Tag')>=0 && t.indexOf('✔ Habe ich getauscht')>=0);
click(function(e){ return e.textContent==='✔ Habe ich getauscht'; });
chk('„getauscht“ sendet ack an den Rückkanal', global.sent.length===1 && global.sent[0][0]==='ack' && JSON.parse(global.sent[0][1]).key==='1' && JSON.parse(global.sent[0][1]).action==='getauscht');
click(function(e){ return e.textContent==='⏹ Außer Betrieb'; });
chk('„Außer Betrieb“ braucht einen zweiten Klick (nichts gesendet)', global.sent.length===1 && texts(global.ROOT).indexOf('Wirklich außer Betrieb?')>=0);
click(function(e){ return e.textContent==='Wirklich außer Betrieb?'; });
chk('Zweiter Klick sendet ausser_betrieb', global.sent.length===2 && JSON.parse(global.sent[1][1]).action==='ausser_betrieb');
P.allowAck=false; handleMessage(P);
click(function(e){ return e.className==='bw-top' && texts(e).indexOf('Alpha')>=0; });
chk('Ohne Quittier-Erlaubnis keine Schaltflächen', texts(global.ROOT).indexOf('Habe ich getauscht')<0);
P.message='✅ erledigt'; handleMessage(P);
chk('Rückmeldung wird angezeigt', texts(global.ROOT).indexOf('✅ erledigt')>=0);
click(function(e){ return e.textContent==='🛒 Einkauf'; });
t = texts(global.ROOT);
chk('Einkauf: Zeilen, fehlender Zelltyp, Tauschrunde mit Ort und Datum', t.indexOf('3× AAA, 1× CR2032')>=0 && t.indexOf('Zelltyp fehlt bei: Ohne Typ')>=0 && t.indexOf('Tauschrunde (2 Geräte) — am besten bis 12.10.2026')>=0 && t.indexOf('Flur')>=0 && t.indexOf('Alpha <b> — 1× CR2032 · Batterie leer')>=0);
chk('Einkauf: Namen als Text, nicht als HTML', all(global.ROOT).every(function(e){ return e.tag!=='b'; }));
click(function(e){ return e.textContent==='📈 Statistik'; });
t = texts(global.ROOT);
chk('Statistik: Lebensdauer und Restlaufzeit je Gerät', t.indexOf('Zelle CR2032')>=0 && t.indexOf('395 Tage (3 Intervalle)')>=0 && t.indexOf('Restlaufzeit je Gerät')>=0 && t.indexOf('reicht noch etwa 4 Tage')>=0 && t.indexOf('Für 1 Gerät gibt es noch keine Prognose')>=0 && t.indexOf('Restlaufzeit unbekannt: zu wenig')<0);
click(function(e){ return e.className.indexOf('bw-chip')===0 && e.children[0] && e.children[0].textContent==='⏳ bald leer'; });
chk('Filter „bald leer“ zeigt nur Geräte mit Prognose-Warnung', texts(global.ROOT).indexOf('Beta')>=0 && texts(global.ROOT).indexOf('Gamma')<0 && texts(global.ROOT).indexOf('Alpha <b>')<0);
click(function(e){ return e.textContent==='📓 Tagebuch'; });
chk('Tagebuch-Ansicht zeigt Einträge', texts(global.ROOT).indexOf('07.10.2026')>=0 && texts(global.ROOT).indexOf('erkannt')>=0);
P.devices=[]; P.summary.total=0; handleMessage(P); click(function(e){ return e.className.indexOf('bw-chip')===0 && e.children[0] && e.children[0].textContent==='Alle'; });
chk('Ohne Geräte: ehrlicher Leer-Text', texts(global.ROOT).indexOf('noch keine Geräte überwacht')>=0);
P.devices=[P.devices[0]]; 
console.log(ok ? 'JS-OK' : 'JS-FEHLER'); process.exit(ok?0:1);
JS;
file_put_contents($tmp, $domJs . "\n" . $mjs[1] . "\n" . $testJs);
$out = []; $rc = 0;
if (trim((string)shell_exec('command -v node')) !== '') {
    exec('node ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
    check('Kachel-JavaScript: Syntax und Verhalten (Minimal-DOM unter Node)', $rc === 0, implode(' / ', $out));
} else {
    echo "  ⏭  Node nicht installiert: JavaScript-Prüfung übersprungen\n";
}
@unlink($tmp);


// ===========================================================================
heading('9 Fehlerursache beim E-Mail-Versand');
// ===========================================================================
check('„Login denied“ → Anmeldung abgelehnt mit Hinweis auf Passwort und App-Passwort', (function () { $s = BWACHMeldung::explainMailError(['Login denied']); return strpos($s, 'Anmeldung abgelehnt') === 0 && strpos($s, 'App-spezifisches Passwort') !== false && strpos($s, '„Login denied“') !== false; })());
check('„535 5.7.8 authentication failed“ ebenfalls', strpos(BWACHMeldung::explainMailError(['535 5.7.8 Error: authentication failed']), 'Anmeldung abgelehnt') === 0);
check('Server nicht gefunden / Zeitüberschreitung / abgelehnt / Zertifikat / Empfänger', strpos(BWACHMeldung::explainMailError(['Could not resolve host: x']), 'Server nicht gefunden') === 0
    && strpos(BWACHMeldung::explainMailError(['Connection timed out after 10001 ms']), 'Zeitüberschreitung') === 0
    && strpos(BWACHMeldung::explainMailError(['Connection refused']), 'Verbindung abgelehnt') === 0
    && strpos(BWACHMeldung::explainMailError(['SSL certificate problem']), 'Verschlüsselung oder Zertifikat') === 0
    && strpos(BWACHMeldung::explainMailError(['RCPT TO rejected']), 'Empfänger abgelehnt') === 0);
check('Unbekannte Meldung wird durchgereicht und als Meldung des SMTP-Moduls gekennzeichnet', BWACHMeldung::explainMailError(['Etwas Neues']) === 'Meldung des SMTP-Moduls („Etwas Neues“)');
check('Ohne jede Meldung: ehrlicher Hinweis auf das Debug der SMTP-Instanz', strpos(BWACHMeldung::explainMailError([]), 'nennt keinen Grund') !== false && strpos(BWACHMeldung::explainMailError([]), 'Debug') !== false);
check('Ausnahme statt Warnung wird ebenfalls erklärt', strpos(BWACHMeldung::explainMailError([], 'Connection refused'), 'Verbindung abgelehnt') === 0);

$m = freshModule(['NotificationsActive' => false, 'NotifyPush' => false, 'NotifyMail' => true, 'MailInstance' => 14223, 'MailTo' => 'a@example.org']);
mkinst(14223, 'SMTP', 'SMTP');
$GLOBALS['SMTP_WARN'] = 'Login denied'; $GLOBALS['SEND_OK'] = false; $GLOBALS['LOG'] = [];
$res = $m->SendTest();
check('Testmeldung nennt die Ursache direkt: „nicht gesendet — Anmeldung abgelehnt …“', strpos($res, 'E-Mail: ⚠️ nicht gesendet — Anmeldung abgelehnt') === 0 && strpos($res, 'Login denied') !== false, $res);
check('Dieselbe Ursache steht im Meldungslog', (bool)array_filter($GLOBALS['LOG'], function ($l) { return strpos($l, 'E-Mail-Versand fehlgeschlagen: Anmeldung abgelehnt') !== false; }));
check('Die Testmeldung zeigt die Ursache auch im offenen Formular (Statuszeile)', (bool)array_filter($m->fieldUpdates, function ($u) { return $u[0] === 'NotifyStatus' && strpos($u[2], 'Anmeldung abgelehnt') !== false; }));
$GLOBALS['SMTP_WARN'] = ''; $GLOBALS['SMTP_THROW'] = 'Connection refused'; $GLOBALS['LOG'] = [];
check('Ausnahme des SMTP-Moduls bringt die Testmeldung nicht zum Absturz und wird erklärt', strpos($m->SendTest(), 'Verbindung abgelehnt') !== false);
$GLOBALS['SMTP_THROW'] = ''; $GLOBALS['SEND_OK'] = true;
check('Nach Behebung: „gesendet“, kein alter Fehlertext', strpos($m->SendTest(), 'E-Mail: ✅ gesendet') !== false);
$set = set_error_handler(function () { return false; }); restore_error_handler();
$GLOBALS['SMTP_WARN'] = 'Login denied'; $GLOBALS['SEND_OK'] = false; $m->SendTest(); $GLOBALS['SMTP_WARN'] = ''; $GLOBALS['SEND_OK'] = true;
$handlerAfter = set_error_handler(function () { return false; }); restore_error_handler();
check('Der Fehler-Handler wird nach dem Versand wieder zurückgesetzt (kein Dauerfang von Warnungen)', $handlerAfter === $set);
$m->props['MailInstance'] = 0;
check('Ohne SMTP-Instanz: Testmeldung sagt, dass keine ausgewählt ist', strpos($m->SendTest(), 'keine SMTP-Instanz ausgewählt') !== false);
// Meldungs-Zustellung nennt die Ursache im Log
$m = freshModule(['NotificationsActive' => true, 'NotifyPush' => false, 'NotifyMail' => true, 'MailInstance' => 14223, 'MailTo' => 'a@example.org'], false);
check('Meldung ohne Zustellweg: Meldungslog nennt auch den E-Mail-Grund', (bool)array_filter($GLOBALS['LOG'], function ($l) { return strpos($l, 'konnte über keinen Weg zugestellt werden') !== false; }));


// ===========================================================================
heading('10 Zelltypen und Spannungskurven');
// ===========================================================================
check('Frische Alkali-AA (1,6 V) = 100 %, leere (1,0 V) = 0 %', BWACHZelle::percentFromVoltage('aa_alkali', 1, 1.6) === 100.0 && BWACHZelle::percentFromVoltage('aa_alkali', 1, 1.0) === 0.0);
check('Kurve interpoliert zwischen Stützpunkten (1,35 V Alkali = 52,5 %)', BWACHZelle::percentFromVoltage('aa_alkali', 1, 1.35) === 52.5);
check('Mehrere Zellen in Reihe: 3× AA bei 4,71 V = 1,57 V je Zelle = 95,5 %, bei 4,8 V = 100 %', BWACHZelle::percentFromVoltage('aa_alkali', 3, 4.71) === 95.5 && BWACHZelle::percentFromVoltage('aa_alkali', 3, 4.8) === 100.0);
check('3× AA bei 3,9 V (1,3 V je Zelle) = 40 %', BWACHZelle::percentFromVoltage('aa_alkali', 3, 3.9) === 40.0);
check('CR2032: 3,0 V = 100 %, 2,7 V = 40 %, 2,0 V = 0 %', BWACHZelle::percentFromVoltage('cr2032', 1, 3.0) === 100.0 && BWACHZelle::percentFromVoltage('cr2032', 1, 2.7) === 40.0 && BWACHZelle::percentFromVoltage('cr2032', 1, 2.0) === 0.0);
check('Li-Ion: 4,2 V = 100 %, 3,7 V = 35 %', BWACHZelle::percentFromVoltage('liion', 1, 4.2) === 100.0 && BWACHZelle::percentFromVoltage('liion', 1, 3.7) === 35.0);
check('Spannung passt nicht zum Typ → null (4,7 V an einer CR2032, 1 Zelle)', BWACHZelle::percentFromVoltage('cr2032', 1, 4.7) === null);
check('Offenbar defekte Messung (0,3 V an AA) → null', BWACHZelle::percentFromVoltage('aa_alkali', 1, 0.3) === null);
check('Unbekannter Zelltyp oder 0 Zellen → null', BWACHZelle::percentFromVoltage('unbekannt', 1, 3.0) === null && BWACHZelle::percentFromVoltage('aa_alkali', 0, 1.5) === null);
check('Kurven fallen monoton (keine Stützpunkt-Verwechslung)', (function () {
    foreach (['cr2032', 'aa_alkali', 'aa_nimh', 'aa_lithium', 'block9v', 'liion'] as $t) { $last = 101; for ($v = 0.5; $v <= 10; $v += 0.05) { $p = BWACHZelle::percentFromVoltage($t, 1, $v); if ($p === null) { continue; } if ($p < 0 || $p > 100) { return false; } } }
    foreach (['aa_alkali', 'cr2032', 'liion'] as $t) { $prev = -1; for ($v = 1.0; $v <= 4.2; $v += 0.01) { $p = BWACHZelle::percentFromVoltage($t, 1, $v); if ($p === null) { continue; } if ($p + 0.0001 < $prev) { return false; } $prev = $p; } }
    return true;
})());
check('Vorschlag aus der Spannung nennt Kandidaten, wählt nichts: 4,71 V → 3× AA/AAA, auch 4× Akku', strpos(BWACHZelle::suggest(4.71), '3× AA') !== false && strpos(BWACHZelle::suggest(4.71), 'passt zu:') === 0);
check('Vorschlag bei 3,0 V nennt Knopfzellen UND 2× AA (mehrdeutig)', strpos(BWACHZelle::suggest(3.0), 'CR2032') !== false && strpos(BWACHZelle::suggest(3.0), '2× AA') !== false);
check('RCR123A-Akku: 4,2 V = 100 %, 3,7 V = 35 %, 3,0 V = 0 %; als Einkauf „RCR123A (Akku)“', BWACHZelle::percentFromVoltage('rcr123a', 1, 4.2) === 100.0 && BWACHZelle::percentFromVoltage('rcr123a', 1, 3.7) === 35.0 && BWACHZelle::percentFromVoltage('rcr123a', 1, 3.0) === 0.0 && BWACHZelle::shopLabel('rcr123a') === 'RCR123A (Akku)');
check('CR123A-Batterie (nicht wiederaufladbar) und RCR123A-Akku sind verschiedene Typen: 3,7 V ist für die Batterie unmöglich, für den Akku 35 %', BWACHZelle::percentFromVoltage('cr123a', 1, 3.7) === null && BWACHZelle::percentFromVoltage('rcr123a', 1, 3.7) === 35.0);
check('CR123A-Batterie: 3,0 V = 100 %, 2,7 V = 40 %; Einkauf „CR123A“; Auswahl nennt „nicht wiederaufladbar“', BWACHZelle::percentFromVoltage('cr123a', 1, 3.0) === 100.0 && BWACHZelle::percentFromVoltage('cr123a', 1, 2.7) === 40.0 && BWACHZelle::shopLabel('cr123a') === 'CR123A' && strpos(BWACHZelle::label('cr123a'), 'nicht wiederaufladbar') !== false);
check('Zwei CR123A in Reihe (6,0 V) = 100 %, 5,4 V = 40 %', BWACHZelle::percentFromVoltage('cr123a', 2, 6.0) === 100.0 && BWACHZelle::percentFromVoltage('cr123a', 2, 5.4) === 40.0);
check('Vorschlag bei 4,0 V nennt den RCR123A-Akku; bei 6,0 V auch 2× CR123A; Knopfzellen nie zu zweit', strpos(BWACHZelle::suggest(4.0), 'RCR123A (Akku)') !== false && strpos(BWACHZelle::suggest(6.0), '2× CR123A') !== false && strpos(BWACHZelle::suggest(6.0), '2× CR2032') === false);
check('Einkaufsliste trennt CR123A-Batterien und RCR123A-Akkus', (function () { $r = BWACHPrognose::shopping([['name' => 'A', 'place' => '', 'cell' => 'cr123a', 'cells' => 2, 'status' => 'leer', 'days' => null], ['name' => 'B', 'place' => '', 'cell' => 'rcr123a', 'cells' => 1, 'status' => 'schwach', 'days' => null]], 30); sort($r['lines']); return $r['lines'] === ['1× RCR123A (Akku)', '2× CR123A']; })());
check('Vorschlag bei unmöglicher Spannung (20 V)', BWACHZelle::suggest(20.0) === 'passt zu keinem bekannten Zelltyp');
check('Einkaufsbezeichnungen: CR2032, AAA, AA (Akku)', BWACHZelle::shopLabel('cr2032') === 'CR2032' && BWACHZelle::shopLabel('aaa_alkali') === 'AAA' && BWACHZelle::shopLabel('aa_nimh') === 'AA (Akku)' && BWACHZelle::shopLabel('unbekannt') === null);
check('Auswahlliste beginnt mit „unbekannt“ und enthält alle Typen', BWACHZelle::options()[0]['value'] === 'unbekannt' && count(BWACHZelle::options()) === 12);

$n = $NOW;
$volt = ['voltage' => ['value' => 4.71, 'updated' => $n - 60]];
$r = ev($volt, $n - 60, ['cell' => 'aa_alkali', 'cells' => 3]);
check('Nur Spannung + Zelltyp: Ladezustand berechnet, als „aus Spannung berechnet“ gekennzeichnet', $r['percent'] === 95.5 && $r['derived'] === true && $r['status'] === 'ok');
$r = ev(['voltage' => ['value' => 3.4, 'updated' => $n - 60]], $n - 60, ['cell' => 'aa_alkali', 'cells' => 3]);
check('3× AA bei 3,4 V (≈1,13 V je Zelle, 12 %) → schwach, Grund nennt „≈“ und „aus Spannung berechnet“', $r['status'] === 'schwach' && strpos($r['reasons'][0], '≈') !== false && strpos($r['reasons'][0], 'aus Spannung berechnet') !== false, implode(' | ', $r['reasons']));
$r = ev(['voltage' => ['value' => 4.71, 'updated' => $n - 60]], $n - 60, ['cell' => 'cr2032', 'cells' => 1]);
check('Spannung passt nicht zum gewählten Zelltyp: Status unbekannt, Hinweis, Dringlichkeit 400', $r['status'] === 'unbekannt' && in_array('zelltyp_passt_nicht', $r['quality'], true) && $r['urgency'] === 400 && strpos(implode(' ', $r['reasons']), 'passt nicht zum gewählten Zelltyp') !== false);
$r = ev($volt, $n - 60);
check('Nur Spannung ohne Zelltyp: Hinweis „Zelltyp wählen“ mit Vorschlag', in_array('nur_spannung', $r['quality'], true) && strpos(implode(' ', $r['reasons']), 'Zelltyp wählen') !== false && strpos(implode(' ', $r['reasons']), 'passt zu:') !== false);
$r = ev(array_merge(pctSig(80, $n - 60), $volt), $n - 60, ['cell' => 'aa_alkali', 'cells' => 3]);
check('Prozent UND Spannung: der gemeldete Prozentwert gilt, nichts wird berechnet', $r['percent'] === 80.0 && $r['derived'] === false);
$r = ev(pctSig(50, $n - 60), $n - 60, ['stillSec' => 3600]);
check('Gelernte Schwelle (1 Stunde) gilt statt der festen 7 Tage', ev(pctSig(50, $n), $n - 7200, ['stillSec' => 3600])['funk'] === 'still' && ev(pctSig(50, $n), $n - 7200)['funk'] === 'aktiv');
check('Gelernte Schwelle steht im Grund', strpos(implode(' ', ev(pctSig(50, $n), $n - 7200, ['stillSec' => 3600])['reasons']), 'gelernt') !== false);
$r = ev(pctSig(50, $n - 86400), $n - 61 * 86400);
check('Verwaist: über 60 Tage still → Hinweis auf „Außer Betrieb“', $r['orphan'] === true && strpos(implode(' ', $r['reasons']), 'Außer Betrieb') !== false);
check('Nicht verwaist: 20 Tage still, oder Verwaist-Prüfung aus (0)', ev(pctSig(50, $n - 86400), $n - 20 * 86400)['orphan'] === false && ev(pctSig(50, $n - 86400), $n - 61 * 86400, ['orphanDays' => 0])['orphan'] === false);

// ===========================================================================
heading('11 Verlauf und Prognose');
// ===========================================================================
$T0 = ts('2026-06-01 12:00'); $Dd = 86400;
$series = [];
foreach ([[0, 100], [10, 95], [20, 90], [30, 85], [40, 80], [50, 75], [60, 70], [70, 65]] as [$d, $p]) { $series = BWACHPrognose::historyAdd($series, $T0 + $d * $Dd, (float)$p); }
$f = BWACHPrognose::forecast($series, 5.0);
check('Gleichmäßiger Abfall (0,5 %/Tag): Steigung −0,5, Restlaufzeit 120 Tage, Sicherheit hoch', $f['slope'] === -0.5 && $f['days'] === 120.0 && $f['confidence'] === 'hoch', json_encode($f));
check('Leerzeitpunkt = letzter Punkt + Restlaufzeit', $f['emptyAt'] === $T0 + 70 * $Dd + 120 * $Dd);
check('Text: „reicht noch etwa 120 Tage (hohe Sicherheit)“', BWACHPrognose::forecastText($f) === 'reicht noch etwa 120 Tage (hohe Sicherheit)');
// Ausreißer
$noisy = $series; $noisy[3][1] = 40.0; $noisy[5][1] = 99.0;
$f2 = BWACHPrognose::forecast($noisy, 5.0);
check('Zwei Ausreißer verschieben die Steigung kaum (Theil-Sen): −0,4 bis −0,6 %/Tag', $f2['slope'] <= -0.4 && $f2['slope'] >= -0.6, (string)$f2['slope']);
check('…aber die Sicherheit sinkt', $f2['confidence'] !== 'hoch', $f2['confidence']);
// zu wenig
$few = BWACHPrognose::historyAdd(BWACHPrognose::historyAdd([], $T0, 100.0), $T0 + 5 * $Dd, 98.0);
$f = BWACHPrognose::forecast($few, 5.0);
check('Zwei Punkte über 5 Tage: ehrlich „unbekannt“ mit Grund', $f['days'] === null && $f['confidence'] === 'unbekannt' && strpos(BWACHPrognose::forecastText($f), 'Restlaufzeit unbekannt: zu wenig Verlauf (2 Messpunkte über 5 Tage') === 0, BWACHPrognose::forecastText($f));
// vier Punkte, aber nur 9 Tage
$short = [];
foreach ([[0, 100], [3, 98], [6, 96], [9, 94]] as [$d, $p]) { $short = BWACHPrognose::historyAdd($short, $T0 + $d * $Dd, (float)$p); }
$f = BWACHPrognose::forecast($short, 5.0);
check('4 Messpunkte über nur 9 Tage: keine Prognose (Mindestzeitraum 14 Tage)', $f['days'] === null && strpos($f['why'], '4 Messpunkte über 9 Tage') !== false, $f['why']);
$f = BWACHPrognose::forecast(array_merge($short, [[$T0 + 14 * $Dd, 92.0, null]]), 5.0);
check('…mit dem Punkt am 14. Tag genau an der Grenze gibt es eine Prognose', $f['days'] !== null);
// stufig
$steps = [];
foreach ([[0, 100], [15, 100], [30, 100], [45, 100], [60, 90]] as [$d, $p]) { $steps = BWACHPrognose::historyAdd($steps, $T0 + $d * $Dd, (float)$p); }
$f = BWACHPrognose::forecast($steps, 5.0);
check('Grobe Stufen (nur 2 verschiedene Werte): keine Prognose, Grund genannt', $f['days'] === null && strpos($f['why'], 'zu selten') !== false, $f['why']);
// flach
$flat = [];
foreach ([0, 15, 30, 45, 60] as $d) { $flat = BWACHPrognose::historyAdd($flat, $T0 + $d * $Dd, 100.0 - $d * 0.001); }
$flat[1][1] = 99.95; $flat[2][1] = 99.9; $flat[3][1] = 99.95; $flat[4][1] = 99.9;
$f = BWACHPrognose::forecast($flat, 5.0);
check('Praktisch keine Entladung: „keine Entladung erkennbar“ statt Millionen Tage', $f['confidence'] === 'keine' && BWACHPrognose::forecastText($f) === 'keine Entladung erkennbar', json_encode($f));
// Wechsel beendet den Abschnitt
$withSwap = $series; $withSwap = BWACHPrognose::historyAdd($withSwap, $T0 + 75 * $Dd, 100.0);
foreach ([85, 95, 105, 115] as $d) { $withSwap = BWACHPrognose::historyAdd($withSwap, $T0 + $d * $Dd, 100.0 - ($d - 75) * 0.2); }
$f = BWACHPrognose::forecast($withSwap, 5.0);
check('Nach einem Batteriewechsel zählt nur der neue Abschnitt (Steigung −0,2, nicht −0,5)', $f['slope'] === -0.2 && $f['points'] === 5, json_encode($f));
check('Abschnitt seit Wechsel erkennt den Sprung ab 25 Punkten, nicht davor', count(BWACHPrognose::segmentSinceReplacement([[1, 50, null], [2, 74, null]], 25)) === 2 && count(BWACHPrognose::segmentSinceReplacement([[1, 50, null], [2, 75, null]], 25)) === 1);
// Verlauf
$s = BWACHPrognose::historyAdd([], $T0, 80.0);
check('Verlauf: gleicher Wert wenige Tage später wird nicht doppelt gespeichert', count(BWACHPrognose::historyAdd($s, $T0 + 3600, 80.2)) === 1);
check('Verlauf: Änderung ab 0,5 Punkten wird sofort gespeichert', count(BWACHPrognose::historyAdd($s, $T0 + 3600, 79.4)) === 2);
check('Verlauf: nach 3 Tagen auch ohne Änderung ein Punkt', count(BWACHPrognose::historyAdd($s, $T0 + 3 * $Dd, 80.0)) === 2 && count(BWACHPrognose::historyAdd($s, $T0 + 3 * $Dd - 1, 80.0)) === 1);
check('Verlauf: Zeit rückwärts oder fehlender Wert ändert nichts', BWACHPrognose::historyAdd($s, $T0 - 5, 50.0) === $s && BWACHPrognose::historyAdd($s, $T0 + 9 * $Dd, null) === $s);
$cap = []; for ($i = 0; $i < 150; $i++) { $cap = BWACHPrognose::historyAdd($cap, $T0 + $i * 4 * $Dd, 100.0 - $i * 0.1); }
check('Verlauf ist auf 90 Punkte begrenzt (die jüngsten bleiben)', count($cap) === 90 && $cap[89][0] === $T0 + 149 * 4 * $Dd);
check('Außentemperatur wird mit dem Messpunkt gespeichert', BWACHPrognose::historyAdd([], $T0, 80.0, 3.44)[0][2] === 3.4);
check('Prognose über 3 Jahre wird gedeckelt, Text in Jahren', (function () { $T = ts('2026-06-01'); $se = []; foreach ([0, 20, 40, 60, 80, 100] as $d) { $se = BWACHPrognose::historyAdd($se, $T + $d * 86400, 100.0 - $d * 0.0301); } $f = BWACHPrognose::forecast($se, 5.0); return $f['days'] > 365 && strpos(BWACHPrognose::forecastText($f), 'Jahre') !== false; })());

// ===========================================================================
heading('12 Einkaufsliste, Tauschrunde, Lebensdauer, gelernte Intervalle');
// ===========================================================================
$items = [
    ['name' => 'A', 'place' => 'Flur', 'cell' => 'cr2032', 'cells' => 1, 'status' => 'schwach', 'days' => null],
    ['name' => 'B', 'place' => 'Flur', 'cell' => 'cr2032', 'cells' => 1, 'status' => 'ok', 'days' => 20.0],
    ['name' => 'C', 'place' => 'Bad', 'cell' => 'aaa_alkali', 'cells' => 3, 'status' => 'ok', 'days' => 29.9],
    ['name' => 'D', 'place' => 'Bad', 'cell' => 'aaa_alkali', 'cells' => 3, 'status' => 'ok', 'days' => 31.0],
    ['name' => 'E', 'place' => '', 'cell' => 'unbekannt', 'cells' => 1, 'status' => 'leer', 'days' => null],
    ['name' => 'F', 'place' => 'Keller', 'cell' => 'aa_nimh', 'cells' => 2, 'status' => 'ok', 'days' => null],
];
$sh = BWACHPrognose::shopping($items, 30);
check('Einkaufsliste: 2× CR2032 (A, B), 3× AAA (C; D erst nach 31 Tagen), unbekannter Typ gesondert', $sh['lines'] === ['3× AAA', '2× CR2032'] || $sh['lines'] === ['2× CR2032', '3× AAA'], json_encode($sh['lines'], JSON_UNESCAPED_UNICODE));
check('Gerät ohne Zelltyp steht unter „fehlt“, nicht stillschweigend weg', $sh['missing'] === ['E']);
check('Geräte ohne Prognose und ohne Befund (F) werden nicht eingekauft', !in_array('F', array_column($sh['need'], 'name'), true));
check('Genau am Horizont (30,0 Tage) zählt noch mit, 30,1 nicht', count(BWACHPrognose::due([['status' => 'ok', 'days' => 30.0]], 30)) === 1 && count(BWACHPrognose::due([['status' => 'ok', 'days' => 30.1]], 30)) === 0);
check('Horizont 45 Tage nimmt D mit (6× AAA)', in_array('6× AAA', BWACHPrognose::shopping($items, 45)['lines'], true));
check('Anzahl Zellen zählt: 3× AAA je Gerät', in_array('3× AAA', $sh['lines'], true));
$tr = BWACHPrognose::tauschrunde($items, 30, $NOW);
check('Tauschrunde: nach Ort gebündelt (Flur, Bad, ohne Ort), 4 Geräte', array_keys($tr['places']) === ['Bad', 'Flur', 'ohne Ort'] && $tr['count'] === 4, json_encode(array_keys($tr['places']), JSON_UNESCAPED_UNICODE));
check('Tauschrunde: „bis wann“ = jetzt, solange ein Gerät schon schwach/leer ist', $tr['until'] === $NOW);
$tr2 = BWACHPrognose::tauschrunde([$items[1], $items[2]], 30, $NOW);
check('Tauschrunde ohne akutes Gerät: früheste Restlaufzeit minus 3 Tage Reserve (B: 20 − 3 = 17 Tage)', $tr2['until'] === $NOW + 17 * 86400, (string)(($tr2['until'] - $NOW) / 86400));
$trSoon = BWACHPrognose::tauschrunde([['name' => 'S', 'place' => 'Flur', 'cell' => 'cr2032', 'cells' => 1, 'status' => 'ok', 'days' => 1.0]], 30, $NOW);
check('Tauschrunde liegt nie in der Vergangenheit (Restlaufzeit 1 Tag minus 3 Tage Reserve → heute)', $trSoon['until'] === $NOW);
check('Leere Tauschrunde', BWACHPrognose::tauschrunde([], 30, $NOW) === ['places' => [], 'until' => null, 'count' => 0]);
// Lebensdauer
$diary = [['t' => $T0, 'key' => 'k1', 'name' => 'Sensor 1'], ['t' => $T0 + 400 * $Dd, 'key' => 'k1', 'name' => 'Sensor 1'], ['t' => $T0 + 780 * $Dd, 'key' => 'k1', 'name' => 'Sensor 1'], ['t' => $T0, 'key' => 'k2', 'name' => 'Sensor 2'], ['t' => $T0 + 200 * $Dd, 'key' => 'k2', 'name' => 'Sensor 2'], ['t' => $T0, 'key' => 'k3', 'name' => 'Einzeln']];
$lf = BWACHPrognose::lifetimes($diary);
check('Lebensdauer: Abstände zwischen Wechseln je Gerät; ein einzelner Wechsel ergibt keine', $lf['k1']['days'] === [400.0, 380.0] && $lf['k2']['days'] === [200.0] && !isset($lf['k3']));
$grp = BWACHPrognose::lifetimeByGroup($lf, ['k1' => 'CR2032', 'k2' => 'CR2032', 'k3' => 'AA']);
check('Lebensdauer je Zelltyp: Median 380 Tage aus 3 Intervallen bei 2 Geräten', $grp['CR2032'] === ['median' => 380.0, 'n' => 3, 'devices' => 2] && !isset($grp['AA']), json_encode($grp));
check('Median: gerade und ungerade Anzahl, leer', BWACHPrognose::median([1, 3, 2]) === 2.0 && BWACHPrognose::median([1, 2, 3, 4]) === 2.5 && BWACHPrognose::median([]) === 0.0);
// Intervalle lernen
$obs = [];
foreach ([0, 3000, 6000, 9000, 12000, 15000, 18000, 21000, 24000] as $s) { $obs = BWACHPrognose::observeLife($obs, 1000000 + $s); }
check('Meldeintervalle: 8 gleiche Abstände von 50 Minuten gelernt', count($obs['gaps']) === 8 && $obs['gaps'][0] === 3000);
check('Gelernte Schwelle = 3× 50 min = 2,5 h, aber mindestens 6 Stunden', BWACHPrognose::learnedThreshold($obs, 7 * 86400) === 6 * 3600);
$obs2 = []; $t = 1000000; foreach ([2, 2, 3, 2, 3, 2, 2, 3, 2] as $dd) { $t += $dd * 86400; $obs2 = BWACHPrognose::observeLife($obs2, $t); }
check('Gerät meldet alle 2–3 Tage: gelernte Schwelle 9 Tage (3× P90 = 3× 3 Tage)', BWACHPrognose::learnedThreshold($obs2, 30 * 86400) === 9 * 86400, (string)(BWACHPrognose::learnedThreshold($obs2, 30 * 86400) / 86400));
check('Gelernte Schwelle macht nur empfindlicher: nie über der eingestellten', BWACHPrognose::learnedThreshold($obs2, 5 * 86400) === 5 * 86400);
check('Zu wenige Beobachtungen: eingestellte Schwelle gilt', BWACHPrognose::learnedThreshold(['gaps' => [100, 200, 300]], 7 * 86400) === 7 * 86400);
check('Gleiches Lebenszeichen zweimal gesehen erzeugt keinen Abstand; ältere Zeit wird ignoriert', count(BWACHPrognose::observeLife(['last' => 500, 'gaps' => []], 500)['gaps']) === 0 && BWACHPrognose::observeLife(['last' => 500, 'gaps' => []], 400)['last'] === 500);
check('Nur die letzten 20 Abstände bleiben', (function () { $o = []; for ($i = 1; $i <= 40; $i++) { $o = BWACHPrognose::observeLife($o, $i * 1000); } return count($o['gaps']) === 20; })());


// ===========================================================================
heading('13 Prognose, Einkauf und Statistik im Modul');
// ===========================================================================
function setHistory(BWTest $m, string $key, array $points): void { $m->SetValue('History', json_encode([$key => $points])); }
$cellSettings = json_encode([
    ['Instance' => 114, 'Group' => 'standard', 'Critical' => true, 'IgnoreAge' => false, 'Excluded' => false, 'Cell' => 'cr2032', 'Cells' => 1],
    ['Instance' => 106, 'Group' => 'standard', 'Critical' => false, 'IgnoreAge' => false, 'Excluded' => false, 'Cell' => 'aa_alkali', 'Cells' => 3],
]);
$m = freshModule(['NotificationsActive' => false, 'DeviceSettings' => $cellSettings]);
$pl = json_decode(end($m->visUpdates), true);
$shelly = array_values(array_filter($pl['devices'], function ($d) { return $d['name'] === 'Shelly H&T'; }))[0];
check('Shelly mit Prozent UND Spannung: Prozentwert des Geräts gilt, Zelltyp-Text in der Kachel', $shelly['percentText'] === '35 %' && $shelly['cellText'] === '3× AA Alkali (1,5 V)' && $shelly['derived'] === false, json_encode($shelly, JSON_UNESCAPED_UNICODE));
check('Zelltyp aus den Geräte-Einstellungen kommt in der Zeile an', (function () use ($m) { $f = json_decode($m->GetConfigurationForm(), true); return strpos(json_encode($f, JSON_UNESCAPED_UNICODE), 'CR2032 (Knopfzelle, 3 V)') !== false; })());

// Verlauf wird mitgeschrieben
$hist = json_decode($m->GetValue('History'), true);
check('Der Verlauf wird mitgeschrieben (ein Punkt je Gerät mit Prozentwert)', isset($hist['114']) && count($hist['114']) === 1 && $hist['114'][0][1] == 25 && !isset($hist['105-1051']), json_encode(array_keys($hist)));
shiftWorld(3600); $m->Check();
check('Eine Stunde später ohne Änderung kein zweiter Punkt', count(json_decode($m->GetValue('History'), true)['114']) === 1);
$obs = json_decode($m->GetValue('LifeObs'), true);
check('Meldeverhalten wird beobachtet (Lebenszeichen je Gerät)', isset($obs['101']['last']) && $obs['101']['last'] > 0);

// Prognose wirkt: Rauchmelder fällt gleichmäßig → „bald leer“ (Restlaufzeit ≤ 14 Tage, Sicherheit hoch)
$now = $GLOBALS['CLOCK']; $pts = [];
for ($i = 0; $i <= 9; $i++) { $pts[] = [$now - (90 - $i * 10) * 86400, round(60 - $i * 4.0, 1), null]; }   // 60 → 24 über 90 Tage, −0,4 %/Tag
$GLOBALS['OBJ'][1141]['var']['value'] = 24;
setHistory($m, '114', $pts);
$m->Check();
$pl = json_decode(end($m->visUpdates), true);
$dev = array_values(array_filter($pl['devices'], function ($d) { return $d['id'] === '114'; }))[0];
check('Prognose in der Kachel: Text mit Dauer und Sicherheit', preg_match('/^reicht noch etwa \d+ Tage \((hohe|mittlere) Sicherheit\)$/u', $dev['forecastText']) === 1, $dev['forecastText']);
$dev = array_values(array_filter(json_decode(end($m->visUpdates), true)['devices'], function ($d) { return $d['id'] === '114'; }))[0];
// ok-Gerät mit Prognose (nicht kritisch, 70 %)
$GLOBALS['OBJ'][1141]['var']['value'] = 50;
$pts = []; for ($i = 0; $i <= 9; $i++) { $pts[] = [$now - (90 - $i * 10) * 86400, round(86 - $i * 4.0, 1), null]; }  // endet bei 50 %
$m->props['DeviceSettings'] = json_encode([['Instance' => 114, 'Group' => 'standard', 'Critical' => false, 'IgnoreAge' => false, 'Excluded' => false, 'Cell' => 'cr2032', 'Cells' => 1]]);
setHistory($m, '114', $pts); $m->props['SoonDays'] = 14; $m->Check();
$d114 = function () use ($m) { return array_values(array_filter(json_decode(end($m->visUpdates), true)['devices'], function ($d) { return $d['id'] === '114'; }))[0]; };
check('Restlaufzeit 112 Tage > 14: nicht „bald leer“, Status ok', $d114()['soon'] === false && $d114()['status'] === 'ok');
$m->props['SoonDays'] = 150; $m->Check();
check('Schwelle 150 Tage: jetzt „bald leer“, Dringlichkeit 650, Grund nennt die Prognose', $d114()['soon'] === true && $d114()['urgency'] === 650 && strpos(implode(' ', $d114()['reasons']), 'Batterie bald leer: reicht noch etwa') !== false, json_encode($d114()['reasons'], JSON_UNESCAPED_UNICODE));
check('Kennzahl „Bald leer“ zählt mit', $m->GetValue('Soon') === 1 && strpos($m->GetValue('StatusLine'), '1 bald leer') !== false, (string)$m->GetValue('Soon'));
$m->props['NotificationsActive'] = true; $GLOBALS['SENT'] = []; $m->Check();
$soonMsg = array_values(array_filter($GLOBALS['SENT'], function ($s) { return $s[0] === 'visu' && strpos($s[3], 'Rauchmelder Heizung') !== false; }));
check('Meldung „bald leer“ wird verschickt (Titel ⏳, Text mit Prognose)', $soonMsg && (strpos($soonMsg[0][2], '⏳') !== false || strpos($soonMsg[0][2], 'Batteriemeldungen') !== false) && strpos($soonMsg[0][3], 'bald leer') !== false, json_encode($soonMsg[0] ?? null, JSON_UNESCAPED_UNICODE));
// geringe Sicherheit löst nichts aus
$few = [[$now - 20 * 86400, 80.0, null], [$now - 10 * 86400, 60.0, null], [$now - 5 * 86400, 55.0, null], [$now, 50.0, null]];
setHistory($m, '114', $few); $m->Check();
check('Geringe Sicherheit (20 Tage Verlauf): keine Meldung „bald leer“', $d114()['soon'] === false && strpos($d114()['forecastText'], 'geringe Sicherheit') !== false, $d114()['forecastText']);
setHistory($m, '114', [[$now - 3 * 86400, 50.0, null], [$now, 50.0, null]]); $m->Check();
check('Zu wenig Verlauf: „Restlaufzeit unbekannt: zu wenig Verlauf (2 Messpunkte über 3 Tage …“', strpos($d114()['forecastText'], 'Restlaufzeit unbekannt: zu wenig Verlauf (2 Messpunkte über 3 Tage') === 0, $d114()['forecastText']);

// Einkauf und Tauschrunde
$m = freshModule(['NotificationsActive' => false, 'DeviceSettings' => $cellSettings]);
$all = $m->GetValue('TableShopping');
check('Einkauf: schwacher kritischer Rauchmelder braucht 1× CR2032', strpos($all, '1× CR2032') !== false && strpos($all, 'Einkaufsliste für die nächsten 30 Tage') !== false, strip_tags($all));
$noCell = freshModule(['NotificationsActive' => false, 'DeviceSettings' => json_encode([['Instance' => 114, 'Group' => 'standard', 'Critical' => true, 'IgnoreAge' => false, 'Excluded' => false, 'Cell' => 'unbekannt', 'Cells' => 1]])]);
check('Einkauf nennt Geräte ohne Zelltyp ehrlich', strpos($noCell->GetValue('TableShopping'), 'Zelltyp fehlt bei: Rauchmelder Heizung') !== false, strip_tags($noCell->GetValue('TableShopping')));
check('Tauschrunde nennt Ort, Gerät, Batterie und den Vorschlag „bis TT.MM.JJJJ“', strpos($all, 'Tauschrunde') !== false && preg_match('/bis \d\d\.\d\d\.\d{4}/u', $all) === 1 && strpos($all, '<td>Heizung</td><td>Rauchmelder Heizung</td><td>1× CR2032</td>') !== false);
$pl = json_decode(end($m->visUpdates), true);
check('Kachel kennt Einkauf und Tauschrunde (Zeilen, Orte, Anzahl)', $pl['shopping']['lines'] === ['1× CR2032'] && $pl['shopping']['count'] >= 1 && $pl['shopping']['places'][0]['place'] !== '' && $pl['shopping']['horizon'] === 30, json_encode($pl['shopping'], JSON_UNESCAPED_UNICODE));
$m->props['NotificationsActive'] = true;
clock('2026-10-12 08:05'); $GLOBALS['SENT'] = []; $m->Check();
$dg = array_values(array_filter($GLOBALS['SENT'], function ($s) { return $s[0] === 'visu' && strpos($s[2], 'Wochenbericht') !== false; }));
check('Wochenbericht enthält die Einkaufszeile, und zwar vorn (Push kürzt bei 256 Byte)', $dg && strpos($dg[0][3], '🛒 Einkauf für die nächsten 30 Tage: 1× CR2032') !== false && strlen($dg[0][3]) <= 256, $dg[0][3] ?? '');

// Statistik: Lebensdauer
$m = freshModule(['NotificationsActive' => false, 'DeviceSettings' => $cellSettings]);
register_shutdown_function(function () use (&$m) { if (getenv('BWACH_DUMP_TILE2')) { file_put_contents(getenv('BWACH_DUMP_TILE2'), end($m->visUpdates)); } });
$t = $GLOBALS['CLOCK'];
$m->SetValue('Diary', json_encode([
    ['t' => $t - 800 * 86400, 'key' => '114', 'name' => 'Rauchmelder Heizung', 'type' => 'erkannt', 'note' => 'x'],
    ['t' => $t - 400 * 86400, 'key' => '114', 'name' => 'Rauchmelder Heizung', 'type' => 'erkannt', 'note' => 'x'],
    ['t' => $t - 10 * 86400, 'key' => '114', 'name' => 'Rauchmelder Heizung', 'type' => 'manuell', 'note' => 'x'],
]));
$m->Check();
$st = $m->GetValue('TableStats');
check('Statistik: Lebensdauer des Rauchmelders 400 und 390 Tage, Median je Zelltyp CR2032 395 Tage', strpos($st, '400 Tage, 390 Tage') !== false && strpos($st, '<td>CR2032</td><td>395 Tage</td>') !== false, strip_tags($st));
check('Statistik: Median je System (Z-Wave Module)', strpos($st, '<td>Z-Wave Module</td><td>395 Tage</td>') !== false);
check('Statistik zeigt die Restlaufzeit je Gerät, auch „unbekannt“ mit Grund', strpos($st, 'Restlaufzeit je Gerät') !== false && strpos($st, 'Restlaufzeit unbekannt') !== false);
$pl = json_decode(end($m->visUpdates), true);
check('Kachel kennt die Statistik', (bool)array_filter($pl['shopping']['stats'], function ($s) { return strpos($s['group'], 'Zelle CR2032') === 0 && strpos($s['text'], '395 Tage') === 0; }));
$m6 = freshModule(['NotificationsActive' => false]);
check('Ohne Wechsel im Tagebuch: ehrlicher Hinweis statt leerer Tabelle', strpos($m6->GetValue('TableStats'), 'mindestens zwei erfasste Batteriewechsel') !== false);

// Spannung → Ladezustand im Modul (nur Spannungsgerät mit Zelltyp)
$GLOBALS['OBJ'] = []; mkinst(12345, 'Batteriewächter', 'Batteriewaechter'); mkcat(900, 'Sensoren');
mkinst(160, 'Spannungsgerät', 'ShellyDevice', 900);
mkvar(1601, 160, 'devicepower_0_battery_V', 'Batteriespannung', 2, 3.4, $GLOBALS['CLOCK'] - 60);
mkvar(1602, 160, 'temp', 'Temperatur', 2, 20.0, $GLOBALS['CLOCK'] - 60);
$GLOBALS['INSTS'] = []; $GLOBALS['SENT'] = [];
$mv = new BWTest(); $mv->Create(); $mv->props['DeviceSettings'] = json_encode([['Instance' => 160, 'Group' => 'standard', 'Critical' => false, 'IgnoreAge' => false, 'Excluded' => false, 'Cell' => 'aa_alkali', 'Cells' => 3]]); $mv->ApplyChanges();
$pl = json_decode(end($mv->visUpdates), true);
check('Nur-Spannungsgerät mit Zelltyp 3× AA: schwach, ≈12 %, „aus Spannung berechnet“ in der Kachel', $pl['devices'][0]['status'] === 'schwach' && $pl['devices'][0]['derived'] === true && $pl['devices'][0]['percentText'] === '12 %' && strpos($pl['devices'][0]['headline'], 'aus Spannung berechnet') !== false, json_encode($pl['devices'][0], JSON_UNESCAPED_UNICODE));
check('Berechneter Ladezustand kommt in den Verlauf (Prognose-Grundlage)', isset(json_decode($mv->GetValue('History'), true)['160']));
$mv->props['DeviceSettings'] = '[]'; $mv->Check();
$pl = json_decode(end($mv->visUpdates), true);
check('Ohne Zelltyp: Status unbekannt, Hinweis mit Vorschlag statt Rate', $pl['devices'][0]['status'] === 'unbekannt' && strpos(implode(' ', $pl['devices'][0]['reasons']), 'Zelltyp wählen') !== false && strpos(implode(' ', $pl['devices'][0]['reasons']), '3× AA') !== false);

// Gelernter Meldetakt und Verwaist im Modul
$m = freshModule(['NotificationsActive' => false, 'StillDays' => 7]);
$obsT = []; $tt = $GLOBALS['CLOCK'] - 30 * 3600; $o = [];
for ($i = 0; $i < 12; $i++) { $tt += 3000; $o = BWACHPrognose::observeLife($o, $tt); }
$m->SetValue('LifeObs', json_encode(['103' => $o]));
$m->Check();
$d103 = array_values(array_filter(json_decode(end($m->visUpdates), true)['devices'], function ($d) { return $d['id'] === '103'; }))[0];
check('Gelernter Takt (alle 50 Minuten, 12 Beobachtungen): Sensor Heizung wird mit der gelernten Schwelle als still erkannt und der Grund sagt es', $d103['funk'] === 'still' && strpos(implode(' ', $d103['reasons']), 'aus dem Meldeverhalten gelernt') !== false, json_encode($d103['reasons'], JSON_UNESCAPED_UNICODE));
$m->props['LearnIntervals'] = false; $m->Check();
$d103 = array_values(array_filter(json_decode(end($m->visUpdates), true)['devices'], function ($d) { return $d['id'] === '103'; }))[0];
check('Lernen abgeschaltet: feste Schwelle, Grund ohne „gelernt“', strpos(implode(' ', $d103['reasons']), 'gelernt') === false);
$GLOBALS['OBJ'][1032]['var']['VariableUpdated'] = $GLOBALS['CLOCK'] - 70 * 86400; $GLOBALS['OBJ'][1031]['var']['VariableUpdated'] = $GLOBALS['CLOCK'] - 70 * 86400; $m->Check();
$d103 = array_values(array_filter(json_decode(end($m->visUpdates), true)['devices'], function ($d) { return $d['id'] === '103'; }))[0];
check('70 Tage still: Vorschlag „vermutlich ausgebaut, Außer Betrieb wählen“', strpos(implode(' ', $d103['reasons']), 'vermutlich ausgebaut oder defekt') !== false);


// ===========================================================================
heading('14 Gleichartige, Funkqualität, Kälte');
// ===========================================================================
$mk = function (array $r) { return $r; };
$rates = [];
foreach ([['a', 0.10], ['b', 0.11], ['c', 0.12], ['d', 0.13], ['e', 0.40]] as [$k, $r]) { $rates[$k] = ['rate' => $r, 'group' => 'Z|cr2032']; }
$pe = BWACHPrognose::peerOutliers($rates);
check('Gleichartige: e entlädt 3,3× so schnell wie der Median (0,12) → auffällig, die übrigen nicht', array_keys($pe) === ['e'] && $pe['e']['factor'] === 3.3 && $pe['e']['median'] === 0.12 && $pe['e']['n'] === 5, json_encode($pe));
check('Gleichartige: genau doppelte Rate zählt (≥ 2×), knapp darunter nicht', array_keys(BWACHPrognose::peerOutliers(['a' => ['rate' => 0.1, 'group' => 'g'], 'b' => ['rate' => 0.1, 'group' => 'g'], 'c' => ['rate' => 0.1, 'group' => 'g'], 'd' => ['rate' => 0.2, 'group' => 'g']])) === ['d']
    && BWACHPrognose::peerOutliers(['a' => ['rate' => 0.1, 'group' => 'g'], 'b' => ['rate' => 0.1, 'group' => 'g'], 'c' => ['rate' => 0.1, 'group' => 'g'], 'd' => ['rate' => 0.199, 'group' => 'g']]) === []);
check('Gleichartige: Gruppe mit nur 3 Geräten wird nicht verglichen (Median wäre Zufall)', BWACHPrognose::peerOutliers(['a' => ['rate' => 0.1, 'group' => 'g'], 'b' => ['rate' => 0.1, 'group' => 'g'], 'c' => ['rate' => 0.9, 'group' => 'g']]) === []);
check('Gleichartige: sehr kleine Raten (unter 0,05 Punkte/Tag) sind nie auffällig, auch bei 5-fachem Median', BWACHPrognose::peerOutliers(['a' => ['rate' => 0.005, 'group' => 'g'], 'b' => ['rate' => 0.005, 'group' => 'g'], 'c' => ['rate' => 0.005, 'group' => 'g'], 'd' => ['rate' => 0.04, 'group' => 'g']]) === []);
check('Gleichartige: verschiedene Gruppen werden getrennt verglichen', BWACHPrognose::peerOutliers(['a' => ['rate' => 0.1, 'group' => 'g1'], 'b' => ['rate' => 0.1, 'group' => 'g1'], 'c' => ['rate' => 0.1, 'group' => 'g2'], 'd' => ['rate' => 0.5, 'group' => 'g2']]) === []);
check('Gleichartige: Geräte ohne Entladung (Rate 0) zählen nicht zur Gruppe', BWACHPrognose::peerOutliers(['a' => ['rate' => 0.0, 'group' => 'g'], 'b' => ['rate' => 0.1, 'group' => 'g'], 'c' => ['rate' => 0.1, 'group' => 'g'], 'd' => ['rate' => 0.5, 'group' => 'g']]) === []);

check('Funkqualität: linkquality 160 gut, 100 mittel, 30 schwach', BWACHLogik::classifySignal('linkquality', 160)['level'] === 'gut' && BWACHLogik::classifySignal('linkquality', 100)['level'] === 'mittel' && BWACHLogik::classifySignal('linkquality', 30)['level'] === 'schwach');
check('Funkqualität: Grenzen 50 (mittel) und 120 (gut)', BWACHLogik::classifySignal('linkquality', 50)['level'] === 'mittel' && BWACHLogik::classifySignal('linkquality', 49)['level'] === 'schwach' && BWACHLogik::classifySignal('linkquality', 120)['level'] === 'gut' && BWACHLogik::classifySignal('linkquality', 119)['level'] === 'mittel');
check('Funkqualität: RSSI −60 gut, −78 mittel, −90 schwach, mit Text „dBm“', BWACHLogik::classifySignal('rssi', -60)['level'] === 'gut' && BWACHLogik::classifySignal('rssi', -78)['level'] === 'mittel' && BWACHLogik::classifySignal('rssi', -90)['level'] === 'schwach' && strpos(BWACHLogik::classifySignal('rssi', -78)['text'], '-78 dBm (mittel)') !== false);
check('Funkqualität: unbekannte Skala wird nur angezeigt, nicht bewertet', BWACHLogik::classifySignal('signal', 60)['level'] === '' && strpos(BWACHLogik::classifySignal('signal', 60)['text'], 'Funksignal 60') === 0);
check('Funkqualität: Erkennung der Idents', BWACHLogik::isSignalIdent('linkquality') && BWACHLogik::isSignalIdent('RSSI') && BWACHLogik::isSignalIdent('SignalStrength') && !BWACHLogik::isSignalIdent('temperature') && !BWACHLogik::isSignalIdent('signal_name'));

// Kälte
$H = ts('2026-01-01 12:00'); $hs = [];
$mkHist = function (float $coldRate, float $warmRate) use ($H) {
    $s = []; $p = 100.0; $t = $H;
    // 4 kalte Abschnitte (−2 °C), 4 warme (+15 °C)
    $s[] = [$t, $p, -2.0];
    for ($i = 0; $i < 4; $i++) { $t += 5 * 86400; $p -= $coldRate * 5; $s[] = [$t, round($p, 2), -2.0]; }
    $t += 5 * 86400; $p -= 0.1 * 5; $s[] = [$t, round($p, 2), 15.0];
    for ($i = 0; $i < 4; $i++) { $t += 5 * 86400; $p -= $warmRate * 5; $s[] = [$t, round($p, 2), 15.0]; }
    return $s;
};
$ce = BWACHPrognose::coldEffect(['a' => $mkHist(0.4, 0.2), 'b' => $mkHist(0.6, 0.2), 'c' => $mkHist(0.3, 0.2)]);
check('Kälte: Batterien entladen sich bei Kälte 2× schneller (Median aus 3 Geräten)', $ce['ok'] === true && $ce['factor'] === 2.0 && $ce['devices'] === 3 && strpos($ce['text'], '2× schneller') !== false, json_encode($ce, JSON_UNESCAPED_UNICODE));
check('Kälte: zählt die Zeitabschnitte (je Gerät 4 kalte und 5 warme, der Übergang zählt mit seinem Mittel von 6,5 °C als warm)', $ce['cold'] === 12 && $ce['warm'] === 15, json_encode($ce));
$ce2 = BWACHPrognose::coldEffect(['a' => $mkHist(0.2, 0.2), 'b' => $mkHist(0.2, 0.2), 'c' => $mkHist(0.2, 0.2)]);
check('Kälte: gleiche Rate → „Kein deutlicher Kälteeinfluss“', $ce2['ok'] === true && strpos($ce2['text'], 'Kein deutlicher Kälteeinfluss') === 0);
$ce3 = BWACHPrognose::coldEffect(['a' => $mkHist(0.4, 0.2), 'b' => $mkHist(0.6, 0.2)]);
check('Kälte: nur 2 Geräte → ehrlich „noch nicht genug Daten“ mit Zahlen', $ce3['ok'] === false && $ce3['factor'] === null && strpos($ce3['text'], 'noch nicht genug Daten') !== false && strpos($ce3['text'], 'bisher 2') !== false, $ce3['text']);
$noTemp = $mkHist(0.4, 0.2); foreach ($noTemp as &$pt) { $pt[2] = null; } unset($pt);
check('Kälte: Verläufe ohne Außentemperatur zählen nicht', BWACHPrognose::coldEffect(['a' => $noTemp, 'b' => $noTemp, 'c' => $noTemp])['devices'] === 0);
$onlyCold = []; $t = $H; $p = 100.0; $onlyCold[] = [$t, $p, -3.0]; for ($i = 0; $i < 8; $i++) { $t += 4 * 86400; $p -= 2; $onlyCold[] = [$t, $p, -3.0]; }
check('Kälte: Gerät mit nur kalten Abschnitten (kein Vergleich möglich) zählt nicht', BWACHPrognose::coldEffect(['a' => $onlyCold, 'b' => $onlyCold, 'c' => $onlyCold])['devices'] === 0);
check('Kälte: Batteriewechsel (Sprung nach oben) im Verlauf verfälscht die Rate nicht', (function () use ($mkHist) { $h = $mkHist(0.4, 0.2); $h[3][1] = 100.0; $h[4][1] = 99.0; $r = BWACHPrognose::coldEffect(['a' => $h, 'b' => $mkHist(0.4, 0.2), 'c' => $mkHist(0.4, 0.2)]); return $r['devices'] === 3; })());
check('Kälte: zu kurze Abschnitte (unter 2 Tage) werden ignoriert', BWACHPrognose::coldEffect(['a' => [[$H, 100.0, -1.0], [$H + 3600, 99.0, -1.0]]])['cold'] === 0);


// Kälte: Randfälle, jeweils mit eigenem Verlauf
$coldPart = function (array $temps, int $stepDays) use ($H) { return $temps; };
$build = function (array $coldTemps, array $warmTemps, int $coldStep = 5, ?array $swapAt = null) use ($H) {
    $s = []; $t = $H; $p = 100.0; $s[] = [$t, $p, $coldTemps[0] ?? 0.0];
    foreach ($coldTemps as $i => $c) { if ($i === 0) { continue; } $t += $coldStep * 86400; $p -= 0.4 * $coldStep; $s[] = [$t, round($p, 2), $c]; }
    foreach ($warmTemps as $c) { $t += 5 * 86400; $p -= 0.2 * 5; $s[] = [$t, round($p, 2), $c]; }
    return $s;
};
$c4 = [-2.0, -2.0, -2.0, -2.0, -2.0]; $w4 = [15.0, 15.0, 15.0, 15.0, 15.0];
check('Kälte (Kontrolle): 5 kalte, 5 warme Punkte je Gerät → Ergebnis', BWACHPrognose::coldEffect(['a' => $build($c4, $w4), 'b' => $build($c4, $w4), 'c' => $build($c4, $w4)])['ok'] === true);
$nullCold = [null, null, null, null, null];
check('Kälte: Abschnitte ohne Außentemperatur werden ignoriert (nicht als 0 °C gewertet)', BWACHPrognose::coldEffect(['a' => $build($nullCold, $w4), 'b' => $build($nullCold, $w4), 'c' => $build($nullCold, $w4)])['devices'] === 0);
check('Kälte: Abschnitte unter 2 Tagen Abstand werden ignoriert', BWACHPrognose::coldEffect(['a' => $build($c4, $w4, 1), 'b' => $build($c4, $w4, 1), 'c' => $build($c4, $w4, 1)])['devices'] === 0);
$swapSeries = function () use ($H) { $s = []; $t = $H; $p = 60.0; $s[] = [$t, $p, -2.0]; $t += 5 * 86400; $p -= 2; $s[] = [$t, $p, -2.0]; $t += 5 * 86400; $p = 100.0; $s[] = [$t, $p, -2.0]; for ($i = 0; $i < 2; $i++) { $t += 5 * 86400; $p -= 2; $s[] = [$t, $p, -2.0]; } for ($i = 0; $i < 4; $i++) { $t += 5 * 86400; $p -= 1; $s[] = [$t, $p, 15.0]; } return $s; };
$sw = BWACHPrognose::coldEffect(['a' => $swapSeries(), 'b' => $swapSeries(), 'c' => $swapSeries()]);
check('Kälte: der Sprung beim Batteriewechsel ist KEIN kalter Abschnitt (je Gerät 3 kalte, nicht 4)', $sw['cold'] === 9, json_encode($sw));
$e5 = [5.0, 5.0, 5.0, 5.0, 5.0];
check('Kälte: genau 5,0 °C zählt als „nicht kalt“ (Grenze „unter 5“)', BWACHPrognose::coldEffect(['a' => $build($e5, $w4), 'b' => $build($e5, $w4), 'c' => $build($e5, $w4)], 5.0)['devices'] === 0);
$c2 = [-2.0, -2.0, -2.0]; $w2 = [15.0, 15.0, 15.0];
check('Kälte: 2 kalte + 2 warme Abschnitte reichen nicht (mindestens 3 je Seite)', BWACHPrognose::coldEffect(['a' => $build($c2, $w2), 'b' => $build($c2, $w2), 'c' => $build($c2, $w2)])['devices'] === 0);
check('Kälte: Faktor knapp unter 1,2 („Kein deutlicher Kälteeinfluss“), 1,2 selbst gilt als Einfluss', strpos(BWACHPrognose::coldEffect(['a' => $mkHist(0.23, 0.2), 'b' => $mkHist(0.23, 0.2), 'c' => $mkHist(0.23, 0.2)])['text'], 'Kein deutlicher') === 0 && strpos(BWACHPrognose::coldEffect(['a' => $mkHist(0.24, 0.2), 'b' => $mkHist(0.24, 0.2), 'c' => $mkHist(0.24, 0.2)])['text'], 'Bei unter') === 0);

// Übernahme aus dem alten Modul
$old = ['PushMsgAktiv' => true, 'EMailMsgAktiv' => false, 'SmtpInstanceID' => 14223, 'WebFrontInstanceID' => 58070, 'BatterieBenachrichtigungCBOX' => true, 'Intervall' => 21600, 'EigenesSkriptAktiv' => true, 'EigenesSkriptID' => 777, 'TextFarbcode' => 'FFFFFF'];
$im = BWACHLogik::importOldConfig($old, function (int $i) { return in_array($i, [14223, 58070], true); });
check('Übernahme: Push an, E-Mail aus, SMTP #14223, Push-Ziel #58070, Meldungen aktiv', $im['fields'] === ['NotifyPush' => true, 'NotifyMail' => false, 'MailInstance' => 14223, 'PushTargets' => [['Instance' => 58070]], 'NotificationsActive' => true], json_encode($im['fields']));
check('Übernahme: nennt ehrlich, was NICHT übernommen wird (Texte/Farben, Intervall, eigenes Skript)', count($im['skipped']) === 3 && strpos(implode(' ', $im['skipped']), 'Meldungstext') !== false && strpos(implode(' ', $im['skipped']), '21600 s') !== false && strpos(implode(' ', $im['skipped']), 'eigenes Skript #777') !== false);
$im2 = BWACHLogik::importOldConfig(['PushMsgAktiv' => true, 'SmtpInstanceID' => 999, 'WebFrontInstanceID' => 998], function (int $i) { return false; });
check('Übernahme: nicht mehr vorhandene Instanzen werden nicht eingetragen, sondern genannt', !isset($im2['fields']['MailInstance']) && !isset($im2['fields']['PushTargets']) && strpos(implode(' ', $im2['skipped']), '#999 (gibt es nicht mehr)') !== false && strpos(implode(' ', $im2['skipped']), '#998 (gibt es nicht mehr)') !== false);
check('Übernahme: ohne Push/E-Mail werden Meldungen nicht aktiviert', !isset(BWACHLogik::importOldConfig(['BatterieBenachrichtigungCBOX' => true], function (int $i) { return true; })['fields']['NotificationsActive']));

// im Modul
clock('2026-10-07 10:00'); buildWorld($GLOBALS['CLOCK']);
mkinst(14223, 'SMTP', 'SMTP'); mkinst(58070, 'WebFront', 'Tile Visualization');
mkinst(12613, 'BatterieMonitor', 'BatterieMonitor'); $GLOBALS['OBJ'][12613]['config'] = $old;
$GLOBALS['INSTS'] = ['{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}' => [701]]; $GLOBALS['SENT'] = [];
$mi = new BWTest(); $mi->Create(); $mi->ApplyChanges();
check('Alte Instanz wird im Formular genannt (mit ID)', strpos(json_encode(json_decode($mi->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE), 'Gefunden: „BatterieMonitor“ (#12613)') !== false);
$mi->fieldUpdates = [];
$res = $mi->ImportOld();
$upd = []; foreach ($mi->fieldUpdates as [$n, $pr, $v]) { $upd[$n] = [$pr, $v]; }
check('Übernahme füllt die offene Maske (value/values), schreibt aber NICHTS in die Eigenschaften', $upd['NotifyPush'] === ['value', true] && $upd['MailInstance'] === ['value', 14223] && $upd['PushTargets'] === ['values', [['Instance' => 58070]]] && $upd['NotificationsActive'] === ['value', true] && $mi->props['NotificationsActive'] === false && $mi->props['MailInstance'] === 0);
check('Rückmeldung nennt, was übernommen wurde, was nicht und dass „Übernehmen“ noch fehlt', strpos($res, '✅ Aus „BatterieMonitor“ (#12613)') === 0 && strpos($res, 'Nicht übernommen:') !== false && strpos($res, 'noch NICHT gespeichert') !== false && strpos($res, 'kann die alte Instanz entfernt werden') !== false, $res);
check('Statuszeile im Formular wird aufgefrischt', (bool)array_filter($mi->fieldUpdates, function ($u) { return $u[0] === 'ImportStatus'; }));
unset($GLOBALS['OBJ'][12613]);
check('Ohne alte Instanz: ehrliche Meldung', strpos($mi->ImportOld(), 'Keine BY_BatterieMonitor-Instanz gefunden') !== false && strpos(json_encode(json_decode($mi->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE), 'Keine alte BY_BatterieMonitor-Instanz gefunden') !== false);


// Vergleich nur innerhalb gleichen Systems/Zelltyps: 2 Shelly + 2 andere Systeme → keine Gruppe mit 4
$GLOBALS['OBJ'] = []; mkinst(12345, 'Batteriewächter', 'Batteriewaechter'); mkcat(900, 'Sensoren');
$nowT = $GLOBALS['CLOCK'];
foreach ([[201, 'Sensor A', 'ShellyDevice', 0.10], [202, 'Sensor B', 'ShellyDevice', 0.11], [203, 'Sensor C', 'Froggit', 0.12], [204, 'Sensor D', 'Froggit', 0.50]] as [$iid, $nm, $mod, $rt]) {
    mkinst($iid, $nm, $mod, 900);
    mkvar($iid * 10 + 1, $iid, 'devicepower_0_battery_percent', 'Batteriestatus', 1, 70, $nowT - 60);
    mkvar($iid * 10 + 2, $iid, 'temp', 'Temperatur', 2, 20.0, $nowT - 60);
}
$mp2 = new BWTest(); $mp2->Create(); $mp2->ApplyChanges();
$hist = []; foreach ([[201, 0.10], [202, 0.11], [203, 0.12], [204, 0.50]] as [$iid, $rt]) { $pts = []; for ($i = 0; $i <= 8; $i++) { $pts[] = [$nowT - (80 - $i * 10) * 86400, round(100 - $rt * $i * 10, 2), null]; } $hist[(string)$iid] = $pts; $GLOBALS['OBJ'][$iid * 10 + 1]['var']['value'] = (int)round(100 - $rt * 80); }
$mp2->SetValue('History', json_encode($hist)); $mp2->Check();
check('Vergleich nur innerhalb desselben Systems: zwei Gruppen zu je 2 Geräten → niemand auffällig', strpos(implode(' ', array_merge(...array_column(json_decode(end($mp2->visUpdates), true)['devices'], 'reasons'))), 'schneller als vergleichbare') === false);


// Matter: Batteriespannung in Millivolt
check('Matter PowerSource_BatVoltage wird als Spannung erkannt (laut Dokumentation, ungetestet)', kindOf(det('PowerSource_BatVoltage', 'Batteriespannung', 1)) === 'voltage/ident');
check('Matter PowerSource_BatPercentRemaining wird erkannt (Halbprozent)', ($mp = det('PowerSource_BatPercentRemaining', 'x', 1)) !== null && $mp['kind'] === 'percent' && $mp['scale'] === 0.5 && $mp['unverified'] === false);
$GLOBALS['OBJ'] = []; mkinst(12345, 'Batteriewächter', 'Batteriewaechter'); mkcat(900, 'Sensoren');
mkinst(401, 'Matter Kontakt', 'Matter Device', 900);
mkvar(4011, 401, 'BooleanState_State', 'Kontakt', 0, true, $GLOBALS['CLOCK'] - 60);
mkvar(4012, 401, 'PowerSource_BatVoltage', 'Batteriespannung', 1, 2950, $GLOBALS['CLOCK'] - 60);
$GLOBALS['INSTS'] = []; $mm = new BWTest(); $mm->Create(); $mm->props['DeviceSettings'] = json_encode([['Instance' => 401, 'Group' => 'ereignis', 'Critical' => false, 'IgnoreAge' => false, 'Excluded' => false, 'Cell' => 'cr2032', 'Cells' => 1, 'Poll' => false]]); $mm->ApplyChanges();
$plm = json_decode(end($mm->visUpdates), true);
check('Matter-Gerät mit 2950 mV und Zelltyp CR2032: 2,95 V → 95 % (nicht „2950 V“)', count($plm['devices']) === 1 && $plm['devices'][0]['derived'] === true && $plm['devices'][0]['percent'] == 95 && $plm['devices'][0]['status'] === 'ok', json_encode($plm['devices'][0], JSON_UNESCAPED_UNICODE));

// Matter: Stromversorgung (Endpunkt 0) und Kontakt (Endpunkt 1) desselben Knotens sind ein Gerät
check('Matter PowerSource_BatReplacementNeeded (Bool) → Flag „schwach“', kindOf(det('PowerSource_BatReplacementNeeded', 'Ersatz erforderlich', 0)) === 'flag/ident');
check('Matter BatChargeLevel wird NICHT als Prozent gelesen (0 = OK, 1 = Warnung, 2 = kritisch)', det('PowerSource_BatChargeLevel', 'Ladezustand', 1) === null);
check('Zelltyp aus Gerätebeschreibung: CR2032, CR123A, RCR123A, AAA, AA, 9V', array_map('BWACHZelle::fromDescription', ['CR2032', 'cr 123a', 'RCR123A', 'AAA', 'AA', '9V']) === ['cr2032', 'cr123a', 'rcr123a', 'aaa_alkali', 'aa_alkali', 'block9v']);
check('Unbekannte Gerätebeschreibung ergibt keinen Zelltyp', BWACHZelle::fromDescription('Sonderzelle X') === null && BWACHZelle::fromDescription('') === null);
$GLOBALS['OBJ'] = []; mkinst(12345, 'Batteriewächter', 'Batteriewaechter'); mkcat(900, 'Öffnungskontakte');
mkinst(501, 'Badfenster Senkrecht', 'Matter Device', 900); $GLOBALS['OBJ'][501]['config'] = ['NodeId' => 14, 'EndpointId' => 1];
mkvar(5011, 501, 'BooleanState_State', 'Kontakt', 0, true, $GLOBALS['CLOCK'] - 3600);
mkinst(502, 'Badfenster Senkrecht Stromversorgung', 'Matter Device', 900); $GLOBALS['OBJ'][502]['config'] = ['NodeId' => 14, 'EndpointId' => 0];
mkvar(5021, 502, 'PowerSource_BatPercentRemaining', 'Batteriestand', 1, 200, $GLOBALS['CLOCK'] - 20 * 86400);
mkvar(5022, 502, 'PowerSource_BatReplacementNeeded', 'Ersatz erforderlich', 0, false, $GLOBALS['CLOCK'] - 20 * 86400);
mkvar(5023, 502, 'PowerSource_BatReplacementDescription', 'Ersatz Beschreibung', 3, 'AAA', $GLOBALS['CLOCK'] - 20 * 86400);
mkinst(503, 'Anderer Kontakt', 'Matter Device', 900); $GLOBALS['OBJ'][503]['config'] = ['NodeId' => 8, 'EndpointId' => 1];
mkvar(5031, 503, 'BooleanState_State', 'Kontakt', 0, false, $GLOBALS['CLOCK'] - 60);
$GLOBALS['INSTS'] = ['GUID-Matter Device' => [501, 502, 503]]; $mn = new BWTest(); $mn->Create(); $mn->ApplyChanges();
$pln = json_decode(end($mn->visUpdates), true); $dn = $pln['devices'][0] ?? [];
check('Matter-Knoten: genau ein Gerät, mit dem Namen des Kontakts (nicht „… Stromversorgung“)', count($pln['devices']) === 1 && $dn['name'] === 'Badfenster Senkrecht', json_encode($pln['devices'], JSON_UNESCAPED_UNICODE));
check('Matter-Knoten: Batteriestand 200 (Halbprozent) = 100 %', ($dn['percent'] ?? null) == 100 && ($dn['status'] ?? '') === 'ok');
check('Matter-Knoten: Lebenszeichen vom Kontakt (vor 1 Stunde), nicht vom 20 Tage alten Batteriewert', ($dn['lifeText'] ?? '') === 'vor 1 Stunde' && ($dn['funk'] ?? '') === 'aktiv', json_encode($dn, JSON_UNESCAPED_UNICODE));
check('Matter-Knoten: Zelltyp „AAA“ vom Gerät übernommen, mit Hinweis auf die Bauform', strpos($dn['cellText'] ?? '', 'AAA') !== false && strpos($dn['cellText'] ?? '', 'laut Gerät') !== false && strpos($dn['cellText'], 'Alkali oder Akku') !== false, $dn['cellText'] ?? '');
$GLOBALS['OBJ'][5022]['var']['value'] = true; $mn->Check(); $dn2 = json_decode(end($mn->visUpdates), true)['devices'][0];
check('Matter „Ersatz erforderlich“ = Ja bei 100 %: Widerspruch erkannt', in_array('widerspruch', $dn2['quality'], true), json_encode($dn2, JSON_UNESCAPED_UNICODE));
$GLOBALS['OBJ'][5021]['var']['value'] = 8; $mn->Check(); $dn3 = json_decode(end($mn->visUpdates), true)['devices'][0];
check('Matter 8 (= 4 %) und „Ersatz erforderlich“: Batterie leer', $dn3['status'] === 'leer' && abs($dn3['percent'] - 4) < 0.01, json_encode($dn3, JSON_UNESCAPED_UNICODE));
$GLOBALS['OBJ'][5021]['var']['value'] = 200; $GLOBALS['OBJ'][5022]['var']['value'] = false;
$mn->props['DeviceSettings'] = json_encode([['Instance' => 502, 'Group' => 'ereignis', 'Critical' => false, 'IgnoreAge' => false, 'Excluded' => false, 'Cell' => 'aaa_nimh', 'Cells' => 2, 'Poll' => false]]); $mn->ApplyChanges();
check('Vom Nutzer gewählter Zelltyp hat Vorrang vor der Gerätebeschreibung', strpos(json_decode(end($mn->visUpdates), true)['devices'][0]['cellText'], '2× AAA Akku NiMH') === 0 && strpos(json_decode(end($mn->visUpdates), true)['devices'][0]['cellText'], 'laut Gerät') === false);

// Funkqualität in der Kachel
buildWorld($GLOBALS['CLOCK']);
mkvar(1015, 101, 'linkquality', 'Verbindungsqualität', 1, 30, $GLOBALS['CLOCK'] - 60);
$GLOBALS['INSTS'] = []; $ms = new BWTest(); $ms->Create(); $ms->ApplyChanges();
$pl = json_decode(end($ms->visUpdates), true);
$d101 = array_values(array_filter($pl['devices'], function ($d) { return $d['id'] === '101'; }))[0];
check('Funkqualität des Geräts steht in den Kachel-Daten (30 von 255, schwach)', $d101['signalText'] === 'Funkqualität 30 von 255 (schwach)', $d101['signalText']);
$noSig = array_values(array_filter($pl['devices'], function ($d) { return $d['id'] === '102'; }))[0];
check('Gerät ohne Funkvariable: kein Funkhinweis erfunden', $noSig['signalText'] === '');

// Außentemperatur im Verlauf
buildWorld($GLOBALS['CLOCK']);
mkvar(1900, 0, 'AussenTemp', 'Außentemperatur', 2, 3.46, $GLOBALS['CLOCK'] - 600);
$mt = new BWTest(); $mt->Create(); $mt->props['OutdoorTempVar'] = 1900; $mt->ApplyChanges();
$h = json_decode($mt->GetValue('History'), true);
check('Mit Außentemperatur-Variable wird die Temperatur im Verlauf mitgespeichert (gerundet)', isset($h['114']) && $h['114'][0][2] === 3.5, json_encode($h['114'] ?? null));
buildWorld($GLOBALS['CLOCK']); mkvar(1900, 0, 'AussenTemp', 'Außentemperatur', 2, 3.46, $GLOBALS['CLOCK'] - 13 * 3600);
$mt2 = new BWTest(); $mt2->Create(); $mt2->props['OutdoorTempVar'] = 1900; $mt2->ApplyChanges();
check('Veraltete Außentemperatur (13 Stunden) wird NICHT in den Verlauf geschrieben', json_decode($mt2->GetValue('History'), true)['114'][0][2] === null);
$mn = freshModule(['NotificationsActive' => false]);
check('Statistik ohne Außentemperatur-Variable sagt, wie man den Kälteeinfluss bekommt', strpos($mn->GetValue('TableStats'), 'keine Außentemperatur-Variable gewählt') !== false);
$mt2->Check();
check('Statistik mit Variable: „noch nicht genug Daten“ mit Zahlen', strpos($mt2->GetValue('TableStats'), 'noch nicht genug Daten') !== false);

// Auffällige Geräte im Modul: 4 Z-Wave-Sensoren, einer entlädt 4× so schnell
$GLOBALS['OBJ'] = []; mkinst(12345, 'Batteriewächter', 'Batteriewaechter'); mkcat(900, 'Sensoren');
$nowT = $GLOBALS['CLOCK'];
foreach ([[201, 'Sensor A', 0.10], [202, 'Sensor B', 0.11], [203, 'Sensor C', 0.12], [204, 'Sensor D', 0.50]] as [$iid, $nm, $rt]) {
    mkinst($iid, $nm, 'ShellyDevice', 900);
    mkvar($iid * 10 + 1, $iid, 'devicepower_0_battery_percent', 'Batteriestatus', 1, 70, $nowT - 60);
    mkvar($iid * 10 + 2, $iid, 'temp', 'Temperatur', 2, 20.0, $nowT - 60);
}
$mp = new BWTest(); $mp->Create(); $mp->props['DeviceSettings'] = '[]'; $mp->ApplyChanges();
$hist = []; foreach ([[201, 0.10], [202, 0.11], [203, 0.12], [204, 0.50]] as [$iid, $rt]) { $pts = []; for ($i = 0; $i <= 8; $i++) { $pts[] = [$nowT - (80 - $i * 10) * 86400, round(100 - $rt * $i * 10, 2), null]; } $hist[(string)$iid] = $pts; foreach ($pts as $pp) { } $GLOBALS['OBJ'][$iid * 10 + 1]['var']['value'] = (int)round(100 - $rt * 80); }
$mp->SetValue('History', json_encode($hist)); $mp->Check();
$pl = json_decode(end($mp->visUpdates), true);
$byName = []; foreach ($pl['devices'] as $d) { $byName[$d['name']] = $d; }
check('Auffälliges Gerät (Sensor D, 5× so schnell) trägt den Hinweis mit Zahlen und möglichen Ursachen', strpos(implode(' ', $byName['Sensor D']['reasons']), 'Entlädt 4,3× schneller als vergleichbare Geräte') !== false && strpos(implode(' ', $byName['Sensor D']['reasons']), 'Mögliche Ursachen') !== false, json_encode($byName['Sensor D']['reasons'], JSON_UNESCAPED_UNICODE));
check('Die drei Gleichartigen bleiben unauffällig', strpos(implode(' ', $byName['Sensor A']['reasons']), 'schneller') === false && strpos(implode(' ', $byName['Sensor C']['reasons']), 'schneller') === false);
check('Auffälligkeit: Dringlichkeit 450, zählt unter „Daten prüfen“, taucht in der Statistik auf', $byName['Sensor D']['urgency'] === 450 && $mp->GetValue('Check') >= 1 && strpos($mp->GetValue('TableStats'), 'Auffälliges Gerät') !== false && strpos($mp->GetValue('TableStats'), 'Sensor D') !== false);

// Abfrage schlafender Geräte
function pollWorld(int $ageDays, bool $pollOn, string $module = 'Z-Wave Module', bool $zwOk = true): BWTest
{
    $GLOBALS['OBJ'] = []; mkinst(12345, 'Batteriewächter', 'Batteriewaechter'); mkcat(900, 'Sensoren');
    $now = $GLOBALS['CLOCK'];
    mkinst(301, 'Schläfer', $module, 900);
    mkvar(3011, 301, 'BatteryVariable', 'Batterie', 1, 82, $now - $ageDays * 86400, '~Battery.100');
    mkvar(3012, 301, 'SensorMultilevel01Variable', 'Temperatur', 2, 20.0, $now - 600);
    $GLOBALS['ZW'] = []; $GLOBALS['ZW_OK'] = $zwOk; $GLOBALS['INSTS'] = [];
    $m = new BWTest(); $m->Create();
    $m->props['DeviceSettings'] = json_encode([['Instance' => 301, 'Group' => 'standard', 'Critical' => false, 'IgnoreAge' => false, 'Excluded' => false, 'Cell' => 'unbekannt', 'Cells' => 1, 'Poll' => $pollOn]]);
    $m->ApplyChanges();
    return $m;
}
clock('2026-10-08 12:00');
$mq = pollWorld(30, true);
check('Abfrage: Batteriewert 30 Tage alt (Schwelle 14), Gerät eingeschaltet → eine Statusanfrage an die Instanz', $GLOBALS['ZW'] === [301], json_encode($GLOBALS['ZW']));
$ps = json_decode($mq->GetValue('Poll'), true);
check('Abfrage: Zeitpunkt wird festgehalten, Antwort noch offen', isset($ps['301']) && $ps['301']['ans'] === null && $ps['301']['t'] === $GLOBALS['CLOCK']);
$pl = json_decode(end($mq->visUpdates), true);
check('Kachel meldet „Antwort steht noch aus“ samt Hinweis auf schlafende Geräte', strpos($pl['devices'][0]['pollText'], 'gesendet, Antwort steht noch aus (schlafende Geräte antworten erst beim Aufwachen)') !== false, $pl['devices'][0]['pollText']);
$GLOBALS['ZW'] = []; shiftWorld(3 * 86400); $mq->Check();
check('Nicht erneut innerhalb von 7 Tagen (Warteschlange der Z-Wave-Instanz nicht füllen)', $GLOBALS['ZW'] === []);
$GLOBALS['OBJ'][3011]['var']['VariableUpdated'] = $GLOBALS['CLOCK'] - 3600; $mq->Check();
check('Gerät meldet sich nach der Anfrage: „hat geantwortet“', strpos(json_decode(end($mq->visUpdates), true)['devices'][0]['pollText'], 'Gerät hat geantwortet') !== false && json_decode($mq->GetValue('Poll'), true)['301']['ans'] === true);
$mr = pollWorld(30, true);
shiftWorld(15 * 86400); $GLOBALS['OBJ'][3011]['var']['VariableUpdated'] = $GLOBALS['CLOCK'] - 45 * 86400; $GLOBALS['ZW'] = []; $mr->Check();
$pl = json_decode(end($mr->visUpdates), true); $dd = $pl['devices'][0];
check('Keine Antwort nach 14 Tagen: Befund „Reagiert nicht auf Abfragen“, Dringlichkeit 400', strpos(implode(' ', $dd['reasons']), 'Reagiert nicht auf Abfragen (seit ') !== false && $dd['urgency'] >= 400 && in_array('keine_antwort', $dd['quality'], true), json_encode($dd['reasons'], JSON_UNESCAPED_UNICODE));
check('…und nach 7+ Tagen eine neue Anfrage (Gerät lebt vielleicht doch); der Befund „ohne Antwort“ bleibt dabei sichtbar', $GLOBALS['ZW'] === [301] && strpos($dd['pollText'], 'Abfragen seit') === 0, $dd['pollText']);
$mo = pollWorld(30, false);
check('Abfrage ausgeschaltet (Standard): nie eine Anfrage', $GLOBALS['ZW'] === []);
$mf = pollWorld(5, true);
check('Batteriewert nur 5 Tage alt (unter 14): keine Anfrage', $GLOBALS['ZW'] === []);
$mz = pollWorld(30, true, 'ShellyDevice');
check('Anderes System als Z-Wave: keine Anfrage (nur dort ist die Funktion belegt)', $GLOBALS['ZW'] === []);
$GLOBALS['LOG'] = [];
$mg = pollWorld(30, true, 'Z-Wave Module', false); $GLOBALS['ZW_OK'] = true;
check('Nicht angenommene Anfrage: kein Zustand, Meldungslog nennt es', (json_decode($mg->GetValue('Poll'), true) ?: []) === [] && (bool)array_filter($GLOBALS['LOG'], function ($l) { return strpos($l, 'wurde nicht angenommen') !== false; }));


// ===========================================================================
heading('15 Geräteliste mit erkannten Geräten vorbelegt');
// ===========================================================================
$list = function (BWTest $m) { $f = json_decode($m->GetConfigurationForm(), true); foreach ($f['elements'] as $p) { foreach ($p['items'] ?? [] as $it) { if (($it['name'] ?? '') === 'DeviceSettings') { return [$it, $p]; } } } return [null, null]; };
$mDev = freshModule(['NotificationsActive' => false, 'DeviceSettings' => json_encode([['Instance' => 114, 'Group' => 'standard', 'Critical' => true, 'IgnoreAge' => false, 'Excluded' => false, 'Cell' => 'cr2032', 'Cells' => 2, 'Poll' => false]])]);
[$el, $panel] = $list($mDev);
$ids = array_column($el['values'], 'Instance');
check('Liste enthält jede erkannte Geräteinstanz genau einmal (Instanzen 101–107, 111, 112, 114; 105 trotz zweier Sensoren nur einmal)', count($ids) === count(array_unique($ids)) && count($ids) === 10 && in_array(105, $ids, true) && in_array(101, $ids, true), json_encode($ids));
check('Die bereits gespeicherte Zeile bleibt unverändert vorn (kritisch, CR2032, 2 Zellen)', $el['values'][0]['Instance'] === 114 && $el['values'][0]['Critical'] === true && $el['values'][0]['Cell'] === 'cr2032' && $el['values'][0]['Cells'] === 2);
check('Neue Zeilen tragen neutrale Standardwerte (Standard, nicht kritisch, Zelltyp unbekannt, 1 Zelle, keine Abfrage)', (function () use ($el) { foreach (array_slice($el['values'], 1) as $r) { if ($r['Group'] !== 'standard' || $r['Critical'] || $r['IgnoreAge'] || $r['Excluded'] || $r['Cell'] !== 'unbekannt' || $r['Cells'] !== 1 || $r['Poll']) { return false; } } return true; })());
check('Jede Zeile hat alle Spalten (nichts geht beim Speichern verloren)', (function () use ($el) { foreach ($el['values'] as $r) { if (array_keys($r) !== ['Instance', 'Group', 'Critical', 'IgnoreAge', 'Excluded', 'Cell', 'Cells', 'Poll']) { return false; } } return true; })());
check('Neue Zeilen nach Instanzname sortiert', (function () use ($el) { $names = array_map(function ($r) { return IPS_GetName($r['Instance']); }, array_slice($el['values'], 1)); $s = $names; usort($s, 'strcasecmp'); return $names === $s; })());
check('Liste liest nicht aus der Konfiguration, sondern aus den eingesetzten Werten (loadValuesFromConfiguration=false)', $el['loadValuesFromConfiguration'] === false);
check('Panel ist aufgeklappt, solange es neue Zeilen gibt; Hinweis nennt Zahl und „noch nicht gespeichert“', $panel['expanded'] === true && strpos(json_encode($panel, JSON_UNESCAPED_UNICODE), '9 neu, noch nicht gespeichert') !== false, '');
check('Es wird NICHTS gespeichert: die Eigenschaft bleibt wie sie war', count(json_decode($mDev->props['DeviceSettings'], true)) === 1);
// vollständig gespeichert
$all = []; foreach ($ids as $i) { $all[] = ['Instance' => $i, 'Group' => 'standard', 'Critical' => false, 'IgnoreAge' => false, 'Excluded' => false, 'Cell' => 'unbekannt', 'Cells' => 1, 'Poll' => false]; }
$mDev->props['DeviceSettings'] = json_encode($all);
[$el2, $panel2] = $list($mDev);
check('Nach „Übernehmen“: keine Doppelten, nichts Neues, Panel eingeklappt, Hinweis „alle stehen in der Liste“', count($el2['values']) === 10 && $panel2['expanded'] === false && strpos(json_encode($panel2, JSON_UNESCAPED_UNICODE), 'Alle erkannten Geräte stehen in der Liste') !== false);
// doppelte gespeicherte Zeilen derselben Instanz
$mDev->props['DeviceSettings'] = json_encode([$all[0], $all[0], $all[1]]);
[$el3] = $list($mDev);
check('Doppelte gespeicherte Zeilen derselben Instanz werden nicht verdoppelt', count(array_filter(array_column($el3['values'], 'Instance'), function ($i) use ($all) { return $i === $all[0]['Instance']; })) === 1);
// Geräte, die es nicht mehr gibt, bleiben (Einstellungen gehen nicht verloren)
$mDev->props['DeviceSettings'] = json_encode([['Instance' => 99999, 'Group' => 'ereignis', 'Critical' => true, 'IgnoreAge' => true, 'Excluded' => false, 'Cell' => 'aa_alkali', 'Cells' => 2, 'Poll' => true]]);
[$el4] = $list($mDev);
check('Gespeicherte Zeile einer verschwundenen Instanz bleibt erhalten, nichts wird still gelöscht', $el4['values'][0]['Instance'] === 99999 && $el4['values'][0]['Cell'] === 'aa_alkali' && $el4['values'][0]['Poll'] === true);
// Variablen ohne Instanz
$GLOBALS['OBJ'] = []; mkinst(12345, 'Batteriewächter', 'Batteriewaechter'); mkcat(900, 'Skripte');
mkvar(1700, 900, 'Battery', 'Batterie', 1, 50, $GLOBALS['CLOCK'] - 60);
$mV = new BWTest(); $mV->Create(); $mV->props['ManualVariables'] = json_encode([['Variable' => 1700, 'Kind' => 'percent']]); $mV->ApplyChanges();
[$elV, $pV] = $list($mV);
check('Ein Gerät ohne Instanz (manuelle Variable) gehört nicht in die Instanzliste', $elV['values'] === [] && strpos(json_encode($pV, JSON_UNESCAPED_UNICODE), 'Alle erkannten Geräte stehen in der Liste') === false);
$mE = new BWTest(); $mE->Create(); [$elE, $pE] = $list($mE);
check('Noch nicht gesucht: Hinweis „zuerst Jetzt neu suchen“', $elE['values'] === [] && strpos(json_encode($pE, JSON_UNESCAPED_UNICODE), 'zuerst oben „Jetzt neu suchen“') !== false);

// Hinweis in Kachel und Tabelle sagt genau, wo
$mH = freshModule(['NotificationsActive' => false]);
$plH = json_decode(end($mH->visUpdates), true);
check('Kachel-Daten nennen den Ort für den Zelltyp: Instanzname, Panel, Spalte', $plH['shopping']['where'] === 'Instanz „Batteriewächter“ öffnen, Panel „Geräte-Einstellungen“, Spalte „Zelltyp“', $plH['shopping']['where']);
check('Die Kachel zeigt diesen Ort beim fehlenden Zelltyp', strpos(file_get_contents($MODDIR . '/module.html'), "'Keine Zelltypen bekannt. Zelltyp je Gerät eintragen: ' + s.where") !== false && strpos(file_get_contents($MODDIR . '/module.html'), "'. Eintragen: ' + s.where") !== false);
$mH2 = freshModule(['NotificationsActive' => false, 'DeviceSettings' => json_encode([['Instance' => 114, 'Group' => 'standard', 'Critical' => true, 'IgnoreAge' => false, 'Excluded' => false, 'Cell' => 'unbekannt', 'Cells' => 1]])]);
check('Tabelle nennt Instanz, Panel und Spalte', strpos($mH2->GetValue('TableShopping'), 'in der Instanz „Batteriewächter“ unter „Geräte-Einstellungen“ in der Spalte „Zelltyp“ wählen') !== false, strip_tags($mH2->GetValue('TableShopping')));

echo "\n" . ($fails === 0 ? "Alle Prüfungen bestanden.\n" : "$fails Prüfung(en) fehlgeschlagen.\n");
exit($fails === 0 ? 0 : 1);
