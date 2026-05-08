# IPSymcon Netatmo Advance Camera

Experimentelles IP-Symcon Modul fuer die Netatmo Indoor Camera Advance / Netatmo Camera Advance.

## Installation

Repository in Symcon ueber `Kern Instanzen -> Modules -> Hinzufuegen -> ModulURL` einbinden.

## Netatmo App / Token

Du brauchst eine Netatmo Connect App und einen Refresh Token mit Security/Camera Scopes, typischerweise:

- `read_camera`
- `access_camera`
- `write_camera` optional
- `read_presence` / `access_presence` falls dein Account diese Scopes benoetigt

## Einrichtung

1. Instanz `Netatmo Advance Camera` anlegen.
2. Client ID, Client Secret und Refresh Token eintragen.
3. Optional Home ID und Camera ID leer lassen und `Kamera automatisch erkennen` ausfuehren.
4. Danach `Daten aktualisieren` ausfuehren.

## Hinweise

- Das Modul ist bewusst klein gehalten und unabhaengig von demel42/IPSymconNetatmoSecurity.
- Es erkennt bevorzugt den neuen Product-Type `NPC`, akzeptiert aber auch `NACamera`, `NOC` und `NDB` als Fallback.
- URLs werden aus `vpn_url` und `local_url` gebildet.
