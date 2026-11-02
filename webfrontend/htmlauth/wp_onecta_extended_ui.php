<?php
// Included inside the authenticated MQTT tab. All response values are escaped.
if (!isset($wp_cfg) || !function_exists('wp_fmt')) { return; }
$wp_oc_stand = wp_stand();
$wp_oc_values = wp_oc_ext_map(isset($wp_oc_stand['roh']) ? $wp_oc_stand['roh'] : null, $wp_cfg['geraet']);
$wp_oc_device = wp_oc_ext_device(isset($wp_oc_stand['roh']) ? $wp_oc_stand['roh'] : null, $wp_cfg['geraet']);
$wp_oc_point = wp_oc_ext_point($wp_oc_device, 'climateControl', '1');
$wp_oc_mode = wp_oc_ext_get($wp_oc_point, 'operationMode.value');
?>
<h2>Daikin Onecta: zusätzliche Daten und Steuerung</h2>
<form action="index.php" method="post">
<?= wp_fmt() ?>
<input data-role="none" type="hidden" name="save_onecta_extended" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<?php foreach (wp_oc_ext_options() as $wp_oc_key => $wp_oc_label) { ?>
<div class="sm-feld"><label style="font-weight:400;"><input data-role="none" type="checkbox"
 name="<?= wp_e($wp_oc_key) ?>" value="1"<?= !empty($wp_cfg[$wp_oc_key]) ? ' checked' : '' ?>>
 <?= wp_e($wp_oc_label) ?></label></div>
<?php } ?>
<div class="sm-hilfe">Alle Erweiterungen sind ab Werk aus. Steuerung benötigt zusätzlich beide MQTT-Befehlsfreigaben oben.
Der Gerätebereich, Schrittweite und settable=true werden vor jedem neuen Befehl geprüft.
Ein Schreibversuch pro Minute für alle neuen Befehle zusammen; nach erfolgreichem Schreiben ist ein regulärer Abruf erforderlich.
SG-Ready-Anhebung oder -Sperre hat Vorrang. Warmwasser-Sollwert und Zeitplan werden nicht geschrieben.</div>
<div class="sm-knopfreihe"><button data-role="none" class="sm-btn sm-b-aktion" type="submit">Daikin-Erweiterung speichern</button></div>
</form>
<h3>Gerätebereiche aus dem gespeicherten Abruf</h3>
<table class="sm-tbl"><tr><th>Befehl</th><th>API-Pfad / Modus</th><th>Minimum / Maximum / Schritt</th><th>API schreibbar</th></tr>
<?php foreach (array('heiz_offset' => array('heating', 'leavingWaterOffset'),
    'raum_soll' => array(in_array($wp_oc_mode, array('heating', 'cooling', 'auto'), true) ? $wp_oc_mode : 'unbekannt', 'roomTemperature')) as $wp_oc_cmd => $wp_oc_target) {
    $wp_oc_path = 'temperatureControl.value.operationModes.' . $wp_oc_target[0] . '.setpoints.' . $wp_oc_target[1];
    $wp_oc_meta = wp_oc_ext_get($wp_oc_point, $wp_oc_path);
?>
<tr><td><?= wp_e($wp_oc_cmd) ?></td><td class="sm-mono"><?= wp_e($wp_oc_path) ?></td><td>
<?php foreach (array('minValue', 'maxValue', 'stepValue') as $wp_oc_bound) { ?>
<?= wp_e(wp_oc_ext_payload(wp_oc_ext_get($wp_oc_meta, $wp_oc_bound), 'number')) ?>
<?php if ($wp_oc_bound !== 'stepValue') { echo ' / '; } } ?></td>
<td><?= wp_oc_ext_get($wp_oc_meta, 'settable') === true && wp_oc_ext_get($wp_oc_point, 'temperatureControl.settable') === true ? 'ja' : 'nein / unbekannt' ?></td></tr>
<?php } ?>
<tr><td>heizmodus</td><td>operationMode</td><td><?= wp_e(wp_oc_ext_payload(wp_oc_ext_get($wp_oc_point, 'operationMode.values'), 'json')) ?></td>
<td><?= wp_oc_ext_get($wp_oc_point, 'operationMode.settable') === true ? 'ja' : 'nein / unbekannt' ?></td></tr></table>
<p>Neue Befehle: <code><?= wp_e($wp_cfg['mqtt_topic']) ?>/befehl/heiz_offset</code>,
<code><?= wp_e($wp_cfg['mqtt_topic']) ?>/befehl/raum_soll</code>,
<code><?= wp_e($wp_cfg['mqtt_topic']) ?>/befehl/heizmodus</code>.
Quittung jeweils unter <code>/quittung</code>, ohne Retain. Die Loxone-Ausgangsvorlage enthält diese Befehle.</p>
<p>Letzter erfolgreicher Zusatzdatenabruf:
<?= !empty($wp_oc_stand['oc_zusatz_zeit']) ? wp_e(date('d.m.Y H:i:s', $wp_oc_stand['oc_zusatz_zeit'])) : 'noch keiner / nach Schreibbefehl erneut abzurufen' ?>.
Die Tabelle zeigt den gespeicherten Stand, keine Live-Abfrage. Fehlend / nicht unterstützt: <code>-</code>.
Alle Zusatztopics gehen ohne Retain hinaus; in Loxone <code>zusatz/gueltig</code>, <code>zusatz/zeit</code> und einen Empfangs-Timeout auswerten.</p>
<div style="overflow-x:auto"><table class="sm-tbl">
<tr><th>MQTT-Topic</th><th>Typ</th><th>Quelle / JSON-Pfad</th><th>Gespeicherter Wert</th></tr>
<?php foreach (wp_oc_ext_fields() as $wp_oc_name => $wp_oc_f) { ?>
<tr><td class="sm-mono"><?= wp_e($wp_cfg['mqtt_topic'] . '/zusatz/' . $wp_oc_name) ?></td>
<td><?= wp_e($wp_oc_f[2]) ?></td><td class="sm-mono"><?= wp_e($wp_oc_f[0] . ': ' . $wp_oc_f[1]) ?></td>
<td class="sm-mono" style="max-width:28em;overflow-wrap:anywhere;white-space:normal"><?= wp_e($wp_oc_values['zusatz/' . $wp_oc_name]) ?></td></tr>
<?php } ?>
</table></div>
<div class="sm-hilfe">Heizung = managementPointType climateControl (embeddedId 1 in Ihrer Response), Warmwasser = domesticHotWaterTank (2),
Gateway = gateway (0), innen = indoorUnitHydro (3), außen = outdoorUnit (4), Bedienung = userInterface (5).
Bei mehreren gleichartigen Kreisen ist die Auswahl uneindeutig: Zusatzwerte fehlen und neue Schreibbefehle werden abgelehnt.
Energie: kWh-Rohreihen d/w/m mit unveränderter Reihenfolge und null-Lücken; keine Momentanleistung und keine behaupteten Kalenderdaten.</div>
