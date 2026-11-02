<?php
require __DIR__ . '/../webfrontend/htmlauth/wp_lib.php';
$n = 0; $errors = 0;
function check($ok, $label) {
    global $n, $errors; $n++;
    if (!$ok) { $errors++; fwrite(STDERR, 'FAIL: ' . $label . "\n"); }
}
$now = 2000000000;
$headers = wp_oc_limit_headers(array('HTTP/1.1 100 Continue', 'Retry-After: 999', 'HTTP/1.1 429 Too Many Requests',
    'rEtRy-AfTeR: 120', 'X-RateLimit-Remaining-day: 17', 'Set-Cookie: confidential', 'Authorization: secret'));
check($headers === array('retry-after' => '120', 'x-ratelimit-remaining-day' => '17'), 'only allowed final-response headers captured');
check(wp_oc_retry_seconds('120', $now) === 120, 'Retry-After seconds');
check(wp_oc_retry_seconds(gmdate('D, d M Y H:i:s', $now + 180) . ' GMT', $now) === 180, 'Retry-After HTTP-date');
foreach (array('tomorrow', '-10', "120\nSet-Cookie: x", array(), true) as $v) { check(wp_oc_retry_seconds($v, $now) === null, 'invalid retry input'); }
check(wp_oc_retry_seconds('0', $now) === 1, 'zero retry avoids a tight loop');
$state = wp_oc_limit_response(array(), 429, $headers, $now);
check($state['pause_until'] === $now + 120 && $state['pause_reason'] === 'HTTP_429', '429 pause follows Retry-After');
check(wp_oc_limit_response($state, 200, array(), $now + 1)['pause_until'] === $now + 120, 'success cannot shorten active pause');
check(wp_oc_limit_response($state, 429, array('retry-after' => '300'), $now + 1)['pause_until'] === $now + 301, 'later 429 extends pause');
check(wp_oc_limit_response(array(), 429, array(), $now)['pause_until'] === $now + 60, 'missing retry defaults to minute');
check(wp_oc_limit_response(array(), 429, array('ratelimit-reset' => '75'), $now)['pause_until'] === $now + 75, 'reset fallback');
check(wp_oc_limit_response(array(), 429, array('x-ratelimit-remaining-day' => '0'), $now)['pause_until'] === $now + 86400, 'unknown exhausted day resets conservatively');
check(wp_oc_limit_response(array(), 200, array('x-ratelimit-remaining-minute' => '0', 'ratelimit-reset' => '30'), $now)['pause_until'] === $now + 30, 'zero minute budget pauses before 429');
check(wp_oc_limit_response(array(), 200, array('x-ratelimit-remaining-day' => '0'), $now)['pause_until'] === $now + 86400, 'zero daily budget pauses before 429');
check(wp_oc_limit_admit($state, $now, 0) === 'API_PAUSE', 'active pause gates request');
check(wp_oc_limit_admit($state, $now + 120, 0) === '', 'pause expires exactly at boundary');
check(wp_oc_limit_admit(array(), $now, 200) === 'BUDGET_LEER', 'existing 200/day limit');
check(wp_oc_limit_admit(array('attempts' => array_fill(0, 20, $now - 59)), $now, 0) === 'API_MINUTENLIMIT', 'sliding minute window');
check(wp_oc_limit_admit(array('attempts' => array_fill(0, 20, $now - 60)), $now, 0) === '', '60-second boundary');
$strict = array('seen' => $now, 'headers' => array('x-ratelimit-limit-day' => '50', 'x-ratelimit-limit-minute' => '2'), 'attempts' => array($now, $now));
check(wp_oc_limit_admit($strict, $now, 0) === 'API_MINUTENLIMIT', 'stricter server minute limit');
check(wp_oc_limit_admit($strict, $now, 50) === 'BUDGET_LEER', 'stricter server day limit');
check(wp_oc_limit_admit(array('seen' => $now, 'headers' => array('ratelimit-limit' => '0')), $now, 0) === 'API_MINUTENLIMIT', 'generic server limit including zero');
$savedLimit = wp_oc_limit_response(array(), 200, array('x-ratelimit-limit-minute' => '2'), $now);
$savedLimit = wp_oc_limit_response($savedLimit, 503, array(), $now + 1);
$savedLimit['attempts'] = array($now, $now);
check(wp_oc_limit_admit($savedLimit, $now + 1, 0) === 'API_MINUTENLIMIT', 'missing error-response headers do not relax known server ceiling');

$device = json_decode(file_get_contents(__DIR__ . '/fixtures/altherma.json'), true)[0];
$device['_id'] = 'deprecated-other-id';
check(wp_oc_ext_device($device, 'fixture-gateway') === $device, 'canonical id wins over differing _id for object');
check(wp_oc_ext_device(array($device), 'fixture-gateway') === $device, 'canonical id wins for array');
check(wp_oc_ext_device($device, 'deprecated-other-id') === array(), 'deprecated id cannot override canonical id');
foreach (array(null, '', array()) as $invalidId) {
    $bad = $device; $bad['id'] = $invalidId;
    check(wp_oc_ext_device($bad, 'fixture-gateway') === array(), 'invalid canonical id does not allow legacy identity fallback');
}
$legacy = $device; unset($legacy['id']);
check(wp_oc_ext_device($legacy, 'deprecated-other-id') === $legacy, 'legacy id fallback only if id absent');
$point = $device['managementPoints'][1];
check(wp_oc_ext_block_reason($device, $point) === '', 'normal fixture not blocked');
$p = $point; $p['isInInstallerState']['value'] = true;
check(wp_oc_ext_block_reason($device, $p) === 'INSTALLATEURMODUS', 'installer state');
$p = $point; $p['isHolidayModeActive']['value'] = true;
check(wp_oc_ext_block_reason($device, $p) === 'URLAUB_AKTIV', 'active holiday');
$p = $point; $p['holidayMode']['value']['enabled'] = true;
check(wp_oc_ext_block_reason($device, $p) === '', 'future holiday with explicit inactive status does not block');
unset($p['isHolidayModeActive']);
check(wp_oc_ext_block_reason($device, $p) === 'URLAUB_AKTIV', 'configured holiday without active-status evidence fails closed');
$p = $point; $p['isInInstallerState']['value'] = 'false';
check(wp_oc_ext_block_reason($device, $p) === 'ANLAGENZUSTAND_UNGUELTIG', 'malformed state is not treated as safe');
foreach (array(0, 5) as $index) {
    $d = $device; $d['managementPoints'][$index]['firmwareUpdate']['value']['type'] = 'blocking';
    check(wp_oc_ext_block_reason($d, $point) === 'FIRMWARE_BLOCKIERT', 'blocking gateway/MMI firmware');
}
$d = $device; $d['managementPoints'][0]['firmwareUpdate']['value']['type'] = 'optional';
check(wp_oc_ext_block_reason($d, $point) === '', 'optional available firmware alone does not block');
$d['managementPoints'][0]['firmwareUpdateStatus']['value'] = 'in-progress';
check(wp_oc_ext_block_reason($d, $point) === 'FIRMWARE_UPDATE_AKTIV', 'running firmware blocks new controls');
echo "$n limit/state checks, $errors failures\n";
exit($errors ? 1 : 0);
