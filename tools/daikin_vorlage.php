<?php
/** Local export from a response and an existing user VI file; never logs its token. */
require __DIR__ . '/../webfrontend/htmlauth/wp_lib.php';
if (count($argv) !== 4) { fwrite(STDERR, "Usage: php tools/daikin_vorlage.php response.json existing-VI.xml output-directory\n"); exit(1); }
$raw = json_decode(file_get_contents($argv[1]), true); $device = wp_oc_ext_device($raw);
$old = file_get_contents($argv[2]);
if (!$device || !preg_match('/\bAddress="([^"]*)"/', $old, $m)) { fwrite(STDERR, "Response or VI address missing\n"); exit(1); }
$address = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
$parts = parse_url($address);
if (!$parts || !in_array($parts['scheme'] ?? '', array('http', 'https'), true) || empty($parts['host'])) { fwrite(STDERR, "Invalid existing local URL\n"); exit(1); }
parse_str($parts['query'] ?? '', $query); $query['aktion'] = 'daikin';
$address = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
    . ($parts['path'] ?? '/') . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
$cfg = array_merge(wp_vorgaben(), array('hersteller' => 'onecta', 'geraet' => $device['id'] ?? $device['_id'] ?? ''));
if (preg_match('/\bPollingTime="([0-9]+)"/', $old, $m)) { $cfg['takt'] = max(60, (int) $m[1]); }
$stand = array('roh' => $raw);
if (!is_dir($argv[3]) && !mkdir($argv[3], 0700, true)) { exit(1); }
foreach (array(false, true) as $metadata) {
    list($name, $xml) = wp_daikin_vorlage($stand, $cfg, $address, $metadata);
    if (file_put_contents(rtrim($argv[3], '/\\') . '/' . $name, $xml) === false) { exit(1); }
    echo $name . ': ' . substr_count($xml, '<VirtualInHttpCmd ') . " inputs exported.\n";
}
