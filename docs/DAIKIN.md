# Daikin-Bereich ab Version 0.9.43

Stand: 4. November 2026. Abgleich mit den erneut gelesenen offiziellen Featurekapiteln
und der gespeicherten Altherma-Antwort. Keine Cloud-Schreibtests oder Installation
auf dem LoxBerry ausgeführt.

## Aktivierung und Umstellung

1. `WaermepumpeCloud-0.9.43-onecta-test.zip` als manuelles Pluginupdate installieren.
2. Im neuen Reiter **Daikin** die Datenveröffentlichung einschalten. Bestehende
   Freigaben aus 0.9.40–0.9.42 bleiben erhalten, neue Freigaben sind ausgeschaltet.
3. Im Reiter **MQTT** MQTT einschalten und das gewünschte Präfix beibehalten.
   Für Steuerung zusätzlich beide MQTT-Befehlsfreigaben einschalten und im Reiter
   Daikin die benötigte Funktion freigeben.
4. Für Datierung der Monatswerte die Anlagenzeitzone bestätigen, beispielsweise
   `Europe/Vienna`, falls das Gateway keine liefert. Regulären Abruf abwarten.
5. Loxone-Eingänge auf die neuen Topics umstellen. Es werden keine `zusatz/`-Topics
   mehr veröffentlicht. Die allgemeinen Hauptmesswerte des Plugins bleiben erhalten.

## Gemeinsamer Topic-Baum

Basis: `<präfix>/Daikin/`, beispielsweise `waermepumpe/Daikin/`.
Die Groß-/Kleinschreibung ist bei MQTT relevant.

| Teilbaum | Inhalt und Beispiele |
|---|---|
| `heizung/` | Heizkreis, Sollwerte, Sensoren, Zeitpläne und Zustände |
| `ww/` | Warmwasserspeicher, Boost, Sensoren und Zeitpläne |
| `energie/` | elektrische Rohreihen und Periodenauswertung |
| `diagnose/gateway/` | Gateway, Netzwerk, Zeitzone und Firmware |
| `diagnose/innengeraet/` | Altherma-Innengerät |
| `diagnose/aussengeraet/` | Außengerät |
| `diagnose/bedienung/` | MMI-Bedienoberfläche |
| `geraet/` | Geräte-ID, Gerätemodell, Cloudstatus und historische Felder |
| `faehigkeiten/` | `1`: Merkmal gemeldet; `0`: in diesem eindeutig ausgewählten Punkt nicht gemeldet |
| `status/` | Gültigkeit und eigener Empfangszeitpunkt |
| `befehl/` | Befehle und deren Quittungen |

API-Namen bleiben erhalten. Beispiele:

```text
Daikin/heizung/onOffMode/value
Daikin/heizung/operationMode/value
Daikin/heizung/sensoryData/value/outdoorTemperature/value
Daikin/heizung/temperatureControl/value/operationModes/heating/setpoints/roomTemperature/value
Daikin/ww/sensoryData/value/tankTemperature/value
Daikin/ww/powerfulMode/value
Daikin/ww/isPowerfulModeActive/value
Daikin/ww/temperatureControl/value/operationModes/heating/setpoints/domesticHotWaterTemperature/settable
Daikin/diagnose/gateway/modelInfo/value
Daikin/energie/ww/consumptionData/value/electrical/heating/d
Daikin/energie/heizung/heute/bekannt_kwh
```

Die Quelle jedes Feldes ist im Daikin-Reiter sichtbar. Der maschinenlesbare
[Feldabgleich](DAIKIN-FELDER.json) enthält **279 Katalogtopics** und deckt alle
**503 Einzelwerte einschließlich Metadaten und Arrayeinträgen** der ursprünglichen
Antwort ab. Er enthält ausschließlich Pfade und Typen, keine individuellen Werte.
Zusätzlich werden Merkmalsverfügbarkeit, 101 Energietopics und der Abrufstatus gesendet.

Die Werte werden aus derselben regulären Geräteantwort gelesen. Keine zusätzlichen
Cloud-Abfragen. Die größere Zahl von MQTT-Nachrichten verlängert die lokale Ausgabe
um einige Sekunden; die Empfangszeit ist bereits vor der Übertragung gespeichert.

## Datentypen und Metadaten

- API-Texte wie `on`, `off` und `heating` bleiben Texte.
- Booleans werden für MQTT als `1`/`0` gesendet; der Katalog benennt den Typ `bool`.
- Zahlen verwenden einen Dezimalpunkt. `null` ergibt `-`, niemals eine erfundene Null.
- Listen bleiben JSON-Listen, einschließlich ihrer Reihenfolge und `null`-Lücken.
- Leere Texte werden als JSON-String `""` übertragen. Mehrzeilige oder lange Texte
  werden ebenfalls reversibel als JSON-String kodiert, damit sie keine Gatewayzeile
  einschleusen können. Leere, in PHP eingelesene Objekte erscheinen im Lesekatalog als `[]`.
- Alle `settable`, `values`, Einheiten, Grenzen, Schritte, Plan-IDs und Aktionsmetadaten
  der Response sind enthalten. Ihre Ausgabe ist keine pauschale Schreibfreigabe.

Alle neuen Topics sind **nicht retained**. `Daikin/status/gueltig=1` bedeutet:
Antwort für das ausgewählte Gateway erfolgreich ausgewertet. Einzelne Merkmale können
weiter fehlen. Bei Fehlabrufen wird nur `Daikin/status/gueltig=0` neu gesendet.
Zusätzlich `Daikin/status/zeit` und einen Empfangs-Timeout je benötigtem Eingang auswerten.
Fehlende Merkmale erzeugen keine erfundenen Messwerte; ihre Verfügbarkeit steht unter
`Daikin/faehigkeiten/`. Nach Gerätewechseln alte Loxone-Anzeigen nicht weiterverwenden.

Bei mehreren gleichartigen Management Points erhält jeder einen eigenen Unterbaum,
z. B. `Daikin/heizung/punkt_1/` und `Daikin/heizung/punkt_second/`.
Schreibbefehle wählen bei Mehrdeutigkeit keinen zufälligen Kreis aus.

Auch Kennungen, individuelle Namen, Seriennummern und Netzwerkdaten werden auf dem
eigenen MQTT-Broker veröffentlicht, soweit sie die Response enthält. Die eingecheckte
Testantwort ersetzt solche Werte durch erfundene Daten. Die Originalantwort bleibt lokal.

## Loxone-HTTP-Eingangsvorlagen

Im Reiter **Daikin** lassen sich zwei Dateien herunterladen, im gleichen
`VirtualInHttp`-Format wie die vorhandene `VI_waermepumpe.xml`:

- **VI_Daikin.xml:** 127 Eingänge für die gespeicherten Messwerte, booleschen
  Zustände, Energieperioden sowie OK, Datenalter und Empfangszeit der vorliegenden Altherma.
- **VI_Daikin_Metadaten.xml:** 252 Eingänge einschließlich numerischer/boolean
  Metadaten wie Schreibbarkeit, Grenzen und Schritte.

Die Anzahl passt sich der aktuellen Geräteantwort an. Nach dem ersten erfolgreichen
Abruf exportieren. Die vorhandene allgemeine VI-Vorlage bleibt ebenfalls verfügbar.

Die Adresse enthält das lokale Zugriffstoken und `aktion=daikin`. Der Endpunkt liest
ausschließlich den gespeicherten Stand, löst keinen Cloudabruf aus und führt keine
Befehle aus. Er verwendet denselben Tokenschutz wie der bestehende Statusendpunkt.
Ohne gespeicherte Daikin-Daten antwortet er mit HTTP 503.

Die Vorlagen enthalten Zahlen und boolesche Werte. API-Texte wie `on`/`off`, Namen,
Fehlertexte und JSON-Listen bleiben unverändert über MQTT und im Feldkatalog verfügbar;
sie werden nicht als Zahlen ausgegeben. Boolesche Messwerte werden als 0/1 geführt.

`DAIKIN_OK` und `DAIKIN_ALTER` immer mit auswerten. Ein fehlender oder veralteter
Messwert wird als **-999999999** ausgegeben, auch als Anfangswert des XML-Eingangs.
Echte Nullwerte bleiben null. Ein einmal bekannter, später fehlender Sensor wird
explizit als fehlend gemeldet. Neu hinzugekommene Eingänge benötigen einen erneuten
Export; ein wiederholter XML-Import erzeugt weitere Objekte in Loxone Config.

Die Erkennungsmuster sind an stabile Feldschlüssel gebunden, der Kommentar jedes
Eingangs nennt den vollständigen Daikin-Topic-Pfad. Normale Messwertbezeichnungen
und Energieperioden lassen sich so zuordnen. Das installierte Plugin setzt seine
lokale Adresse und das aktuelle Token ein; für den lokalen Export aus der gelieferten
VI-Datei wurden Adresse, Token und Pollingintervall übernommen.

Beide XML-Dateien sind auf Syntax und passende Erkennungsmuster geprüft. Der echte
Import in Loxone Config wurde noch nicht ausgeführt.

## Befehle und Schreibbarkeit

Basis: `<präfix>/Daikin/befehl/`. Quittung jeweils mit `/quittung` am selben Topic.
Ohne Retain senden. Ein erfolgreich quittierter Cloudaufruf ist ein angenommener
Steuerwunsch; den tatsächlich erreichten Anlagenzustand beim nächsten Abruf prüfen.

| Befehl | Nutzlast | Gerätebezug |
|---|---|---|
| `heizung/ein` | `1` / `0` | onOffMode an/aus |
| `ww/ein` | `1` / `0` | Warmwasserbetrieb freigeben/sperren |
| `ww/boost` | `1` / `0` | powerfulMode starten/beenden |
| `ww/soll` | Dezimalzahl, laut aktuellen Metadaten | WW-Sollwert, sobald vom Gerät freigegeben |
| `heizung/heiz_offset` | ganze Zahl, laut Metadaten | bisherige Offsetfreigabe |
| `heizung/raum_soll` | Dezimalzahl, laut Metadaten | Raum-Sollwert des aktiven Modus |
| `heizung/heizmodus` | `heating`, `cooling`, `auto` | angebotene Betriebsart |
| `heizung/setzen`, `ww/setzen` | JSON, siehe unten | weitere dokumentierte Einzelmerkmale |
| `heizung/urlaub`, `ww/urlaub` | JSON | eigener holiday-mode-Endpunkt; nur wenn gemeldet |
| `heizung/zeitplan_auswahl`, `ww/zeitplan_auswahl` | JSON | Aktionsplan auswählen/einschalten |
| `heizung/zeitplan_inhalt`, `ww/zeitplan_inhalt` | JSON | Inhalte aller Aktionspläne eines Modus |
| `heizung/blockzeitplan_auswahl`, `ww/blockzeitplan_auswahl` | JSON | Blockplan auswählen/einschalten |
| `heizung/blockzeitplan_inhalt`, `ww/blockzeitplan_inhalt` | JSON | Blockpläne einschließlich Standardwerten |
| `diagnose/gateway/firmware`, `diagnose/bedienung/firmware` | exakt die angebotene Firmware-ID | ausdrücklich angefordertes Update |

Einfache Loxone-Beispiele beim Standardpräfix:

```text
publish waermepumpe/Daikin/befehl/ww/boost 1
publish waermepumpe/Daikin/befehl/ww/boost 0
publish waermepumpe/Daikin/befehl/heizung/ein 0
publish waermepumpe/Daikin/befehl/heizung/raum_soll 22.5
```

Die vorhandene Loxone-Ausgangsvorlage enthält die neuen Topics für die einfachen
Befehle. JSON-Befehle verwenden den textuellen Befehlsweg; es wird dafür kein beliebiger
voreingestellter Schreibwert in die Vorlage eingebaut.

Die alten allgemeinen Befehle `befehl/heiz_offset`, `befehl/raum_soll`,
`befehl/heizmodus`, `befehl/ww_boost` und `befehl/sgready` bleiben zur Kompatibilität
erreichbar. SG Ready ist die bestehende Plugin-Nachbildung, keine neue offizielle
Daikin-Characteristic. Auch alte Onecta-Schreibaufrufe lösen jetzt den tatsächlichen
Management Point auf und prüfen den dokumentierten Pfad und die Schreibmetadaten.

### Einzelmerkmale

An `heizung/setzen` beispielsweise:

```json
{"characteristic":"temperatureControl","path":"/operationModes/cooling/setpoints/leavingWaterTemperature","value":21}
```

`path` beginnt innerhalb von `temperatureControl.value`, enthält selbst kein `/value`.
Für ein einfaches Merkmal:

```json
{"characteristic":"operationMode","value":"cooling"}
```

Die erlaubten Wege sind fest begrenzt: onOffMode, operationMode, powerfulMode,
temperatureControl und name; bei climateControl zusätzlich fanControl,
humidityControl und airPurificationMode entsprechend ihren dokumentierten Pfaden.
Installateurmerkmale controlMode, setpointMode und heatupMode sowie das nur in der
Response vorhandene targetTemperature werden nicht als Schreibweg freigeschaltet.
Nicht vorhandene Luftfunktionen werden nicht als Altherma-Fähigkeit behandelt.

In deiner gespeicherten Antwort gilt insbesondere:

- Heizung: onOffMode, operationMode und die gemeldeten Temperatursollwerte/Offsets schreibbar.
- WW: onOffMode und powerfulMode schreibbar; Sollwert 45 °C, Blattmerkmal **nicht schreibbar**.
- WW-Zeitplan: `enabled.settable=false`; er kann über diesen Weg nicht abgeschaltet werden.
  Der Planinhalt hat eigene Metadaten und wird unabhängig davon geprüft.
- Gateway: Firmwareupdate unterstützt, aber kein Updateangebot vorhanden. Daraus entsteht
  kein startbarer Firmwarebefehl.

Die WW-Sollwertsperre ist nicht dauerhaft im Plugin hinterlegt. Eine Anlagenumstellung
von „Programm und Aufheizen“ auf „Nur Aufheizen“ könnte die Gerätefreigabe ändern.
Nach der Umstellung regulär abrufen: Sobald der aktuelle Sollwertknoten `settable=true`
meldet, akzeptiert `ww/soll` die neu gemeldeten Grenzen und Schritte. Ein Modusname
allein oder eine alte Antwort genügt nicht. Das Plugin ändert `heatupMode` nicht selbst.

### Urlaub

Einschalten benötigt bestätigte Anlagenzeitzone und gültige, nicht vergangene Daten:

```json
{"enabled":true,"startDate":"2026-12-01","endDate":"2026-12-07"}
```

Ausschalten enthält ausschließlich:

```json
{"enabled":false}
```

Ein aktiver Urlaub kann so beendet werden. Installateurmodus und blockierende Updates
verhindern weiterhin normale Steuerbefehle. In deiner Response gibt es holidayMode
am Heizkreis; beim WW wird kein separat schreibbares holidayMode-Objekt gemeldet.

### Zeitpläne

Auswahl über den jeweiligen `.../zeitplan_auswahl`-Befehl:

```json
{"mode":"heating","scheduleId":"0","enabled":true}
```

Die Plan-ID bleibt ein String, `enabled` ein Boolean. Ein unverändertes schreibgeschütztes
Feld darf im vom Endpunkt verlangten Body unverändert stehen; eine tatsächliche Änderung
erfordert dessen Freigabe. Vollständig unveränderte Auswahl verbraucht keinen API-Aufruf.

Inhaltsbefehle erwarten `{"mode":"heating","schedules":{...}}`. Unter `schedules`
müssen **alle Plan-IDs dieses Modus** mit ihren vollständigen gewünschten Inhalten stehen.
Der Wrapper entfernt `mode` vor dem Versand und sendet das dokumentierte Objekt direkt.
Es gibt kein automatisches Zusammenführen eines Teilplans mit alten Daten.

Aktionspläne: `planId → actions → Wochentag → HH:MM:SS → Aktionswerte`.
Geprüft werden Schreibschutz, erlaubte Wochentage, Aktionsanzahl, Zeitraster/-abstand,
aufeinanderfolgende gleiche Aktionen sowie Wertebereiche und Schritte der `actionTypes`.
Deren `settable=false` beschreibt Metadaten und sperrt nicht den separaten Planendpunkt.

Blockpläne: jeder Plan benötigt `defaultBlockValues` und `blocks`. Geprüft werden
Standardwerte, Blockanzahl, Mindestlänge, Zeitraster, Wertebereiche und überlappende Blöcke.
Die Altherma-Antwort enthält ausschließlich Aktionspläne; Blockplanbefehle werden dort
mit `ZEITPLAN_NICHT_UNTERSTUETZT` abgelehnt.

MQTT-Textbefehle sind auf 4000 Byte begrenzt. Größere vollständige Planinhalte werden
abgelehnt und niemals stillschweigend abgeschnitten oder als Teiländerung versendet.

### Firmware

Nur bei tatsächlichem Updateangebot und `firmwareUpdate.settable=true` wird die exakt
angebotene ID akzeptiert. Die separate Firmwarefreigabe und der bewusst gesendete
ID-Befehl sind erforderlich. Es gibt keine automatische Firmwareinstallation.
Ein blockierendes Update sperrt sonstige Befehle; ein bereits laufendes Update wird
nicht erneut gestartet. Die Anlage kann während eines Updates neu starten/offline gehen.

## Grenzen und Nachweis

Für die neuen Daikin-Befehle gelten eine gemeinsame persistente Sperrminute, die
gemeinsame Abrufsperre, aktuelle Gerätefähigkeiten, Cloudstatus, separate Freigaben,
SG-Ready-Konfliktschutz und die bestehenden API-Kontingente/Pausen. Nach erfolgreichem
Schreiben wird der Fähigkeitsstand ungültig; Energie behält sein historisches Abrufdatum.

Die vollständige Ausgabe der Response bedeutet nicht, dass jedes Feld in der Doku
einzeln beschrieben oder schreibbar ist. Das wird im Feldkatalog getrennt ausgewiesen.
Nicht alle Daikin-Gerätefamilien oder deren Planvarianten wurden an Hardware getestet.
Token-/Kontoverwaltung, Sites und Serviceinfo sind keine Heizbefehle; bestehende
Anmeldung und Geräteerkennung bleiben zuständig.

Quellen, erneut geprüft am 04.11.2026:
[Climate Control](https://developer.cloud.daikineurope.com/docs/84e709f1-9d33-47e1-a93c-7f5cb8b8f12b/supported_features/supported_features_climatecontrol),
[Warmwasser](https://developer.cloud.daikineurope.com/docs/84e709f1-9d33-47e1-a93c-7f5cb8b8f12b/supported_features/supported_features_dhw),
[Gateway](https://developer.cloud.daikineurope.com/docs/84e709f1-9d33-47e1-a93c-7f5cb8b8f12b/supported_features/supported_features_gateway),
[Innengerät](https://developer.cloud.daikineurope.com/docs/84e709f1-9d33-47e1-a93c-7f5cb8b8f12b/supported_features/supported_features_indoorunit),
[Außengerät](https://developer.cloud.daikineurope.com/docs/84e709f1-9d33-47e1-a93c-7f5cb8b8f12b/supported_features/supported_features_outdoorunit),
[Bedienung](https://developer.cloud.daikineurope.com/docs/84e709f1-9d33-47e1-a93c-7f5cb8b8f12b/supported_features/supported_features_userinterface),
[Geräteinfos](https://developer.cloud.daikineurope.com/docs/84e709f1-9d33-47e1-a93c-7f5cb8b8f12b/supported_features/supported_device_info).
