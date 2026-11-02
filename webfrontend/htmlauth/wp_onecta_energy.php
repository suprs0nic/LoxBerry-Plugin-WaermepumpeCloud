<?php
/** Electrical period summaries from the same Onecta response; never a cloud call. */
function wp_oc_energy_zone_valid($zone)
{
    return is_string($zone) && strlen($zone) <= 64
        && in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
}

function wp_oc_energy_periods($month = null)
{
    return array(
        'heute' => array('Heute bisher', 'd', 12, 12, false),
        'gestern' => array('Gestern', 'd', 0, 12, true),
        'diese_woche' => array('Diese Woche bisher', 'w', 7, 7, false),
        'letzte_woche' => array('Letzte Woche', 'w', 0, 7, true),
        'dieser_monat' => array('Dieser Monat bisher', 'm', $month === null ? null : 11 + $month, 1, false),
        'letzter_monat' => array('Letzter Monat', 'm', $month === null ? null : ($month === 1 ? 11 : 10 + $month), 1, true),
        'dieses_jahr' => array('Dieses Jahr bisher', 'm', 12, 12, false),
        'letztes_jahr' => array('Letztes Jahr', 'm', 0, 12, true),
    );
}

function wp_oc_energy_basis($device, $received, $configured = '')
{
    $gateway = wp_oc_ext_point($device, 'gateway', '0');
    $reported = wp_oc_ext_get($gateway, 'timeZone.value');
    $zone = wp_oc_energy_zone_valid($reported) ? $reported : (wp_oc_energy_zone_valid($configured) ? $configured : '');
    $basis = array('empfangen_unix' => is_int($received) && $received > 0 ? $received : null,
        'zeitzone' => $zone !== '' ? $zone : null,
        'quelle' => $zone === '' ? 'keine' : (wp_oc_energy_zone_valid($reported) ? 'gateway' : 'konfiguration'),
        'bezugsdatum' => null, 'monat' => null, 'grund' => 'ZEITZONE_FEHLT');
    if ($zone !== '' && $basis['empfangen_unix'] !== null) {
        try {
            $date = (new DateTimeImmutable('@' . $received))->setTimezone(new DateTimeZone($zone));
            $basis['bezugsdatum'] = $date->format('Y-m-d');
            $basis['monat'] = (int) $date->format('n');
            $basis['grund'] = 'EMPFANGSZEIT_IN_GERAETEZEITZONE';
        } catch (Throwable $e) { $basis['grund'] = 'EMPFANGSZEIT_UNGUELTIG'; }
    } elseif ($zone !== '') { $basis['grund'] = 'EMPFANGSZEIT_FEHLT'; }
    return $basis;
}

function wp_oc_energy_empty($definition, $reason)
{
    return array('bekannt_kwh' => null, 'gesamt_kwh' => null, 'vollstaendig' => false,
        'abdeckung_vollstaendig' => false, 'zeitraum_abgeschlossen' => $definition[4],
        'werte_vorhanden' => 0, 'werte_erwartet' => $definition[3], 'grund' => $reason);
}

function wp_oc_energy_period($energy, $definition)
{
    if ($definition[2] === null) { return wp_oc_energy_empty($definition, 'MONATSBEZUG_FEHLT'); }
    if (!is_array($energy)) { return wp_oc_energy_empty($definition, 'DATEN_FEHLEN'); }
    $row = wp_oc_ext_get($energy, 'reihen.' . $definition[1] . '.werte');
    if (!is_array($row)) { return wp_oc_energy_empty($definition, 'REIHE_FEHLT'); }
    if (count($row) !== array('d' => 24, 'w' => 14, 'm' => 24)[$definition[1]]) {
        return wp_oc_energy_empty($definition, 'REIHENLAENGE_UNGUELTIG');
    }
    $result = wp_oc_energy_empty($definition, 'KEINE_WERTE');
    $sum = 0.0;
    foreach (array_slice($row, $definition[2], $definition[3]) as $value) {
        if ($value === null) { continue; }
        $sum += $value; $result['werte_vorhanden']++;
    }
    if (!is_finite($sum)) { return wp_oc_energy_empty($definition, 'SUMME_UNGUELTIG'); }
    if (!$result['werte_vorhanden']) { return $result; }
    $result['bekannt_kwh'] = round($sum, 6);
    $result['abdeckung_vollstaendig'] = $result['werte_vorhanden'] === $result['werte_erwartet'];
    // A populated current-month cell is still a running month, never a final monthly total.
    $result['vollstaendig'] = $result['abdeckung_vollstaendig'] && $definition[4];
    $result['gesamt_kwh'] = $result['vollstaendig'] ? $result['bekannt_kwh'] : null;
    $result['grund'] = $result['vollstaendig'] ? 'VOLLSTAENDIG' : ($definition[4] ? 'LUECKEN' : 'LAUFEND');
    return $result;
}

function wp_oc_energy_summary($raw, $id, $received, $configuredZone = '')
{
    $device = wp_oc_ext_device($raw, $id);
    $basis = wp_oc_energy_basis($device, $received, $configuredZone);
    $definitions = wp_oc_energy_periods($basis['monat']);
    $heat = wp_oc_ext_point($device, 'climateControl', '1');
    $water = wp_oc_ext_point($device, 'domesticHotWaterTank', '2');
    $categories = array();
    foreach (array('heizung' => array($heat, 'heating'), 'kuehlung' => array($heat, 'cooling'),
        'warmwasser' => array($water, 'heating')) as $name => $source) {
        $rawEnergy = wp_oc_ext_get($source[0], 'consumptionData.value.electrical.' . $source[1]);
        $energy = wp_oc_ext_get($source[0], 'consumptionData.value.electrical.unit') === 'kWh' ? wp_oc_ext_energy($rawEnergy) : null;
        $categories[$name] = array();
        foreach ($definitions as $period => $definition) {
            $categories[$name][$period] = wp_oc_energy_period($energy, $definition);
        }
    }
    // Sum only the three distinct electrical uses, always at the SAME temporal resolution.
    $categories['gesamt'] = array();
    foreach ($definitions as $period => $definition) {
        $total = wp_oc_energy_empty($definition, 'BEREICHE_FEHLEN');
        $total['werte_erwartet'] *= 3;
        $sum = 0.0; $present = 0; $covered = true;
        foreach (array('heizung', 'kuehlung', 'warmwasser') as $name) {
            $part = $categories[$name][$period];
            $total['werte_vorhanden'] += $part['werte_vorhanden'];
            if ($part['bekannt_kwh'] !== null) { $sum += $part['bekannt_kwh']; $present++; }
            $covered = $covered && $part['abdeckung_vollstaendig'];
        }
        if ($present === 3 && is_finite($sum)) {
            $total['bekannt_kwh'] = round($sum, 6);
            $total['abdeckung_vollstaendig'] = $covered;
            $total['vollstaendig'] = $covered && $definition[4];
            $total['gesamt_kwh'] = $total['vollstaendig'] ? $total['bekannt_kwh'] : null;
            $total['grund'] = $total['vollstaendig'] ? 'VOLLSTAENDIG' : ($definition[4] ? 'LUECKEN' : 'LAUFEND');
        }
        $categories['gesamt'][$period] = $total;
    }
    return array('einheit' => 'kWh', 'zeitbasis' => $basis, 'bereiche' => $categories);
}

function wp_oc_energy_topics(array $summary)
{
    $out = array('zusatz/energie/zeitbasis' => wp_oc_ext_payload($summary['zeitbasis'], 'json'));
    foreach ($summary['bereiche'] as $category => $periods) {
        $prefix = 'zusatz/energie/' . $category;
        $out[$prefix . '/auswertung'] = wp_oc_ext_payload($periods, 'json');
        foreach ($periods as $period => $data) {
            foreach (array('bekannt_kwh', 'gesamt_kwh') as $metric) {
                $out[$prefix . '/' . $period . '/' . $metric] = wp_oc_ext_payload($data[$metric], 'number');
            }
            $out[$prefix . '/' . $period . '/vollstaendig'] = $data['vollstaendig'] ? '1' : '0';
        }
    }
    return $out;
}

function wp_oc_energy_label($data)
{
    if ($data['bekannt_kwh'] === null) {
        return $data['grund'] === 'MONATSBEZUG_FEHLT' ? 'Zeitzone / Empfangszeit fehlt' : 'keine auswertbaren Daten';
    }
    $amount = number_format($data['bekannt_kwh'], 3, ',', '.') . ' kWh';
    if ($data['vollstaendig']) { return $amount . ' · vollständig'; }
    return $amount . ' · ' . ($data['zeitraum_abgeschlossen'] ? 'lückenhaft' : 'bisher, laufend')
        . ' (' . $data['werte_vorhanden'] . '/' . $data['werte_erwartet'] . ' Werte)';
}
