# Shure IP-Symcon Modul-Library

IP-Symcon Module zur Steuerung von **Shure Geräten** über TCP/IP (Shure Command Strings, Port 2202):

- **SLXD Wireless Receiver** (SLXD4, SLXD4D, SLXD24D, SLXD44, QLX-D/ULX-D)
- **MXA920 Ceiling Array Mikrofon**

## Überblick

Diese Library ermöglicht die vollständige Steuerung und Überwachung von Shure Geräten über das Netzwerk und bietet eine nahtlose Integration in IP-Symcon.

**Hauptmerkmale:**
- Automatische Geräteerkennung per IP-Scan (Configurator) mit Auto-Subnetz
- SLXD: Eine Instanz pro Channel (flexibel und übersichtlich)
- MXA920: Device Mute, Automix Gain, Presets, LED-Steuerung, Auto-Coverage, Pegel-Metering
- Push-Updates (REP-Messages) + Polling-Fallback
- Pending-Logik (UI flackert nicht bei Sollwertänderungen)
- Robuste Fehlerbehandlung und Diagnose-Variablen

## Repository-Struktur

```
Symcon-SHURE/
├── library.json
├── README.md
├── Configurator/           (Discovery & Auto-Instanziierung, alle Gerätetypen)
│   ├── module.json
│   ├── module.php
│   └── form.json
├── SLXDChannel/            (Device: 1 Channel pro Instanz)
│   ├── module.json
│   ├── module.php
│   └── form.json
├── MXA920/                 (Device: Ceiling Array Mikrofon)
│   ├── module.json
│   ├── module.php
│   └── form.json
└── SpeakerTracking/        (Logik: MXA920 → PTZ-Kamera Preset-Tracking)
    ├── module.json
    ├── module.php
    └── form.json
```

## Module

### 1. SLXD Configurator
**Typ:** Configurator (Type 4)
**GUID:** `{D5E8F9A1-2B3C-4D5E-6F7A-8B9C0D1E2F3A}`
**Prefix:** `SLXD`

**Funktionen:**
- IP-Range-Scan (von/bis)
- Automatische Erkennung von SLXD-Receivern (Port 2202)
- Erkennt Modell, Device ID, Firmware, Anzahl Channels
- Erstellt automatisch SLXDChannel-Instanzen (eine pro Channel)

**Properties:**
- `ScanSubnet`: Subnetz in CIDR-Notation (Default: automatisch aus der lokalen Netzwerkkonfiguration, Fallback 192.168.1.0/24)
- `Port`: TCP Port (Standard: 2202)
- `ManualIP`: IP-Adresse für manuelles Hinzufügen
- `ManualPort`: Port für manuelles Hinzufügen (Standard: 2202)

**Actions:**
- `Scan()`: Startet automatischen IP-Scan im konfigurierten Subnetz
- `AddManual()`: Fügt manuell eingegebene IP-Adresse hinzu

**Besonderheiten:**
- CIDR-basierter Scan (z.B. 192.168.1.0/24 scannt 192.168.1.1-254)
- Manuelles Hinzufügen für Geräte außerhalb des Scan-Subnetzes
- Automatische Erkennung der Channel-Anzahl (SLXD4=1, SLXD4D=2, SLXD44=4)
- "delete": true ermöglicht Löschen gefundener Geräte aus der Liste

---

### 2. SLXD Channel
**Typ:** Device (Type 3)
**GUID:** `{3F4A5B6C-7D8E-9F0A-1B2C-3D4E5F6A7B8C}`
**Prefix:** `SLXD`

**Funktionen:**
- Steuerung eines einzelnen SLXD-Channels (1-4)
- Audio Mute (ON/OFF)
- Audio Gain (-25 bis +10 dB)
- Frequency (Read-Only, MHz)
- Channel Name (String)
- Battery Level (0-100%)
- RF Level (dBm)
- TX Model (Read-Only, z.B. SLXD2)
- Push-Updates via REP-Messages
- Fallback-Polling (PollSlow/PollFast)

**Properties:**
- `Host`: IP-Adresse des SLXD Receivers
- `Port`: TCP Port (Standard: 2202)
- `Channel`: Channel-Nummer (1-4)
- `PollSlow`: Polling-Intervall Normal (Standard: 15s)
- `PollFast`: Polling-Intervall nach Änderung (Standard: 3s)
- `FastAfterChangeSeconds`: Dauer Fast-Polling (Standard: 30s)
- `TimeoutMs`: Kommando-Timeout (Standard: 1500ms)

**Variablen:**
- `Online` (bool): Verbindungsstatus
- `LastError` (string): Letzte Fehlermeldung
- `LastOKTimestamp` (int): Letzter erfolgreicher Poll
- `Mute` (bool, ~Switch): Audio Mute
- `Gain` (int, -25 bis +10 dB): Audio Gain
- `Frequency` (int, MHz): Frequency (Read-Only)
- `ChannelName` (string): Channel Name
- `Battery` (int, %): Battery Level
- `RFLevel` (int, dBm): RF Signal Level
- `TXModel` (string): Transmitter Model

**Actions:**
- `PollNow()`: Sofortiger Status-Poll
- `ReadDeviceID()`: Liest Device ID aus
- `SetMute(bool)`: Mute ON/OFF
- `SetGain(int)`: Gain setzen (-25 bis +10)

**Profile:**
- `SLXD.Gain`: Integer, -25 bis +10, Suffix " dB"
- `SLXD.Frequency`: Integer, Suffix " MHz"
- `SLXD.Battery`: Integer, 0-100, Suffix " %"
- `SLXD.RFLevel`: Integer, Suffix " dBm"

---

### 3. MXA920 (Ceiling Array Mikrofon)
**Typ:** Device (Type 3)
**GUID:** `{B4F7A2E9-3C61-4D8B-A5F2-7E9C4B1D6A38}`
**Prefix:** `MXA920`

**Funktionen:**
- Device Mute (ON/OFF, wird auch bei Änderung am Gerät sofort gepusht)
- Automix-Ausgangs-Gain (-110 bis +30 dB in 0,1-dB-Schritten, Kanal 09)
- Presets laden (1-10)
- Automatic Coverage ein/aus
- LED-Steuerung: Helligkeit (0-5), Farbe muted/unmuted (17 Farben), Verhalten (An/Aus/Blinkt)
- Identify (LED blinken lassen zum Auffinden des Geräts)
- Pegel-Metering des Automix-Ausgangs (optional, per MeterRate-Property)
- Push-Updates via REP-Messages + Polling-Fallback

**Properties:**
- `Host`: IP-Adresse des MXA920 (Shure Control IP)
- `Port`: TCP Port (Standard: 2202)
- `MeterRate`: Pegel-Metering in ms (0 = aus, 100-99999)
- `PollSlow`: Polling-Intervall Normal (Standard: 30s)
- `PollFast`: Polling-Intervall nach Änderung (Standard: 3s)
- `FastAfterChangeSeconds`: Dauer Fast-Polling (Standard: 30s)
- `TimeoutMs`: Kommando-Timeout (Standard: 1500ms)

**Variablen:**
- `Online` (bool): Verbindungsstatus
- `LastError` (string), `LastOKTimestamp` (int): Diagnose
- `Mute` (bool, ~Switch): Device Mute
- `Gain` (float, MXA920.Gain): Automix-Ausgangs-Gain in dB
- `Preset` (int, MXA920.Preset): Aktives Preset (0 = keins)
- `AutoCoverage` (bool, ~Switch): Automatic Coverage
- `Level` (int, MXA920.Level): Audio-Pegel Automix-Out in dBFS (bei aktivem Metering)
- `LEDBrightness` (int): LED-Helligkeit 0-5
- `LEDColorUnmuted` / `LEDColorMuted` (int, MXA920.LEDColor): LED-Farben
- `LEDStateUnmuted` / `LEDStateMuted` (int, MXA920.LEDState): LED-Verhalten
- `Flash` (bool): Identify-Blinken
- `Model`, `Firmware`, `DeviceID` (string, Read-Only)

**Actions:**
- `PollNow()`: Sofortiger Status-Poll
- `ReadDeviceID()`: Liest Device ID aus
- `SetMute(bool)`, `SetGain(float)`, `SetPreset(int)`, `SetAutoCoverage(bool)`
- `Identify(bool)`: LED-Blinken ein/aus
- `SetLEDBrightness(int)`, `SetLEDColorMuted(string)`, `SetLEDColorUnmuted(string)`,
  `SetLEDStateMuted(string)`, `SetLEDStateUnmuted(string)` (Farben/Status als Shure-Namen, z.B. "RED", "FLASHING")

**Kanal-Schema (Auto-Coverage ON):** 01-08 = Coverage Areas, 09 = Automix-Out, 10 = AEC-Referenz.
Das Modul steuert Gain/Metering am Automix-Ausgang (09); Mute erfolgt als Device Mute.

**Sprecher-Positionen (Speaker Tracking):** Über die Property `TalkerPositionRate` (ms, 0 = aus,
Empfehlung 300) liefert das Gerät `SAMPLE TALKER_POSITIONS`-Telegramme. Das Modul stellt daraus bereit:
- `TalkerCount` (int): Anzahl aktiver Sprecher im Reporting-Intervall
- `TalkerArea` (int, MXA920.CoverageArea): Coverage Area (1-8) des ersten Sprechers —
  wird bei **jedem** Report geschrieben (auch unverändert), damit nachgelagerte Logik per
  VM_UPDATE jeden Report als "es wird gesprochen"-Heartbeat erhält
- `TalkerX/Y/Z` (int, cm relativ zur Gerätemitte)
- `TalkerPositions` (string): JSON-Array aller Sprecher `[{"lobe":n,"area":n,"x":n,"y":n,"z":n},...]`

Wichtig: Bei Stille sendet das Gerät keine Reports — Stille erkennt man am Alter des letzten Updates.

---

### 4. SpeakerTracking (MXA920 → PTZ-Kamera)
**Typ:** Logik (Type 3)
**GUID:** `{C9D2E7A4-8F51-4B6E-A3D7-2E5F9C4B8A16}`
**Prefix:** `SHTRACK`

Koppelt ein MXA920 mit einer PTZ-Kamera (PureLink PTZ100): Spricht jemand in einer Coverage Area,
fährt die Kamera das zugeordnete Preset an. Preset-basiertes Zonen-Tracking — ruhiges Bild statt
kontinuierlichem Nachführen.

**Funktionsweise:**
1. Lauscht per MessageSink (VM_UPDATE) auf `TalkerArea` der MXA920-Instanz
2. **Dwell-Time**: Sprecher muss die eingestellte Zeit stabil in derselben Area sein
3. **Hold-Time**: Mindestabstand zwischen zwei Kamera-Schwenks (verhindert Ping-Pong)
4. **Stille-Timeout**: Nach n Sekunden ohne Sprecher-Report → Übersichts-Preset

**Properties:**
- `MicInstanceID`: MXA920-Instanz (dort muss `TalkerPositionRate` > 0 sein, z.B. 300 ms!)
- `CameraInstanceID`: PTZ100-Instanz
- `AreaPresets`: Mapping-Tabelle Coverage Area (1-8) → Kamera-Preset (Default: Area n → Preset n; Preset 0 = Area ignorieren)
- `DwellMs`: Stabilitätszeit in ms (Standard: 1500)
- `HoldSeconds`: Mindestabstand zwischen Schwenks (Standard: 5)
- `SilenceSeconds`: Stille-Timeout (Standard: 15)
- `OverviewPreset`: Übersichts-Preset bei Stille (0 = deaktiviert)

**Variablen:**
- `Active` (bool, schaltbar): Tracking ein/aus (z.B. für Präsentationsmodus)
- `CurrentArea` (int): Aktuell verfolgte Zone (0 = Übersicht)
- `CurrentPreset` (int): Zuletzt geladenes Kamera-Preset

**Einrichtung:**
1. Coverage Areas im Shure Designer definieren (= die Zonen)
2. In der MXA920-Instanz `TalkerPositionRate` auf z.B. 300 ms stellen
3. PTZ100-Presets pro Zone einrichten (Kamera ausrichten → `PLPTZ_PresetSave`) + Übersichts-Preset
4. SpeakerTracking-Instanz anlegen, Mikrofon + Kamera auswählen, Mapping pflegen

---

## Ansteuerungskonzept

### Protokoll: Shure Command Strings (ASCII)

**Port:** 2202 (TCP)
**Format:** `< COMMAND [channel] PARAMETER [value] >`

**Befehlstypen:**
- `GET`: Status abfragen
- `SET`: Wert setzen
- `REP`: Report/Push (asynchron vom Gerät)

**Beispiele:**
```
< GET 1 AUDIO_MUTE >
< SET 1 AUDIO_MUTE ON >
< REP 1 AUDIO_MUTE ON >
< GET 1 AUDIO_GAIN >
< SET 1 AUDIO_GAIN -15 >
< REP 1 AUDIO_GAIN -15 >
```

### Channel-Schema

- **SLXD4:** 1 Channel (Channel 1)
- **SLXD4D:** 2 Channels (Channel 1, 2)
- **SLXD24D / SLXD44:** 4 Channels (Channel 1-4)

**Empfehlung:** Eine Instanz pro Channel (maximal flexibel, saubere Trennung)

### Pending-Logik (Sollwert-Steuerung)

**Problem:** Bei Sollwertänderung (z.B. Gain) würde der nächste Poll den alten Wert zurückschreiben → UI flackert.

**Lösung:**
1. Bei `SetMute()`/`SetGain()`: Wert in Pending-Map speichern
2. Poll ignoriert Pending-Werte (schreibt nicht über)
3. REP-Message mit erreichtem Wert: Pending löschen, Variable aktualisieren
4. Timeout (10s): Pending automatisch löschen (Fehlerfall)

**Vorteil:** UI stabil, kein Flackern, kein Zurückspringen.

### Polling-Strategie

- **PollSlow (15s):** Standard-Intervall für Battery, RF-Level, etc.
- **PollFast (3s):** Nach Sollwertänderung (Mute/Gain) für 30s aktiv
- **FastAfterChange (30s):** Dauer des Fast-Polling nach Änderung

**Push-Updates (REP):** Gerät sendet REP-Messages bei Wertänderungen (z.B. Gain am Gerät geändert) → sofortige Aktualisierung ohne Poll.

---

## Inbetriebnahme

### 1. SLXD Receiver konfigurieren

**WICHTIG:** SLXD blockiert standardmäßig Third-Party Control!

1. **Ethernet verbinden:** Netzwerkkabel anschließen
2. **IP konfigurieren:** Statische IP empfohlen (z.B. 192.168.1.100)
3. **Controller Access aktivieren:**
   - Menü: `Advanced Settings` → `Controller Access`
   - Option: `Allow Third-Party Control` → **ON**
4. **Port 2202 prüfen:** TCP Port 2202 muss offen sein (Firewall)
5. **Device ID setzen (optional):** Für bessere Identifikation im Configurator

### 2. IP-Symcon Modul installieren

1. **Module Control öffnen:** Kern-Instanzen → Module Control
2. **GitHub-URL hinzufügen:**
   ```
   https://github.com/JLDFACE/Symcon-SHURE
   ```
3. **Modul laden:** "URL hinzufügen" → "Aktualisieren"
4. **Warten:** Module Control lädt library.json und Module

### 3. Configurator verwenden

1. **Configurator-Instanz anlegen:**
   - Objektbaum → Rechtsklick → "Instanz hinzufügen"
   - Suche: "SLXD Configurator"

2. **Automatischer Scan:**
   - ExpansionPanel "Automatische Erkennung (Scan)" öffnen
   - `ScanSubnet`: automatisch aus der lokalen Netzwerkkonfiguration vorbelegt (bei Bedarf manuell anpassen)
   - Port: 2202 (Standard)
   - Button "Scan starten" klicken
   - Hinweis: Bei /24 werden ca. 254 IPs gescannt (dauert ~1-2 Minuten)

3. **Manuelles Hinzufügen:**
   - ExpansionPanel "Manuelles Hinzufügen" öffnen
   - `ManualIP`: z.B. 192.168.5.100 (auch außerhalb des Scan-Subnetzes)
   - `ManualPort`: 2202
   - Button "Manuell hinzufügen" klicken

4. **Geräte gefunden:**
   - Liste zeigt IP, Model, Device ID, Firmware, Channels
   - Pro Channel eine Zeile (z.B. SLXD4D = 2 Zeilen für CH1 und CH2)
   - "delete" aktiviert: Geräte können aus der Liste entfernt werden

5. **Instanzen erstellen:**
   - Checkbox aktivieren → "Übernehmen"
   - Instanzen werden automatisch erstellt (1 pro Channel)

### 4. Manuelle Instanz erstellen (ohne Configurator)

1. **Instanz hinzufügen:**
   - Objektbaum → Rechtsklick → "Instanz hinzufügen"
   - Suche: "SLXD Channel"
2. **Konfiguration:**
   - `Host`: IP des SLXD Receivers
   - `Port`: 2202
   - `Channel`: 1-4 (je nach Modell)
3. **Übernehmen:** Parent Socket wird automatisch konfiguriert
4. **Status prüfen:** Nach ~5s sollte `Online = true` sein

---

## Troubleshooting

### Problem: Online = false

**Ursachen:**
- Controller Access nicht aktiviert (siehe Inbetriebnahme)
- Port 2202 blockiert (Firewall, Switch, VLAN)
- Falsche IP oder Port
- Receiver ausgeschaltet

**Lösung:**
1. Ping-Test: `ping <IP>`
2. Telnet-Test: `telnet <IP> 2202` (muss verbinden)
3. Controller Access prüfen (Advanced Settings)
4. Firewall/VLAN prüfen

---

### Problem: Keine REP-Messages (nur Polling funktioniert)

**Ursache:** Push-Updates evtl. geräteseitig deaktiviert (modellabhängig)

**Lösung:**
- Akzeptabel: Polling als Fallback funktioniert
- Optional: Firmware-Update prüfen
- PollSlow reduzieren (z.B. 10s)

---

### Problem: Gain springt nach Änderung zurück

**Ursachen:**
- Pending-Timeout zu kurz (10s Standard)
- Gerät antwortet nicht mit REP
- Verbindung instabil

**Lösung:**
1. `LastError` prüfen (Fehlermeldung)
2. Pending-Timeout erhöhen (Code: `CleanupOldPending()`, Zeile 340+)
3. Netzwerk prüfen (Ping, Latenz)

---

### Problem: Configurator findet keine Geräte

**Ursachen:**
- ScanSubnet falsch (Subnetz stimmt nicht mit Receiver-IPs überein)
- Auto-Subnetz passt nicht (z.B. mehrere NICs/VLANs)
- Port 2202 blockiert
- Controller Access nicht aktiviert
- Receiver im falschen Subnetz

**Lösung:**
1. ScanSubnet prüfen (auto vorbelegt, ggf. manuell auf das Receiver-Subnetz ändern)
2. Größeres Subnetz scannen (z.B. 192.168.0.0/16 für alle .0.0 bis .255.255)
3. Manuelles Hinzufügen nutzen (wenn IP bekannt ist)
4. Einzeltest: Manuelle Instanz anlegen (siehe oben)

**CIDR-Beispiele:**
- 192.168.1.0/24 → 192.168.1.1 bis 192.168.1.254 (254 IPs)
- 192.168.0.0/16 → 192.168.0.1 bis 192.168.255.254 (65534 IPs, dauert länger!)
- 10.0.0.0/8 → 10.0.0.1 bis 10.255.255.254 (sehr groß, nicht empfohlen)

---

### Problem: Variablen werden nicht aktualisiert

**Ursachen:**
- Polling deaktiviert (Timer prüfen)
- PollSlow/PollFast zu hoch (> 60s)
- Verbindung unterbrochen

**Lösung:**
1. `LastOKTimestamp` prüfen (wann war letzter Poll?)
2. `LastError` prüfen
3. Button "Status aktualisieren" klicken (PollNow)
4. Timer prüfen: Instanz → Properties → PollSlow/PollFast

---

## Kompatibilität

**IP-Symcon Version:** ab 5.0 (konservative APIs)
**PHP Version:** ab 7.0 (keine PHP 8 Features)
**SymBox:** vollständig kompatibel (keine Type Hints, strict_types, etc.)

**Getestete Geräte:**
- Shure SLXD4 (1 Channel)
- Shure SLXD4D (2 Channels)
- Shure SLXD24D / SLXD44 (4 Channels)
- Shure MXA920-S (FW 6.7.0.213)

**Hinweis:** Andere SLXD-Modelle sollten ebenfalls funktionieren, solange sie Port 2202 und Command Strings unterstützen.

---

## Changelog

### Version 1.2 (2026-07-16)
- MXA920: Sprecher-Positionen (`TALKER_POSITION_RATE` / `SAMPLE TALKER_POSITIONS`)
  - Neue Variablen TalkerCount, TalkerArea, TalkerX/Y/Z, TalkerPositions (JSON)
  - Self-Heal der Reporting-Rate wie beim Metering
- Neues Modul: SpeakerTracking (Coverage Area → PTZ100-Preset)
  - Dwell-/Hold-/Stille-Logik gegen Kamera-Ping-Pong
  - Übersichts-Preset bei Stille, Aktiv-Schalter

### Version 1.1 (2026-07-16)
- Library umbenannt in "Shure Control" (mehrere Gerätefamilien)
- Neues Modul: MXA920 Ceiling Array Mikrofon
  - Device Mute, Automix Gain, Presets, Auto-Coverage
  - LED-Steuerung (Helligkeit, Farben, Verhalten), Identify
  - Pegel-Metering via SAMPLE-Telegramme (MeterRate)
  - Push-Updates (REP) + Polling-Fallback, Pending-Logik
- Configurator erkennt jetzt auch MXA920 (Scan + manuelles Hinzufügen)

### Version 1.0 (2025-01-18)
- Initiale Version
- Configurator mit IP-Scan
- SLXDChannel Device (1 Instanz pro Channel)
- Audio Mute, Gain, Frequency, Channel Name
- Battery, RF-Level, TX-Model
- Push-Updates (REP) + Polling-Fallback
- Pending-Logik (stabile UI)
- Diagnose-Variablen (Online, LastError, LastOKTimestamp)

---

## Lizenz

Copyright (c) 2025 FACE GmbH
Alle Rechte vorbehalten.

---

## Support

GitHub: https://github.com/JLDFACE/Symcon-SHURE
Issues: https://github.com/JLDFACE/Symcon-SHURE/issues

---

## Quellen

- [Shure SLXD Command Strings](https://www.shure.com/en-US/docs/commandstrings/SLXD)
- [Shure Device IP Ports](https://content-files.shure.com/FileRepository/common-ip-ports-v2.pdf)
