# Changelog des Forks

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
