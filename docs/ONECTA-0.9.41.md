# Onecta Verbesserungen in Version 0.9.41

Diese lokale Testversion ergänzt die Stabilitätsverbesserungen 1, 2 und 4 aus dem
Dokumentationsabgleich. Grundlage ist 0.9.40; bestehende MQTT-Topics, individuelle
Zuordnungen und Einzelfreigaben bleiben erhalten. Es gibt keine neuen Steuerfunktionen.

## Installation und Nutzung

Das neue `WaermepumpeCloud-0.9.41-onecta-test.zip` wie 0.9.40 manuell über die
LoxBerry-Pluginverwaltung installieren, ohne das vorhandene Plugin zu deinstallieren.
Vorher sichern. Das ZIP ist strukturell geprüft; eine echte LoxBerry-Installation
wurde nicht ausgeführt. Automatische Updates bleiben aus.

Der Daikin-Bereich im MQTT-Reiter zeigt die API-Freigabe beziehungsweise Pause und
blockierende Anlagenzustände. Auch die Selbstprüfung enthält den API-Kontingentstatus.
Bei einer Pause keine Knöpfe wiederholt betätigen: Es erfolgt ohnehin kein Daten-API-Aufruf.
Nach Ablauf der Pause wird beim nächsten regulär fälligen Abruf wieder gelesen. Ein zuvor
abgelehnter Schreibbefehl wird nicht automatisch später nachgeholt.

## Gemeinsame Kontingentprüfung

Geräteliste, Einzelgeräteabruf und bestehende PATCH-Funktion nutzen denselben Zugangsschutz:

- Maximal 200 gebuchte Versuche in gleitenden 24 Stunden und 20 in gleitenden 60 Sekunden.
  Niedrigere ausdrücklich gemeldete Serverlimits werden zusätzlich berücksichtigt.
- Eine gemeinsame Dateisperre serialisiert Reservierung, HTTP-Aufruf und Ergebnisbuchung.
  Ist sie belegt, wird ohne Warten mit `API_BESCHAEFTIGT` abgelehnt.
- Der Versuch wird vor HTTP gespeichert. Auch HTTP 429 verbraucht genau einen Versuch;
  ein lokal abgelehnter Aufruf zählt nicht als Cloudversuch.
- Bei 429 gilt `Retry-After`, wahlweise als Sekundenwert oder HTTP-Datum. Ohne verwertbaren
  Wert wird `RateLimit-Reset` verwendet. Fehlt auch dieser: 60 Sekunden, beziehungsweise
  konservativ 24 Stunden bei ausdrücklich erschöpftem Tageskontingent.
- Erfolgreiche Antworten mit Restkontingent 0 sperren weitere Requests ebenfalls:
  Minutenreset nach gemeldetem Reset, sonst 60 Sekunden; unbekannter Tagesreset konservativ
  24 Stunden, sofern kein verwertbares Retry-After vorliegt.
- Die Werte unter `X-RateLimit-*` und `RateLimit-*` werden nur als erlaubte numerische
  Metadaten beziehungsweise Retry-Datum verarbeitet. Es werden keine vollständigen
  HTTP-Header, Authorization-Werte oder Cookies in der Limitdatei gespeichert.
- Die Datei `data/plugins/<ordner>.bestand/onecta_limits.json` liegt wie die bisherige
  Tagesliste außerhalb des beim Upgrade ausgetauschten Plugin-Datenordners. Sie übersteht
  Prozess- und LoxBerry-Neustarts. Einstellungen und Tokens werden nicht darin abgelegt.
- OAuth/Tokenendpunkte laufen weiterhin im vorhandenen Anmelde- und Refresh-Ablauf.
  Eine aktive Daten-API-Pause wird vor dem Tokenabruf der Datenfunktionen geprüft.

Bei normalen HTTP-Fehlern und Zeitüberschreitungen bleibt der reguläre Abruftakt bestehen.
Wenn ein Prozess während einer echten Anfrage hart abbricht oder das Antwortprotokoll
nicht gespeichert werden kann, bleibt die zuvor persistierte `pending`-Marke stehen.
Weitere Daten-API-Aufrufe werden dann vorsorglich gesperrt (`API_LIMITANTWORT_FEHLT`).
Eine ungültige Limitdatei führt zu `API_LIMITDATEI_DEFEKT`. In diesen Ausnahmefällen
zuerst Prozesszustand und Dateisystem prüfen, die Datei sichern und erst dann reparieren.
Ein blindes Löschen setzt bekannte Serverpausen zurück und ist keine reguläre Abhilfe.

## Neue Befehle und Anlagenzustände

Die Zusatzbefehle `heiz_offset`, `raum_soll` und `heizmodus` werden vor dem Schreiben
zusätzlich aus dem letzten gültigen Gerätestand geprüft:

| MQTT-Ablehnungsgrund | Bedeutung |
|---|---|
| `INSTALLATEURMODUS` | Heizkreis meldet Installateurmodus |
| `URLAUB_AKTIV` | Urlaub aktiv oder aktiviert, ohne eindeutigen inaktiven Status |
| `ANLAGENZUSTAND_UNGUELTIG` | Vorhandener boolescher Sicherheitsstatus hat ungültigen Typ |
| `FIRMWARE_BLOCKIERT` | Mindestens ein Management Point meldet ein blockierendes Firmwareupdate |
| `FIRMWARE_UPDATE_AKTIV` | Mindestens ein Management Point meldet ein laufendes Firmwareupdate |
| `API_PAUSE` | Gespeicherte Serverpause noch nicht abgelaufen |
| `API_MINUTENLIMIT` | Lokale oder strengere gemeldete Minutenobergrenze erreicht |

Die bestehende Befehlsbrücke liefert diese Gründe unter
`<präfix>/befehl/<name>/quittung` als `abgelehnt: <GRUND>`, ohne Retain. Es werden
keine neuen Statustopics angelegt. Eine ausdrücklich inaktive Urlaubsanzeige blockiert
keinen erst in Zukunft vorgesehenen Urlaub. Ein nur optional angebotenes Firmwareupdate
blockiert ebenfalls nicht. Gerätebereiche, Parent-/Leaf-Schreibbarkeit, Freigaben,
SG-Ready-Konfliktprüfung, Frischeprüfung und Befehlsbremse aus 0.9.40 gelten weiter.

Die neuen Anlagenzustandsprüfungen ändern die alten SG-Ready-/Boost-Abläufe nicht.
Die gemeinsame Serverpause und Kontingentgrenze gelten dagegen für alle Daten-API-Aufrufe.
Die Daikin-Empfehlung zum zehnminütigen kompressorrelevanten Start-/Stopp-Abstand ist
eine weitere Aufgabe; weder diese Version noch die bestehende 60-Sekunden-Bremse
behaupten eine vollständige Kompressorschutzregel.

## Geräteidentität

Die oberste `id` ist maßgeblich. Eine davon abweichende `_id` verwirft das Gerät nicht.
Nur wenn `id` tatsächlich fehlt, wird für ältere Antworten noch `_id` herangezogen.
Eine vorhandene leere oder ungültige `id` wird nicht dadurch ersetzt. Management-Point-
IDs sind weiterhin die tatsächlichen `embeddedId`-Werte der jeweiligen Punkte.

## Dokumentationsstand und Energie

Die offizielle Dokumentation wurde am 02.11.2026 in der angemeldeten Sitzung gelesen.
Sie bestätigt die PATCH-Struktur und die relative Reihenfolge der Energiedaten:
`d` gestern/heute in Zweistundenabschnitten, `w` letzte/laufende Woche in Tagen,
`m` letztes/laufendes Jahr in Monaten. Die damalige Aussage „Reihenfolge unbekannt“
in der 0.9.40-Dokumentation ist damit überholt. Die **Softwareausgabe bleibt** in
0.9.41 bei den unveränderten Rohreihen, einschließlich `zeitzuordnung=unbekannt`;
eine neue Kalender- oder Summenaufbereitung ist noch nicht Bestandteil dieser Version.
Deine Ausgangsresponse enthält keine bestätigte Gateway-Zeitzone.

Quellen:

- [General API Guidelines](https://developer.cloud.daikineurope.com/docs/84e709f1-9d33-47e1-a93c-7f5cb8b8f12b/general_api_guidelines)
- [General concepts](https://developer.cloud.daikineurope.com/docs/84e709f1-9d33-47e1-a93c-7f5cb8b8f12b/general_concepts)
- [Climate Control features](https://developer.cloud.daikineurope.com/docs/84e709f1-9d33-47e1-a93c-7f5cb8b8f12b/supported_features/supported_features_climatecontrol)
- [Gateway features](https://developer.cloud.daikineurope.com/docs/84e709f1-9d33-47e1-a93c-7f5cb8b8f12b/supported_features/supported_features_gateway)
- [Device info](https://developer.cloud.daikineurope.com/docs/84e709f1-9d33-47e1-a93c-7f5cb8b8f12b/supported_features/supported_device_info)

Alle Tests arbeiten mit Fixtures, simulierten Cloudantworten oder einem temporären
HTTP-Server ausschließlich auf 127.0.0.1. Kein Live-Schreibtest, kein produktiver
LoxBerry-Zugriff und keine GitHub-Veröffentlichung wurden vorgenommen.
