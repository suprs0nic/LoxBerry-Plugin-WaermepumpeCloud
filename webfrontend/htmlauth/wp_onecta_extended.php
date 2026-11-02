<?php
/** Additional Onecta telemetry and guarded commands; no OAuth or network on load. */

function wp_oc_ext_get($data, $path)
{
    foreach (explode('.', $path) as $part) {
        if (!is_array($data) || !array_key_exists($part, $data)) { return null; }
        $data = $data[$part];
    }
    return $data;
}

/** Never pick a random gateway from a list or an ambiguous management point. */
function wp_oc_ext_device($raw, $id = '')
{
    if (!is_array($raw)) { return array(); }
    if (isset($raw['managementPoints']) && is_array($raw['managementPoints'])) {
        foreach (array('id', '_id') as $key) {
            if ($id !== '' && isset($raw[$key]) && $raw[$key] !== $id) { return array(); }
        }
        return $raw;
    }
    $matches = array();
    foreach ($raw as $device) {
        if (!is_array($device) || !isset($device['managementPoints'])) { continue; }
        if ($id !== '' && (isset($device['id']) ? $device['id'] : (isset($device['_id']) ? $device['_id'] : null)) !== $id) { continue; }
        $matches[] = $device;
    }
    return count($matches) === 1 ? $matches[0] : array();
}

function wp_oc_ext_point($device, $type, $fallback)
{
    $matches = array();
    $points = wp_oc_ext_get($device, 'managementPoints');
    if (!is_array($points)) { return array(); }
    foreach ($points as $p) {
        if (!is_array($p)) { continue; }
        if (isset($p['managementPointType'])) {
            if ($p['managementPointType'] === $type) { $matches[] = $p; }
        } elseif (isset($p['embeddedId']) && in_array((string) $p['embeddedId'], array($fallback, $type), true)) {
            $matches[] = $p;
        }
    }
    return count($matches) === 1 ? $matches[0] : array();
}

/** name => [source, path, payload type]. Shared by publishing, UI and documentation. */
function wp_oc_ext_fields()
{
    $s = 'temperatureControl.value.operationModes.';
    return array(
        'heizmodus' => array('heizung', 'operationMode.value', 'text'),
        'regelart' => array('heizung', 'controlMode.value', 'text'),
        'sollwertmodus' => array('heizung', 'setpointMode.value', 'text'),
        'heiz_offset' => array('heizung', $s . 'heating.setpoints.leavingWaterOffset.value', 'number'),
        'kuehl_soll' => array('heizung', $s . 'cooling.setpoints.roomTemperature.value', 'number'),
        'kuehl_vlsoll' => array('heizung', $s . 'cooling.setpoints.leavingWaterTemperature.value', 'number'),
        'ww_ein' => array('warmwasser', 'onOffMode.value', 'bool'),
        'ww_modus' => array('warmwasser', 'heatupMode.value', 'text'),
        'ww_boost_aktiv' => array('warmwasser', 'isPowerfulModeActive.value', 'bool'),
        'ww_zeitprogramm' => array('warmwasser', 'schedule.value.modes.heating.enabled.value', 'bool'),
        'ww_naechste_aktion' => array('warmwasser', 'schedule.value.nextAction', 'json'),
        'ww_naechste_startzeit' => array('warmwasser', 'schedule.value.nextAction.startTime', 'text'),
        'ww_naechste_aktion_wert' => array('warmwasser', 'schedule.value.nextAction.domesticHotWaterTemperature', 'text'),
        'ww_naechste_wochentag' => array('warmwasser', 'schedule.value.nextAction.actionPeriod', 'text'),
        'ww_naechste_betriebsart' => array('warmwasser', 'schedule.value.nextAction.operationMode', 'text'),
        'cloud_online' => array('geraet', 'isCloudConnectionUp.value', 'bool'),
        'fehler_heizung' => array('heizung', 'isInErrorState.value', 'bool'),
        'warnung_heizung' => array('heizung', 'isInWarningState.value', 'bool'),
        'notbetrieb' => array('heizung', 'isInEmergencyState.value', 'bool'),
        'fehler_ww' => array('warmwasser', 'isInErrorState.value', 'bool'),
        'warnung_ww' => array('warmwasser', 'isInWarningState.value', 'bool'),
        'diagnose/geraetemodell' => array('geraet', 'deviceModel', 'text'),
        'diagnose/gateway_modell' => array('gateway', 'modelInfo.value', 'text'),
        'diagnose/gateway_firmware' => array('gateway', 'firmwareVersion.value', 'text'),
        'diagnose/innengeraet_modell' => array('innen', 'modelInfo.value', 'text'),
        'diagnose/innengeraet_software' => array('innen', 'softwareVersion.value', 'text'),
        'diagnose/aussengeraet_software' => array('aussen', 'softwareVersion.value', 'text'),
        'diagnose/bedienung_software' => array('bedienung', 'softwareVersion.value', 'text'),
        'energie/heizung' => array('heizung', 'consumptionData.value.electrical.heating', 'energy'),
        'energie/kuehlung' => array('heizung', 'consumptionData.value.electrical.cooling', 'energy'),
        'energie/warmwasser' => array('warmwasser', 'consumptionData.value.electrical.heating', 'energy'),
    );
}

function wp_oc_ext_number($value)
{
    return (is_int($value) || is_float($value)) && is_finite((float) $value);
}

/** Array indexes are preserved. Invalid samples become null, never zero. */
function wp_oc_ext_energy($value)
{
    if (!is_array($value)) { return null; }
    $series = array();
    foreach (array('d', 'w', 'm') as $period) {
        if (!isset($value[$period]) || !is_array($value[$period]) || !$value[$period]) { continue; }
        $row = $value[$period];
        if (array_keys($row) !== range(0, count($row) - 1) || count($row) > 512) { continue; }
        $clean = array(); $valid = 0;
        foreach ($row as $sample) {
            $ok = wp_oc_ext_number($sample) && $sample >= 0;
            $clean[] = $ok ? $sample : null;
            if ($ok) { $valid++; }
        }
        $series[$period] = array('werte' => $clean, 'gueltige_werte' => $valid);
    }
    return $series ? array('einheit' => 'kWh', 'zeitzuordnung' => 'unbekannt',
        'reihenfolge' => 'api_unveraendert', 'reihen' => $series) : null;
}

function wp_oc_ext_payload($value, $type)
{
    if ($type === 'energy') { $value = wp_oc_ext_energy($value); $type = 'json'; }
    if ($value === null) { return '-'; }
    if ($type === 'bool') {
        if (in_array($value, array(true, 1, 'on'), true)) { return '1'; }
        if (in_array($value, array(false, 0, 'off'), true)) { return '0'; }
        return '-';
    }
    if ($type === 'number') { return wp_oc_ext_number($value) ? (string) $value : '-'; }
    if ($type === 'json') {
        if (!is_array($value) || !$value) { return '-'; }
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded !== false && strlen($encoded) <= 12000 ? $encoded : '-';
    }
    return is_string($value) && $value !== '' && strlen($value) <= 256
        && !preg_match('/[\x00-\x1f\x7f]/', $value) ? $value : '-';
}

function wp_oc_ext_map($raw, $id = '')
{
    $d = wp_oc_ext_device($raw, $id);
    $sources = array('geraet' => $d);
    foreach (array('heizung' => array('climateControl', '1'), 'warmwasser' => array('domesticHotWaterTank', '2'),
        'gateway' => array('gateway', '0'), 'innen' => array('indoorUnitHydro', '3'),
        'aussen' => array('outdoorUnit', '4'), 'bedienung' => array('userInterface', '5')) as $name => $p) {
        $sources[$name] = wp_oc_ext_point($d, $p[0], $p[1]);
    }
    $out = array();
    foreach (wp_oc_ext_fields() as $name => $f) {
        $v = wp_oc_ext_get($sources[$f[0]], $f[1]);
        if ($f[2] === 'energy' && wp_oc_ext_get($sources[$f[0]], 'consumptionData.value.electrical.unit') !== 'kWh') { $v = null; }
        $out['zusatz/' . $name] = wp_oc_ext_payload($v, $f[2]);
    }
    return $out;
}

function wp_oc_ext_options()
{
    return array('oc_zusatz_ein' => 'Zusätzliche Daikin-Messwerte veröffentlichen',
        'oc_heiz_offset' => 'Heizkurvenverschiebung freigeben (−10 bis +10 K, Schritt 1)',
        'oc_raum_soll' => 'Raum-Solltemperatur freigeben (12 bis 35 °C, Gerätebereich und Schritt gelten)',
        'oc_heizmodus' => 'Betriebsart freigeben (heating, cooling, auto)');
}

/** Pure allowlisted request plan. Metadata must come from a recent successful poll. */
function wp_oc_ext_plan($name, $value, array $cfg, array $stand, $now)
{
    $fail = function ($reason) { return array(null, $reason); };
    if (!in_array($name, array('heiz_offset', 'raum_soll', 'heizmodus'), true)) { return $fail('UNBEKANNTER_BEFEHL'); }
    if ($cfg['hersteller'] !== 'onecta') { return $fail('NICHT_UNTERSTUETZT'); }
    if (empty($cfg['mqtt_ein']) || empty($cfg['befehle_mqtt_ein']) || empty($cfg['befehle_mqtt_steuern'])
        || empty($cfg['oc_' . $name])) { return $fail('NICHT_FREIGEGEBEN'); }
    $stamp = isset($stand['oc_zusatz_zeit']) ? $stand['oc_zusatz_zeit'] : 0;
    if (empty($stand['ok']) || !$stamp || $stamp > $now || $now - $stamp > min(3600, max(60, (int) $cfg['takt']) * 2)
        || empty($cfg['geraet']) || !isset($stand['oc_zusatz_geraet']) || $stand['oc_zusatz_geraet'] !== $cfg['geraet']) {
        return $fail('DATEN_VERALTET');
    }
    if (!empty($cfg['sg_ein']) && !empty($cfg['sg_angefordert'])
        && ((int) $cfg['sg_stufe'] !== 2 || (isset($stand['stufe_gesetzt']) && (int) $stand['stufe_gesetzt'] !== 2))) {
        return $fail('SGREADY_AKTIV');
    }
    $d = wp_oc_ext_device(isset($stand['roh']) ? $stand['roh'] : null, $cfg['geraet']);
    if (wp_oc_ext_get($d, 'isCloudConnectionUp.value') !== true) { return $fail('CLOUD_OFFLINE'); }
    $p = wp_oc_ext_point($d, 'climateControl', '1');
    if (!isset($p['embeddedId']) || !is_string($p['embeddedId']) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $p['embeddedId'])) {
        return $fail('KEIN_HEIZKREIS');
    }
    $path = ''; $feature = 'operationMode';
    $meta = wp_oc_ext_get($p, $feature);
    if ($name === 'heizmodus') {
        if (!is_string($value) || !in_array($value, array('heating', 'cooling', 'auto'), true)
            || !is_array($meta) || !isset($meta['values']) || !is_array($meta['values'])
            || !in_array($value, $meta['values'], true)) { return $fail('WERT_UNGUELTIG'); }
    } else {
        $mode = wp_oc_ext_get($p, 'operationMode.value');
        if (!in_array($mode, array('heating', 'cooling', 'auto'), true)
            || ($name === 'heiz_offset' && $mode !== 'heating')) { return $fail('BETRIEBSART_UNPASSEND'); }
        $target = $name === 'heiz_offset' ? 'leavingWaterOffset' : 'roomTemperature';
        $feature = 'temperatureControl';
        $path = '/operationModes/' . $mode . '/setpoints/' . $target;
        $meta = wp_oc_ext_get($p, 'temperatureControl.value.operationModes.' . $mode . '.setpoints.' . $target);
        if (wp_oc_ext_get($p, 'temperatureControl.settable') !== true) { return $fail('NICHT_SCHREIBBAR'); }
        if (!is_scalar($value) || is_bool($value) || !preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', (string) $value)) {
            return $fail('WERT_UNGUELTIG');
        }
        $value = (float) $value;
        foreach (array('minValue', 'maxValue', 'stepValue') as $k) {
            if (!wp_oc_ext_number(wp_oc_ext_get($meta, $k))) { return $fail('BEREICH_UNBEKANNT'); }
        }
        $min = $meta['minValue']; $max = $meta['maxValue']; $step = $meta['stepValue'];
        $hardMin = $name === 'heiz_offset' ? -10 : 12;
        $hardMax = $name === 'heiz_offset' ? 10 : 35;
        if ($step <= 0 || $min > $max || !is_finite($value) || $value < max($min, $hardMin) || $value > min($max, $hardMax)
            || abs(($value - $min) / $step - round(($value - $min) / $step)) > 0.000001
            || ($name === 'heiz_offset' && floor($value) !== $value)) { return $fail('WERT_UNGUELTIG'); }
    }
    if (wp_oc_ext_get($meta, 'settable') !== true) { return $fail('NICHT_SCHREIBBAR'); }
    if (isset($meta['value']) && ($meta['value'] === $value
        || (wp_oc_ext_number($meta['value']) && wp_oc_ext_number($value) && (float) $meta['value'] === (float) $value))) {
        return $fail('UNVERAENDERT');
    }
    return array(array('punkt' => $p['embeddedId'], 'merkmal' => $feature, 'wert' => $value, 'pfad' => $path), '');
}

/** Called ONLY by the existing retained-aware MQTT bridge; same lock and writer as cron. */
function wp_oc_ext_command($name, $value)
{
    $lock = @fopen(wp_tmpdir() . '/abruf.lock', 'c');
    if (!$lock) { return array(false, 'SPERRE_FEHLER'); }
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return array(false, 'ABRUF_AKTIV'); }
    try {
        $cfg = wp_config(false); $stand = wp_stand();
        list($plan, $reason) = wp_oc_ext_plan($name, $value, $cfg, $stand, time());
        if ($plan === null) { return array($reason === 'UNVERAENDERT', $reason); }
        if (wp_probe()) { return array(true, 'PROBE'); }
        $file = wp_tmpdir() . '/oc_zusatz_befehl.json';
        $last = is_file($file) ? json_decode((string) @file_get_contents($file), true) : array();
        // One attempt per minute across all new commands, including failures and daemon restarts.
        if (!is_array($last)) { return array(false, 'BREMSE_UNLESBAR'); }
        if (isset($last['zeit']) && time() - (int) $last['zeit'] < 60) { return array(false, 'BEFEHLSBREMSE'); }
        if (!wp_json_schreiben($file, array('zeit' => time()))) { return array(false, 'NICHT_GESPEICHERT'); }
        list($ok, $reason) = wp_oc_setzen($cfg, $plan['punkt'], $plan['merkmal'], $plan['wert'], $plan['pfad']);
        // The old capability snapshot must not authorize another write after a mode change.
        if ($ok) { $stand['oc_zusatz_zeit'] = 0; wp_stand_write($stand); }
        return array((bool) $ok, $reason);
    } catch (Throwable $e) {
        wp_log('Daikin-Zusatzbefehl abgebrochen (' . get_class($e) . ').');
        return array(false, 'INTERNER_FEHLER');
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
}
