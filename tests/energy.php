<?php
/** Offline consumption calculations: synthetic data only, no network. */
require __DIR__ . '/../webfrontend/htmlauth/wp_lib.php';
$n = 0; $errors = 0;
function energy_check($ok, $message) {
    global $n, $errors; $n++;
    if (!$ok) { $errors++; fwrite(STDERR, "FAIL energy: $message\n"); }
}
$raw = json_decode(file_get_contents(__DIR__ . '/fixtures/altherma.json'), true);
foreach (array(1, 2) as $index) {
    $electrical =& $raw[0]['managementPoints'][$index]['consumptionData']['value']['electrical'];
    $electrical['unit'] = 'kWh';
    foreach ($index === 1 ? array('heating', 'cooling') : array('heating') as $mode) {
        $electrical[$mode] = array('d' => range(1, 24), 'w' => range(1, 14), 'm' => range(1, 24));
    }
    unset($electrical);
}
$received = strtotime('2026-11-02T12:00:00Z');
$s = wp_oc_energy_summary($raw, 'fixture-gateway', $received, 'Europe/Vienna');
foreach (array('heute' => 222, 'gestern' => 78, 'diese_woche' => 77, 'letzte_woche' => 28,
    'dieser_monat' => 23, 'letzter_monat' => 22, 'dieses_jahr' => 222, 'letztes_jahr' => 78) as $period => $expected) {
    foreach (array('heizung', 'kuehlung', 'warmwasser', 'gesamt') as $category) {
        $row = $s['bereiche'][$category][$period];
        $value = $expected * ($category === 'gesamt' ? 3 : 1);
        energy_check($row['bekannt_kwh'] == $value, "$category $period offset and sum");
        $closed = wp_oc_energy_periods(11)[$period][4];
        energy_check($row['vollstaendig'] === $closed, "$category $period closed coverage");
        energy_check($closed ? $row['gesamt_kwh'] == $value : $row['gesamt_kwh'] === null, "$category $period final total");
    }
}
$topics = wp_oc_energy_topics($s);
energy_check(count($topics) === 101, 'complete MQTT contract');
energy_check(isset($topics['Daikin/energie/ww/heute/bekannt_kwh']), 'WW uses consistent Daikin subgroup');
energy_check($topics['Daikin/energie/heizung/heute/gesamt_kwh'] === '-', 'running total unavailable');
energy_check($topics['Daikin/energie/heizung/gestern/gesamt_kwh'] === '78', 'closed MQTT value');
foreach ($topics as $topic => $payload) {
    energy_check(is_string($payload) && strpos($payload, "\n") === false && strlen($payload) < 12000
        && wp_mqtt_befehl($topic, $payload) === 'publish', 'safe non-retained MQTT payload ' . $topic);
}
$gap = $raw;
$gap[0]['managementPoints'][1]['consumptionData']['value']['electrical']['heating']['d'][0] = null;
$g = wp_oc_energy_summary($gap, 'fixture-gateway', $received, 'Europe/Vienna');
energy_check($g['bereiche']['heizung']['gestern']['bekannt_kwh'] == 77, 'known subtotal preserves gap');
energy_check($g['bereiche']['heizung']['gestern']['gesamt_kwh'] === null, 'gap has no final value');
energy_check($g['bereiche']['gesamt']['gestern']['gesamt_kwh'] === null, 'gap propagates to combined total');
energy_check($g['bereiche']['heizung']['gestern']['werte_vorhanden'] === 11, 'gap count');
$missing = $raw;
unset($missing[0]['managementPoints'][1]['consumptionData']['value']['electrical']['cooling']);
$m = wp_oc_energy_summary($missing, 'fixture-gateway', $received);
energy_check($m['bereiche']['gesamt']['gestern']['bekannt_kwh'] === null, 'missing cooling never assumed zero');
energy_check($m['bereiche']['heizung']['gestern']['gesamt_kwh'] == 78, 'independent heating still available');
energy_check($m['bereiche']['heizung']['dieser_monat']['bekannt_kwh'] === null, 'month needs explicit timezone');
foreach (array(array_fill(0, 24, 0), array_fill(0, 24, null), array_fill(0, 23, 1), array_fill(0, 24, PHP_FLOAT_MAX)) as $i => $values) {
    $energy = wp_oc_ext_energy(array('d' => $values));
    $row = wp_oc_energy_period($energy, wp_oc_energy_periods()['gestern']);
    energy_check($i === 0 ? $row['gesamt_kwh'] === 0.0 : $row['gesamt_kwh'] === null, 'zero / absent / malformed / overflow ' . $i);
}
$bad = $raw; $bad[0]['managementPoints'][1]['consumptionData']['value']['electrical']['unit'] = 'W';
energy_check(wp_oc_energy_summary($bad, 'fixture-gateway', $received)['bereiche']['heizung']['gestern']['bekannt_kwh'] === null, 'no W to kWh conversion');
$device = $raw[0]; $device['managementPoints'][0]['timeZone']['value'] = 'Europe/Vienna';
$basis = wp_oc_energy_basis($device, strtotime('2025-12-31T23:30:00Z'), 'America/New_York');
energy_check($basis['monat'] === 1 && $basis['bezugsdatum'] === '2026-01-01' && $basis['quelle'] === 'gateway', 'gateway wins at local year boundary');
energy_check(wp_oc_energy_periods(1)['letzter_monat'][2] === 11, 'January previous December');
energy_check(wp_oc_energy_periods(12)['dieser_monat'][2] === 23, 'December current month');
energy_check(wp_oc_energy_basis($device, strtotime('2026-03-31T22:30:00Z'))['monat'] === 4, 'summer offset month boundary');
energy_check(wp_oc_energy_basis($device, 0)['monat'] === null, 'deprecated device timestamp not used');
foreach (array('', 'invalid', '../Europe/Vienna', array(), null) as $zone) {
    energy_check(!wp_oc_energy_zone_valid($zone), 'invalid timezone rejected');
}
$device['managementPoints'][0]['timeZone']['value'] = 'invalid';
energy_check(wp_oc_energy_basis($device, $received, 'Europe/Vienna')['quelle'] === 'konfiguration', 'explicit timezone fallback');
$empty = wp_oc_energy_summary($raw, 'wrong-device', $received, 'Europe/Vienna');
energy_check($empty['bereiche']['gesamt']['gestern']['bekannt_kwh'] === null, 'wrong device never produces consumption');
energy_check(strpos(wp_oc_energy_label($g['bereiche']['heizung']['gestern']), '11/12') !== false, 'UI coverage visible');
fwrite(STDOUT, "Energy: $n checks, $errors errors\n");
exit($errors ? 1 : 0);
