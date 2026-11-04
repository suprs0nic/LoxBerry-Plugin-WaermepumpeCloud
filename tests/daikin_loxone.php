<?php
require __DIR__ . '/../webfrontend/htmlauth/wp_lib.php';
$n = 0; $errors = 0;
function vc($ok, $why) { global $n, $errors; $n++; if (!$ok) { $errors++; fwrite(STDERR, "FAIL Daikin VI: $why\n"); } }
$raw = json_decode(file_get_contents(__DIR__ . '/fixtures/altherma.json'), true);
$cfg = array_merge(wp_vorgaben(), array('hersteller' => 'onecta', 'geraet' => 'fixture-gateway', 'oc_energie_zeitzone' => 'Europe/Vienna'));
$now = strtotime('2026-11-04T12:00:00Z');
$stand = array('ok' => 1, 'roh' => $raw, 'oc_energie_zeit' => $now, 'oc_zusatz_geraet' => 'fixture-gateway');
list($fields, $values) = wp_daikin_vi_snapshot($stand, $cfg);
$line = wp_daikin_http_line($stand, $cfg, $now);
vc(strpos($line, 'DAIKIN;OK=1;ALTER=0;ZEIT=' . $now) === 0, 'local status and capture date');
$stand['daikin_vi_felder'] = wp_daikin_vi_inventory($stand, $cfg); $stand['daikin_vi_geraet'] = $cfg['geraet'];
$address = 'http://192.0.2.10/plugins/waermepumpe/index.php?token=fixture-only&aktion=daikin';
list($name, $xml) = wp_daikin_vorlage($stand, $cfg, $address);
list($allName, $allXml) = wp_daikin_vorlage($stand, $cfg, $address, true);
vc($name === 'VI_Daikin.xml' && $allName === 'VI_Daikin_Metadaten.xml', 'download names');
vc(strpos($xml, '<VirtualInHttp ') !== false && strpos($xml, 'PollingTime="300"') !== false, 'same HTTP input format and regular interval');
vc(strpos($xml, 'token=fixture-only&amp;aktion=daikin') !== false, 'XML escaping of protected cached endpoint');
vc(strpos($xml, 'Daikin/ww/sensoryData/value/tankTemperature/value') !== false, 'tank temperature included');
vc(strpos($xml, 'Daikin/energie/ww/heute/bekannt_kwh') !== false, 'WW energy included');
vc(strpos($xml, 'DefVal="-999999999"') !== false, 'missing value is not default zero');
vc(strpos($xml, 'Daikin/ww/powerfulMode/value') === false, 'text on/off never pretended numeric');
vc(strpos($allXml, 'domesticHotWaterTemperature/settable') !== false, 'optional metadata template');
vc(substr_count($allXml, '<VirtualInHttpCmd ') > substr_count($xml, '<VirtualInHttpCmd '), 'metadata adds inputs');
preg_match_all('/Title="([^"]+)"/', $allXml, $titles);
vc(count($titles[1]) === count(array_unique($titles[1])), 'unique Loxone titles');
preg_match_all('/Check="([^\"]+)"/', $xml, $checks);
foreach ($checks[1] as $check) {
    $pattern = html_entity_decode($check, ENT_QUOTES, 'UTF-8');
    $marker = substr($pattern, 2, -4); // remove Loxone \i ... \i\v
    vc(strpos($line, $marker) !== false, 'every template check matches HTTP output');
}
$topic = 'Daikin/ww/sensoryData/value/tankTemperature/value';
$key = wp_daikin_vi_key($topic);
$gone = $stand; unset($gone['roh'][0]['managementPoints'][2]['sensoryData']);
vc(strpos(wp_daikin_http_line($gone, $cfg, $now), ';' . $key . '=-999999999') !== false, 'disappearing sensor explicitly missing');
$zero = $stand; $zero['roh'][0]['managementPoints'][2]['sensoryData']['value']['tankTemperature']['value'] = 0;
vc(strpos(wp_daikin_http_line($zero, $cfg, $now), ';' . $key . '=0;') !== false, 'real zero remains zero');
$invalid = $stand; $invalid['ok'] = 0;
vc(strpos(wp_daikin_http_line($invalid, $cfg, $now), ';' . $key . '=-999999999') !== false, 'failed read no stale measurement');
vc(strpos(wp_daikin_http_line($stand, $cfg, $now + 3600), ';OK=0;') !== false, 'stale read invalid');
$changed = $cfg; $changed['geraet'] = 'other-device';
vc(strpos(wp_daikin_http_line($stand, $changed, $now), ';OK=0;') !== false, 'device switch invalid');
vc(strpos(wp_daikin_http_line(array(), $cfg, $now), ';OK=0;ALTER=-1;') !== false, 'no read produces explicit unavailable status');
echo "Daikin VI: $n checks, $errors failures; " . substr_count($xml, '<VirtualInHttpCmd ') . ' measurement inputs / '
    . substr_count($allXml, '<VirtualInHttpCmd ') . " including metadata\n";
exit($errors ? 1 : 0);
