<?php
/** Exercise the real poll/writer/config code with HTTP/MQTT transport boundaries replaced.
 * No network possible: the original HTTP implementation is removed before loading.
 */
$root = __DIR__ . '/.runtime/run-' . getmypid();
foreach (array('/config/plugins', '/data/plugins', '/config/system', '/tmp') as $dir) { mkdir($root . $dir, 0700, true); }
file_put_contents($root . '/config/system/general.json', '{}');
putenv('LBHOMEDIR=' . $root); putenv('LBPPLUGINDIR=fixture');
$http = array(); $mqtt = array(); $responseCode = 200; $responseHeaders = array(); $throwMqtt = false;
$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/altherma.json'), true)[0];
function wp_http($method, $url, $headers = array(), $body = null, $timeout = 20) {
    global $http, $responseCode, $responseHeaders, $fixture;
    $http[] = array($method, $url, $body);
    return array('code' => in_array($method, array('PATCH', 'PUT', 'POST'), true) && $responseCode === 200 ? 204 : $responseCode,
        'text' => json_encode($fixture), 'fehler' => '', 'headers' => $responseHeaders);
}
function wp_mqtt_senden($values) {
    global $mqtt, $throwMqtt;
    if ($throwMqtt && isset($values['Daikin/status/gueltig'])) { throw new RuntimeException('mock gateway failure'); }
    $mqtt[] = $values; return true;
}
function wp_tmpdir() { global $root; return $root . '/tmp'; }
function fixture_atomic_replace($from, $to) {
    // Windows indexers can briefly hold a JSON file open across atomic replacement.
    // Retry ONLY this filesystem boundary, never HTTP or a heat-pump command.
    for ($attempt = 0; $attempt < (PHP_OS_FAMILY === 'Windows' ? 12 : 1); $attempt++) {
        if (@rename($from, $to)) { return true; }
        usleep(25000);
    }
    return false;
}
$lib = realpath(__DIR__ . '/../webfrontend/htmlauth/wp_lib.php');
$source = file_get_contents($lib);
// Strip the HTTP function completely, so even an accidental call cannot reach a network.
$begin = strpos($source, 'function wp_http(');
$end = strpos($source, 'function wp_json(', $begin);
$source = substr($source, 0, $begin) . substr($source, $end);
$source = str_replace(array('function wp_mqtt_senden(', 'function wp_tmpdir('),
    array('function unused_mqtt_transport(', 'function unused_tmpdir('), $source);
$source = str_replace('if (!@rename($neben, $pfad))', 'if (!fixture_atomic_replace($neben, $pfad))', $source);
$source = str_replace(array('__DIR__', '__FILE__'), array(var_export(dirname($lib), true), var_export($lib, true)), $source);
eval('?>' . $source);
$n = 0; $errors = 0;
function check($condition, $label) {
    global $n, $errors; $n++;
    if (!$condition) { $errors++; fwrite(STDERR, "FAIL: $label\n"); }
}
try {
    $cfg = array_merge(wp_vorgaben(), array('hersteller' => 'onecta', 'geraet' => 'fixture-gateway',
        'aktionstoken' => str_repeat('a', 32), 'zuordnung' => 'SOLL=managementPoints[embeddedId=1].temperatureControl.value.operationModes.heating.setpoints.roomTemperature.value',
        'befehle_mqtt_ein' => 1, 'befehle_mqtt_steuern' => 1,
        'oc_daikin_schreiben' => 1, 'oc_daikin_zeitplan' => 1, 'oc_daikin_urlaub' => 1,
        'oc_zusatz_ein' => 1, 'oc_heiz_offset' => 1, 'oc_raum_soll' => 1, 'oc_heizmodus' => 1));
    check(wp_config_write($cfg), 'save extended configuration');
    check(wp_config(false)['oc_heiz_offset'] === 1 && wp_config(false)['zuordnung'] === $cfg['zuordnung'], 'settings round-trip preserves custom paths');
    wp_geheim_write(array('client_id' => 'offline-client', 'client_secret' => 'offline-secret'));
    wp_json_schreiben(wp_tmpdir() . '/token_onecta.json', array('token' => 'offline-token', 'bis' => time() + 3600));
    check(wp_abrufen(true)[0] === 1, 'real poll succeeds with fixture transport');
    check(count($http) === 1 && $http[0][0] === 'GET', 'exactly one cloud read, no extra requests');
    check(count($mqtt) === 2 && $mqtt[1]['Daikin/heizung/operationMode/value'] === 'heating' && $mqtt[1]['Daikin/status/gueltig'] === '1', 'regular poll publishes Daikin catalogue');
    check(isset($mqtt[0]['AUSSEN'], $mqtt[0]['WW'], $mqtt[0]['EIN']) && $mqtt[0]['RUECKLAUF'] === wp_fehlwert('RUECKLAUF'), 'legacy MQTT still published with explicit missing return marker');
    check(wp_stand()['oc_zusatz_geraet'] === 'fixture-gateway', 'cached capabilities belong to configured gateway');
    check(wp_stand()['oc_energie_zeit'] === wp_stand()['oc_zusatz_zeit'], 'energy capture timestamp persisted with raw response');
    check(isset($mqtt[1]['Daikin/energie/gesamt/gestern/bekannt_kwh'])
        && $mqtt[1]['Daikin/energie/heizung/dieser_monat/bekannt_kwh'] === '-', 'normal poll adds summaries, month needs timezone');
    $wp_cfg = wp_config(false);
    ob_start(); include __DIR__ . '/../webfrontend/htmlauth/wp_daikin_ui.php'; $html = ob_get_clean();
    check(strpos($html, 'name="oc_heiz_offset" value="1" checked') !== false
        && strpos($html, 'Alle Felder und Metadaten') !== false && strpos($html, 'Daikin/energie/') !== false,
        'real Daikin UI renders saved checkbox, catalogue and energy topics');
    check(strpos($html, 'Elektrischer Energieverbrauch') !== false && strpos($html, 'Heute bisher') !== false
        && strpos($html, 'Summe dieser Bereiche') !== false && strpos($html, 'name="oc_energie_zeitzone"') !== false,
        'energy table and explicit timezone field rendered');
    $saved = wp_stand(); $injected = $saved; $injected['roh']['deviceModel'] = '<script>alert(1)</script>';
    wp_stand_write($injected);
    ob_start(); include __DIR__ . '/../webfrontend/htmlauth/wp_daikin_ui.php'; $html = ob_get_clean();
    check(strpos($html, '<script>alert(1)</script>') === false && strpos($html, '&lt;script&gt;') !== false,
        'UI escapes device-provided values');
    wp_stand_write($saved);
    $count = count($http); wp_probe(true);
    check(wp_daikin_command('ww/boost', '1') === array(true, 'PROBE') && count($http) === $count, 'Daikin probe never writes');
    wp_probe(false);
    check(wp_daikin_command('ww/boost', '1')[0], 'canonical Daikin boost dispatched through real wrapper');
    $request = $http[count($http) - 1];
    check($request[0] === 'PATCH' && strpos($request[1], '/management-points/2/characteristics/powerfulMode') !== false
        && json_decode($request[2], true) === array('value' => 'on'), 'actual boost request uses ID 2 and on/off');
    check(wp_stand()['oc_zusatz_zeit'] === 0 && wp_stand()['oc_energie_zeit'] > 0, 'canonical write invalidates capabilities, preserves energy capture');
    $count = count($http);
    check(wp_daikin_command('ww/boost', '1')[1] === 'DATEN_VERALTET' && count($http) === $count, 'canonical next write requires poll');
    $refresh = wp_abrufen(true);
    check($refresh[0] === 1 && wp_stand()['ok'] === 1, 'refresh after canonical boost: ' . json_encode($refresh));
    check(wp_daikin_command('heizung/ein', '0')[1] === 'BEFEHLSBREMSE', 'canonical global cooldown spans heating and WW');
    wp_json_schreiben(wp_tmpdir() . '/oc_zusatz_befehl.json', array('zeit' => time() - 61));
    $scheduleResult = wp_daikin_command('heizung/zeitplan_auswahl', '{"mode":"heating","scheduleId":"0","enabled":true}');
    check($scheduleResult[0], 'schedule selection through actual wrapper: ' . json_encode($scheduleResult));
    $request = $http[count($http) - 1];
    check($request[0] === 'PUT' && strpos($request[1], '/management-points/1/schedule/heating/current') !== false
        && json_decode($request[2], true) === array('scheduleId' => '0', 'enabled' => true), 'actual PUT excludes mode from body');
    $refresh = wp_abrufen(true);
    check($refresh[0] === 1 && wp_stand()['ok'] === 1, 'refresh after schedule: ' . json_encode($refresh));
    wp_json_schreiben(wp_tmpdir() . '/oc_zusatz_befehl.json', array('zeit' => time() - 61));
    check(wp_ww_boost($cfg, true)[0], 'legacy WW boost also resolves numeric ID');
    check(strpos($http[count($http) - 1][1], '/management-points/2/characteristics/powerfulMode') !== false, 'legacy WW no longer sends type name as endpoint ID');
    check(count(array_filter(array_keys($mqtt[1]), function ($key) { return strpos($key, 'zusatz/') === 0; })) === 0, 'poll emits no legacy zusatz topics');
    $count = count($http); check(wp_abrufen(false)[1] === 'NOCH_FRISCH' && count($http) === $count, 'normal scheduling unchanged');
    $mqtt = array(); $throwMqtt = true;
    check(wp_abrufen(true)[0] === 1 && wp_stand()['ok'] === 1, 'optional MQTT exception cannot fail poll');
    $throwMqtt = false; $responseCode = 503; $mqtt = array();
    check(wp_abrufen(true)[0] === 0, 'HTTP failure preserved');
    check($mqtt[1] === array('Daikin/status/gueltig' => '0') && !isset($mqtt[0]['AUSSEN']), 'failure publishes validity only, no stale extra/legacy readings');
    $responseCode = 200; $refresh = wp_abrufen(true);
    check($refresh[0] === 1 && wp_stand()['ok'] === 1, 'refresh after simulated 503: ' . json_encode($refresh));
    $count = count($http);
    $lock = fopen(wp_tmpdir() . '/abruf.lock', 'c'); flock($lock, LOCK_EX);
    check(wp_oc_ext_command('heiz_offset', '2')[1] === 'ABRUF_AKTIV' && count($http) === $count, 'shared poll lock blocks commands');
    flock($lock, LOCK_UN); fclose($lock);
    wp_probe(true);
    $probeResult = wp_oc_ext_command('heiz_offset', '2');
    check($probeResult === array(true, 'PROBE') && count($http) === $count, 'dry run cannot write: ' . json_encode($probeResult));
    wp_probe(false);
    check(wp_oc_ext_command('heiz_offset', '2')[0], 'guarded command succeeds through existing writer (mock HTTP)');
    $request = $http[count($http) - 1];
    check($request[0] === 'PATCH' && $request[1] === 'https://api.onecta.daikineurope.com/v1/gateway-devices/fixture-gateway/management-points/1/characteristics/temperatureControl', 'actual numeric-id endpoint');
    check(json_decode($request[2], true) === array('value' => 2, 'path' => '/operationModes/heating/setpoints/leavingWaterOffset'), 'actual PATCH body');
    check(wp_stand()['oc_zusatz_zeit'] === 0, 'successful command invalidates snapshot');
    check(wp_stand()['oc_energie_zeit'] > 0, 'write does not erase historical energy capture date');
    check(wp_oc_ext_command('heizmodus', 'cooling')[1] === 'DATEN_VERALTET', 'must poll again before another command');
    wp_abrufen(true);
    check(wp_oc_ext_command('heizmodus', 'cooling')[1] === 'BEFEHLSBREMSE', 'global cooldown across command types');
    wp_json_schreiben(wp_tmpdir() . '/oc_zusatz_befehl.json', array('zeit' => time() - 61));
    $responseCode = 429;
    check(wp_oc_ext_command('raum_soll', '22.5') === array(false, 'BUDGET_LEER'), '429 acknowledged without retry');
    check(wp_oc_ext_command('heiz_offset', '3')[1] === 'BEFEHLSBREMSE', 'failed write still throttled');
    $responseCode = 200;
    $limitFile = wp_oc_limits_file();
    check(wp_oc_lesen($cfg)['fehler'] === 'API_PAUSE', '429 fallback blocks a later read');
    $count = count($http);
    check(wp_oc_geraete() === array() && count($http) === $count, '429 pause also blocks device discovery');
    wp_json_schreiben($limitFile, array());
    wp_json_schreiben(wp_tmpdir() . '/oc_zusatz_befehl.json', array('zeit' => time() - 61));
    wp_json_schreiben(wp_budget_datei('onecta'), array_fill(0, 200, time()));
    $count = count($http);
    check(wp_oc_ext_command('heiz_offset', '3') === array(false, 'BUDGET_LEER') && count($http) === $count, 'existing daily budget respected');
    wp_json_schreiben(wp_budget_datei('onecta'), array());
    $responseCode = 429; $responseHeaders = array('retry-after' => '120', 'x-ratelimit-remaining-day' => '17');
    $count = count($http); $used = count(wp_budget_liste('onecta'));
    check(wp_oc_lesen($cfg)['fehler'] === 'BUDGET_LEER' && count($http) === $count + 1, 'real GET 429 detected');
    check(count(wp_budget_liste('onecta')) === $used + 1, '429 counted exactly once in daily budget');
    $state = json_decode(file_get_contents($limitFile), true);
    check($state['pause_until'] >= time() + 119 && $state['headers']['x-ratelimit-remaining-day'] === '17', 'Retry-After and remaining header persisted');
    $count = count($http);
    check(wp_oc_setzen($cfg, '1', 'operationMode', 'heating') === array(0, 'API_PAUSE') && count($http) === $count,
        'pause protects existing as well as new writers');
    $responseCode = 200; $responseHeaders = array();
    wp_json_schreiben($limitFile, array('pause_until' => time() - 1));
    check(wp_oc_lesen($cfg)['ok'] === 1, 'expired pause allows a regular read');
    wp_json_schreiben($limitFile, array('attempts' => array_fill(0, 20, time())));
    $count = count($http);
    check(wp_oc_lesen($cfg)['fehler'] === 'API_MINUTENLIMIT' && count($http) === $count, 'twenty attempts prevent next minute request');
    wp_json_schreiben($limitFile, array('attempts' => array_fill(0, 20, time() - 61)));
    check(wp_oc_lesen($cfg)['ok'] === 1, 'minute window expires without reset at clock boundary');
    $rateLock = fopen(dirname($limitFile) . '/onecta_limits.lock', 'c'); flock($rateLock, LOCK_EX);
    $count = count($http);
    check(wp_oc_api('GET', 'https://api.onecta.daikineurope.com/v1/gateway-devices', array())['fehler'] === 'API_BESCHAEFTIGT'
        && count($http) === $count, 'shared rate lock rejects concurrent data requests');
    flock($rateLock, LOCK_UN); fclose($rateLock);
    file_put_contents($limitFile, '{broken');
    check(wp_oc_lesen($cfg)['fehler'] === 'API_LIMITDATEI_DEFEKT', 'corrupt persistent rate state fails closed');
    wp_json_schreiben($limitFile, array('pending' => true));
    check(wp_oc_lesen($cfg)['fehler'] === 'API_LIMITANTWORT_FEHLT', 'incomplete response state fails closed');
    wp_json_schreiben($limitFile, array());
    $saved = wp_stand();
    foreach (array('isInInstallerState' => 'INSTALLATEURMODUS', 'isHolidayModeActive' => 'URLAUB_AKTIV') as $flag => $expected) {
        $blockedStand = $saved; $blockedStand['roh']['managementPoints'][1][$flag]['value'] = true;
        wp_stand_write($blockedStand); $count = count($http);
        check(wp_oc_ext_command('heiz_offset', '3') === array(false, $expected) && count($http) === $count,
            'actual dispatcher blocks ' . $expected . ' without HTTP');
    }
    wp_stand_write($saved);
    $cfg['oc_zusatz_ein'] = 0; wp_config_write($cfg); $mqtt = array(); wp_abrufen(true);
    check(count($mqtt) === 1 && !isset($mqtt[0]['Daikin/status/gueltig']), 'telemetry off emits only existing topics');
    // Legacy configuration with no new keys upgrades without losing existing values.
    foreach (wp_oc_ext_options() as $key => $label) { unset($cfg[$key]); }
    unset($cfg['oc_energie_zeitzone']);
    wp_config_write($cfg); $read = wp_config(false);
    check($read['oc_heiz_offset'] === 0 && $read['oc_zusatz_ein'] === 0 && $read['aktionstoken'] === $cfg['aktionstoken']
        && $read['zuordnung'] === $cfg['zuordnung'], 'old config gains disabled defaults and keeps existing token/mapping');
    check($read['oc_energie_zeitzone'] === '', 'old config never assumes a gateway timezone');
} finally {
    if ($errors) {
        foreach ((array) @file(wp_paths()['logdir'] . '/waermepumpe.log', FILE_IGNORE_NEW_LINES) as $line) {
            if (strpos($line, 'NICHT geschrieben') !== false || strpos($line, 'API-Limitantwort') !== false) { fwrite(STDERR, 'Test persistence: ' . $line . "\n"); }
        }
    }
    // Only the test-created directory under tests/.runtime is eligible for cleanup.
    $base = realpath(__DIR__ . '/.runtime'); $target = realpath($root);
    if ($target && strpos($target, $base . DIRECTORY_SEPARATOR . 'run-') === 0) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($target);
    }
}
echo "$n integration checks, $errors failures\n";
exit($errors ? 1 : 0);
