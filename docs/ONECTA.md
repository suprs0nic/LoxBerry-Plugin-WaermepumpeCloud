# Daikin Onecta – Erweiterung 0.9.41 (Testversion)

Neu in 0.9.41: gemeinsame API-Pausen und Minutenlimits, zusätzliche Zustandsprüfungen
für neue Befehle und korrigierte Geräteidentität. Details und neue Fehlergründe:
[Onecta Verbesserungen in Version 0.9.41](ONECTA-0.9.41.md).
Die am 02.11.2026 nach Anmeldung gelesene offizielle Dokumentation bestätigt inzwischen
die relative Reihenfolge der Energiearrays. Die Softwareausgabe bleibt unverändert
bei Rohreihen; die historischen Einschränkungen zu Kalenderdaten gelten weiter.

Diese Erweiterung basiert auf Version 0.9.39 des Forks. Ein Upgrade von der beim
Auftraggeber installierten 0.9.36 nutzt die unveränderten Sicherungs- und
Wiederherstellungsskripte des Projekts. Die tatsächliche Installation auf LoxBerry
muss noch geprüft werden. Es findet keine Installation auf einer produktiven Anlage statt.

## Installation, Upgrade und Aktivierung

1. Bestehende Plugin-Konfiguration und LoxBerry sichern. Für einen Rückweg zusätzlich
   die bisherige Installationsversion bereithalten. Zunächst auf einer Testinstallation prüfen.
2. Das ZIP `WaermepumpeCloud-0.9.41-onecta-test.zip` in der LoxBerry-Pluginverwaltung
   als manuelles Update installieren. Der Pluginname und Ordner bleiben `waermepumpe`.
   Das Paket ist strukturell geprüft, noch nicht durch einen echten LoxBerry-Installer gelaufen.
3. Bestehende Daikin-Einrichtung und Feldzuordnungen beibehalten. Eine neue OAuth-Anmeldung
   ist für diese Erweiterung nicht vorgesehen; Refresh und Anmeldung sind unverändert.
4. Im Reiter **MQTT → Daikin Onecta: zusätzliche Daten und Steuerung** den ersten Haken
   „Zusätzliche Daikin-Messwerte veröffentlichen“ setzen und **Daikin-Erweiterung speichern**.
   MQTT selbst muss eingeschaltet sein. Beim nächsten regulären Abruf erscheinen die Topics.
5. Im Reiter **Test** die Selbstprüfung sowie die Tabelle der gespeicherten Zusatzwerte ansehen.
   Die Anzeige löst keine zusätzliche Daikin-Abfrage aus. `-` bedeutet fehlend/nicht unterstützt.
6. Steuerung erst bei Bedarf aktivieren: zusätzlich „Befehle über MQTT annehmen“,
   „Wärmepumpe über MQTT steuern“ und die jeweilige neue Einzelfreigabe einschalten.
   Die drei neuen Einzelfreigaben und die Zusatztelemetrie sind ab Werk aus.

Alte Einstellungen werden durch neue Vorgaben ergänzt, nicht ersetzt. MQTT-Präfix,
Aktionstoken und individuelle Pfade bleiben erhalten. Es gibt keinen neuen Speicherort
für Zugangsdaten und keine Änderung des OAuth2-Ablaufs.

Die Testversion deaktiviert automatische Updates und setzt die Updateadressen in
`plugin.cfg` auf den Fork. `release.cfg` und `prerelease.cfg` bleiben als historische
0.9.39-Releasebeschreibungen unverändert; sie veröffentlichen diese Entwicklung nicht
als stabiles automatisches Update. Künftige Teststände ebenfalls manuell installieren.
Für einen Rückbau Erweiterungen abschalten, bisheriges Paket installieren und bei Bedarf
die vorherige Sicherung wiederherstellen. Ein Rückbau wurde nicht live getestet.

## Daten, Pfade und Topics

Die vollständige Liste steht in [MQTT-ONECTA.md](MQTT-ONECTA.md) und wird in der Weboberfläche
mit dem konfigurierten Präfix angezeigt. Alle neuen Topics liegen unter `<präfix>/zusatz/`.
Das vorhandene `<präfix>/#`-Abonnement deckt sie ab. Bestehende Großbuchstaben-Topics und
HTTP-Ausgaben bleiben erhalten.

Die Auswertung nutzt die Antwort des regulären Abrufs des ausgewählten Gateways. Objekt-
und Arrayantworten werden unterstützt; Listen werden nach `id`/`_id` des ausgewählten
Gateways gefiltert. Management Points werden nach `managementPointType` ausgewählt,
mit Rückfall auf die bekannten IDs bei fehlender Typangabe. Reihenfolge ist unerheblich.
Mehrere Punkte gleichen Typs sind uneindeutig: Zusatzwerte fehlen und neue Befehle werden
abgelehnt. Die bestehende Hauptfeldzuordnung bleibt für komplexe Mehrkreisgeräte individuell
konfigurierbar; die neuen Funktionen raten keinen Heizkreis.

- Boolesche Payloads: `1` und `0`; `on`/`off` werden entsprechend normalisiert.
- Zahlen: Dezimalpunkt, ohne Einheit. Text: native API-Texte, keine erratenen Zahlencodes.
- Fehlend, `null`, ungültig oder nicht unterstützt: `-`, niemals eine erfundene Null.
- Strukturen: kompaktes JSON, keine Zeilenumbrüche im Gateway-Datagramm.
- Zusatzdaten werden **ohne Retain** gesendet. `zusatz/zeit` enthält die Unixzeit des Abrufs,
  `zusatz/gueltig=1` bedeutet eine auswertbare Antwort für dieses Gateway; einzelne Felder
  können trotzdem fehlen. Bei einem tatsächlichen Fehlabruf kommt nur `zusatz/gueltig=0`,
  keine Wiederholung alter Zusatzmesswerte. Bei übersprungenen Abrufen/ausgeschöpftem
  Tagesbudget werden Zusatzdaten nicht erneut gesendet: Empfangs-Timeout auswerten.
- Alle Zusatzwerte lassen sich gemeinsam abschalten. Einzelfreigaben gibt es für neue Befehle.
- RUECKLAUF hat bei dieser Anlage keinen Sensor. Der zuvor falsche Standardpfad auf
  `leavingWaterTemperature` wurde entfernt. Das bestehende Topic bleibt bestehen, meldet
  ohne eigene Zuordnung den bisherigen Fehlwertmechanismus (`-999` für signierte Temperaturen).
  Keine Spreizung aus erfundenem Rücklauf. Individuelle Zuordnungen werden nicht gelöscht.

MQTT benutzt weiterhin den vorhandenen lokalen UDP-Eingang des LoxBerry-Gateways und
dessen Präfixkonfiguration. Zusatzpublishing erfolgt getrennt und fängt Ausnahmen ab;
ein fehlender Broker oder UDP-Versand beweist keine Zustellung. QoS-/Broker-Ende-zu-Ende-
Bestätigung liefert dieser bestehende Versandweg nicht. Es entstehen keine zusätzlichen
Cloud-Leseaufrufe; zusätzliche Datagramme werden wie bisher mit 5 ms Abstand gesendet.

## Elektrische Energie

`energie/heizung`, `energie/kuehlung` und `energie/warmwasser` enthalten JSON in dieser Form:

```json
{"einheit":"kWh","zeitzuordnung":"unbekannt","reihenfolge":"api_unveraendert","reihen":{"d":{"werte":[0,null,1.5],"gueltige_werte":2}}}
```

Es werden nur vorhandene Listen `d`, `w`, `m` veröffentlicht. Die vorliegende Response
enthält jeweils 24, 14 und 24 Elemente. `d`/`w`/`m` sind API-Zeitraumkennungen, **kein Beleg
dafür, dass jedes Element einen ganzen Tag/eine Woche/einen Monat beschreibt**. Aus dieser
Momentaufnahme lassen sich Indexrichtung, Periodengrenzen, Zeitzone und Kalenderzuordnung
nicht eindeutig verifizieren. Deshalb bleiben Indizes, Reihenfolge und `null`-Lücken erhalten.
Es gibt keine datierten Einzelwerte, „heute“-Summen, laufenden Zähler oder W-Leistungswerte.
Die Reihen können überlappende Zeiträume abdecken und dürfen nicht addiert werden.

Ungültige Samples (Text, negative Zahlen, boolesche Werte, Objekte) werden `null`, nicht 0.
`gueltige_werte` zählt echte numerische Samples einschließlich echter 0. Leere, objektartige
oder übergroße Reihen werden ausgelassen. Ohne explizite API-Einheit `kWh` wird das Topic
als fehlend gemeldet. Bei ausschließlich fehlenden Samples bleiben die `null`-Positionen und
der Zähler 0 erhalten. Eine Änderung dieses Zeitmodells verlangt belegte API-Dokumentation
oder mehrere zeitlich zugeordnete Responses; bisher ist das bewusst offen.

## Neue MQTT-Befehle

| Topic nach `<präfix>/befehl/` | Payload | Prüfung |
|---|---|---|
| `heiz_offset` | ganze Zahl −10…10 | heating aktiv, Gerätebereich, Schritt, schreibbare leaf/parent-Metadaten |
| `raum_soll` | Zahl mit Punkt, z. B. `22.5` | Sollwert des aktuellen Modus heating/cooling/auto; Schnittmenge 12…35 °C und Gerätebereich; API-Schritt |
| `heizmodus` | `heating`, `cooling`, `auto` | erlaubte API-`values` und `settable=true` |

Bei dieser Fixture beträgt der Raum-Sollbereich für heating/auto 12…30 °C, Schritt 0.5,
für cooling 15…35 °C, Schritt 0.5. Andere Geräte dürfen strengere Grenzen liefern.
Die Weboberfläche zeigt den aktuellen Rohdatenpfad und seine Werte; Geräte-Metadaten stehen
zusätzlich in der bestehenden Rohdatenansicht im Test-Reiter.

Alle Befehle benutzen die vorhandene `wp_oc_setzen()`-Funktion:

```text
PATCH /v1/gateway-devices/{geraet}/management-points/{tatsaechliche_embeddedId}/characteristics/temperatureControl
{"value":2,"path":"/operationModes/heating/setpoints/leavingWaterOffset"}

PATCH .../characteristics/temperatureControl
{"value":22.5,"path":"/operationModes/heating/setpoints/roomTemperature"}

PATCH .../characteristics/operationMode
{"value":"cooling"}
```

Frei übergebbare Geräte-, Merkmals- oder Pfadnamen sind nicht zugelassen. Die tatsächliche
ID wird aus dem eindeutigen Heizkreis des letzten erfolgreichen Abrufs entnommen (hier `1`).
Bestehende SG-Ready- und Boost-Schreiblogik bleibt unverändert. Es gibt keinen neuen
HTTP-Schaltendpunkt. Warmwasser-Boost bleibt als `ww_boost` verfügbar; neue Warmwasser-
Sollwert-, Zeitplan- und Betriebsartbefehle werden nicht angeboten. Insbesondere ist
`domesticHotWaterTemperature` in der Fixture `settable=false`.

Sicherheit und Fehlerverhalten:

- Alle Freigaben werden bei jedem Befehl erneut gelesen. Ohne MQTT, beide bestehende
  Freigaben oder passende Einzelfreigabe: `NICHT_FREIGEGEBEN`.
- Nur das konfigurierte Gerät mit erfolgreichem, frischem Abruf und bestätigtem Cloudstatus
  darf schreiben. Maximalalter: doppelter konfigurierter Abruftakt, höchstens eine Stunde.
  Gerätewechsel, unbekannte Schreibbarkeit, fehlende Grenzen oder mehrdeutiger Heizkreis
  führen zur Ablehnung. Keine zusätzliche Cloud-Abfrage zur Fähigkeitsprüfung.
- Bei aktiv angeforderter SG-Ready-Sperre oder -Anhebung: `SGREADY_AKTIV`. Normalbetrieb
  Stufe 2 ist zulässig. Bestehende SG-Ready-Abläufe haben dadurch weiter Vorrang.
- Die Befehlsbrücke verwirft Retained-Messages **vor** der Befehlsausführung und quittiert
  sie nicht. Nicht retained publizieren. Sie verwirft eigene Quittungen und legt gleiche
  Befehlsnamen im Eingangspuffer zusammen.
- Die gemeinsame `abruf.lock` verhindert parallele neue Befehle und Cron-Abrufe. Bei
  belegter Sperre wird `ABRUF_AKTIV` quittiert, es gibt keinen späteren Automatik-Retry.
- Zusätzlich zur Brückensperrminute gilt über alle neuen Befehle zusammen maximal ein
  Schreibversuch pro 60 Sekunden (auf Ramdisk gespeichert, überlebt Brückenneustart,
  nicht LoxBerry-Neustart). Auch gescheiterte Versuche werden gebremst. Das bestehende
  gleitende Daikin-Tagesbudget wird unverändert gebucht und geprüft. Keine Retries.
- Nach erfolgreichem PATCH wird der Fähigkeitsstand ungültig. Ein neuer regulärer Abruf
  ist erforderlich, bevor der nächste neue Befehl ausgeführt wird. Bei unklarer Wirkung
  nach HTTP-Fehler den aktuellen Stand vor einer manuellen Wiederholung prüfen.
- Quittungen: `<präfix>/befehl/<name>/quittung`, ohne Retain. `angenommen` bestätigt
  erfolgreiche API-Annahme oder einen bereits identischen Sollwert; die mechanische
  Umsetzung/Wirksamkeit ist erst durch spätere Messwerte nachgewiesen. Fehler z. B.
  `abgelehnt: WERT_UNGUELTIG`, `NICHT_SCHREIBBAR`, `DATEN_VERALTET`, `BEFEHLSBREMSE`,
  `BUDGET_LEER`, `HTTP_...`. Unveränderter Sollwert führt zu keinem PATCH.
- Keine Live-Schreibtests wurden ausgeführt. Geräteannahme und tatsächliche Wirkung
  müssen ausdrücklich freigegeben und auf einer realen Testanlage geprüft werden.

## Einbindung in Loxone

Das vorhandene Gateway-Abonnement `<präfix>/#` deckt Zusatztopics ab. Im MQTT-Gateway V2
gewünschte Zusatztopics für Loxone auswählen; bei V1 nach dessen bestehender Anleitung
abonnieren. Gateway-Namen werden aus Topic-Schreibweise abgeleitet, z. B.
`waermepumpe/zusatz/heiz_offset` → `waermepumpe_zusatz_heiz_offset`.
In der bestehenden Hakenliste werden die neuen optionalen Topics nicht automatisch als
Pflichtfelder ergänzt; ihre vollständige Liste steht im separaten Daikin-Bereich.

Numerische Werte und 1/0-Schalter als analoge Eingänge mit Fehlwerterkennung einlesen:
`-` ist **kein** gültiger Messwert. Für native Modustexte einen Texteingang oder eine
explizite Textauswertung verwenden. JSON-Rohreihen benötigen eine JSON-Auswertung, z. B.
in Node-RED; nicht unmittelbar als momentanen Leistungs- oder Energiezähler verdrahten.
`zusatz/gueltig`, `zusatz/zeit` und Empfangs-Timeout (größer als regulärer Abruftakt)
auswerten; nach Loxone-Neustart bis zum nächsten Abruf keinen gültigen Zustand annehmen.

Die bestehende Loxone-Ausgangsvorlage aus der Befehlsbrücke enthält alle drei neuen Befehle.
Beispiel für einen virtuellen UDP-Ausgang zum MQTT-Gateway-Port (typisch 11884):

```text
publish waermepumpe/befehl/heiz_offset 2
publish waermepumpe/befehl/raum_soll 22.5
publish waermepumpe/befehl/heizmodus heating
```

Präfix und Gateway-Port an die vorhandene Einrichtung anpassen. Befehle nur bei bewusster
Wertänderung senden, nicht zyklisch und nicht retained. Kein automatisches Wiederholen
einer Ablehnung in Loxone einrichten. Neue Befehle benötigen keine Änderung bestehender
Virtueller-Eingang- oder HTTP-Vorlagen.

## Nachweise und offene Dokumentation

- Eigene Codebasis: `wp_oc_setzen()` verwendet bereits PATCH mit `value` und optionalem
  JSON-Pointer `path`. Die Erweiterung verwendet genau diese Funktion, Authentifizierung
  und Budgetbuchung.
- Gegenprüfung am 2026-11-02 mit den Primärquellen des Client-Projekts:
  [ClimateControlClient](https://github.com/jwillemsen/daikin-onecta/blob/main/src/daikin_onecta/climate.py)
  (`set_temperature`, `set_operation_mode`) und
  [OnectaClient](https://github.com/jwillemsen/daikin-onecta/blob/main/src/daikin_onecta/client.py)
  (`patch_characteristic`). Das ist eine unabhängige Implementierung, keine offizielle
  Daikin-Garantie und kein Nachweis eines erfolgreichen Schreibens auf dieser Anlage.
- Die vom Auftraggeber verlinkte offizielle
  [Supported-features-Seite](https://developer.cloud.daikineurope.com/docs/84e709f1-9d33-47e1-a93c-7f5cb8b8f12b/supported_features)
  leitet im verfügbaren Browser zur Anmeldung weiter. Ihr Inhalt konnte nicht gelesen
  werden; insbesondere wird daraus keine Zeitzuordnung der Energiedaten behauptet.
- Der Arbeitsordner enthielt die originale Response. Die eingecheckte Fixture wurde
  davon abgeleitet: Gerätekennungen ersetzt, IP/MAC/Seriennummern/Namen/Originalzeitstempel
  entfernt; Funktionsstruktur und Metadaten erhalten, Verbrauchsmuster synthetisch ersetzt. Keine Zugangsdaten erforderlich.

Prüfergebnisse und noch ausstehende reale Tests: [TESTS.md](TESTS.md).
