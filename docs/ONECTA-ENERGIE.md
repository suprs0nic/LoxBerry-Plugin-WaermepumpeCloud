# Energieverbrauch mit Onecta 0.9.42

Im Reiter **MQTT → Daikin Onecta** steht eine Verbrauchstabelle für Heizung,
Kühlung und Warmwasser sowie deren Summe. Angezeigt wird elektrische Energie
in **kWh**, keine momentane Leistung und keine erzeugte Wärme. Eine Arbeitszahl
oder ein COP lässt sich daraus allein nicht bestimmen.

## Die Anzeige verstehen

Die Tabelle enthält heute, gestern, diese und letzte Woche, diesen und letzten
Monat sowie dieses und letztes Jahr. Laufende Perioden heißen ausdrücklich „bisher“.

- **Vollständig:** abgeschlossener Zeitraum und alle erwarteten Werte vorhanden.
- **Bisher, laufend:** bekannter Zwischenstand; die Periode ist noch nicht abgeschlossen.
- **Lückenhaft:** abgeschlossene Periode mit fehlenden Einzelwerten. Angezeigt wird
  nur deren bekannte Teilsumme, zusätzlich die Anzahl vorhandener/erwarteter Werte.
- **Keine auswertbaren Daten:** fehlende, ungültige oder nicht unterstützte Werte.
  Ein echter gemeldeter Nullverbrauch bleibt dagegen **0 kWh**.

Beispiel mit erfundenen Zahlen: Gestern 4,200 kWh bei 11/12 Werten ist eine
lückenhafte Teilsumme. Daraus wird kein vollständiger Tagesverbrauch.
Die Gesamtsumme erscheint nur, wenn alle drei Bereiche wenigstens einen gültigen
Wert haben. Fehlt etwa Kühlung vollständig, wird sie nicht als 0 angenommen;
Heizung und Warmwasser bleiben einzeln sichtbar.

Die Cloud liefert verzögerte Daten und kann Werte nachträglich aktualisieren.
„Vollständig“ beschreibt die Abdeckung der API-Werte, keine Abrechnungsgenauigkeit.
Diese Version speichert keine fortlaufende Verbrauchshistorie über die vom Gerät
gelieferten Zeitfenster hinaus.

## Zeitzone und gespeicherter Stand

Das Plugin speichert den eigenen Empfangszeitpunkt zusammen mit der Antwort.
Eine gültige Gateway-Zeitzone hat Vorrang. Meldet das Gateway keine, kann im
MQTT-Reiter die bekannte **Anlagenzeitzone** eingetragen werden, beispielsweise
`Europe/Vienna` oder `Europe/Berlin`. Leer bedeutet: keine Ersatzannahme.
Die vorliegende ursprüngliche Altherma-Antwort enthält keine Gateway-Zeitzone.

Ohne bestätigte Zeitzone oder Empfangszeit bleiben aktueller/vorheriger Monat
offen. Die übrigen Perioden werden relativ zur API-Reihe ausgewertet.
Die Monatsauswahl verwendet den Empfangsmonat in Anlagenzeit, einschließlich
Jahreswechsel und Sommerzeit. Die Rechnerzeitzone und der veraltete
Gerätezeitstempel werden dafür nicht verwendet. Ein verzögert gerolltes
Cloud-Zeitfenster an einer Kalendergrenze kann damit nicht sicher erkannt werden.

„Heute“ bezeichnet den Tag des gespeicherten Abrufs. Ein alter Stand wird beim
Öffnen der Oberfläche nicht auf das heutige Datum umgedeutet. Nach dem Upgrade
ist ein erfolgreicher regulärer Abruf nötig, um die neue Empfangszeit zu speichern.
Nach Schreibbefehlen bleibt die historische Energiezeit erhalten; die bestehende
Befehlsvalidierung verlangt weiterhin einen erneuten Abruf.

## MQTT und Loxone

„Zusätzliche Daikin-Messwerte veröffentlichen“ aktiviert auch die Energieauswertung.
Sie nutzt denselben regulären Abruf und verursacht **keine zusätzlichen Cloud-Aufrufe**.
Es kommen 101 nicht gespeicherte (nicht retained) MQTT-Nachrichten pro erfolgreichem
Abruf hinzu. Bestehende Rohreihen-Topics und Hauptmesswerte bleiben unverändert.

Basis: `<präfix>/zusatz/energie/`, standardmäßig `waermepumpe/zusatz/energie/`.
Bereiche: `heizung`, `kuehlung`, `warmwasser`, `gesamt`.
Perioden: `heute`, `gestern`, `diese_woche`, `letzte_woche`, `dieser_monat`,
`letzter_monat`, `dieses_jahr`, `letztes_jahr`.

| Suffix unter `<bereich>/<periode>/` | Bedeutung |
|---|---|
| `bekannt_kwh` | Bekannte Summe, auch bei Lücken oder laufender Periode |
| `gesamt_kwh` | Zahl nur für abgeschlossene, vollständig erfasste Periode |
| `vollstaendig` | `1` nur bei abgeschlossener und vollständig erfasster Periode, sonst `0` |

Fehlende Zahlen werden als `-` übertragen, Zahlen mit Dezimalpunkt.
`<bereich>/auswertung` liefert alle acht Perioden als JSON mit Anzahl vorhandener
und erwarteter Werte, Abdeckung, Abschlussstatus und Grund.
`zeitbasis` liefert Empfangszeit, Bezugsdatum, Zeitzone, Quelle und Grund als JSON.

Für eine einfache Verbrauchsanzeige in Loxone:

1. `<präfix>/zusatz/energie/heizung/heute/bekannt_kwh` als Tageszwischenstand anzeigen.
2. Für einen vollständigen Vortag `.../heizung/gestern/gesamt_kwh` zusammen mit
   `.../heizung/gestern/vollstaendig` auswerten.
3. Zusätzlich `<präfix>/zusatz/gueltig`, `<präfix>/zusatz/zeit` und Empfangs-Timeout
   auswerten. Bei fehlgeschlagenem Abruf werden keine alten Energiezahlen neu gesendet.
4. `-` darf nicht als Nullverbrauch interpretiert werden. Je nach Loxone-Eingang
   die Anzeige über den Gültigkeitsstatus sperren.

Diese Periodensummen **nicht bei jedem Empfang addieren** und nicht als monotonen
Zählerstand an einen Energiezähler anschließen: wiederholte Abrufe würden Verbrauch
mehrfach zählen; Zwischenstände können durch Nachlieferungen auch korrigiert werden.

## Zuordnung der Reihen

Die am 02.11.2026 gelesene offizielle Daikin-Dokumentation beschreibt:

| Reihe | Erwartete Länge | Vorherige Periode | Aktuelle Periode |
|---|---|---|---|
| `d` | 24 | 0–11: gestern, 2-Stunden-Slots | 12–23: heute |
| `w` | 14 | 0–6: letzte Woche Montag–Sonntag | 7–13: diese Woche |
| `m` | 24 | 0–11: letztes Jahr Januar–Dezember | 12–23: dieses Jahr |

Tages-, Wochen- und Monatsreihen überlappen und werden **nie untereinander addiert**.
Jede Auswertung nutzt ausschließlich ihre passende Reihe. Die vorhandenen Rohreihen
behalten aus Kompatibilitätsgründen ihr bisheriges Format einschließlich
`zeitzuordnung: unbekannt`; die neue Interpretation steht in separaten Topics.
Abweichende Reihenlängen werden in der Auswertung abgelehnt, in den Rohreihen
weiter unverändert transportiert. `null`, negative/ungültige Werte und andere
Einheiten werden nicht in erfundene Verbrauchswerte umgewandelt.

Grundlage: [Daikin Supported Features](https://developer.cloud.daikineurope.com/docs/84e709f1-9d33-47e1-a93c-7f5cb8b8f12b/supported_features),
Unterkapitel climateControl und domesticHotWaterTank; lokale Referenz vom 02.11.2026.
