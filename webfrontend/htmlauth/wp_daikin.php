<?php
/** Daikin ONECTA catalogue. API names and values remain recognizable and reversible. */
function wp_daikin_features()
{
    $state = array('errorCode', 'isInErrorState', 'isInWarningState', 'isInInstallerState',
        'isInEmergencyState', 'isHolidayModeActive', 'name');
    return array(
        'climateControl' => array_merge($state, array('onOffMode', 'operationMode', 'controlMode', 'setpointMode',
            'temperatureControl', 'sensoryData', 'consumptionData', 'holidayMode', 'schedule', 'blockScheduleV2',
            'fanControl', 'humidityControl', 'airPurificationMode', 'powerfulMode',
            'isInCautionState', 'isInModeConflict', 'isPowerfulModeActive')),
        'domesticHotWaterTank' => array_merge($state, array('onOffMode', 'operationMode', 'powerfulMode',
            'isPowerfulModeActive', 'heatupMode', 'setpointMode', 'temperatureControl', 'sensoryData',
            'consumptionData', 'schedule', 'blockScheduleV2', 'holidayMode')),
        'gateway' => array('modelInfo', 'firmwareVersion', 'softwareVersion', 'timeZone', 'errorCode',
            'isInErrorState', 'isFirmwareUpdateSupported', 'firmwareUpdate', 'firmwareUpdateStatus',
            'ipAddress', 'macAddress', 'serialNumber', 'ssid'),
        'indoorUnitHydro' => array('modelInfo', 'softwareVersion', 'serialNumber'),
        'indoorUnit' => array('modelInfo', 'softwareVersion', 'serialNumber'),
        'outdoorUnit' => array('modelInfo', 'softwareVersion', 'serialNumber', 'errorCode', 'isInErrorState', 'isInWarningState', 'isInCautionState'),
        'userInterface' => array('modelInfo', 'softwareVersion', 'sgtin', 'firmwareUpdate', 'firmwareUpdateStatus'),
    );
}

function wp_daikin_groups()
{
    return array('climateControl' => 'heizung', 'domesticHotWaterTank' => 'ww', 'indoorUnit' => 'diagnose/innengeraet_luft',
        'gateway' => 'diagnose/gateway', 'indoorUnitHydro' => 'diagnose/innengeraet',
        'outdoorUnit' => 'diagnose/aussengeraet', 'userInterface' => 'diagnose/bedienung');
}

/** Every response leaf, including all metadata; lists stay lossless JSON lists. */
function wp_daikin_walk($value, $topic, $source, $documented, array &$rows, $depth = 0)
{
    if ($depth > 32) { throw new RuntimeException('Daikin response nesting exceeds 32'); }
    $isList = is_array($value) && $value && array_keys($value) === range(0, count($value) - 1)
        && substr($source, -10) !== '.schedules';
    if (is_array($value) && $value && !$isList) {
        foreach ($value as $key => $child) {
            wp_daikin_walk($child, $topic . '/' . rawurlencode((string) $key), $source . '.' . $key, $documented, $rows, $depth + 1);
        }
        return;
    }
    $type = is_array($value) ? 'json' : (is_bool($value) ? 'bool' : (wp_oc_ext_number($value) ? 'number' : 'text'));
    $payload = is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : wp_oc_ext_payload($value, $type);
    if (is_string($value)) {
        // Preserve empty/long/multiline strings reversibly; never inject a gateway command.
        $payload = $value === '' || strlen($value) > 256 || preg_match('/[\x00-\x1f\x7f]/', $value)
            ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $value;
    }
    if ($payload === false || strlen($payload) > 12000) { throw new RuntimeException('Daikin value exceeds transport limit'); }
    $rows[$topic] = array('quelle' => $source, 'typ' => $type, 'dokumentiert' => $documented,
        'wert' => $value, 'payload' => $payload);
}

function wp_daikin_catalog($raw, $id = '')
{
    $device = wp_oc_ext_device($raw, $id); $rows = array();
    if (!$device) { return $rows; }
    foreach ($device as $key => $value) {
        if ($key !== 'managementPoints') {
            wp_daikin_walk($value, 'Daikin/geraet/' . rawurlencode((string) $key), $key,
                in_array($key, array('id', 'deviceModel', 'isCloudConnectionUp'), true), $rows);
        }
    }
    $groups = wp_daikin_groups(); $features = wp_daikin_features();
    $counts = array(); $ids = array();
    foreach ($device['managementPoints'] as $p) {
        $type = isset($p['managementPointType']) ? $p['managementPointType'] : '';
        if (!is_string($type) || !isset($p['embeddedId']) || !is_string($p['embeddedId'])) { throw new RuntimeException('Invalid Daikin point'); }
        if (isset($ids[$p['embeddedId']])) { throw new RuntimeException('Duplicate Daikin point ID'); }
        $ids[$p['embeddedId']] = true;
        $counts[$type] = isset($counts[$type]) ? $counts[$type] + 1 : 1;
    }
    foreach ($device['managementPoints'] as $p) {
        $type = $p['managementPointType']; $pid = $p['embeddedId'];
        $group = isset($groups[$type]) ? $groups[$type] : 'weitere/' . rawurlencode($type);
        if ($counts[$type] > 1) { $group .= '/punkt_' . rawurlencode($pid); }
        foreach ($p as $key => $value) {
            $target = $key === 'consumptionData' ? 'energie/' . ($type === 'domesticHotWaterTank' ? 'ww' : $group) : $group;
            wp_daikin_walk($value, 'Daikin/' . $target . '/' . rawurlencode((string) $key),
                'managementPoints[embeddedId=' . $pid . '].' . $key,
                in_array($key, array('embeddedId', 'managementPointType', 'managementPointCategory', 'managementPointSubType'), true)
                || in_array($key, isset($features[$type]) ? $features[$type] : array(), true), $rows);
        }
    }
    return $rows;
}

function wp_daikin_map($raw, $id)
{
    $out = array();
    foreach (wp_daikin_catalog($raw, $id) as $topic => $row) { $out[$topic] = $row['payload']; }
    $device = wp_oc_ext_device($raw, $id);
    foreach (wp_daikin_features() as $type => $features) {
        $point = wp_oc_ext_point($device, $type, '');
        foreach ($features as $feature) {
            $out['Daikin/faehigkeiten/' . wp_daikin_groups()[$type] . '/' . $feature] = array_key_exists($feature, $point) ? '1' : '0';
        }
    }
    return $out;
}

/** Strict runtime metadata validation, also used by the pre-existing SG/WW writers. */
function wp_daikin_patch($point, $feature, $path, $value)
{
    $type = wp_oc_ext_get($point, 'managementPointType');
    $allowed = $type === 'climateControl' ? array('onOffMode', 'operationMode', 'temperatureControl', 'powerfulMode',
            'fanControl', 'humidityControl', 'airPurificationMode', 'name')
        : ($type === 'domesticHotWaterTank' ? array('onOffMode', 'operationMode', 'powerfulMode', 'temperatureControl', 'name') : array());
    if (!in_array($feature, $allowed, true)) { return 'NICHT_DOKUMENTIERTER_SCHREIBWEG'; }
    $meta = wp_oc_ext_get($point, $feature);
    if (wp_oc_ext_get($meta, 'settable') !== true) { return 'NICHT_SCHREIBBAR'; }
    if ($feature === 'temperatureControl') {
        if (!is_string($path) || !preg_match('~^/operationModes/([a-zA-Z0-9_-]{1,40})/setpoints/(roomTemperature|leavingWaterTemperature|leavingWaterOffset|domesticHotWaterTemperature)$~D', $path, $m)) {
            return 'PFAD_UNGUELTIG';
        }
        if (($type === 'domesticHotWaterTank') !== ($m[2] === 'domesticHotWaterTemperature')) { return 'PFAD_UNGUELTIG'; }
        $meta = wp_oc_ext_get($meta, 'value.' . str_replace('/', '.', substr($path, 1)));
        if (wp_oc_ext_get($meta, 'settable') !== true) { return 'NICHT_SCHREIBBAR'; }
    } elseif ($feature === 'fanControl' || $feature === 'humidityControl') {
        $valid = is_string($path) && ($feature === 'fanControl'
            ? preg_match('~^/operationModes/[a-zA-Z0-9_-]{1,40}/(?:fanSpeed/(?:currentMode|modes/[a-zA-Z0-9_-]{1,40})|fanDirection/(?:horizontal|vertical)/currentMode)$~D', $path)
            : preg_match('~^/airPurificationModes/[a-zA-Z0-9_-]{1,40}/humiditySetting/(?:currentMode|modes/humidification)$~D', $path));
        if (!$valid) { return 'PFAD_UNGUELTIG'; }
        $meta = wp_oc_ext_get($meta, 'value.' . str_replace('/', '.', substr($path, 1)));
        if (wp_oc_ext_get($meta, 'settable') !== true) { return 'NICHT_SCHREIBBAR'; }
    } elseif ($path !== '') { return 'PFAD_UNGUELTIG'; }
    if ($feature === 'name') {
        $max = wp_oc_ext_get($meta, 'maxLength');
        return is_string($value) && is_int($max) && $max > 0 && strlen($value) <= $max
            && !preg_match('/[\x00-\x1f\x7f]/', $value) ? '' : 'WERT_UNGUELTIG';
    }
    $enum = wp_oc_ext_get($meta, 'values');
    if (is_array($enum)) { return in_array($value, $enum, true) ? '' : 'WERT_UNGUELTIG'; }
    if (!wp_oc_ext_number($value)) { return 'WERT_UNGUELTIG'; }
    foreach (array('minValue', 'maxValue', 'stepValue') as $key) {
        if (!wp_oc_ext_number(wp_oc_ext_get($meta, $key))) { return 'BEREICH_UNBEKANNT'; }
    }
    $step = $meta['stepValue']; $min = $meta['minValue']; $max = $meta['maxValue'];
    return $step > 0 && $min <= $max && $value >= $min && $value <= $max
        && abs(($value - $min) / $step - round(($value - $min) / $step)) <= 0.000001 ? '' : 'WERT_UNGUELTIG';
}

function wp_daikin_commands()
{
    $out = array();
    foreach (array('heizung', 'ww') as $group) {
        foreach (array('setzen', 'urlaub', 'zeitplan_auswahl', 'zeitplan_inhalt', 'blockzeitplan_auswahl', 'blockzeitplan_inhalt') as $op) {
            $out[$group . '/' . $op] = array('art' => 'text', 'max_byte' => 4000);
        }
        $out[$group . '/ein'] = array('art' => 'schalter');
    }
    $out['ww/boost'] = array('art' => 'schalter');
    $out['ww/soll'] = array('art' => 'zahl', 'min' => -999999999, 'max' => 999999999);
    $out['heizung/heiz_offset'] = array('art' => 'ganz', 'min' => -10, 'max' => 10);
    $out['heizung/raum_soll'] = array('art' => 'zahl', 'min' => 12, 'max' => 35);
    $out['heizung/heizmodus'] = array('art' => 'auswahl', 'werte' => array('heating', 'cooling', 'auto'));
    foreach (array('gateway', 'bedienung') as $part) { $out['diagnose/' . $part . '/firmware'] = array('art' => 'text', 'max_byte' => 200); }
    return $out;
}

function wp_daikin_plan($name, $payload, array $cfg, array $stand, $now)
{
    $fail = function ($why) { return array(null, $why); };
    if (!isset(wp_daikin_commands()[$name])) { return $fail('UNBEKANNTER_BEFEHL'); }
    $parts = explode('/', $name); $group = $parts[0]; $operation = end($parts);
    if (in_array($operation, array('heiz_offset', 'raum_soll', 'heizmodus'), true)) {
        list($old, $why) = wp_oc_ext_plan($operation, $payload, $cfg, $stand, $now);
        if (!$old) { return $fail($why); }
        return array(array('method' => 'PATCH', 'point' => $old['punkt'], 'suffix' => 'characteristics/' . $old['merkmal'],
            'body' => array_merge(array('value' => $old['wert']), $old['pfad'] !== '' ? array('path' => $old['pfad']) : array())), '');
    }
    if ($cfg['hersteller'] !== 'onecta') { return $fail('NICHT_UNTERSTUETZT'); }
    $flag = strpos($operation, 'zeitplan') !== false ? 'oc_daikin_zeitplan'
        : ($operation === 'urlaub' ? 'oc_daikin_urlaub' : ($operation === 'firmware' ? 'oc_daikin_firmware' : 'oc_daikin_schreiben'));
    if (empty($cfg['mqtt_ein']) || empty($cfg['befehle_mqtt_ein']) || empty($cfg['befehle_mqtt_steuern']) || empty($cfg[$flag])) {
        return $fail('NICHT_FREIGEGEBEN');
    }
    $stamp = isset($stand['oc_zusatz_zeit']) ? $stand['oc_zusatz_zeit'] : 0;
    if (empty($stand['ok']) || !$stamp || $stamp > $now || $now - $stamp > min(3600, max(60, (int) $cfg['takt']) * 2)
        || empty($cfg['geraet']) || ($stand['oc_zusatz_geraet'] ?? '') !== $cfg['geraet']) { return $fail('DATEN_VERALTET'); }
    if (!empty($cfg['sg_ein']) && !empty($cfg['sg_angefordert']) && ((int) $cfg['sg_stufe'] !== 2
        || (isset($stand['stufe_gesetzt']) && (int) $stand['stufe_gesetzt'] !== 2))) { return $fail('SGREADY_AKTIV'); }
    $device = wp_oc_ext_device($stand['roh'] ?? null, $cfg['geraet']);
    if (wp_oc_ext_get($device, 'isCloudConnectionUp.value') !== true) { return $fail('CLOUD_OFFLINE'); }
    $types = array('heizung' => array('climateControl', '1'), 'ww' => array('domesticHotWaterTank', '2'),
        'gateway' => array('gateway', '0'), 'bedienung' => array('userInterface', '5'));
    $target = $group === 'diagnose' ? $parts[1] : $group;
    $p = wp_oc_ext_point($device, $types[$target][0], $types[$target][1]);
    $pid = wp_oc_ext_get($p, 'embeddedId');
    if (!is_string($pid) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $pid)) { return $fail('GERAETEBEREICH_FEHLT'); }
    $body = is_string($payload) ? json_decode($payload, true, 32) : null;
    if ($operation === 'ein' || $operation === 'boost') {
        if (!in_array($payload, array(0, 1, '0', '1'), true)) { return $fail('WERT_UNGUELTIG'); }
        $body = array('characteristic' => $operation === 'boost' ? 'powerfulMode' : 'onOffMode', 'value' => (string) $payload === '1' ? 'on' : 'off');
        $operation = 'setzen';
    }
    if ($group === 'ww' && $operation === 'soll') {
        if (!is_scalar($payload) || is_bool($payload) || !preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', (string) $payload)) { return $fail('WERT_UNGUELTIG'); }
        $mode = wp_oc_ext_get($p, 'operationMode.value');
        if (!is_string($mode) || !preg_match('/^[a-zA-Z0-9_-]{1,40}$/D', $mode)) { return $fail('BETRIEBSART_UNPASSEND'); }
        $body = array('characteristic' => 'temperatureControl', 'path' => '/operationModes/' . $mode . '/setpoints/domesticHotWaterTemperature', 'value' => (float) $payload);
        $operation = 'setzen';
    }
    $blockedPoint = $p;
    if ($operation === 'urlaub' && is_array($body) && ($body['enabled'] ?? null) === false) {
        $blockedPoint['isHolidayModeActive']['value'] = false;
        $blockedPoint['holidayMode']['value']['enabled'] = false;
    }
    $blocked = wp_oc_ext_block_reason($device, $blockedPoint);
    if ($operation !== 'firmware' && $blocked !== '') { return $fail($blocked); }
    $plan = array('method' => 'PATCH', 'point' => $pid, 'suffix' => '', 'body' => $body);
    if ($operation === 'setzen') {
        if (!is_array($body) || !isset($body['characteristic']) || !is_string($body['characteristic']) || !array_key_exists('value', $body)
            || array_diff(array_keys($body), array('characteristic', 'path', 'value'))) { return $fail('JSON_UNGUELTIG'); }
        $path = $body['path'] ?? '';
        $error = wp_daikin_patch($p, $body['characteristic'], $path, $body['value']);
        if ($error !== '') { return $fail($error); }
        $metaPath = $body['characteristic'] . ($path !== '' ? '.value.' . str_replace('/', '.', substr($path, 1)) : '');
        $current = wp_oc_ext_get($p, $metaPath . '.value');
        if ($current === $body['value'] || (wp_oc_ext_number($current) && wp_oc_ext_number($body['value']) && (float) $current === (float) $body['value'])) { return $fail('UNVERAENDERT'); }
        $plan['suffix'] = 'characteristics/' . $body['characteristic'];
        unset($body['characteristic']); $plan['body'] = $body;
    } elseif ($operation === 'urlaub') {
        if (wp_oc_ext_get($p, 'holidayMode.settable') !== true) { return $fail('NICHT_SCHREIBBAR'); }
        if (!is_array($body) || !isset($body['enabled']) || !is_bool($body['enabled'])
            || array_diff(array_keys($body), $body['enabled'] ? array('enabled', 'startDate', 'endDate') : array('enabled'))) { return $fail('JSON_UNGUELTIG'); }
        if ($body['enabled']) {
            $basis = wp_oc_energy_basis($device, $now, $cfg['oc_energie_zeitzone'] ?? '');
            if (!$basis['bezugsdatum']) { return $fail('ZEITZONE_FEHLT'); }
            foreach (array('startDate', 'endDate') as $key) {
                $date = isset($body[$key]) && is_string($body[$key]) ? DateTimeImmutable::createFromFormat('!Y-m-d', $body[$key]) : false;
                if (!$date || $date->format('Y-m-d') !== $body[$key]) { return $fail('DATUM_UNGUELTIG'); }
            }
            if ($body['startDate'] < $basis['bezugsdatum'] || $body['endDate'] < $body['startDate']) { return $fail('DATUM_UNGUELTIG'); }
        }
        $plan['method'] = 'POST'; $plan['suffix'] = 'holiday-mode';
    } elseif (strpos($operation, 'zeitplan') !== false) {
        list($suffix, $error) = wp_daikin_schedule($p, $operation, $body);
        if ($error !== '') { return $fail($error); }
        $plan['method'] = 'PUT'; $plan['suffix'] = $suffix; unset($body['mode']); $plan['body'] = $body;
        if (substr($operation, -7) === '_inhalt') { $plan['body'] = $body['schedules']; }
    } elseif ($operation === 'firmware') {
        $firmware = wp_oc_ext_get($p, 'firmwareUpdate.value');
        if (($target === 'gateway' && wp_oc_ext_get($p, 'isFirmwareUpdateSupported.value') !== true)
            || wp_oc_ext_get($p, 'firmwareUpdate.settable') !== true || !is_array($firmware)
            || !is_string($payload) || !isset($firmware['id']) || $payload !== $firmware['id']
            || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $payload)) { return $fail('KEIN_PASSENDES_FIRMWAREANGEBOT'); }
        if (wp_oc_ext_get($p, 'firmwareUpdateStatus.value') === 'in-progress') { return $fail('FIRMWARE_UPDATE_AKTIV'); }
        $plan['method'] = 'PUT'; $plan['suffix'] = 'firmware/' . rawurlencode($payload); $plan['body'] = null;
    }
    return array($plan, '');
}

/** Dedicated schedule endpoints; never PATCH the metadata or write read-only plans. */
function wp_daikin_schedule($point, $op, $body)
{
    $block = strpos($op, 'block') === 0; $feature = $block ? 'blockScheduleV2' : 'schedule';
    $bad = function ($why) { return array('', $why); };
    if (!is_array($body) || !isset($body['mode']) || !is_string($body['mode']) || !preg_match('/^[a-zA-Z0-9_-]{1,40}$/D', $body['mode'])) { return $bad('JSON_UNGUELTIG'); }
    $mode = wp_oc_ext_get($point, $feature . '.value.modes.' . $body['mode']);
    if (!is_array($mode) || wp_oc_ext_get($point, $feature . '.settable') !== true) { return $bad('ZEITPLAN_NICHT_UNTERSTUETZT'); }
    $prefix = ($block ? 'block-schedule/' : 'schedule/') . $body['mode'];
    if (substr($op, -8) === '_auswahl') {
        if (array_diff(array_keys($body), array('mode', 'scheduleId', 'enabled')) || !isset($body['scheduleId'], $body['enabled'])
            || !is_string($body['scheduleId']) || !is_bool($body['enabled'])
            || (wp_oc_ext_get($mode, 'currentSchedule.settable') !== true && wp_oc_ext_get($mode, 'currentSchedule.value') !== $body['scheduleId'])
            || (wp_oc_ext_get($mode, 'enabled.settable') !== true && wp_oc_ext_get($mode, 'enabled.value') !== $body['enabled'])
            || !in_array($body['scheduleId'], wp_oc_ext_get($mode, 'currentSchedule.values') ?? array(), true)) { return $bad('ZEITPLAN_AUSWAHL_UNGUELTIG'); }
        if (wp_oc_ext_get($mode, 'currentSchedule.value') === $body['scheduleId'] && wp_oc_ext_get($mode, 'enabled.value') === $body['enabled']) { return $bad('UNVERAENDERT'); }
        return array($prefix . '/current', '');
    }
    if (array_diff(array_keys($body), array('mode', 'schedules')) || !isset($body['schedules']) || !is_array($body['schedules'])) { return $bad('JSON_UNGUELTIG'); }
    $plans = wp_oc_ext_get($mode, 'schedules');
    if (!is_array($plans) || array_diff_key($plans, $body['schedules']) || array_diff_key($body['schedules'], $plans)) { return $bad('ALLE_PLAN_IDS_ERFORDERLICH'); }
    if ($block) { return wp_daikin_block_schedule($mode, $body['schedules'], $prefix); }
    $max = wp_oc_ext_get($mode, 'meta.maxActionsPerActionPeriod');
    $interval = wp_daikin_seconds(wp_oc_ext_get($mode, 'meta.minIntervalBetweenActions'));
    $types = wp_oc_ext_get($mode, 'meta.actionTypes');
    if (!is_int($max) || $interval === null || !is_array($types)) { return $bad('ZEITPLAN_METADATEN_FEHLEN'); }
    foreach ($body['schedules'] as $id => $candidate) {
        $original = $plans[$id];
        if (!is_array($candidate) || array_keys($candidate) !== array('actions') || !is_array($candidate['actions'])) { return $bad('JSON_UNGUELTIG'); }
        if (wp_oc_ext_get($original, 'meta.isReadOnly') !== false || wp_oc_ext_get($original, 'settable') !== true) {
            if (is_array(wp_oc_ext_get($original, 'actions')) && $candidate['actions'] == wp_oc_ext_get($original, 'actions')) { continue; }
            return $bad('ZEITPLAN_SCHREIBGESCHUETZT');
        }
        foreach ($candidate['actions'] as $period => $actions) {
            if (!in_array($period, wp_oc_ext_get($original, 'meta.actionPeriods') ?? array(), true) || !is_array($actions) || count($actions) > $max) { return $bad('AKTIONSPERIODE_UNGUELTIG'); }
            $previous = null; $previousValues = null; $ordered = $actions; ksort($ordered);
            foreach ($ordered as $time => $values) {
                $seconds = wp_daikin_seconds($time);
                if ($seconds === null || $interval <= 0 || $seconds % $interval !== 0
                    || ($previous !== null && $seconds - $previous < $interval) || !is_array($values) || !$values) { return $bad('AKTIONSZEIT_UNGUELTIG'); }
                if (wp_oc_ext_get($mode, 'meta.consecutiveActionsAllowed') === false && $previousValues == $values) { return $bad('AUFEINANDERFOLGENDE_AKTIONEN'); }
                foreach ($values as $key => $value) {
                    if (!isset($types[$key]) || !wp_daikin_action_value($types[$key], $value, $values['operationMode'] ?? $body['mode'])) { return $bad('AKTIONSWERT_UNGUELTIG'); }
                }
                $previous = $seconds; $previousValues = $values;
            }
        }
    }
    return array($prefix . '/schedules', '');
}

function wp_daikin_block_schedule($mode, $candidates, $prefix)
{
    $bad = function ($why) { return array('', $why); };
    $min = wp_oc_ext_get($mode, 'meta.minBlockSize');
    $step = wp_oc_ext_get($mode, 'meta.timeStepMinutes');
    $max = wp_oc_ext_get($mode, 'meta.maxBlocksPerDay');
    $types = wp_oc_ext_get($mode, 'meta.blockTypes');
    if (!is_int($min) || $min <= 0 || !is_int($step) || $step <= 0 || !is_int($max) || $max < 0 || !is_array($types) || !$types) { return $bad('ZEITPLAN_METADATEN_FEHLEN'); }
    foreach ($candidates as $id => $candidate) {
        $original = $mode['schedules'][$id];
        if (wp_oc_ext_get($original, 'meta.isReadOnly') !== false) { return $bad('ZEITPLAN_SCHREIBGESCHUETZT'); }
        if (!is_array($candidate) || array_diff(array_keys($candidate), array('defaultBlockValues', 'blocks'))
            || !isset($candidate['defaultBlockValues'], $candidate['blocks']) || !is_array($candidate['defaultBlockValues']) || !is_array($candidate['blocks'])
            || array_diff_key($types, $candidate['defaultBlockValues']) || array_diff_key($candidate['defaultBlockValues'], $types)) { return $bad('BLOCKSTANDARD_ERFORDERLICH'); }
        foreach ($candidate['defaultBlockValues'] as $key => $setting) {
            if (!is_array($setting) || array_keys($setting) !== array('value') || !wp_daikin_action_value($types[$key], $setting['value'])
                || wp_oc_ext_get($original, 'defaultBlockValues.' . $key . '.settable') !== true) { return $bad('BLOCKSTANDARD_UNGUELTIG'); }
        }
        foreach ($candidate['blocks'] as $day => $entry) {
            if (!in_array($day, wp_oc_ext_get($original, 'meta.blockPeriods') ?? array(), true) || !is_array($entry)
                || array_keys($entry) !== array('periods') || !is_array($entry['periods']) || count($entry['periods']) > $max
                || ($entry['periods'] && array_keys($entry['periods']) !== range(0, count($entry['periods']) - 1))) { return $bad('BLOCKPERIODE_UNGUELTIG'); }
            $ordered = array();
            foreach ($entry['periods'] as $period) {
                $start = wp_daikin_seconds($period['startTime'] ?? null);
                $end = ($period['endTime'] ?? null) === '24:00:00' ? 86400 : wp_daikin_seconds($period['endTime'] ?? null);
                if (!is_array($period) || $start === null || $end === null || $end - $start < $min * 60
                    || $start % ($step * 60) !== 0 || $end % ($step * 60) !== 0) { return $bad('BLOCKZEIT_UNGUELTIG'); }
                $values = $period; unset($values['startTime'], $values['endTime']);
                if (array_diff_key($values, $types) || array_diff_key($types, $values)) { return $bad('BLOCKWERT_UNGUELTIG'); }
                foreach ($values as $key => $value) { if (!wp_daikin_action_value($types[$key], $value)) { return $bad('BLOCKWERT_UNGUELTIG'); } }
                $ordered[] = array($start, $end);
            }
            usort($ordered, function ($a, $b) { return $a[0] <=> $b[0]; });
            $last = 0;
            foreach ($ordered as $period) { if ($period[0] < $last) { return $bad('BLOECKE_UEBERLAPPEN'); } $last = $period[1]; }
        }
    }
    return array($prefix . '/schedules', '');
}

function wp_daikin_seconds($time)
{
    if (!is_string($time) || !preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9])$/D', $time, $m)) { return null; }
    return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
}

function wp_daikin_action_value($meta, $value, $mode = null)
{
    if (is_array(wp_oc_ext_get($meta, 'values'))) { return in_array($value, $meta['values'], true); }
    if (is_string($mode) && isset($meta[$mode]) && !isset($meta['minValue'])) { $meta = $meta[$mode]; }
    if (is_array($value)) {
        if (!$value) { return false; }
        foreach ($value as $key => $part) {
            if (!isset($meta[$key]) || !wp_daikin_action_value($meta[$key], $part, $mode)) { return false; }
        }
        return true;
    }
    foreach (array('minValue', 'maxValue', 'stepValue') as $key) { if (!wp_oc_ext_number($meta[$key] ?? null)) { return false; } }
    return wp_oc_ext_number($value) && $meta['stepValue'] > 0 && $value >= $meta['minValue'] && $value <= $meta['maxValue']
        && abs(($value - $meta['minValue']) / $meta['stepValue'] - round(($value - $meta['minValue']) / $meta['stepValue'])) < 0.000001;
}

function wp_daikin_command($name, $payload)
{
    $lock = @fopen(wp_tmpdir() . '/abruf.lock', 'c');
    if (!$lock) { return array(false, 'SPERRE_FEHLER'); }
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return array(false, 'ABRUF_AKTIV'); }
    try {
        $cfg = wp_config(false); $stand = wp_stand();
        list($plan, $why) = wp_daikin_plan($name, $payload, $cfg, $stand, time());
        if (!$plan) { return array($why === 'UNVERAENDERT', $why); }
        if (wp_probe()) { return array(true, 'PROBE'); }
        $file = wp_tmpdir() . '/oc_zusatz_befehl.json';
        $last = is_file($file) ? json_decode((string) @file_get_contents($file), true) : array();
        if (!is_array($last)) { return array(false, 'BREMSE_UNLESBAR'); }
        if (isset($last['zeit']) && time() - (int) $last['zeit'] < 60) { return array(false, 'BEFEHLSBREMSE'); }
        if (!wp_json_schreiben($file, array('zeit' => time()))) { return array(false, 'NICHT_GESPEICHERT'); }
        $token = wp_oc_token();
        if ($token === '') { return array(false, 'KEIN_TOKEN'); }
        $result = wp_oc_api($plan['method'], 'https://api.onecta.daikineurope.com/v1/gateway-devices/' . rawurlencode($cfg['geraet'])
            . '/management-points/' . rawurlencode($plan['point']) . '/' . $plan['suffix'],
            array('Authorization: Bearer ' . $token, 'Content-Type: application/json'),
            $plan['body'] === null ? null : json_encode(wp_daikin_request_object($plan['body'])), 25);
        $ok = $result['code'] >= 200 && $result['code'] < 300;
        if ($ok) { $stand['oc_zusatz_zeit'] = 0; wp_stand_write($stand); }
        return array($ok, $ok ? '' : wp_oc_api_error($result));
    } catch (Throwable $e) { return array(false, 'INTERNER_FEHLER'); }
    finally { flock($lock, LOCK_UN); fclose($lock); }
}

/** Schedule IDs such as "0" must be JSON object keys; periods must remain arrays. */
function wp_daikin_request_object($value, $key = '')
{
    if (!is_array($value)) { return $value; }
    $out = array();
    foreach ($value as $k => $v) { $out[$k] = wp_daikin_request_object($v, (string) $k); }
    return $key === 'periods' ? array_values($out) : (object) $out;
}
