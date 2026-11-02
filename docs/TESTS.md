# Prüfbericht – Onecta 0.9.41

Stand: 2026-11-02. Ausgangspunkt: Fork master `6e995e1`, Plugin 0.9.39.
Lokale Umgebung: Windows, PHP 8.4.4 CLI, Python 3.14.3. Es wurden keine echten
Cloud-Schreibzugriffe, Brokerbefehle oder LoxBerry-Installationen ausgeführt.

## Reproduzierbar ausführen

```text
python tools/check.py --php C:/php-8.4.4/php.exe
python tools/package.py
python tools/package.py --verify dist/WaermepumpeCloud-0.9.41-onecta-test.zip
```

Unter Linux mit PHP auf PATH genügt `python tools/check.py`. Das Workflow
`.github/workflows/offline.yml` führt dieselben Prüfungen unter PHP 7.4 und 8.4 aus
und stellt ZIP und SHA256 als CI-Artefakt bereit. Die lokale Ausführung allein
belegt nicht, dass dieser Remote-Workflow bereits durchgelaufen ist.

## Lokal geprüfte Bereiche

| Prüfung | Ergebnis | Aussagegrenze |
|---|---|---|
| PHP-Syntax aller PHP-Dateien | bestanden, PHP 8.4.4 | PHP 7.4 zusätzlich im CI vorgesehen |
| `tests/onecta.php` | 195 Prüfungen, 0 Fehler | Fixture, reine Funktionen und Brückenparser |
| `tests/onecta_limits.php` | 43 Prüfungen, 0 Fehler | Header, Zeitfenster, Geräteidentität und Anlagenzustände |
| `tests/integration.php` | 40 Prüfungen, 0 Fehler | reale Abruf-/Konfig-/Writer-/UI-Logik, HTTP und MQTT ersetzt |
| vorhandenes `befehlsbruecke.php --selbsttest` | 122 Fälle, 0 Fehler | Rechenkern ohne Mosquitto-Prozesse |
| `tests/http_headers.php` | je 6 Prüfungen mit curl und PHP-Streams, 0 Fehler | echter HTTP-Transport ausschließlich zum temporären Loopback-Testserver |
| `git diff --check` | bestanden | Format-/Whitespaceprüfung |
| ZIP-Prüfung | bestanden | CRC, Struktur, Unix-Ausführrechte, LF, Ausschluss privater Laufzeitdateien |

Mapping-/Payloadprüfungen: originale Feldstrukturen der bereinigten Altherma-Response,
Objekt/Array, falsches Gateway, vertauschte/mehrdeutige Management Points, fehlende Felder,
falsche Typen, `null`, boolesche Werte, echte Null, Textinjektion, Energieeinheiten und
Rohreihen einschließlich Lücken und ungültiger Samples. Bestehende Onecta-Felder,
alte stringbasierte IDs und benutzerdefinierte Pfade bleiben geprüft. Regressionserwartungen
für myUplink, MELCloud, myVAILLANT und EMS-ESP prüfen repräsentative reale Feldpfade
und digitale Normalisierung, nicht deren vollständige Live-Schnittstellen.

Befehlsprüfungen: alle Freigaben, Hersteller/Gerätewechsel, Cloudstatus, Alter der
Fähigkeiten, erlaubte Werte, Gerätegrenzen, Schrittweite, `settable`, active-mode-Pfad,
nicht erlaubter Warmwasser-Sollwert, unveränderter Wert, SG-Ready-Konflikt, Retained-
Verwerfen, gültige Brückenweitergabe sowie Loxone-Vorlage mit eigenem Präfix.

Die Integrationstests instrumentieren ausschließlich die Transportgrenzen und den
temporären Speicherort der realen Bibliothek. Die HTTP-Implementierung wird vor dem
Laden aus der Testkopie entfernt. Jeder HTTP-Aufruf wird aufgezeichnet und als Fixture
beantwortet. Geprüft werden: genau ein GET pro Abruf, Zusatzdaten im normalen Abruf,
unveränderter Takt, Publishing-Ausnahme, HTTP-Fehler ohne alte Messwerte, gemeinsame
Abrufsperre, Probe ohne PATCH, konkreter PATCH-Endpunkt und JSON-Body, Cacheinvalidierung,
globale Befehlsbremse, HTTP 429 ohne Retry, existierendes Tagesbudget und Alt-Konfig-Upgrade.
Keine echten Tokens oder externen Netzwerkverbindungen werden benötigt.

Seit 0.9.41 zusätzlich geprüft: Retry-After in Sekunden und als HTTP-Datum, ungültige
Header, strenge Header-Positivliste ohne Cookies/Authorization, gleitendes Minutenfenster,
strengere Servergrenzen, Pausenablauf, Sperrdatei, beschädigte/unvollständige Limitzustände,
einmalige 429-Buchung sowie gemeinsame Pausen für Geräteliste, Lesen und den bestehenden
Writer. Der echte HTTP-Transport wird zusätzlich über einen kurzzeitig laufenden Server
auf 127.0.0.1 geprüft; dabei werden keine externen Adressen aufgerufen.

Neue Anlagenprüfungen: Installateurmodus und aktiver Urlaub ohne HTTP-Weitergabe,
zukünftiger Urlaub mit explizit inaktivem Status, ungültige boolesche Zustände,
blockierende Updates an Gateway/MMI, laufendes Update und nicht blockierendes optionales
Update. Geräte-id gegen abweichende deprecated _id, Fallback nur bei fehlender id sowie
ungültige vorhandene id werden separat geprüft. Insgesamt 412 erfolgreiche Prüffälle
zuzüglich PHP-Syntax und Paketprüfung in der lokalen Windows-Ausführung.

## Noch auf echter Testanlage auszuführen

- Installation und Upgrade 0.9.36 → 0.9.40 durch den echten LoxBerry-Installer;
  Wiederherstellung bestehender Konfiguration und Rückbau mit Sicherung.
- Darstellung/Bedienbarkeit mit echtem LoxBerry-Webrahmen und jQuery-Mobile,
  lokale Anzeige und Speicherung der neuen Freigaben.
- Bestehende OAuth-Anmeldung/Tokenrotation im realen Dienst (Code unverändert).
- Regulärer Abruf und MQTT-Gateway V1/V2 bis zum Loxone-Eingang, Empfangs-Timeout,
  Topics mit angepasstem Präfix und reale Payload-Verarbeitung.
- Mosquitto v5-Retained-Erkennung auf dem Gerät einschließlich Brücken-Neustart.
- Neue Schreibbefehle nur nach ausdrücklicher Live-Testfreigabe, zuerst einen passenden
  Wert innerhalb der aktuellen Gerätefreigaben wählen; PATCH-Annahme und spätere
  Gerätemessung getrennt prüfen. Keine Betriebsart zum bloßen Test ungewollt umschalten.
- Mehrkreis-/andere Daikin-Modelle und deren Metadaten. Die Erweiterung unterstützt
  bewusst nur einen eindeutig identifizierbaren Heizkreis und Warmwasserspeicher.
- Offizielle Daikin-Dokumentation zu zeitlicher Reihenfolge und Periodengrenzen der
  Verbrauchsreihen. Bis dahin gibt es ausschließlich indizierte Rohdaten ohne Kalenderdatum.

Das ZIP ist eine strukturell geprüfte Testversion, keine als produktiv erprobte Freigabe.
