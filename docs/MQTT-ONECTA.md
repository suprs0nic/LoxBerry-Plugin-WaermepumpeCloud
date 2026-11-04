# MQTT-Datenpunkte Daikin 0.9.43

Gemeinsame Basis: `<präfix>/Daikin/` – Groß-/Kleinschreibung beachten.
Der alte `zusatz/`-Baum wird nicht mehr veröffentlicht.

| Teilbaum | Bedeutung |
|---|---|
| `heizung/` | Heizkreis einschließlich aller Sensoren, Sollwerte und Metadaten |
| `ww/` | Warmwasser, Boost-Wunsch und tatsächlicher Boost-Status |
| `energie/` | Rohdaten und elektrische Periodensummen; WW unter `energie/ww/` |
| `diagnose/` | Gateway, Innengerät, Außengerät und Bedienung |
| `geraet/` | Gerätedaten einschließlich ausdrücklich historischer Felder |
| `faehigkeiten/` | Vorhandensein dokumentierter Merkmale |
| `status/gueltig`, `status/zeit` | Antwort gültig und eigener Empfangszeitpunkt |
| `befehl/` | Befehle, Quittung jeweils mit `/quittung` |

Alle Daikin-Topics sind nicht retained. Einzelwerte behalten API-Namen und
Struktur (`value`, `settable`, `values`, Grenzen und Schritte).
Boolesche Werte: `1`/`0`; Texte wie `on`/`off`: unverändert; Zahlen: Dezimalpunkt;
fehlende Werte: `-`; Listen: JSON mit unveränderten Indizes und null-Lücken.
Leere oder mehrzeilige Texte werden reversibel als JSON-String kodiert.

Der [maschinenlesbare Katalog](DAIKIN-FELDER.json) benennt jeden Topic-Pfad,
API-Quellpfad und Typ der ursprünglichen Antwort. Aktuelle Werte stehen im
Daikin-Reiter. Die Ausgabe berücksichtigt alle 503 Einzelwerte der gespeicherten
Response, einschließlich Metadaten, aufgeteilt in 279 Katalogtopics.

[Alle Befehle, Nutzlasten und Umstellung von bisherigen Topics](DAIKIN.md).
[Alle Energieperioden und Loxone-Anzeige](ONECTA-ENERGIE.md).

In Loxone immer Gültigkeit, Empfangszeit und Empfangs-Timeout auswerten.
`faehigkeiten=1` bedeutet vorhanden, nicht pauschal schreibbar.
Allgemeine Haupttopics des Plugins behalten ihre bisherigen Retain-Regeln.
