# IPSymconNetatmoAdvanceCamera

Testmodul fuer Netatmo Indoor Camera Advance.

## v0.2

Aenderungen gegenueber v0.1:

- Access Token wird vor API-Aufrufen erzwungen erneuert, wenn noetig.
- API-Aufrufe senden den Token jetzt im `Authorization: Bearer ...` Header und zusaetzlich als Query-Parameter `access_token`, um den Netatmo-Fehler `Access token is missing` abzufangen.
- Kamera-Erkennung sucht in `cameras`, `modules` und `devices`.
- Kamera-Typ `NPC` ist enthalten.
- Wenn keine Kamera gefunden wird und `DebugRaw` aktiv ist, wird `homesdata` in der Variable `Rohdaten` gespeichert.

## Installation

Repository/ZIP in Symcon als Modul installieren, Instanz `Netatmo Advance Camera` anlegen und eintragen:

- Client ID
- Client Secret
- Refresh Token
- optional Home ID
- optional Camera ID

Danach zuerst `Access Token erneuern`, dann `Daten aktualisieren` oder `Kamera automatisch erkennen` ausfuehren.

