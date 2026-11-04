<?php
/** Export paths/types only. No identifiers, readings or credential values in the report. */
require __DIR__ . '/../webfrontend/htmlauth/wp_lib.php';
$path = $argv[1] ?? (__DIR__ . '/../tests/fixtures/altherma.json');
$raw = json_decode(file_get_contents($path), true);
$device = wp_oc_ext_device($raw);
if (!$device) { fwrite(STDERR, "Expected one unambiguous gateway\n"); exit(1); }
$catalog = wp_daikin_catalog($device);
function catalog_leaves($node, $path, &$out) {
    if (!is_array($node) || !$node) { $out[$path] = $node; return; }
    foreach ($node as $key => $value) { catalog_leaves($value, $path . '.' . $key, $out); }
}
$expected = array(); $mapped = array();
foreach ($device as $key => $value) { if ($key !== 'managementPoints') { catalog_leaves($value, $key, $expected); } }
foreach ($device['managementPoints'] as $p) {
    foreach ($p as $key => $value) { catalog_leaves($value, 'managementPoints[embeddedId=' . $p['embeddedId'] . '].' . $key, $expected); }
}
$export = array();
foreach ($catalog as $topic => $row) {
    catalog_leaves($row['wert'], $row['quelle'], $mapped);
    $export[] = array('topic' => $topic, 'api_path' => $row['quelle'], 'type' => $row['typ'],
        'feature_documented' => $row['dokumentiert']);
}
ksort($expected); ksort($mapped);
if ($expected !== $mapped) { fwrite(STDERR, "Catalogue lost or changed response data\n"); exit(1); }
$report = array('version' => '0.9.43', 'documentation_checked' => '2026-11-04', 'catalogue_topics' => count($catalog),
    'response_leaves_checked' => count($expected), 'lossless_response_coverage' => true, 'fields' => $export);
$json = __DIR__ . '/../docs/DAIKIN-FELDER.json';
file_put_contents($json, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo count($catalog) . ' topics, ' . count($expected) . " response leaves: lossless coverage verified. Path/type report exported without values.\n";
