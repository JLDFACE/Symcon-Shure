# Shure SLXD IP-Symcon Modul

IP-Symcon Modul zur Steuerung von **Shure SLXD Wireless Receiver** über TCP/IP (Port 2202).

## Überblick

Dieses Modul ermöglicht die vollständige Steuerung und Überwachung von Shure SLXD Wireless Receivern über das Netzwerk. Es unterstützt alle gängigen SLXD-Modelle (SLXD4, SLXD4D, SLXD24D, SLXD44) und bietet eine nahtlose Integration in IP-Symcon.

**Hauptmerkmale:**
- Automatische Geräteerkennung per IP-Scan (Configurator) mit Auto-Subnetz
- Eine Instanz pro Channel (flexibel und übersichtlich)
- Audio Mute, Gain, Frequency, Channel Name
- Battery-Status, RF-Level, TX-Model
- Push-Updates (REP-Messages) + Polling-Fallback
- Pending-Logik (UI flackert nicht bei Sollwertänderungen)
- Robuste Fehlerbehandlung und Diagnose-Variablen

## Repository-Struktur

```
Symcon-SHURE/
├── library.json
├── README.md
├── Configurator/           (Discovery & Auto-Instanziierung)
│   ├── module.json
│   ├── module.php
│   └── form.json
└── SLXDChannel/            (Device: 1 Channel pro Instanz)
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

**Hinweis:** Andere SLXD-Modelle sollten ebenfalls funktionieren, solange sie Port 2202 und Command Strings unterstützen.

---

## Changelog

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
