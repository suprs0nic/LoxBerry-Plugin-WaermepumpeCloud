<?php
/** Offline tests of real mapping and bridge parsing. No credentials or cloud calls. */
require __DIR__ . '/../webfrontend/htmlauth/wp_lib.php';
define('BB_NUR_LADEN', true);
require __DIR__ . '/../bin/befehlsbruecke.php';
$n = 0; $errors = 0;
function check($condition, $message) {
    global $n, $errors; $n++;
    if (!$condition) { $errors++; fwrite(STDERR, 'FAIL: ' . $message . "\n"); }
}
$raw = json_decode(file_get_contents(__DIR__ . '/fixtures/altherma.json'), true);
$device = $raw[0];
$cfg = array_merge(wp_vorgaben(), array('hersteller' => 'onecta', 'geraet' => 'fixture-gateway'));
$map = wp_oc_ext_map($raw, 'fixture-gateway');
foreach (array('heizmodus' => 'heating', 'heiz_offset' => '0', 'ww_ein' => '1', 'cloud_online' => '1',
    'ww_zeitprogramm' => '1', 'fehler_heizung' => '0', 'ww_naechste_startzeit' => '10:00:00',
    'ww_naechste_aktion_wert' => 'comfort', 'ww_naechste_wochentag' => 'thursday',
    'diagnose/gateway_modell' => 'BRP069A61', 'diagnose/innengeraet_modell' => 'EHBX08DA9W7') as $key => $expected) {
    check($map['zusatz/' . $key] === $expected, 'fixture ' . $key);
}
check(wp_oc_ext_map($device, 'fixture-gateway') === $map, 'single-device and array equivalent');
$reordered = $device; $reordered['managementPoints'] = array_reverse($reordered['managementPoints']);
check(wp_oc_ext_map($reordered) === $map, 'managementPoints order independent');
foreach (array(null, false, 42, 'bad', array(), array('managementPoints' => 'bad'), array('managementPoints' => array(null, 42))) as $invalid) {
    check(count(array_filter(wp_oc_ext_map($invalid), function ($v) { return $v !== '-'; })) === 0, 'invalid/missing data');
}
check(wp_oc_ext_device(array($device, $device)) === array(), 'ambiguous list rejected');
check(wp_oc_ext_device($raw, 'another-device') === array(), 'wrong gateway rejected');
$duplicate = $device; $duplicate['managementPoints'][] = $device['managementPoints'][1];
check(wp_oc_ext_map($duplicate)['zusatz/heizmodus'] === '-', 'duplicate heating circuits not guessed');
foreach (array(true => '1', false => '0') as $v => $expected) { check(wp_oc_ext_payload($v, 'bool') === $expected, 'bool integers'); }
foreach (array('on' => '1', 'off' => '0') as $v => $expected) { check(wp_oc_ext_payload($v, 'bool') === $expected, 'bool on/off'); }
foreach (array(null, 'unknown', 2, array()) as $v) { check(wp_oc_ext_payload($v, 'bool') === '-', 'invalid bool'); }
check(wp_oc_ext_payload("test\npublish injected 1", 'text') === '-', 'text injection rejected');
check(wp_oc_ext_payload('22', 'number') === '-', 'numeric strings not silently accepted as measurements');
check(wp_oc_ext_payload(0, 'number') === '0', 'real zero retained');
foreach (array('heizung', 'kuehlung', 'warmwasser') as $kind) {
    $energy = json_decode($map['zusatz/energie/' . $kind], true);
    check($energy['einheit'] === 'kWh' && $energy['zeitzuordnung'] === 'unbekannt', 'energy unit and unknown dates');
    check(count($energy['reihen']['d']['werte']) === 24 && count($energy['reihen']['w']['werte']) === 14
        && count($energy['reihen']['m']['werte']) === 24, 'fixture series lengths');
    check($energy['reihen']['d']['werte'][23] === null, 'null energy gap');
}
$energy = wp_oc_ext_energy(array('d' => array(0, null, 1.5, -1, '2', false, array(), INF), 'w' => array(), 'm' => 'bad'));
check($energy['reihen']['d']['werte'] === array(0, null, 1.5, null, null, null, null, null), 'energy values preserve order/gaps');
check($energy['reihen']['d']['gueltige_werte'] === 2, 'energy valid count');
check(wp_oc_ext_energy(array('d' => array(2 => 10))) === null, 'sparse/object series rejected');
$badunit = $device; $badunit['managementPoints'][1]['consumptionData']['value']['electrical']['unit'] = 'W';
check(wp_oc_ext_map($badunit)['zusatz/energie/heizung'] === '-', 'wrong unit rejected');
$old = wp_umrechnen($raw, $cfg)['werte'];
foreach (array('AUSSEN', 'VORLAUF', 'RAUM', 'SOLL', 'WW', 'WWSOLL', 'WWZWANG', 'EIN') as $key) {
    check(isset($old[$key]), 'legacy field available: ' . $key);
}
check(!isset($old['RUECKLAUF']) && !isset($old['LEISTUNG']), 'no invented return temperature or instantaneous power');
$legacyDevice = $device;
foreach ($legacyDevice['managementPoints'] as &$p) { $p['embeddedId'] = $p['managementPointType']; } unset($p);
check(wp_umrechnen($legacyDevice, $cfg)['werte'] === $old, 'legacy string embeddedIds unchanged');
$custom = $cfg; $custom['zuordnung'] = 'SOLL=managementPoints[embeddedId=1].temperatureControl.value.operationModes.heating.setpoints.roomTemperature.value';
check(wp_umrechnen($raw, $custom)['werte']['SOLL'] === $old['SOLL'], 'custom mapping preserved');
$custom['zuordnung'] = 'SOLL=missing.path';
check(!isset(wp_umrechnen($raw, $custom)['werte']['SOLL']), 'custom mapping never silently falls back');

// Regression expectations for every other existing manufacturer, including digital conversions.
$vendors = array(
    'melcloud' => array(array('OutdoorTemperature' => 4, 'FlowTemperature' => 31, 'ReturnTemperature' => 26,
        'Power' => true, 'ForcedHotWaterMode' => false), array('AUSSEN' => 4.0, 'VORLAUF' => 31.0, 'RUECKLAUF' => 26.0, 'EIN' => 1, 'WWZWANG' => 0)),
    'myuplink' => array(array(array('parameterId' => '40004', 'value' => 4), array('parameterId' => '40008', 'value' => 31),
        array('parameterId' => '40012', 'value' => 26)), array('AUSSEN' => 4.0, 'VORLAUF' => 31.0, 'RUECKLAUF' => 26.0)),
    'vaillant' => array(array('state' => array('system' => array('outdoorTemperature' => 4, 'systemOff' => false),
        'circuits' => array(array('currentCircuitFlowTemperature' => 31)))), array('AUSSEN' => 4.0, 'VORLAUF' => 31.0, 'EIN' => 1)),
    'emsesp' => array(array('boiler' => array('outdoortemp' => 4, 'curflowtemp' => 31, 'rettemp' => 26)),
        array('AUSSEN' => 4.0, 'VORLAUF' => 31.0, 'RUECKLAUF' => 26.0)),
);
foreach ($vendors as $vendor => $test) {
    $c = $cfg; $c['hersteller'] = $vendor;
    $result = wp_umrechnen($test[0], $c)['werte'];
    foreach ($test[1] as $key => $expected) { check(isset($result[$key]) && $result[$key] === $expected, $vendor . ' regression ' . $key); }
}
foreach ($map as $topic => $payload) {
    check(wp_mqtt_befehl($topic, $payload) === 'publish', 'new topic nonretained ' . $topic);
    check(wp_mqtt_wert_saeubern($payload) === $payload && strlen($payload) < 16000, 'gateway-safe payload ' . $topic);
}

$cfg = array_merge($cfg, array('befehle_mqtt_ein' => 1, 'befehle_mqtt_steuern' => 1,
    'oc_heiz_offset' => 1, 'oc_raum_soll' => 1, 'oc_heizmodus' => 1, 'sg_angefordert' => 0));
$now = 2000000000;
$stand = array('ok' => 1, 'oc_zusatz_zeit' => $now, 'oc_zusatz_geraet' => 'fixture-gateway', 'roh' => $raw);
$plan = function ($name, $value, $c = null, $s = null) use ($cfg, $stand, $now) {
    return wp_oc_ext_plan($name, $value, $c === null ? $cfg : $c, $s === null ? $stand : $s, $now);
};
foreach (array(-10, -1, 1, 10) as $v) {
    list($p, $r) = $plan('heiz_offset', (string) $v);
    check($p && $p['punkt'] === '1' && $p['merkmal'] === 'temperatureControl'
        && $p['pfad'] === '/operationModes/heating/setpoints/leavingWaterOffset' && $p['wert'] === (float) $v, 'offset request plan');
}
foreach (array(-11, 11, 1.5, 'nan', '1e1', '1/../../foo', '1,5', array(), true, INF) as $v) {
    check($plan('heiz_offset', $v)[0] === null, 'invalid offset rejected');
}
check($plan('heiz_offset', 0)[1] === 'UNVERAENDERT', 'no-op no cloud request');
check($plan('raum_soll', '22.5')[0]['pfad'] === '/operationModes/heating/setpoints/roomTemperature', 'room endpoint verified shape');
foreach (array(11, 31, 22.25, '22,5') as $v) { check($plan('raum_soll', $v)[0] === null, 'room bounds/step'); }
foreach (array('cooling', 'auto') as $v) { check($plan('heizmodus', $v)[0]['wert'] === $v, 'operation mode supported'); }
check($plan('heizmodus', 'dry')[0] === null, 'mode allowlist');
check($plan('ww_soll', '50')[0] === null, 'DHW setpoint never supported');
foreach (array('mqtt_ein', 'befehle_mqtt_ein', 'befehle_mqtt_steuern', 'oc_heiz_offset') as $flag) {
    $c = $cfg; $c[$flag] = 0; check($plan('heiz_offset', 1, $c)[1] === 'NICHT_FREIGEGEBEN', 'gate ' . $flag);
}
$c = $cfg; $c['hersteller'] = 'melcloud'; check($plan('heiz_offset', 1, $c)[1] === 'NICHT_UNTERSTUETZT', 'no cross-vendor command');
$c = $cfg; $c['sg_angefordert'] = 1; $c['sg_stufe'] = 3; check($plan('heiz_offset', 1, $c)[1] === 'SGREADY_AKTIV', 'SG conflict');
$c = $cfg; $c['geraet'] = 'new'; check($plan('heiz_offset', 1, $c)[1] === 'DATEN_VERALTET', 'device switch invalidates cache');
foreach (array(0, $now - 3601, $now + 1) as $stamp) {
    $s = $stand; $s['oc_zusatz_zeit'] = $stamp; check($plan('heiz_offset', 1, null, $s)[1] === 'DATEN_VERALTET', 'stale/future/missing snapshot');
}
foreach (array(false, null, 'true') as $settable) {
    $s = $stand; $s['roh'][0]['managementPoints'][1]['temperatureControl']['value']['operationModes']['heating']['setpoints']['leavingWaterOffset']['settable'] = $settable;
    check($plan('heiz_offset', 1, null, $s)[1] === 'NICHT_SCHREIBBAR', 'strict settable');
}
$s = $stand; $s['roh'][0]['isCloudConnectionUp']['value'] = false;
check($plan('heiz_offset', 1, null, $s)[1] === 'CLOUD_OFFLINE', 'offline fails closed');
$s = $stand; unset($s['roh'][0]['managementPoints'][1]['temperatureControl']['value']['operationModes']['heating']['setpoints']['leavingWaterOffset']['stepValue']);
check($plan('heiz_offset', 1, null, $s)[1] === 'BEREICH_UNBEKANNT', 'missing metadata');
$s = $stand; $s['roh'][0]['managementPoints'][1]['operationMode']['value'] = 'cooling';
check($plan('raum_soll', '23.5', null, $s)[0]['pfad'] === '/operationModes/cooling/setpoints/roomTemperature', 'active mode room target');
check($plan('heiz_offset', '1', null, $s)[1] === 'BETRIEBSART_UNPASSEND', 'offset only heating');

list($z, $error) = bb_zuordnung_laden();
check($z !== null && $error === '', 'bridge table loads');
check(!bb_zuordnung_pruefen($z)[0], 'bridge table valid');
$calls = array(); $acks = array();
foreach ($z['befehle'] as $key => &$entry) {
    $entry['tun'] = function ($w, $t) use (&$calls, $key) { $calls[] = array($key, $w); return array(true, ''); };
} unset($entry);
$state = bb_neuer_zustand();
$settings = array('praefix' => 'test/hp', 'annehmen' => true, 'eingreifend' => true, 'mqtt_ein' => true);
$run = function ($name, $value, $retained = '0') use (&$state, $z, $settings, &$acks, $now) {
    return bb_stapel(array($retained . ' test/hp/befehl/' . $name . ' ' . bin2hex($value)), $state, $z, $settings,
        function ($n, $v) use (&$acks) { $acks[] = array($n, $v); }, function () use ($now) { return $now; });
};
foreach (array('heiz_offset' => '2', 'raum_soll' => '22.5', 'heizmodus' => 'cooling', 'sgready' => '2', 'ww_boost' => '1') as $name => $value) {
    $count = count($calls); $run($name, $value, '1'); check(count($calls) === $count, 'retained discarded ' . $name);
    $run($name, $value); check(count($calls) === $count + 1, 'valid bridge dispatch ' . $name);
}
check(count($acks) === 5, 'retained commands generate no acknowledgements');
$count = count($calls); $run('heiz_offset', '99'); $run('raum_soll', 'bad'); $run('heizmodus', 'dry');
check(count($calls) === $count, 'bridge rejects malformed payloads');
check(strpos(bb_vorlage(bb_liste($z, $settings), 'loxberry', 11884), 'publish test/hp/befehl/heiz_offset') !== false, 'Loxone template custom prefix');
foreach (wp_oc_ext_options() as $key => $unused) { check(wp_vorgaben()[$key] === 0, 'default off ' . $key); }
echo "$n checks, $errors failures\n";
exit($errors ? 1 : 0);
