<?php
/** Onecta data-API admission and response-header handling; OAuth remains independent. */
function wp_oc_limit_headers($lines)
{
    $out = array();
    foreach ((array) $lines as $key => $line) {
        if (!is_string($line)) { continue; }
        if (is_string($key)) { $line = $key . ': ' . $line; }
        if (preg_match('#^HTTP/\S+\s+\d{3}#i', $line)) { $out = array(); continue; }
        if (strpos($line, ':') === false) { continue; }
        list($name, $value) = explode(':', $line, 2);
        $name = strtolower(trim($name));
        if (in_array($name, array('retry-after', 'ratelimit-limit', 'ratelimit-remaining', 'ratelimit-reset',
            'x-ratelimit-limit-minute', 'x-ratelimit-remaining-minute', 'x-ratelimit-limit-day', 'x-ratelimit-remaining-day'), true)) {
            $value = trim($value);
            if (strlen($value) <= 128 && !preg_match('/[\r\n\x00]/', $value)) { $out[$name] = $value; }
        }
    }
    return $out;
}

function wp_oc_limit_integer($value)
{
    return is_scalar($value) && !is_bool($value) && preg_match('/^[0-9]{1,9}$/D', (string) $value)
        ? (int) $value : null;
}

function wp_oc_retry_seconds($value, $now)
{
    $seconds = wp_oc_limit_integer($value);
    if ($seconds !== null) { return max(1, $seconds); }
    // Retry-After also permits an HTTP-date. Never interpret arbitrary relative expressions.
    if (!is_string($value) || !preg_match('/^[A-Za-z]{3}, [0-9]{2} [A-Za-z]{3} [0-9]{4} [0-9:]{8} GMT$/D', $value)) { return null; }
    $stamp = strtotime($value);
    return $stamp === false ? null : max(1, $stamp - $now);
}

/** Pure transition. A response can extend, but never shorten, a known pause. */
function wp_oc_limit_response(array $state, $code, array $headers, $now)
{
    $state['seen'] = $now;
    $state['last_http'] = (int) $code;
    $state['headers'] = $headers;
    // Keep explicit server ceilings across responses without headers (e.g. an HTTP 503).
    foreach (array('x-ratelimit-limit-day' => 'day', 'x-ratelimit-limit-minute' => 'minute', 'ratelimit-limit' => 'minute') as $header => $scope) {
        if ($header === 'ratelimit-limit' && isset($headers['x-ratelimit-limit-minute'])) { continue; }
        $limit = wp_oc_limit_integer(isset($headers[$header]) ? $headers[$header] : null);
        if ($limit !== null) { $state['server_limits'][$scope] = array('value' => $limit, 'seen' => $now); }
    }
    $retry = wp_oc_retry_seconds(isset($headers['retry-after']) ? $headers['retry-after'] : null, $now);
    $reset = wp_oc_limit_integer(isset($headers['ratelimit-reset']) ? $headers['ratelimit-reset'] : null);
    $minute = wp_oc_limit_integer(isset($headers['x-ratelimit-remaining-minute']) ? $headers['x-ratelimit-remaining-minute']
        : (isset($headers['ratelimit-remaining']) ? $headers['ratelimit-remaining'] : null));
    $day = wp_oc_limit_integer(isset($headers['x-ratelimit-remaining-day']) ? $headers['x-ratelimit-remaining-day'] : null);
    $wait = 0; $reason = '';
    if ((int) $code === 429) {
        $wait = $retry !== null ? $retry : ($reset !== null && $reset > 0 ? $reset : ($day === 0 ? 86400 : 60));
        $reason = 'HTTP_429';
    } elseif ($day === 0) {
        // No day-specific reset exists in the documented headers: conservative fallback.
        $wait = $retry !== null ? $retry : 86400; $reason = 'TAGESKONTINGENT';
    } elseif ($minute === 0) {
        $wait = max($retry !== null ? $retry : 0, $reset !== null ? $reset : 60);
        $reason = 'MINUTENKONTINGENT';
    }
    if ($wait > 0 && $now + $wait > (isset($state['pause_until']) ? $state['pause_until'] : 0)) {
        $state['pause_until'] = $now + $wait;
        $state['pause_reason'] = $reason;
    }
    return $state;
}

function wp_oc_limits_file()
{
    return wp_paths()['bestand'] . '/onecta_limits.json';
}

/** Read only, including for UI. Missing state is a normal upgrade, malformed state fails closed. */
function wp_oc_limits_read()
{
    $file = wp_oc_limits_file();
    if (!is_file($file)) { return array(array(), ''); }
    $state = json_decode((string) @file_get_contents($file), true);
    if (!is_array($state)) { return array(array(), 'API_LIMITDATEI_DEFEKT'); }
    if (!empty($state['pending'])) { return array($state, 'API_LIMITANTWORT_FEHLT'); }
    foreach (array('pause_until', 'seen') as $key) {
        if (isset($state[$key]) && (!is_int($state[$key]) || $state[$key] < 0)) { return array(array(), 'API_LIMITDATEI_DEFEKT'); }
    }
    if (isset($state['attempts']) && !is_array($state['attempts'])) { return array(array(), 'API_LIMITDATEI_DEFEKT'); }
    if (isset($state['headers']) && !is_array($state['headers'])) { return array(array(), 'API_LIMITDATEI_DEFEKT'); }
    if (isset($state['pause_reason']) && !is_string($state['pause_reason'])) { return array(array(), 'API_LIMITDATEI_DEFEKT'); }
    if (isset($state['server_limits']) && !is_array($state['server_limits'])) { return array(array(), 'API_LIMITDATEI_DEFEKT'); }
    foreach (isset($state['server_limits']) ? $state['server_limits'] : array() as $limit) {
        if (!is_array($limit) || !isset($limit['value'], $limit['seen']) || !is_int($limit['value'])
            || $limit['value'] < 0 || !is_int($limit['seen'])) { return array(array(), 'API_LIMITDATEI_DEFEKT'); }
    }
    foreach (isset($state['attempts']) ? $state['attempts'] : array() as $attempt) {
        if (!is_int($attempt) || $attempt < 0) { return array(array(), 'API_LIMITDATEI_DEFEKT'); }
    }
    return array($state, '');
}

function wp_oc_limit_admit(array $state, $now, $dayCount)
{
    if (!empty($state['pause_until']) && $state['pause_until'] > $now) { return 'API_PAUSE'; }
    $dayLimit = 200; $minuteLimit = 20;
    $headers = isset($state['headers']) && is_array($state['headers']) ? $state['headers'] : array();
    if (isset($state['seen']) && $state['seen'] <= $now && $now - $state['seen'] < 86400) {
        if (!isset($headers['x-ratelimit-limit-minute']) && isset($headers['ratelimit-limit'])) {
            $headers['x-ratelimit-limit-minute'] = $headers['ratelimit-limit'];
        }
        foreach (array('x-ratelimit-limit-day' => 'dayLimit', 'x-ratelimit-limit-minute' => 'minuteLimit') as $header => $var) {
            $value = wp_oc_limit_integer(isset($headers[$header]) ? $headers[$header] : null);
            if ($value !== null) { $$var = min($$var, $value); }
        }
    }
    foreach (array('day' => 'dayLimit', 'minute' => 'minuteLimit') as $scope => $var) {
        $limit = isset($state['server_limits'][$scope]) ? $state['server_limits'][$scope] : null;
        if (is_array($limit) && isset($limit['value'], $limit['seen']) && $limit['seen'] <= $now && $now - $limit['seen'] < 86400) {
            $$var = min($$var, $limit['value']);
        }
    }
    if ($dayCount >= $dayLimit) { return 'BUDGET_LEER'; }
    $attempts = isset($state['attempts']) ? $state['attempts'] : array();
    $attempts = array_filter($attempts, function ($t) use ($now) { return is_int($t) && $t > $now - 60; });
    return count($attempts) >= $minuteLimit ? 'API_MINUTENLIMIT' : '';
}

function wp_oc_limits_reason()
{
    list($state, $error) = wp_oc_limits_read();
    return $error !== '' ? $error : wp_oc_limit_admit($state, time(), count(wp_budget_liste('onecta')));
}

function wp_oc_limits_text()
{
    list($state, $error) = wp_oc_limits_read();
    if ($error !== '') { return 'Daikin-Aufrufe gesperrt: Limitdatei ist unlesbar oder die letzte Anfrage nicht abgeschlossen. Gesicherte Datei vor einer Reparatur prüfen.'; }
    $reason = wp_oc_limit_admit($state, time(), count(wp_budget_liste('onecta')));
    if ($reason === 'API_PAUSE') {
        return 'Daikin-API pausiert bis ' . date('d.m.Y H:i:s', $state['pause_until']) . ' ('
            . (isset($state['pause_reason']) ? $state['pause_reason'] : 'Kontingent') . '). Kein automatisches Wiederholen von Schreibbefehlen.';
    }
    if ($reason === 'BUDGET_LEER') { return 'Daikin-Tageskontingent aufgebraucht; kein weiterer Daten-API-Aufruf.'; }
    if ($reason === 'API_MINUTENLIMIT') { return 'Daikin-Minutenkontingent aufgebraucht; nächster regulärer Versuch nach Freigabe.'; }
    return 'Daikin-Aufrufe freigegeben. Gleitende Grenzen: höchstens 200 pro Tag und 20 pro Minute; Servergrenzen können strenger sein.';
}

/** All three existing Onecta data endpoints pass here. Lock spans reservation and response. */
function wp_oc_api($method, $url, array $headers, $body = null, $timeout = 25)
{
    $fail = function ($reason) { return array('code' => 0, 'text' => '', 'fehler' => $reason, 'headers' => array()); };
    if (strpos($url, 'https://api.onecta.daikineurope.com/v1/') !== 0) { return $fail('API_ADRESSE_UNGUELTIG'); }
    $dir = dirname(wp_oc_limits_file());
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) { return $fail('API_LIMIT_NICHT_GESPEICHERT'); }
    $lock = @fopen($dir . '/onecta_limits.lock', 'c');
    if (!$lock) { return $fail('API_LIMITSPERRE'); }
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return $fail('API_BESCHAEFTIGT'); }
    try {
        list($state, $error) = wp_oc_limits_read();
        if ($error !== '') { return $fail($error); }
        $now = time(); $day = wp_budget_liste('onecta');
        $reason = wp_oc_limit_admit($state, $now, count($day));
        if ($reason !== '') { return $fail($reason); }
        $attempts = isset($state['attempts']) ? $state['attempts'] : array();
        $state['attempts'] = array_values(array_filter($attempts, function ($t) use ($now) { return is_int($t) && $t > $now - 60; }));
        $state['attempts'][] = $now;
        $state['pending'] = true;
        $day[] = $now;
        // Reserve both counters BEFORE HTTP; crashes and 429 still consume an attempt.
        if (!wp_json_schreiben(wp_oc_limits_file(), $state) || !wp_json_schreiben(wp_budget_datei('onecta'), $day)) {
            return $fail('API_LIMIT_NICHT_GESPEICHERT');
        }
        $response = wp_http($method, $url, $headers, $body, $timeout);
        $safeHeaders = wp_oc_limit_headers(isset($response['headers']) ? $response['headers'] : array());
        $state = wp_oc_limit_response($state, $response['code'], $safeHeaders, time());
        unset($state['pending']);
        if (!wp_json_schreiben(wp_oc_limits_file(), $state)) {
            // The previously persisted pending flag closes admission after a failed response write.
            wp_log('Daikin: API-Limitantwort konnte nicht gespeichert werden; weitere Aufrufe werden gesperrt.', 'oc_limit_write');
        }
        return $response;
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
}

function wp_oc_api_error(array $response)
{
    if ((int) $response['code'] === 0 && !empty($response['fehler'])) { return $response['fehler']; }
    return (int) $response['code'] === 429 ? 'BUDGET_LEER' : 'HTTP_' . (int) $response['code'];
}
