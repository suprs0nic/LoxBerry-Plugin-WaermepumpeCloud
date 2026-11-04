<?php
require __DIR__ . '/../webfrontend/htmlauth/wp_lib.php';
define('BB_NUR_LADEN', true); require __DIR__ . '/../bin/befehlsbruecke.php';
$n = 0; $errors = 0;
function dc($ok, $why) { global $n, $errors; $n++; if (!$ok) { $errors++; fwrite(STDERR, "FAIL Daikin: $why\n"); } }
function leaves($node, $path, &$out) {
    if (!is_array($node) || !$node) { $out[$path] = $node; return; }
    foreach ($node as $key => $value) { leaves($value, $path . '.' . $key, $out); }
}
$raw = json_decode(file_get_contents(__DIR__ . '/fixtures/altherma.json'), true);
$device = $raw[0]; $expected = array(); $actual = array();
foreach ($device as $key => $value) {
    if ($key !== 'managementPoints') { leaves($value, $key, $expected); }
}
foreach ($device['managementPoints'] as $point) {
    foreach ($point as $key => $value) { leaves($value, 'managementPoints[embeddedId=' . $point['embeddedId'] . '].' . $key, $expected); }
}
$catalog = wp_daikin_catalog($raw, 'fixture-gateway');
foreach ($catalog as $topic => $row) {
    leaves($row['wert'], $row['quelle'], $actual);
    dc(strpos($topic, 'Daikin/') === 0 && wp_mqtt_befehl($topic, $row['payload']) === 'publish', 'namespace/non-retain ' . $topic);
    dc(wp_mqtt_wert_saeubern($row['payload']) === $row['payload'], 'safe unchanged gateway payload ' . $topic);
}
ksort($expected); ksort($actual);
dc($actual === $expected, 'every response leaf and metadata preserved exactly');
dc($catalog['Daikin/heizung/targetTemperature/value']['dokumentiert'] === false, 'targetTemperature not falsely documented');
dc($catalog['Daikin/ww/temperatureControl/value/operationModes/heating/setpoints/domesticHotWaterTemperature/settable']['wert'] === false, 'DHW read-only leaf preserved');
dc(isset($catalog['Daikin/energie/ww/consumptionData/value/electrical/heating/d']), 'WW energy correctly grouped');
dc(wp_daikin_catalog($raw, 'other-device') === array(), 'wrong device rejected');
$multi = $raw; $multi[0]['managementPoints'][] = $device['managementPoints'][1];
$multi[0]['managementPoints'][6]['embeddedId'] = 'second';
$multiMap = wp_daikin_catalog($multi, 'fixture-gateway');
dc(isset($multiMap['Daikin/heizung/punkt_1/onOffMode/value'], $multiMap['Daikin/heizung/punkt_second/onOffMode/value']), 'multiple points never overwrite each other');
$mut = $device; $mut['managementPoints'][1]['name'] = array('value' => "room\npublish injected 1", 'settable' => false);
$mutMap = wp_daikin_catalog($mut, 'fixture-gateway');
dc(strpos($mutMap['Daikin/heizung/name/value']['payload'], "\n") === false && json_decode($mutMap['Daikin/heizung/name/value']['payload']) === "room\npublish injected 1", 'text reversibly encoded without injection');
$now = strtotime('2026-11-04T12:00:00Z');
$cfg = array_merge(wp_vorgaben(), array('hersteller' => 'onecta', 'geraet' => 'fixture-gateway', 'mqtt_ein' => 1,
    'befehle_mqtt_ein' => 1, 'befehle_mqtt_steuern' => 1, 'oc_daikin_schreiben' => 1, 'oc_daikin_zeitplan' => 1,
    'oc_daikin_urlaub' => 1, 'oc_daikin_firmware' => 1, 'oc_energie_zeitzone' => 'Europe/Vienna'));
$stand = array('ok' => 1, 'oc_zusatz_zeit' => $now, 'oc_zusatz_geraet' => 'fixture-gateway', 'roh' => $raw);
$plan = function ($name, $body, $s = null, $c = null) use ($stand, $cfg, $now) {
    return wp_daikin_plan($name, is_array($body) ? json_encode($body) : $body, $c ?? $cfg, $s ?? $stand, $now);
};
list($p, $why) = $plan('ww/boost', '1');
dc($p && $p['point'] === '2' && $p['method'] === 'PATCH' && $p['suffix'] === 'characteristics/powerfulMode' && $p['body'] === array('value' => 'on'), 'WW boost numeric endpoint and documented body');
dc($plan('ww/boost', '0')[1] === 'UNVERAENDERT', 'unchanged boost consumes no request');
dc($plan('heizung/ein', '0')[0]['point'] === '1', 'heating off correct ID');
dc($plan('ww/ein', '0')[0]['suffix'] === 'characteristics/onOffMode', 'WW enabled distinct from boost');
dc($plan('ww/setzen', array('characteristic' => 'temperatureControl', 'path' => '/operationModes/heating/setpoints/domesticHotWaterTemperature', 'value' => 50))[1] === 'NICHT_SCHREIBBAR', 'read-only WW setpoint cannot be bypassed');
dc($plan('ww/soll', '50')[1] === 'NICHT_SCHREIBBAR', 'WW simple setpoint respects current read-only metadata');
$writable = $stand;
$writable['roh'][0]['managementPoints'][2]['heatupMode']['value'] = 'reheatOnly';
dc($plan('ww/soll', '50', $writable)[1] === 'NICHT_SCHREIBBAR', 'reheatOnly name alone does not authorize a write');
$writable['roh'][0]['managementPoints'][2]['temperatureControl']['value']['operationModes']['heating']['setpoints']['domesticHotWaterTemperature']['settable'] = true;
list($wwPlan) = $plan('ww/soll', '50', $writable);
dc($wwPlan && $wwPlan['point'] === '2' && $wwPlan['body'] == array('value' => 50.0, 'path' => '/operationModes/heating/setpoints/domesticHotWaterTemperature')
    && $wwPlan['body']['value'] === 50.0, 'WW setpoint becomes usable after device reports writable');
foreach (array('29', '61', '50.5', 'bad') as $badValue) { dc($plan('ww/soll', $badValue, $writable)[0] === null, 'newly writable WW still obeys min/max/step'); }
foreach (array('controlMode', 'setpointMode', 'targetTemperature', 'heatupMode', 'isInErrorState') as $feature) {
    dc($plan('heizung/setzen', array('characteristic' => $feature, 'value' => 'on'))[0] === null, 'no installer/undocumented setter ' . $feature);
}
dc($plan('heizung/setzen', array('characteristic' => 'temperatureControl', 'path' => '/operationModes/cooling/setpoints/leavingWaterTemperature', 'value' => 21))[0]['body']['value'] === 21, 'documented per-mode flow setpoint');
foreach (array(17, 23, 20.5, '21', true, null) as $bad) {
    dc($plan('heizung/setzen', array('characteristic' => 'temperatureControl', 'path' => '/operationModes/cooling/setpoints/leavingWaterTemperature', 'value' => $bad))[0] === null, 'flow range/step/types rejected');
}
foreach (array('mqtt_ein', 'befehle_mqtt_ein', 'befehle_mqtt_steuern', 'oc_daikin_schreiben') as $flag) {
    $c = $cfg; $c[$flag] = 0; dc($plan('ww/boost', '1', null, $c)[1] === 'NICHT_FREIGEGEBEN', 'gate ' . $flag);
}
$s = $stand; $s['oc_zusatz_zeit'] = $now - 4000; dc($plan('ww/boost', '1', $s)[1] === 'DATEN_VERALTET', 'stale capabilities rejected');
foreach (array('isInInstallerState', 'isHolidayModeActive') as $flag) {
    $s = $stand; $s['roh'][0]['managementPoints'][2][$flag]['value'] = true;
    dc($plan('ww/boost', '1', $s)[0] === null, 'WW own state checked ' . $flag);
}
$s = $stand; $s['roh'] = $multi;
dc($plan('heizung/ein', '0', $s)[1] === 'GERAETEBEREICH_FEHLT', 'multiple heating points not guessed');
list($p) = $plan('heizung/urlaub', array('enabled' => true, 'startDate' => '2026-11-05', 'endDate' => '2026-11-08'));
dc($p && $p['method'] === 'POST' && $p['suffix'] === 'holiday-mode', 'holiday dedicated endpoint');
dc($plan('heizung/urlaub', array('enabled' => false, 'startDate' => '2026-11-05'))[0] === null, 'holiday off forbids dates');
foreach (array('2026-11-01', '2026-02-30', '2026-11-05T12:00:00Z') as $date) {
    dc($plan('heizung/urlaub', array('enabled' => true, 'startDate' => $date, 'endDate' => '2026-11-08'))[0] === null, 'invalid holiday date ' . $date);
}
$s = $stand; $s['roh'][0]['managementPoints'][1]['isHolidayModeActive']['value'] = true;
dc($plan('heizung/urlaub', array('enabled' => false), $s)[0]['body'] === array('enabled' => false), 'active holiday can be disabled');
list($p) = $plan('heizung/zeitplan_auswahl', array('mode' => 'heating', 'scheduleId' => '0', 'enabled' => true));
dc($p && $p['method'] === 'PUT' && $p['point'] === '1' && $p['suffix'] === 'schedule/heating/current'
    && $p['body'] === array('scheduleId' => '0', 'enabled' => true), 'schedule selection string id and bool body');
dc($plan('ww/zeitplan_auswahl', array('mode' => 'heating', 'scheduleId' => '0', 'enabled' => false))[0] === null, 'WW read-only schedule enable cannot be disabled');
dc($plan('ww/zeitplan_auswahl', array('mode' => 'heating', 'scheduleId' => '0', 'enabled' => true))[1] === 'UNVERAENDERT', 'WW unchanged read-only enable causes no request');
foreach (array(array('mode' => 'heating', 'scheduleId' => 0, 'enabled' => true), array('mode' => 'cooling', 'scheduleId' => '0', 'enabled' => true), array('mode' => 'heating', 'scheduleId' => '999', 'enabled' => true)) as $body) {
    dc($plan('ww/zeitplan_auswahl', $body)[0] === null, 'bad schedule selection rejected');
}
$body = array('mode' => 'heating', 'schedules' => array('0' => array('actions' => array('monday' => array('10:00:00' => array('roomTemperature' => 21)))),
    '1' => array('actions' => array()), '2' => array('actions' => array())));
list($p) = $plan('heizung/zeitplan_inhalt', $body);
dc($p && $p['suffix'] === 'schedule/heating/schedules', 'action schedule dedicated endpoint');
$json = json_encode(wp_daikin_request_object($p['body']));
dc(strpos($json, '{"0":') === 0 && strpos($json, '"actions":{}') !== false, 'schedule numeric IDs and empty actions serialized as objects');
$bad = $body; unset($bad['schedules'][2]); dc($plan('heizung/zeitplan_inhalt', $bad)[1] === 'ALLE_PLAN_IDS_ERFORDERLICH', 'partial destructive schedule update refused');
$bad = $body; $bad['schedules'][0]['actions']['monday']['10:05:00'] = array('roomTemperature' => 22);
dc($plan('heizung/zeitplan_inhalt', $bad)[1] === 'AKTIONSZEIT_UNGUELTIG', 'action interval and grid enforced');
$bad = $body; $bad['schedules'][0]['actions']['monday']['10:00:00']['roomTemperature'] = 21.5;
dc($plan('heizung/zeitplan_inhalt', $bad)[1] === 'AKTIONSWERT_UNGUELTIG', 'schedule own step differs from direct setpoint');
$s = $stand; $s['roh'][0]['managementPoints'][1]['schedule']['value']['modes']['heating']['schedules'][0]['meta']['isReadOnly'] = true;
dc($plan('heizung/zeitplan_inhalt', $body, $s)[1] === 'ZEITPLAN_SCHREIBGESCHUETZT', 'read-only schedule refused');
$s = $stand; $s['roh'][0]['managementPoints'][1]['schedule']['value']['modes']['heating']['schedules'][1]['meta']['isReadOnly'] = true;
dc($plan('heizung/zeitplan_inhalt', $body, $s)[0] !== null, 'required read-only plan IDs may be carried unchanged');
dc(wp_daikin_action_value(array('heating' => array('minValue' => 10, 'maxValue' => 30, 'stepValue' => 0.5)), 21.5, 'heating'), 'mode-dependent documented action metadata');
dc(wp_daikin_action_value(array('currentMode' => array('values' => array('auto', 'fixed')), 'modes' => array('fixed' => array('minValue' => 1, 'maxValue' => 5, 'stepValue' => 1))), array('currentMode' => 'fixed', 'modes' => array('fixed' => 3))), 'documented nested fan action metadata');
dc($plan('heizung/blockzeitplan_auswahl', array('mode' => 'heating', 'scheduleId' => '0', 'enabled' => true))[1] === 'ZEITPLAN_NICHT_UNTERSTUETZT', 'absent block plan not invented');
$s = $stand;
$s['roh'][0]['managementPoints'][1]['blockScheduleV2'] = array('settable' => true, 'value' => array('modes' => array('heating' => array(
    'meta' => array('minBlockSize' => 10, 'timeStepMinutes' => 10, 'maxBlocksPerDay' => 2,
        'blockTypes' => array('roomTemperature' => array('minValue' => 12, 'maxValue' => 30, 'stepValue' => 1))),
    'schedules' => array('block1' => array('meta' => array('isReadOnly' => false, 'blockPeriods' => array('monday')),
        'defaultBlockValues' => array('roomTemperature' => array('settable' => true))))))));
$block = array('mode' => 'heating', 'schedules' => array('block1' => array('defaultBlockValues' => array('roomTemperature' => array('value' => 20)),
    'blocks' => array('monday' => array('periods' => array(array('startTime' => '08:00:00', 'endTime' => '12:00:00', 'roomTemperature' => 22)))))));
list($p, $why) = $plan('heizung/blockzeitplan_inhalt', $block, $s);
dc($p && $p['suffix'] === 'block-schedule/heating/schedules', 'documented block content shape accepted with synthetic metadata');
$encoded = json_encode(wp_daikin_request_object($p['body']));
dc(strpos($encoded, '"periods":[') !== false && strpos($encoded, '"defaultBlockValues":{') !== false, 'block periods remain JSON arrays');
$bad = $block; unset($bad['schedules']['block1']['defaultBlockValues']);
dc($plan('heizung/blockzeitplan_inhalt', $bad, $s)[0] === null, 'block default required');
$bad = $block; $bad['schedules']['block1']['blocks']['monday']['periods'][0]['startTime'] = '08:05:00';
dc($plan('heizung/blockzeitplan_inhalt', $bad, $s)[1] === 'BLOCKZEIT_UNGUELTIG', 'block grid enforced');
$bad = $block; $bad['schedules']['block1']['blocks']['monday']['periods'][] = array('startTime' => '10:00:00', 'endTime' => '13:00:00', 'roomTemperature' => 23);
dc($plan('heizung/blockzeitplan_inhalt', $bad, $s)[1] === 'BLOECKE_UEBERLAPPEN', 'overlapping blocks refused');
$bad = $block; $bad['schedules']['block1']['blocks']['monday']['periods'][0]['roomTemperature'] = 22.5;
dc($plan('heizung/blockzeitplan_inhalt', $bad, $s)[1] === 'BLOCKWERT_UNGUELTIG', 'block setpoint step enforced');
dc($plan('diagnose/gateway/firmware', 'not-offered')[1] === 'KEIN_PASSENDES_FIRMWAREANGEBOT', 'capability alone never starts firmware');
$s = $stand; $s['roh'][0]['managementPoints'][0]['firmwareUpdate'] = array('settable' => true, 'value' => array('id' => 'firmware-test', 'type' => 'blocking'));
dc($plan('diagnose/gateway/firmware', 'firmware-test', $s)[0]['suffix'] === 'firmware/firmware-test', 'offered firmware explicitly addressable even when blocking');
dc($plan('ww/boost', '1', $s)[1] === 'FIRMWARE_BLOCKIERT', 'blocking firmware prevents other operations');
$s['roh'][0]['managementPoints'][0]['firmwareUpdate']['settable'] = false;
dc($plan('diagnose/gateway/firmware', 'firmware-test', $s)[0] === null, 'firmware settable enforced');
list($table, $error) = bb_zuordnung_laden(); dc($error === '' && !bb_zuordnung_pruefen($table)[0], 'full new bridge table valid');
$calls = array(); $acks = array();
foreach ($table['befehle'] as $key => &$entry) { $entry['tun'] = function ($v, $t) use (&$calls, $key) { $calls[] = array($key, $v); return array(true, ''); }; } unset($entry);
$state = bb_neuer_zustand(); $settings = array('praefix' => 'test/custom', 'annehmen' => true, 'eingreifend' => true, 'mqtt_ein' => true);
foreach (array('1', '0') as $retain) {
    bb_stapel(array($retain . ' test/custom/Daikin/befehl/ww/boost ' . bin2hex('1')), $state, $table, $settings,
        function ($name, $text) use (&$acks) { $acks[] = bb_thema_text('test/custom', $name) . '/quittung'; }, function () use ($now) { return $now; });
    dc(count($calls) === ($retain === '1' ? 0 : 1), 'Daikin retained rejection and real dispatch');
}
dc($acks === array('test/custom/Daikin/befehl/ww/boost/quittung'), 'ack under same Daikin subtree');
dc(strpos(bb_vorlage(bb_liste($table, $settings), 'loxberry', 11884), 'publish test/custom/Daikin/befehl/ww/boost 1') !== false, 'Loxone template uses new root');
$index = file_get_contents(__DIR__ . '/../webfrontend/htmlauth/index.php');
preg_match_all('/data-ziel="tab-([a-z0-9]+)"\s+href="index\.php\?form=([a-z0-9]+)"><\?= wp_e\(wp_t\(\'(REITER\.[A-Z0-9_]+)\'\)\) \?><\/a>/', $index, $tabs, PREG_SET_ORDER);
dc(count($tabs) === 7 && count(array_filter($tabs, function ($tab) { return $tab[1] === 'daikin' && $tab[2] === 'daikin'; })) === 1, 'new tab remains recognized by existing LoxBerry self-check');
foreach (array('de', 'en') as $lang) {
    $ini = parse_ini_file(__DIR__ . '/../templates/lang/language_' . $lang . '.ini', true, INI_SCANNER_RAW);
    dc($ini['REITER']['DAIKIN'] === 'Daikin', 'tab translation resolves ' . $lang);
}
echo "Daikin: $n checks, $errors failures; " . count($catalog) . " catalogue topics; " . count($expected) . " response leaves\n";
exit($errors ? 1 : 0);
