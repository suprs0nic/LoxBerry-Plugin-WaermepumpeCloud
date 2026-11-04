<?php
/** Only used with the isolated test LoxBerry root supplied by the Python runner. */
require __DIR__ . '/../webfrontend/htmlauth/wp_lib.php';
$cfg = array_merge(wp_vorgaben(), array('hersteller' => 'onecta', 'geraet' => 'fixture-gateway',
    'aktionstoken' => str_repeat('a', 32), 'oc_energie_zeitzone' => 'Europe/Vienna'));
wp_config_write($cfg);
$raw = json_decode(file_get_contents(__DIR__ . '/fixtures/altherma.json'), true);
$stand = array('ok' => 1, 'zeit' => time(), 'roh' => $raw, 'oc_energie_zeit' => time(), 'oc_zusatz_geraet' => $cfg['geraet']);
$stand['daikin_vi_felder'] = wp_daikin_vi_inventory($stand, $cfg); $stand['daikin_vi_geraet'] = $cfg['geraet'];
wp_stand_write($stand);
foreach (array(false, true) as $metadata) {
    list($name, $xml) = wp_daikin_vorlage($stand, $cfg, 'http://127.0.0.1/index.php?token=' . $cfg['aktionstoken'] . '&aktion=daikin', $metadata);
    file_put_contents(getenv('DAIKIN_TEST_XMLDIR') . '/' . $name, $xml);
}
