# Changelog des Forks

## 0.9.42 - Verständliche Energieauswertung (2026-11-02)

- Verbrauchstabelle für Heizung, Kühlung, Warmwasser und deren Summe: heute/gestern,
  diese/letzte Woche, dieser/letzter Monat sowie dieses/letztes Jahr.
- Bekannte Teilsumme, Datenabdeckung und vollständige abgeschlossene Perioden getrennt.
  Fehlende Werte bleiben unbekannt; keine zusätzliche Leistung oder Arbeitszahl erfunden.
- Zusätzliche MQTT-Topics für Perioden, Status und Zeitbasis; Rohreihen unverändert.
  Gleicher regulärer Abruf, keine weiteren Cloud-Aufrufe, keine Verbrauchshistorie.
- Gateway-Zeitzone bevorzugt; optional bestätigte Anlagenzeitzone im MQTT-Reiter.
  Eigene gespeicherte Empfangszeit, keine angenommene Rechnerzeitzone oder deprecated Zeitstempel.
- Monatswechsel, Jahreswechsel, Datenlücken, echte Null, Einheiten, MQTT und UI offline geprüft.
  Anleitung mit Loxone-Beispielen und manuelles Test-ZIP 0.9.42 ergänzt.

## 0.9.41 – Onecta Stabilisierung (2026-11-02)

- Alle drei bestehenden Onecta-Datenendpunkte verwenden eine gemeinsame, nicht wartende
  Sperre und persistente Kontingentprüfung. Tagesbuchung vor dem Versand, auch bei 429.
- HTTP-Antworten liefern ausschließlich erlaubte Limitheader zusätzlich zurück. Beide
  Transportwege (curl und PHP-Streams) getestet; keine Tokens oder Cookies in der Limitdatei.
- Retry-After (Sekunden oder HTTP-Datum), RateLimit-Reset, erschöpfte Restkontingente
  und strengere Servergrenzen berücksichtigt. Gleitende lokale Grenze 20/Minute ergänzt.
- Pausen gelten auch für bestehende SG-Ready-/Boost-Schreibaufrufe und Geräteerkennung.
  OAuth2- und Refresh-Ablauf unverändert; keine Cloud-Probeaufrufe bei aktiver Pause.
- Installateurmodus, aktiver/unklarer Urlaub und blockierende/laufende Firmwareupdates
  sperren neue Zusatzbefehle. UI zeigt den Grund; MQTT-Brücke quittiert eindeutige Fehlercodes.
- Geräte-id hat Vorrang gegenüber deprecated _id; ungültige id wird nicht durch _id ersetzt.
- Statusanzeige und Selbstprüfung um API-Pausen erweitert. Offline-Regressions- und
  Loopback-Transportprüfungen ergänzt. Neues manuelles Test-ZIP 0.9.41.
- Verbrauchsaufbereitung, zusätzliche Zeitplansteuerung und eine globale zehnminütige
  Kompressorschutzregel sind separate Folgeschritte und hier nicht ergänzt.

## 0.9.40 – Onecta-Testversion (2026-11-02)

- Auf Fork-Stand 0.9.39 aufgebaut; bestehende OAuth2- und Refresh-Funktionen unverändert.
- Optionale Daikin-Zusatzdaten im regulären Abruf, einschließlich Heizung, Warmwasser,
  Fehlerzuständen, Geräte-/Firmwarediagnose und elektrischen kWh-Rohreihen.
- Eigener Bereich im MQTT-Reiter mit Freigaben, Topics, Pfaden und gespeichertem Stand;
  zusätzliche Hinweise in der Selbstprüfung.
- Neue MQTT-Befehle `heiz_offset`, `raum_soll`, `heizmodus` über die vorhandene
  Befehlsbrücke und PATCH-Funktion. Separate Freigaben ab Werk aus, Gerätevalidierung,
  Retained-Schutz, Sperre mit Abruf, Befehlsbremse und Quittungen.
- Numerische Altherma-Management-Point-IDs werden über den Typ erkannt. Alte stringbasierte
  IDs und explizite Feldzuordnungen bleiben unterstützt.
- Falschen Onecta-Standardpfad für RUECKLAUF entfernt: keine Vorlauftemperatur als Rücklauf.
  Das bestehende Ausgabetopic meldet stattdessen den üblichen Fehlwert.
- Offline-Tests, Transport-Mocks, CI und reproduzierbare ZIP-Erstellung mit Strukturprüfung.
- Testpaket ohne automatische Updates; Updateadressen in plugin.cfg auf diesen Fork gesetzt.

Vorherige Änderungen stehen in der historischen README des Originalprojekts.
