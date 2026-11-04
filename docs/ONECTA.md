# Daikin Onecta 0.9.43 – Testversion

Die aktuelle Einrichtung, alle Daten und alle Daikin-Befehle sind im eigenen
Reiter **Daikin** gebündelt. MQTT-Basis ist `<präfix>/Daikin/`.

- [Einrichtung, Migration, Befehle und Gerätefreigaben](DAIKIN.md)
- [Vollständiger Feldabgleich ohne individuelle Werte](DAIKIN-FELDER.json)
- [Elektrischer Energieverbrauch](ONECTA-ENERGIE.md)
- [MQTT-Übersicht](MQTT-ONECTA.md)
- [Offline-Prüfbericht und Grenzen](TESTS.md)
- [API-Limits aus 0.9.41](ONECTA-0.9.41.md)

Das manuelle Update heißt `WaermepumpeCloud-0.9.43-onecta-test.zip`.
Pluginname und Installationsordner bleiben `waermepumpe`. Automatische Updates
sind für diesen Entwicklungsstand ausgeschaltet. Installation und Upgrade müssen
noch mit dem echten LoxBerry-Installer geprüft werden.

Vor dem Upgrade Konfiguration sichern. OAuth-Anmeldung und Tokenrefresh bleiben
im bestehenden Ablauf. Nach dem Upgrade regulär abrufen und Loxone-Eingänge
von `zusatz/` auf die neuen, strukturierten Daikin-Topics umstellen.
Allgemeine Hauptmesswerte und bisherige Befehlsadressen bleiben kompatibel.

Die gespeicherte Altherma-Antwort ist eine historische Momentaufnahme.
Der WW-Sollwert ist darin nicht schreibbar. Nach einem Wechsel des Aufheizmodus
kann ein neuer Abruf eine geänderte Schreibbarkeit liefern; das Plugin prüft
die Metadaten dynamisch. Ein Modusname oder ein Installateurkennwort ersetzen
keine von der Cloud gemeldete Freigabe.
