<?php
/** Read-only Loxone HTTP input export. No cloud calls or configuration writes. */
function wp_daikin_vi_snapshot(array $stand, array $cfg)
{
    $values = array(); $fields = array();
    $catalog = wp_daikin_catalog($stand['roh'] ?? null, $cfg['geraet']);
    foreach ($catalog as $topic => $row) {
        if (!in_array($row['typ'], array('number', 'bool'), true)) { continue; }
        $base = substr($topic, -6) === '/value' ? substr($topic, 0, -6) : '';
        $unit = $base !== '' && isset($catalog[$base . '/unit']) ? $catalog[$base . '/unit']['wert'] : '';
        $fields[$topic] = array('type' => $row['typ'], 'unit' => is_string($unit) ? $unit : '',
            'measurement' => $base !== '');
        $values[$topic] = is_bool($row['wert']) ? ($row['wert'] ? 1 : 0) : $row['wert'];
    }
    $energy = wp_oc_energy_topics(wp_oc_energy_summary($stand['roh'] ?? null, $cfg['geraet'],
        $stand['oc_energie_zeit'] ?? 0, $cfg['oc_energie_zeitzone'] ?? ''));
    foreach ($energy as $topic => $payload) {
        if (substr($topic, -11) === '/auswertung' || $topic === 'Daikin/energie/zeitbasis') { continue; }
        $boolean = substr($topic, -13) === '/vollstaendig';
        if (!$boolean && substr($topic, -4) !== '_kwh') { continue; }
        $fields[$topic] = array('type' => $boolean ? 'bool' : 'number', 'unit' => $boolean ? '' : 'kWh', 'measurement' => true);
        $values[$topic] = $payload === '-' ? null : (float) $payload;
    }
    return array($fields, $values);
}

/** Remember exported fields so a disappearing sensor produces an explicit missing marker. */
function wp_daikin_vi_inventory(array $stand, array $cfg)
{
    list($current) = wp_daikin_vi_snapshot($stand, $cfg);
    $old = ($stand['daikin_vi_geraet'] ?? '') === $cfg['geraet'] && is_array($stand['daikin_vi_felder'] ?? null)
        ? $stand['daikin_vi_felder'] : array();
    $fields = array_merge($old, $current);
    if (count($fields) > 2000) { throw new RuntimeException('Daikin VI field limit exceeded'); }
    ksort($fields);
    return $fields;
}

function wp_daikin_vi_key($topic)
{
    return 'D_' . strtoupper(substr(hash('sha256', $topic), 0, 12));
}

function wp_daikin_http_line(array $stand, array $cfg, $now = null)
{
    $now = $now ?? time(); $stamp = $stand['oc_energie_zeit'] ?? 0;
    $age = is_int($stamp) && $stamp > 0 && $stamp <= $now ? $now - $stamp : -1;
    $valid = $cfg['hersteller'] === 'onecta' && !empty($stand['ok']) && $age >= 0
        && $age <= max(60, (int) $cfg['takt']) * 3
        && ($stand['oc_zusatz_geraet'] ?? '') === $cfg['geraet'];
    try { $fields = wp_daikin_vi_inventory($stand, $cfg); list(, $values) = wp_daikin_vi_snapshot($stand, $cfg); }
    catch (Throwable $e) { $fields = $stand['daikin_vi_felder'] ?? array(); $values = array(); $valid = false; }
    $line = 'DAIKIN;OK=' . ($valid ? '1' : '0') . ';ALTER=' . $age . ';ZEIT=' . (is_int($stamp) && $stamp > 0 ? $stamp : '0');
    $keys = array();
    foreach ($fields as $topic => $definition) {
        $key = wp_daikin_vi_key($topic);
        if (isset($keys[$key])) { throw new RuntimeException('Daikin VI key collision'); }
        $keys[$key] = true;
        $value = $values[$topic] ?? null;
        $line .= ';' . $key . '=' . ($valid && wp_oc_ext_number($value) ? (string) $value : '-999999999');
    }
    return $line;
}

function wp_daikin_vorlage(array $stand, array $cfg, $address, $metadata = false)
{
    $fields = wp_daikin_vi_inventory($stand, $cfg); $commands = array();
    foreach (array('OK' => array(false, 0, 1, '<v>'), 'ALTER' => array(true, -1, 2147483647, '<v> s'),
        'ZEIT' => array(true, 0, 2147483647, '<v>')) as $key => $spec) {
        $commands[] = array('title' => 'DAIKIN_' . $key, 'comment' => $key === 'OK' ? 'Gültiger gespeicherter Abruf; zusammen mit Datenalter auswerten' : 'Eigener Abrufzeitpunkt / Datenalter',
            'check' => '\i;' . $key . '=\i\v', 'analog' => $spec[0], 'min' => $spec[1], 'max' => $spec[2], 'unit' => $spec[3]);
    }
    foreach ($fields as $topic => $definition) {
        if (!$metadata && empty($definition['measurement'])) { continue; }
        $key = wp_daikin_vi_key($topic);
        $title = 'DAIKIN_' . strtoupper(preg_replace('/[^a-zA-Z0-9]+/', '_', substr($topic, 7)));
        $title = substr($title, 0, 48) . '_' . substr($key, 2, 12);
        $commands[] = array('title' => $title, 'comment' => $topic . '; fehlend/veraltet: -999999999; bool: 0/1; kein neuer Cloudabruf',
            'check' => '\i;' . $key . '=\i\v', 'analog' => true, 'min' => -999999999, 'max' => 2147483647,
            'default' => -999999999, 'unit' => $definition['unit'] !== '' ? '<v.3> ' . $definition['unit'] : '<v.3>');
    }
    return array($metadata ? 'VI_Daikin_Metadaten.xml' : 'VI_Daikin.xml', wp_xml_virtual_in_http(array(
        'title' => $metadata ? 'Daikin mit Metadaten' : 'Daikin Wärmepumpe', 'address' => $address,
        'polling' => (string) max(60, (int) $cfg['takt']),
        'comment' => 'Gespeicherte Daikin-Daten; OK und ALTER auswerten. Fehlwert -999999999. Texte und JSON-Listen bleiben im Daikin-Katalog/MQTT. Wiederholter Import erzeugt weitere Bausteine.'), $commands));
}
