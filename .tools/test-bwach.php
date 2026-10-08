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
function IPS_GetInstance(int $id): array { return ['ModuleInfo' => ['ModuleName' => $GLOBALS['OBJ'][$id]['module']], 'InstanceStatus' => 102]; }
function IPS_InstanceExists($id): bool { return isset($GLOBALS['OBJ'][(int)$id]) && $GLOBALS['OBJ'][(int)$id]['type'] === 1; }
function IPS_VariableExists($id): bool { return isset($GLOBALS['OBJ'][(int)$id]) && $GLOBALS['OBJ'][(int)$id]['type'] === 2; }
function IPS_GetName(int $id): string { return $GLOBALS['OBJ'][$id]['name'] ?? ''; }
function IPS_GetParent(int $id): int { return $GLOBALS['OBJ'][$id]['parent'] ?? 0; }
function IPS_GetChildrenIDs(int $p): array { $o = []; foreach ($GLOBALS['OBJ'] as $id => $x) { if ($x['parent'] === $p) { $o[] = $id; } } return $o; }
function GetValue($id) { return $GLOBALS['OBJ'][(int)$id]['var']['value']; }
function IPS_GetObjectIDByIdent(string $ident, int $parent) { foreach ($GLOBALS['OBJ'] as $id => $x) { if ($x['ident'] === $ident && $x['parent'] === $parent) { return $id; } } return false; }
$GLOBALS['INSTS'] = []; $GLOBALS['SENT'] = []; $GLOBALS['SEND_OK'] = true; $GLOBALS['LOG'] = [];
function IPS_SetHidden(int $id, bool $h): bool { $GLOBALS['OBJ'][$id]['hidden'] = $h; return true; }
function IPS_GetInstanceListByModuleID(string $g): array { return $GLOBALS['INSTS'][$g] ?? []; }
function IPS_LogMessage(string $s, string $m): bool { $GLOBALS['LOG'][] = "$s: $m"; return true; }
function VISU_PostNotificationEx($id, $t, $x, $icon, $sound, $target) { $GLOBALS['SENT'][] = ['visu', $id, $t, $x, $sound]; return $GLOBALS['SEND_OK']; }
function WFC_PushNotification($id, $t, $x, $sound, $target) { $GLOBALS['SENT'][] = ['wfc', $id, $t, $x, $sound]; return $GLOBALS['SEND_OK']; }
function SMTP_SendMailEx($id, $to, $subj, $body) { $GLOBALS['SENT'][] = ['mailex', $id, $subj, $body, $to]; return $GLOBALS['SEND_OK']; }
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
check('Matter BatPercentRemaining: Halbprozent-Skala und „ungetestet“ markiert', $matter !== null && $matter['scale'] === 0.5 && $matter['unverified'] === true);
check('Froggit-Ident soilbatt1 nur über Profil', det('soilbatt1', 'Batterie Bodenfeuchtesensor', 1) === null && kindOf(det('soilbatt1', 'x', 1, '~Battery.100')) === 'percent/profil');
check('Name „Batterie schwach“ → nur Namensvorschlag', kindOf(det('foo', 'Batterie schwach', 0)) === 'flag/name');
check('Name „Batteriestand“ → Prozent (Name)', kindOf(det('foo', 'Batteriestand', 1)) === 'percent/name');
foreach (['Batterieladung', 'Batterieentladung Gesamt', 'Batterieleistung (W)', 'Batterie SOC (%)', 'Batterie: Einstandspreis (ct/kWh)', 'Batteriemodule', 'Kategorie Batterie - Strom L2', 'Batterie (Nachtladen)', 'Batterie Aktoren - Leer', 'Ziel-SOC für die Autobeladung'] as $n) {
    check('Heimspeicher-Name wird nie erkannt: ' . $n, det('foo', $n, 1) === null && det('foo', $n, 2) === null && det('foo', $n, 0) === null);
}
check('Sammelwert-Namen erkannt', BWACHLogik::isAggregateName('Schwächste Batterie') && BWACHLogik::isAggregateName('Batterie Aktoren - Gesamt') && !BWACHLogik::isAggregateName('Batteriestand'));
check('Zahlenformat deutsch: 4,71 / 100 / −20', BWACHLogik::num(4.71) === '4,71' && BWACHLogik::num(100.0) === '100' && BWACHLogik::num(-20.0) === '-20');
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
check('Funkstille 16 Tage (Standard 7): still, Dringlichkeit 600', $r['funk'] === 'still' && $r['urgency'] === 600 && $r['status'] === 'ok');
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
check('Trockenlauf kennzeichnet Dokumentations-Signale als ungetestet (Matter-Skala)', strpos($m7->Preview(), 'laut Dokumentation, ungetestet') !== false);
check('Matter 150 (Halbprozent) wird als 75 % bewertet', strpos($m7->GetValue('TableAll'), '75 %') !== false);

// ===========================================================================
heading('5 Formular- und Dateihygiene');
// ===========================================================================
$form = json_decode($m6->GetConfigurationForm(), true);
check('Formular ist gültiges JSON', is_array($form) && isset($form['elements']));
$caps = array_map(function ($e) { return $e['caption']; }, $form['elements']);
$order = ['👋  Wozu dieses Modul?', '🆕  Neu bis Version 0.2.0', '📖  Dokumentation & Hilfe', '🔎  Gefundene Geräte', '🔋  Zustand', '🔔  Meldungen', '✅  Quittieren und Batterietagebuch', '⚙️  Schwellen', '🏷️  Geräte-Einstellungen', '➕  Weitere Variablen', '💬  Rückmeldungen', '🧡  Über dieses Modul'];
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
check('„Verstanden“ speichert die installierte Version und blendet das Panel aus', $m6->ReadAttributeString('SeenNews') === '0.2.0' && in_array(['NewsPanel', 'visible', false], $m6->fieldUpdates, true));
$form2 = json_decode($m6->GetConfigurationForm(), true);
check('News-Panel erscheint danach nicht mehr', !in_array('🆕  Neu bis Version 0.2.0', array_map(function ($e) { return $e['caption']; }, $form2['elements']), true));
$m6->AckPurposeIntro(); $m6->AckForumHint();
check('Zweck- und Forum-Hinweis einmalig wegklickbar', count(json_decode($m6->GetConfigurationForm(), true)['elements']) === 9);
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
    foreach ($f['elements'] as $p) { foreach ($p['items'] ?? [] as $it) { if (($it['name'] ?? '') === 'AckDevice') { return $it['options'][0]['value'] === '' && strpos($it['options'][1]['caption'], '—') !== false; } } }
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

echo "\n" . ($fails === 0 ? "Alle Prüfungen bestanden.\n" : "$fails Prüfung(en) fehlgeschlagen.\n");
exit($fails === 0 ? 0 : 1);
