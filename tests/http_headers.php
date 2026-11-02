<?php
/** Calls only the temporary loopback server started by tools/check.py. */
require __DIR__ . '/../webfrontend/htmlauth/wp_lib.php';
$url = isset($argv[1]) ? $argv[1] : '';
if (!preg_match('#^http://127\.0\.0\.1:[0-9]+/headers$#D', $url)) { exit(2); }
$r = wp_http('GET', $url);
$checks = array(
    $r['code'] === 429,
    json_decode($r['text'], true) === array('fixture' => true),
    $r['headers']['retry-after'] === '120',
    $r['headers']['x-ratelimit-remaining-day'] === '141',
    $r['headers']['x-ratelimit-remaining-minute'] === '19',
    !isset($r['headers']['set-cookie']) && !isset($r['headers']['authorization']),
);
$failures = count(array_filter($checks, function ($v) { return !$v; }));
echo count($checks) . ' HTTP-header checks (' . (function_exists('curl_init') ? 'curl' : 'stream') . '), ' . $failures . " failures\n";
exit($failures ? 1 : 0);
