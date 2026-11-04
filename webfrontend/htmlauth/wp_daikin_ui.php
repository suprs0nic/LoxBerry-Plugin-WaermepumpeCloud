<?php
if (!isset($wp_cfg) || !function_exists('wp_fmt')) { return; }
$wp_oc_stand = wp_stand();
$wp_oc_device = wp_oc_ext_device($wp_oc_stand['roh'] ?? null, $wp_cfg['geraet']);
try { $wp_daikin_rows = wp_daikin_catalog($wp_oc_stand['roh'] ?? null, $wp_cfg['geraet']); }
catch (Throwable $e) { $wp_daikin_rows = array(); }
$wp_oc_energy = wp_oc_energy_summary($wp_oc_stand['roh'] ?? null, $wp_cfg['geraet'],
    $wp_oc_stand['oc_energie_zeit'] ?? 0, $wp_cfg['oc_energie_zeitzone'] ?? '');
?>
<h2>Daikin – Heizung, Warmwasser und Diagnose</h2>
<p>Alle Daikin-Daten und Befehle stehen unter <code><?= wp_e($wp_cfg['mqtt_topic']) ?>/Daikin/</code>.
Die folgenden Tabellen zeigen den gespeicherten Abruf. Das Öffnen dieser Seite fragt die Cloud nicht ab.</p>
<div class="sm-hinweis"><?= wp_e(wp_oc_limits_text()) ?></div>
<form action="index.php" method="post">
<?= wp_fmt() ?>
<input data-role="none" type="hidden" name="save_onecta_extended" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-daikin">
<?php foreach (wp_oc_ext_options() as $wp_d_key => $wp_d_label) { ?>
<div class="sm-feld"><label style="font-weight:400"><input data-role="none" type="checkbox"
 name="<?= wp_e($wp_d_key) ?>" value="1"<?= !empty($wp_cfg[$wp_d_key]) ? ' checked' : '' ?>> <?= wp_e($wp_d_label) ?></label></div>
<?php } ?>
<div class="sm-feld"><label for="oc_energie_zeitzone">Anlagenzeitzone, wenn das Gateway keine meldet</label>
<input data-role="none" type="text" id="oc_energie_zeitzone" name="oc_energie_zeitzone" maxlength="64"
 value="<?= wp_e($wp_cfg['oc_energie_zeitzone'] ?? '') ?>" placeholder="z. B. Europe/Vienna">
<div class="sm-hilfe">Eine Gateway-Zeitzone hat Vorrang. Ohne bestätigte Zeitzone bleiben Monatswerte offen.</div></div>
<p class="sm-hilfe">MQTT muss eingeschaltet sein. Für Befehle sind außerdem die beiden Befehlsfreigaben im Reiter MQTT erforderlich.
Neue Freigaben sind ab Werk aus. Schreibbarkeit, Gerätebereich, Grenzen und Schrittweite werden vor dem Senden geprüft.
Alle Daikin-Befehle teilen sich eine Sperrminute. Nach einer erfolgreichen Änderung ist ein regulärer Abruf nötig.</p>
<button data-role="none" class="sm-btn sm-b-aktion" type="submit">Daikin-Einstellungen speichern</button>
</form>
<h3>Loxone-Vorlagen herunterladen</h3>
<p>Eine virtuelle HTTP-Eingangsvorlage wie <code>VI_waermepumpe.xml</code>, mit den Daikin-Messwerten
und Energieperioden. Loxone liest damit den gespeicherten Stand; es entstehen keine zusätzlichen Cloud-Aufrufe.
Nach dem ersten erfolgreichen Abruf exportieren. Texte und JSON-Listen stehen im Feldkatalog und über MQTT.</p>
<form action="index.php" method="post">
<?= wp_fmt() ?><input data-role="none" type="hidden" name="activetab" value="tab-daikin">
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="xml_daikin">VI_Daikin.xml – Messwerte und Energie</button>
<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="xml_daikin_meta">VI_Daikin_Metadaten.xml – zusätzlich Grenzen und Freigaben</button>
</div></form>
<p class="sm-hilfe">Die Vorlage enthält die lokale Adresse und das vorhandene Zugriffstoken.
<code>DAIKIN_OK</code> und <code>DAIKIN_ALTER</code> mit auswerten. Fehlende oder veraltete Zahlen sind
<code>-999999999</code>. Ein erneuter Import erzeugt weitere Eingänge.</p>
<h3>Elektrischer Energieverbrauch</h3>
<p>Bekannter Verbrauch in kWh. „Vollständig“ bedeutet: abgeschlossener Zeitraum mit allen erwarteten Werten.
Laufende Zeiträume und Datenlücken sind gekennzeichnet. Cloud-Werte können mehrere Stunden verzögert sein.</p>
<p class="sm-hilfe">Empfangen: <?= !empty($wp_oc_stand['oc_energie_zeit']) ? wp_e(gmdate('d.m.Y H:i:s', $wp_oc_stand['oc_energie_zeit']) . ' UTC') : 'noch kein gespeicherter Energieabruf' ?>.
Bezugsdatum: <?= wp_e($wp_oc_energy['zeitbasis']['bezugsdatum'] ?: 'nur relative Zeiträume') ?>.
Zeitzone: <?= wp_e($wp_oc_energy['zeitbasis']['zeitzone'] ?: 'nicht bestätigt') ?>.
„Heute“ und „gestern“ beziehen sich auf diesen Abruf.
<?php if (empty($wp_oc_stand['ok'])) { ?>Der letzte Abruf war nicht erfolgreich; die Anzeige ist kein aktueller Verbrauchsstand.<?php } ?></p>
<div style="overflow-x:auto"><table class="sm-tbl"><tr><th>Zeitraum</th><th>Heizung</th><th>Kühlung</th><th>Warmwasser</th><th>Summe dieser Bereiche</th></tr>
<?php foreach (wp_oc_energy_periods() as $wp_d_period => $wp_d_definition) { ?>
<tr><td><?= wp_e($wp_d_definition[0]) ?></td>
<?php foreach (array('heizung', 'kuehlung', 'warmwasser', 'gesamt') as $wp_d_category) { ?>
<td><?= wp_e(wp_oc_energy_label($wp_oc_energy['bereiche'][$wp_d_category][$wp_d_period])) ?></td>
<?php } ?></tr><?php } ?></table></div>
<p class="sm-hilfe">Die Auswertung steht unter <code>Daikin/energie/</code>. Rohreihen stehen dort ebenfalls, unter
<code>heizung/consumptionData/</code> und <code>ww/consumptionData/</code>.
Periodensummen bei wiederholtem MQTT-Empfang nicht addieren.</p>
<h3>Befehle</h3>
<p>Warmwasser sofort aufheizen: <code><?= wp_e($wp_cfg['mqtt_topic']) ?>/Daikin/befehl/ww/boost</code>
mit <code>1</code> einschalten, mit <code>0</code> ausschalten. Boost kann den elektrischen Zusatzheizer nutzen.
Der Status steht getrennt unter <code>Daikin/ww/isPowerfulModeActive/value</code>.</p>
<p>Die Liste enthält auch dokumentierte Funktionen, die diese Anlage derzeit nicht anbietet.
Fehlende oder gesperrte Fähigkeiten werden beim Befehl abgelehnt.
Der WW-Sollwert wird nur freigegeben, wenn die aktuelle Antwort ihn als schreibbar meldet.
Nach einer Änderung des Aufheizmodus zuerst regulär abrufen; ein anderer Modusname allein ist keine Schreibfreigabe.</p>
<table class="sm-tbl"><tr><th>Topic nach <?= wp_e($wp_cfg['mqtt_topic']) ?>/Daikin/befehl/</th><th>Nutzlast</th></tr>
<?php foreach (wp_daikin_commands() as $wp_d_name => $wp_d_command) { ?>
<tr><td class="sm-mono"><?= wp_e($wp_d_name) ?></td><td><?= wp_e($wp_d_command['art'] === 'schalter' ? '0 / 1'
    : ($wp_d_command['art'] === 'auswahl' ? implode(', ', $wp_d_command['werte']) : ($wp_d_command['art'] === 'text' ? 'JSON gemäß Anleitung; Firmware: angebotene ID' : 'Zahl gemäß Gerätegrenzen'))) ?></td></tr>
<?php } ?></table>
<p class="sm-hilfe">Quittung jeweils am Befehlstopic mit <code>/quittung</code>. Alle Befehle ohne Retain senden.
Bei mehreren gleichartigen Management Points ist eine eindeutige Auswahl für Schreibbefehle erforderlich.
Die allgemeinen Altbefehle bleiben für bestehende Loxone-Verknüpfungen erreichbar.</p>
<h3>Dokumentierte Fähigkeiten und Geräteantwort</h3>
<details><summary>Vorhandene und fehlende Merkmale anzeigen</summary>
<table class="sm-tbl"><tr><th>Bereich</th><th>API-Merkmal</th><th>Antwort</th><th>Schreibbar laut Antwort</th></tr>
<?php foreach (wp_daikin_features() as $wp_d_type => $wp_d_features) {
    $wp_d_point = wp_oc_ext_point($wp_oc_device, $wp_d_type, (string) array_search($wp_d_type,
        array('gateway', 'climateControl', 'domesticHotWaterTank', 'indoorUnitHydro', 'outdoorUnit', 'userInterface'), true));
    foreach ($wp_d_features as $wp_d_feature) { ?>
<tr><td><?= wp_e(wp_daikin_groups()[$wp_d_type] ?? $wp_d_type) ?></td><td><?= wp_e($wp_d_feature) ?></td>
<td><?= array_key_exists($wp_d_feature, $wp_d_point) ? 'vorhanden' : 'nicht gemeldet' ?></td>
<td><?= wp_oc_ext_get($wp_d_point, $wp_d_feature . '.settable') === true ? 'ja; untergeordnete Freigaben beachten' : 'nein / nicht gemeldet' ?></td></tr>
<?php } } ?></table></details>
<h3>Alle Felder und Metadaten</h3>
<p><?= count($wp_daikin_rows) ?> Datenpunkte aus der gespeicherten Geräteantwort.
Die API-Namen und die Struktur mit <code>value</code>, <code>settable</code>, <code>values</code> und Grenzen bleiben erhalten.
„Response-Erweiterung“ bedeutet: geliefert, im zugehörigen Featurekapitel nicht ausdrücklich beschrieben; daraus entsteht keine Schreibfreigabe.</p>
<p class="sm-hilfe">Auch Gerätekennungen und Netzwerkdaten werden übertragen, wenn sie die Antwort enthält.
Alle Daikin-Topics gehen ohne Retain hinaus. <code>Daikin/status/gueltig</code>,
<code>Daikin/status/zeit</code> und einen Empfangs-Timeout auswerten; fehlende Werte sind <code>-</code>.</p>
<details><summary>Vollständigen Feldkatalog öffnen</summary><div style="overflow-x:auto"><table class="sm-tbl">
<tr><th>Topic nach <?= wp_e($wp_cfg['mqtt_topic']) ?>/</th><th>Typ</th><th>API-Pfad</th><th>Abgleich</th><th>Wert</th></tr>
<?php foreach ($wp_daikin_rows as $wp_d_topic => $wp_d_row) { ?>
<tr><td class="sm-mono"><?= wp_e($wp_d_topic) ?></td><td><?= wp_e($wp_d_row['typ']) ?></td>
<td class="sm-mono"><?= wp_e($wp_d_row['quelle']) ?></td><td><?= $wp_d_row['dokumentiert'] ? 'Doku-Merkmal' : 'Response-Erweiterung' ?></td>
<td style="max-width:28em;overflow-wrap:anywhere"><?= wp_e($wp_d_row['payload']) ?></td></tr>
<?php } ?></table></div></details>
