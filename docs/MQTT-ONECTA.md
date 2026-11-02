# MQTT-Datenpunktliste Onecta 0.9.41

Version 0.9.41 ergänzt keine Topics. Neue Ablehnungsgründe und API-Pausen: [ONECTA-0.9.41.md](ONECTA-0.9.41.md).

Alle folgenden Namen beginnen mit `<präfix>/zusatz/` (Vorgabe `waermepumpe/zusatz/`).
Alle Zusatztopics sind **nicht retained**. Boolean: `1`/`0`; Zahl: Dezimalpunkt;
Text: native API-Texte; JSON: kompakte Struktur; fehlend/ungültig: `-`.
Gemeinsam aktivierbar im MQTT-Reiter; keine zusätzlichen Cloud-Anfragen.

| Suffix | Typ | Quelle | JSON-Pfad relativ zur Quelle |
|---|---|---|---|
| `heizmodus` | text | heizung | `operationMode.value` |
| `regelart` | text | heizung | `controlMode.value` |
| `sollwertmodus` | text | heizung | `setpointMode.value` |
| `heiz_offset` | number | heizung | `temperatureControl.value.operationModes.heating.setpoints.leavingWaterOffset.value` |
| `kuehl_soll` | number | heizung | `temperatureControl.value.operationModes.cooling.setpoints.roomTemperature.value` |
| `kuehl_vlsoll` | number | heizung | `temperatureControl.value.operationModes.cooling.setpoints.leavingWaterTemperature.value` |
| `ww_ein` | bool | warmwasser | `onOffMode.value` |
| `ww_modus` | text | warmwasser | `heatupMode.value` |
| `ww_boost_aktiv` | bool | warmwasser | `isPowerfulModeActive.value` |
| `ww_zeitprogramm` | bool | warmwasser | `schedule.value.modes.heating.enabled.value` |
| `ww_naechste_aktion` | json | warmwasser | `schedule.value.nextAction` |
| `ww_naechste_startzeit` | text | warmwasser | `schedule.value.nextAction.startTime` |
| `ww_naechste_aktion_wert` | text | warmwasser | `schedule.value.nextAction.domesticHotWaterTemperature` |
| `ww_naechste_wochentag` | text | warmwasser | `schedule.value.nextAction.actionPeriod` |
| `ww_naechste_betriebsart` | text | warmwasser | `schedule.value.nextAction.operationMode` |
| `cloud_online` | bool | geraet | `isCloudConnectionUp.value` |
| `fehler_heizung` | bool | heizung | `isInErrorState.value` |
| `warnung_heizung` | bool | heizung | `isInWarningState.value` |
| `notbetrieb` | bool | heizung | `isInEmergencyState.value` |
| `fehler_ww` | bool | warmwasser | `isInErrorState.value` |
| `warnung_ww` | bool | warmwasser | `isInWarningState.value` |
| `diagnose/geraetemodell` | text | geraet | `deviceModel` |
| `diagnose/gateway_modell` | text | gateway | `modelInfo.value` |
| `diagnose/gateway_firmware` | text | gateway | `firmwareVersion.value` |
| `diagnose/innengeraet_modell` | text | innen | `modelInfo.value` |
| `diagnose/innengeraet_software` | text | innen | `softwareVersion.value` |
| `diagnose/aussengeraet_software` | text | aussen | `softwareVersion.value` |
| `diagnose/bedienung_software` | text | bedienung | `softwareVersion.value` |
| `energie/heizung` | energy | heizung | `consumptionData.value.electrical.heating` |
| `energie/kuehlung` | energy | heizung | `consumptionData.value.electrical.cooling` |
| `energie/warmwasser` | energy | warmwasser | `consumptionData.value.electrical.heating` |

| Status-Suffix | Bedeutung |
|---|---|
| `gueltig` | `1`: gültiger Abruf dieses Gateways; einzelne Datenpunkte dürfen fehlen. `0`: Fehlabruf, keine alten Messwerte gesendet. |
| `zeit` | Unixzeit in Sekunden; nur bei erfolgreichem Abruf. Empfangs-Timeout zusätzlich auswerten. |

Quellen: geraet = ausgewähltes Gateway-Objekt; heizung = managementPointType `climateControl`
(embeddedId `1` in der Fixture); warmwasser = `domesticHotWaterTank` (`2`); gateway = `gateway` (`0`);
innen = `indoorUnitHydro` (`3`); aussen = `outdoorUnit` (`4`); bedienung = `userInterface` (`5`).
Pfade für Management Points beginnen vollständig mit `managementPoints[managementPointType=TYPE].`.
Bei fehlender Typangabe sind die Fixture-IDs sowie alte stringbasierte IDs Rückfallkandidaten.

`ww_naechste_aktion` enthält die vorhandene API-Struktur; die bekannten Teile sind zusätzlich
als einzelne Topics verfügbar. Keine Umrechnung des Wochentags in ein angenommenes Kalenderdatum.
`energy`-Payloads sind JSON mit `einheit`, `zeitzuordnung`, `reihenfolge`, `reihen.d/w/m.werte` und
`gueltige_werte`. Keine Momentanleistung und keine Kalenderzuordnung; Details in [ONECTA.md](ONECTA.md).

Die vorhandenen Haupttopics AUSSEN, VORLAUF, RAUM, SOLL, WW, WWSOLL, WWZWANG und EIN bleiben erhalten.
Das bestehende RUECKLAUF-Topic erhält ohne gültige individuelle Zuordnung den regulären Fehlwert,
keine kopierte Vorlauftemperatur. Die vorhandenen Haupttopics behalten ihre bisherige Retain-Regel.

Neue Befehle liegen unter `<präfix>/befehl/heiz_offset`, `/raum_soll`, `/heizmodus`;
Quittung jeweils `/quittung`. Vorhandene Befehle `/sgready` und `/ww_boost` bleiben erhalten.
Wertebereiche, Freigaben, Fehlergründe und Loxone-Beispiele: [ONECTA.md](ONECTA.md).
