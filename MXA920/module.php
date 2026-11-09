<?php

class MXA920 extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RequireParent('{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}');

        $this->RegisterPropertyString('Host', '192.168.1.100');
        $this->RegisterPropertyInteger('Port', 2202);

        $this->RegisterPropertyInteger('PollSlow', 30);
        $this->RegisterPropertyInteger('PollFast', 3);
        $this->RegisterPropertyInteger('FastAfterChangeSeconds', 30);
        $this->RegisterPropertyInteger('TimeoutMs', 1500);
        $this->RegisterPropertyInteger('MeterRate', 0);
        $this->RegisterPropertyInteger('TalkerPositionRate', 0);

        $this->RegisterTimer('PollTimer', 30000, 'MXA920_PollNow($_IPS[\'TARGET\']);');

        $this->SetBuffer('FastUntil', '0');
        $this->SetBuffer('RxBuf', '');
        $this->SetBuffer('PendingMap', '{}');
        $this->SetBuffer('PendingTSMap', '{}');
        $this->SetBuffer('DeviceID', '');
        $this->SetBuffer('Model', '');
        $this->SetBuffer('LastReconnect', '0');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->CreateProfiles();

        $this->RegisterVariableBoolean('Online', 'Online', '~Alert.Reversed', 1);
        $this->RegisterVariableString('LastError', 'LastError', '', 2);
        $this->RegisterVariableInteger('LastOKTimestamp', 'LastOKTimestamp', '~UnixTimestamp', 3);

        $this->RegisterVariableBoolean('Mute', 'Device Mute', '~Switch', 10);
        $this->EnableAction('Mute');

        $this->RegisterVariableFloat('Gain', 'Automix Gain', 'MXA920.Gain', 11);
        $this->EnableAction('Gain');

        $this->RegisterVariableInteger('Preset', 'Preset', 'MXA920.Preset', 12);
        $this->EnableAction('Preset');

        $this->RegisterVariableBoolean('AutoCoverage', 'Automatic Coverage', '~Switch', 13);
        $this->EnableAction('AutoCoverage');

        $this->RegisterVariableInteger('Level', 'Audio Level (Automix)', 'MXA920.Level', 20);

        $this->RegisterVariableInteger('TalkerCount', 'Sprecher (aktiv)', '', 25);
        $this->RegisterVariableInteger('TalkerArea', 'Sprecher Coverage Area', 'MXA920.CoverageArea', 26);
        $this->RegisterVariableInteger('TalkerX', 'Sprecher X', 'MXA920.Position', 27);
        $this->RegisterVariableInteger('TalkerY', 'Sprecher Y', 'MXA920.Position', 28);
        $this->RegisterVariableInteger('TalkerZ', 'Sprecher Z', 'MXA920.Position', 29);
        $this->RegisterVariableString('TalkerPositions', 'Sprecher Positionen (JSON)', '', 30);

        $this->RegisterVariableInteger('LEDBrightness', 'LED Helligkeit', 'MXA920.LEDBrightness', 30);
        $this->EnableAction('LEDBrightness');

        $this->RegisterVariableInteger('LEDColorUnmuted', 'LED Farbe (aktiv)', 'MXA920.LEDColor', 31);
        $this->EnableAction('LEDColorUnmuted');

        $this->RegisterVariableInteger('LEDColorMuted', 'LED Farbe (stumm)', 'MXA920.LEDColor', 32);
        $this->EnableAction('LEDColorMuted');

        $this->RegisterVariableInteger('LEDStateUnmuted', 'LED Verhalten (aktiv)', 'MXA920.LEDState', 33);
        $this->EnableAction('LEDStateUnmuted');

        $this->RegisterVariableInteger('LEDStateMuted', 'LED Verhalten (stumm)', 'MXA920.LEDState', 34);
        $this->EnableAction('LEDStateMuted');

        $this->RegisterVariableBoolean('Flash', 'Identify (LED blinken)', '~Switch', 35);
        $this->EnableAction('Flash');

        $this->RegisterVariableString('Model', 'Model', '', 40);
        $this->RegisterVariableString('Firmware', 'Firmware', '', 41);
        $this->RegisterVariableString('DeviceID', 'Device ID', '', 42);

        $this->EnsureParentClientSocket();

        $this->UpdatePollingInterval(false);

        $this->PollNow();

        $this->SetStatus(102);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Mute':
                $this->SetMute((bool)$Value);
                break;
            case 'Gain':
                $this->SetGain((float)$Value);
                break;
            case 'Preset':
                $this->SetPreset((int)$Value);
                break;
            case 'AutoCoverage':
                $this->SetAutoCoverage((bool)$Value);
                break;
            case 'Flash':
                $this->Identify((bool)$Value);
                break;
            case 'LEDBrightness':
                $this->SetLEDBrightness((int)$Value);
                break;
            case 'LEDColorMuted':
                $this->SetLEDColorMuted($this->ColorFromIndex((int)$Value));
                break;
            case 'LEDColorUnmuted':
                $this->SetLEDColorUnmuted($this->ColorFromIndex((int)$Value));
                break;
            case 'LEDStateMuted':
                $this->SetLEDStateMuted($this->LedStateFromIndex((int)$Value));
                break;
            case 'LEDStateUnmuted':
                $this->SetLEDStateUnmuted($this->LedStateFromIndex((int)$Value));
                break;
        }
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data['Buffer'])) return;

        $buffer = (string)$data['Buffer'];
        if ($buffer === '') return;

        $this->SendDebug('MXA920 RX', $buffer, 0);

        $buf = $this->GetBuffer('RxBuf') . $buffer;
        $buf = str_replace("\r", "\n", $buf);
        $lines = array();

        while (true) {
            $start = strpos($buf, '<');
            if ($start === false) break;
            if ($start > 0) {
                $buf = substr($buf, $start);
            }

            $end = strpos($buf, '>');
            if ($end === false) break;

            $frame = trim(substr($buf, 0, $end + 1));
            $buf = substr($buf, $end + 1);
            if ($frame !== '') {
                $lines[] = $frame;
            }
        }

        $this->SetBuffer('RxBuf', $buf);

        foreach ($lines as $line) {
            if ($line === '') continue;
            $this->ProcessLine($line);
        }
    }

    public function PollNow()
    {
        if (!$this->IsParentConnected()) {
            $this->SetCommError('Socket nicht verbunden');
            return;
        }

        $this->SendCommand('< GET DEVICE_AUDIO_MUTE >', 'Mute');
        $this->SendCommand('< GET 09 AUDIO_GAIN_HI_RES >', 'Gain');
        $this->SendCommand('< GET PRESET >', 'Preset');
        $this->SendCommand('< GET AUTO_COVERAGE >', 'AutoCoverage');
        $this->SendCommand('< GET FLASH >', 'Flash');
        $this->SendCommand('< GET LED_BRIGHTNESS >', 'LEDBrightness');
        $this->SendCommand('< GET LED_COLOR_MUTED >', 'LEDColorMuted');
        $this->SendCommand('< GET LED_COLOR_UNMUTED >', 'LEDColorUnmuted');
        $this->SendCommand('< GET LED_STATE_MUTED >', 'LEDStateMuted');
        $this->SendCommand('< GET LED_STATE_UNMUTED >', 'LEDStateUnmuted');
        $this->SendCommand('< GET METER_RATE >', 'MeterRate');
        $this->SendCommand('< GET TALKER_POSITION_RATE >', 'TalkerPositionRate');

        if (trim((string)$this->GetBuffer('Model')) === '') {
            $this->SendCommand('< GET MODEL >', 'Model');
            $this->SendCommand('< GET FW_VER >', 'Firmware');
            $this->SendCommand('< GET DEVICE_ID >', 'DeviceID');
        }

        $this->CleanupOldPending();
        $this->MarkOfflineIfStale();
        $this->UpdatePollingInterval(false);
    }

    public function ReadDeviceID(): string
    {
        $resp = $this->TrimBraces($this->SendCommandSync('< GET DEVICE_ID >'));
        if ($resp !== '') {
            $this->SetBuffer('DeviceID', $resp);
            $this->UpdateVariableString('DeviceID', $resp);
        }
        IPS_LogMessage('MXA920', 'Device ID: ' . $resp);
        return $resp;
    }

    public function SetMute(bool $mute)
    {
        $val = $mute ? 'ON' : 'OFF';
        if (!$this->SendCommand('< SET DEVICE_AUDIO_MUTE ' . $val . ' >', 'Mute')) {
            return;
        }

        $this->AddPending('Mute', $mute ? 1 : 0);
        $this->UpdatePollingInterval(true);
    }

    public function SetGain(float $gainDb)
    {
        $g = max(-110.0, min(30.0, $gainDb));
        $raw = (int)round($g * 10) + 1100;
        if (!$this->SendCommand('< SET 09 AUDIO_GAIN_HI_RES ' . $raw . ' >', 'Gain')) {
            return;
        }

        $this->AddPending('Gain', $raw);
        $this->UpdatePollingInterval(true);
    }

    public function SetPreset(int $preset)
    {
        $p = max(1, min(10, $preset));
        if (!$this->SendCommand('< SET PRESET ' . $p . ' >', 'Preset')) {
            return;
        }

        $this->AddPending('Preset', $p);
        $this->UpdatePollingInterval(true);
    }

    public function SetAutoCoverage(bool $on)
    {
        $val = $on ? 'ON' : 'OFF';
        if (!$this->SendCommand('< SET AUTO_COVERAGE ' . $val . ' >', 'AutoCoverage')) {
            return;
        }

        $this->AddPending('AutoCoverage', $on ? 1 : 0);
        $this->UpdatePollingInterval(true);
    }

    public function Identify(bool $on)
    {
        $val = $on ? 'ON' : 'OFF';
        if (!$this->SendCommand('< SET FLASH ' . $val . ' >', 'Flash')) {
            return;
        }

        $this->AddPending('Flash', $on ? 1 : 0);
        $this->UpdatePollingInterval(true);
    }

    public function SetLEDBrightness(int $level)
    {
        $l = max(0, min(5, $level));
        if (!$this->SendCommand('< SET LED_BRIGHTNESS ' . $l . ' >', 'LEDBrightness')) {
            return;
        }

        $this->AddPending('LEDBrightness', $l);
        $this->UpdatePollingInterval(true);
    }

    public function SetLEDColorMuted(string $color)
    {
        $this->SendLedColor('LEDColorMuted', 'LED_COLOR_MUTED', $color);
    }

    public function SetLEDColorUnmuted(string $color)
    {
        $this->SendLedColor('LEDColorUnmuted', 'LED_COLOR_UNMUTED', $color);
    }

    public function SetLEDStateMuted(string $state)
    {
        $this->SendLedState('LEDStateMuted', 'LED_STATE_MUTED', $state);
    }

    public function SetLEDStateUnmuted(string $state)
    {
        $this->SendLedState('LEDStateUnmuted', 'LED_STATE_UNMUTED', $state);
    }

    private function SendLedColor($ident, $param, $color)
    {
        $color = strtoupper(trim((string)$color));
        $idx = $this->ColorToIndex($color);
        if ($idx < 0) {
            $this->SendDebug('MXA920 ERR', 'Ungueltige LED-Farbe: ' . $color, 0);
            return;
        }

        if (!$this->SendCommand('< SET ' . $param . ' ' . $color . ' >', $ident)) {
            return;
        }

        $this->AddPending($ident, $idx);
        $this->UpdatePollingInterval(true);
    }

    private function SendLedState($ident, $param, $state)
    {
        $state = strtoupper(trim((string)$state));
        $idx = $this->LedStateToIndex($state);
        if ($idx < 0) {
            $this->SendDebug('MXA920 ERR', 'Ungueltiger LED-Status: ' . $state, 0);
            return;
        }

        if (!$this->SendCommand('< SET ' . $param . ' ' . $state . ' >', $ident)) {
            return;
        }

        $this->AddPending($ident, $idx);
        $this->UpdatePollingInterval(true);
    }

    private function ProcessLine($line)
    {
        if (!preg_match('/^<\s*(REP|SAMPLE)\s+(.+?)\s*>$/i', $line, $m)) {
            return;
        }

        $kind = strtoupper($m[1]);
        $payload = trim($m[2]);

        if ($kind === 'SAMPLE') {
            $this->ProcessSample($payload);
            return;
        }

        if (strtoupper($payload) === 'ERR') {
            $this->SendDebug('MXA920 REP', 'ERR', 0);
            return;
        }

        $channel = -1;
        if (preg_match('/^(\d{1,2})\s+(.*)$/s', $payload, $mm)) {
            $channel = (int)$mm[1];
            $payload = trim($mm[2]);
        }

        $parts = preg_split('/\s+/', $payload, 2);
        if (count($parts) == 0) return;
        $param = strtoupper($parts[0]);
        $value = (count($parts) >= 2) ? trim($parts[1]) : '';

        $this->SendDebug('MXA920 REP', ($channel >= 0 ? 'CH' . $channel . ' ' : '') . $param . '=' . $value, 0);
        $this->MarkOnline();

        switch ($param) {
            case 'DEVICE_AUDIO_MUTE':
                $this->UpdateBool('Mute', strtoupper($value) === 'ON');
                break;
            case 'AUDIO_GAIN_HI_RES':
                if ($channel == 9) {
                    $this->UpdateGain((int)$value);
                }
                break;
            case 'PRESET':
                $this->UpdateInt('Preset', (int)$value);
                break;
            case 'AUTO_COVERAGE':
                $this->UpdateBool('AutoCoverage', strtoupper($value) === 'ON');
                break;
            case 'FLASH':
                $this->UpdateBool('Flash', strtoupper($value) === 'ON');
                break;
            case 'LED_BRIGHTNESS':
                $this->UpdateInt('LEDBrightness', (int)$value);
                break;
            case 'LED_COLOR_MUTED':
                $this->UpdateIndexed('LEDColorMuted', $this->ColorToIndex(strtoupper($value)));
                break;
            case 'LED_COLOR_UNMUTED':
                $this->UpdateIndexed('LEDColorUnmuted', $this->ColorToIndex(strtoupper($value)));
                break;
            case 'LED_STATE_MUTED':
                $this->UpdateIndexed('LEDStateMuted', $this->LedStateToIndex(strtoupper($value)));
                break;
            case 'LED_STATE_UNMUTED':
                $this->UpdateIndexed('LEDStateUnmuted', $this->LedStateToIndex(strtoupper($value)));
                break;
            case 'METER_RATE':
                $this->SyncRate('METER_RATE', (int)$this->ReadPropertyInteger('MeterRate'), (int)$value);
                break;
            case 'TALKER_POSITION_RATE':
                $this->SyncRate('TALKER_POSITION_RATE', (int)$this->ReadPropertyInteger('TalkerPositionRate'), (int)$value);
                break;
            case 'MODEL':
                $model = $this->TrimBraces($value);
                $this->SetBuffer('Model', $model);
                $this->UpdateVariableString('Model', $model);
                break;
            case 'FW_VER':
                $this->UpdateVariableString('Firmware', $this->TrimBraces($value));
                break;
            case 'DEVICE_ID':
                $deviceID = $this->TrimBraces($value);
                $this->SetBuffer('DeviceID', $deviceID);
                $this->UpdateVariableString('DeviceID', $deviceID);
                break;
        }
    }

    private function ProcessSample($payload)
    {
        if (preg_match('/^TALKER_POSITIONS\s+(.*)$/is', $payload, $m)) {
            $this->ProcessTalkerPositions($m[1]);
            return;
        }

        // Nur reine Zahlenlisten sind Pegel-Samples; andere benannte
        // Samples werden nicht ausgewertet.
        if (!preg_match('/^[\d\s]+$/', $payload)) {
            return;
        }

        $vals = preg_split('/\s+/', trim($payload));
        if (!is_array($vals) || count($vals) == 0) {
            return;
        }

        // Auto-Coverage ON: 1 Wert (Automix-Out). OFF: Werte je Kanal,
        // Automix-Out ist Kanal 09.
        $idx = (count($vals) >= 9) ? 8 : (count($vals) - 1);
        $level = (int)$vals[$idx] - 60;

        $this->MarkOnline();
        $this->UpdateIntNoPending('Level', $level);
    }

    private function SyncRate($param, $want, $reported)
    {
        if ($want > 0 && $want < 100) {
            $want = 100;
        }
        if ($want !== (int)$reported) {
            $this->SendCommand('< SET ' . $param . ' ' . $want . ' >', $param);
        }
    }

    private function ProcessTalkerPositions($data)
    {
        $tok = preg_split('/\s+/', trim($data));
        if (!is_array($tok)) {
            return;
        }

        // Format je Sprecher: LobeID CoverageAreaID X Y Z reserved
        $positions = array();
        for ($i = 0; $i + 5 < count($tok); $i += 6) {
            $set = array_slice($tok, $i, 6);
            $ok = true;
            foreach ($set as $t) {
                if (!preg_match('/^-?\d+$/', $t)) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) break;

            $positions[] = array(
                'lobe' => (int)$set[0],
                'area' => (int)$set[1],
                'x' => (int)$set[2],
                'y' => (int)$set[3],
                'z' => (int)$set[4]
            );
        }

        if (count($positions) == 0) {
            return;
        }

        $this->MarkOnline();

        // Bewusst immer schreiben (auch bei unveraendertem Wert), damit
        // nachgelagerte Logik (z.B. SpeakerTracking) jeden Report als
        // VM_UPDATE-Ereignis erhaelt.
        $first = $positions[0];
        $this->ForceInt('TalkerCount', count($positions));
        $this->ForceInt('TalkerX', $first['x']);
        $this->ForceInt('TalkerY', $first['y']);
        $this->ForceInt('TalkerZ', $first['z']);

        $id = @$this->GetIDForIdent('TalkerPositions');
        if ($id > 0) {
            SetValueString($id, json_encode($positions));
        }

        // TalkerArea zuletzt: darauf lauscht die Tracking-Logik, die
        // uebrigen Werte sind dann bereits konsistent.
        $this->ForceInt('TalkerArea', $first['area']);
    }

    private function ForceInt($ident, $intValue)
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id > 0) {
            SetValueInteger($id, (int)$intValue);
        }
    }

    private function UpdateGain($raw)
    {
        $db = ((int)$raw - 1100) / 10.0;

        if (!$this->IsPending('Gain')) {
            $this->UpdateVariableFloat('Gain', $db);
        } else {
            $pending = $this->GetPending('Gain');
            if ((int)$pending === (int)$raw) {
                $this->ClearPending('Gain');
                $this->UpdateVariableFloat('Gain', $db, true);
            }
        }
    }

    private function UpdateBool($ident, $boolValue)
    {
        $intValue = $boolValue ? 1 : 0;

        if (!$this->IsPending($ident)) {
            $id = @$this->GetIDForIdent($ident);
            if ($id > 0 && GetValueBoolean($id) !== $boolValue) {
                SetValueBoolean($id, $boolValue);
            }
        } else {
            $pending = $this->GetPending($ident);
            if ((int)$pending === $intValue) {
                $this->ClearPending($ident);
                $id = @$this->GetIDForIdent($ident);
                if ($id > 0) {
                    SetValueBoolean($id, $boolValue);
                }
            }
        }
    }

    private function UpdateInt($ident, $intValue)
    {
        if (!$this->IsPending($ident)) {
            $this->UpdateIntNoPending($ident, $intValue);
        } else {
            $pending = $this->GetPending($ident);
            if ((int)$pending === (int)$intValue) {
                $this->ClearPending($ident);
                $id = @$this->GetIDForIdent($ident);
                if ($id > 0) {
                    SetValueInteger($id, $intValue);
                }
            }
        }
    }

    private function UpdateIndexed($ident, $index)
    {
        if ($index < 0) {
            return;
        }
        $this->UpdateInt($ident, $index);
    }

    private function UpdateIntNoPending($ident, $intValue)
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id > 0 && GetValueInteger($id) !== (int)$intValue) {
            SetValueInteger($id, (int)$intValue);
        }
    }

    private function UpdateVariableFloat($ident, $floatValue, $force = false)
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id > 0 && ($force || abs(GetValueFloat($id) - $floatValue) > 0.0001)) {
            SetValueFloat($id, $floatValue);
        }
    }

    private function UpdateVariableString($ident, $stringValue)
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id > 0 && GetValueString($id) !== $stringValue) {
            SetValueString($id, $stringValue);
        }
    }

    private function LedColors()
    {
        return array(
            'RED', 'ORANGE', 'GOLD', 'YELLOW', 'YELLOWGREEN', 'GREEN',
            'TURQUOISE', 'POWDERBLUE', 'CYAN', 'SKYBLUE', 'BLUE', 'PURPLE',
            'LIGHTPURPLE', 'VIOLET', 'ORCHID', 'PINK', 'WHITE'
        );
    }

    private function ColorToIndex($color)
    {
        $idx = array_search(strtoupper(trim((string)$color)), $this->LedColors(), true);
        return ($idx === false) ? -1 : (int)$idx;
    }

    private function ColorFromIndex($index)
    {
        $colors = $this->LedColors();
        return isset($colors[$index]) ? $colors[$index] : '';
    }

    private function LedStates()
    {
        return array('OFF', 'ON', 'FLASHING');
    }

    private function LedStateToIndex($state)
    {
        $idx = array_search(strtoupper(trim((string)$state)), $this->LedStates(), true);
        return ($idx === false) ? -1 : (int)$idx;
    }

    private function LedStateFromIndex($index)
    {
        $states = $this->LedStates();
        return isset($states[$index]) ? $states[$index] : '';
    }

    private function MarkOnline()
    {
        $tsId = @$this->GetIDForIdent('LastOKTimestamp');
        if ($tsId > 0) {
            SetValueInteger($tsId, time());
        }

        $onlineId = @$this->GetIDForIdent('Online');
        if ($onlineId > 0 && !GetValueBoolean($onlineId)) {
            SetValueBoolean($onlineId, true);
        }

        $errId = @$this->GetIDForIdent('LastError');
        if ($errId > 0 && GetValueString($errId) !== '') {
            SetValueString($errId, '');
        }
    }

    private function MarkOfflineIfStale()
    {
        $tsId = @$this->GetIDForIdent('LastOKTimestamp');
        if ($tsId <= 0) {
            return;
        }

        $last = (int)GetValueInteger($tsId);
        $pollSlow = (int)$this->ReadPropertyInteger('PollSlow');
        $pollFast = (int)$this->ReadPropertyInteger('PollFast');
        $timeout = max(10, max($pollSlow, $pollFast) * 3);

        if ($last <= 0 || (time() - $last) > $timeout) {
            $onlineId = @$this->GetIDForIdent('Online');
            if ($onlineId > 0 && GetValueBoolean($onlineId)) {
                SetValueBoolean($onlineId, false);
            }
            $errId = @$this->GetIDForIdent('LastError');
            if ($errId > 0 && GetValueString($errId) === '') {
                SetValueString($errId, 'Keine Antwort vom Geraet');
            }
        }
    }

    private function TrimBraces($value)
    {
        $value = trim((string)$value);
        if (strlen($value) >= 2 && $value[0] == '{' && substr($value, -1) == '}') {
            return trim(substr($value, 1, -1));
        }
        return $value;
    }

    private function ParseFirstRepValue($raw)
    {
        if (!preg_match_all('/<\s*REP\s+(.+?)\s*>/i', $raw, $matches)) {
            return '';
        }

        foreach ($matches[1] as $payload) {
            $payload = trim($payload);
            if ($payload === '') continue;
            $parts = preg_split('/\s+/', $payload, 2);
            if (count($parts) == 0) continue;
            if (strtoupper($parts[0]) === 'ERR') continue;
            if (count($parts) >= 2) return trim($parts[1]);
            return '';
        }

        return '';
    }

    private function SendCommand($cmd, $context)
    {
        if (!$this->IsParentConnected()) {
            $this->SetCommError('Socket nicht verbunden');
            return false;
        }

        try {
            $json = json_encode(array('DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}', 'Buffer' => $cmd . "\r\n"));
            $this->SendDataToParent($json);
            $this->SendDebug('MXA920 TX', $cmd . ' (' . $context . ')', 0);
            return true;
        } catch (Exception $e) {
            $this->SetCommError('SendCommand failed: ' . $e->getMessage());
            return false;
        }
    }

    private function SendCommandSync($cmd)
    {
        $host = $this->ReadPropertyString('Host');
        $port = (int)$this->ReadPropertyInteger('Port');
        $timeout = (int)$this->ReadPropertyInteger('TimeoutMs');

        $sock = @fsockopen($host, $port, $errno, $errstr, $timeout / 1000.0);
        if (!$sock) return '';

        stream_set_timeout($sock, 0, $timeout * 1000);

        fwrite($sock, $cmd . "\r\n");
        usleep(50000);

        $response = '';
        while (!feof($sock)) {
            $chunk = fgets($sock, 1024);
            if ($chunk === false) break;
            $response .= $chunk;
            if (strlen($response) > 4096) break;
        }

        fclose($sock);

        return $this->ParseFirstRepValue($response);
    }

    private function EnsureParentClientSocket()
    {
        $host = (string)$this->ReadPropertyString('Host');
        $port = (int)$this->ReadPropertyInteger('Port');

        $inst = IPS_GetInstance($this->InstanceID);
        $parentID = isset($inst['ConnectionID']) ? (int)$inst['ConnectionID'] : 0;
        $oldParentID = $parentID;
        $targetID = $this->FindSharedClientSocket($host, $port);

        if ($targetID > 0 && $targetID !== $parentID && function_exists('IPS_ConnectInstance')) {
            @IPS_ConnectInstance($this->InstanceID, $targetID);
            $parentID = $targetID;
            $this->CleanupOrphanedParent($oldParentID);
        }

        $configureID = 0;
        if ($targetID > 0) {
            $configureID = $parentID;
        } elseif ($this->ParentMatchesHost($parentID, $host, $port)) {
            $configureID = $parentID;
        } elseif ($this->ParentNeedsConfig($parentID)) {
            $configureID = $parentID;
        } elseif ($this->ParentIsExclusive($parentID)) {
            $this->SendDebug('MXA920', 'Rekonfiguriere Socket fuer ' . $host . ':' . $port, 0);
            $configureID = $parentID;
        } else {
            $newParentID = $this->CreateClientSocket($host, $port);
            if ($newParentID > 0 && function_exists('IPS_ConnectInstance')) {
                $oldParentID = $parentID;
                @IPS_ConnectInstance($this->InstanceID, $newParentID);
                $this->CleanupOrphanedParent($oldParentID);
                $this->SendDebug('MXA920', 'Neuen Socket erstellt: ' . $newParentID, 0);
            } else {
                $this->SendDebug('MXA920', 'Kein passender Socket fuer ' . $host . ':' . $port, 0);
            }
            return;
        }

        if ($configureID > 0 && IPS_InstanceExists($configureID)) {
            IPS_SetProperty($configureID, 'Host', $host);
            IPS_SetProperty($configureID, 'Port', $port);

            $canConnect = $this->TestTcp($host, $port, 300);
            $this->SendDebug('MXA920', 'AutoOpen=' . ($canConnect ? 'true' : 'false') . ' for ' . $host . ':' . $port, 0);

            $this->CallSilenced(function () use ($configureID, $canConnect) {
                IPS_SetProperty($configureID, 'Open', $canConnect);
            });
            $this->CallSilenced(function () use ($configureID) {
                IPS_ApplyChanges($configureID);
            });

            $this->ConsolidateSockets($host, $port, $parentID);
        }
    }

    private function ParentMatchesHost($parentID, $host, $port)
    {
        if ($parentID <= 0 || !IPS_InstanceExists($parentID)) {
            return false;
        }
        $h = (string)IPS_GetProperty($parentID, 'Host');
        $p = (int)IPS_GetProperty($parentID, 'Port');
        return ($h === $host && $p === $port);
    }

    private function ParentNeedsConfig($parentID)
    {
        if ($parentID <= 0 || !IPS_InstanceExists($parentID)) {
            return false;
        }
        $h = trim((string)IPS_GetProperty($parentID, 'Host'));
        $p = (int)IPS_GetProperty($parentID, 'Port');
        return ($h === '' || $h === '0.0.0.0' || $p <= 0);
    }

    private function ParentIsExclusive($parentID)
    {
        if ($parentID <= 0 || !IPS_InstanceExists($parentID)) {
            return false;
        }
        $children = $this->GetChildrenByConnection($parentID);
        foreach ($children as $child) {
            if ((int)$child !== (int)$this->InstanceID) {
                return false;
            }
        }
        return true;
    }

    private function CleanupOrphanedParent($parentID)
    {
        if ($parentID <= 0 || !IPS_InstanceExists($parentID)) {
            return;
        }
        $inst = IPS_GetInstance($parentID);
        if (!is_array($inst) || !isset($inst['ModuleInfo']['ModuleID'])) {
            return;
        }
        if (strtoupper($inst['ModuleInfo']['ModuleID']) !== '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}') {
            return;
        }

        $children = $this->GetChildrenByConnection($parentID);
        if (count($children) > 0) {
            return;
        }

        @IPS_DeleteInstance($parentID);
    }

    private function ConsolidateSockets($host, $port, $currentParentID)
    {
        $ids = $this->FindSocketsByHostPort($host, $port);
        if (count($ids) <= 1) {
            return;
        }

        sort($ids, SORT_NUMERIC);
        $primaryID = $ids[0];

        if ($currentParentID > 0 && $currentParentID !== $primaryID && function_exists('IPS_ConnectInstance')) {
            $oldParentID = $currentParentID;
            @IPS_ConnectInstance($this->InstanceID, $primaryID);
            $this->CleanupOrphanedParent($oldParentID);
        }
    }

    private function CreateClientSocket($host, $port)
    {
        if (!function_exists('IPS_CreateInstance')) {
            return 0;
        }

        $id = @IPS_CreateInstance('{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}');
        if ($id <= 0) {
            return 0;
        }

        IPS_SetProperty($id, 'Host', $host);
        IPS_SetProperty($id, 'Port', $port);

        $canConnect = $this->TestTcp($host, $port, 300);
        IPS_SetProperty($id, 'Open', $canConnect);
        IPS_ApplyChanges($id);

        return $id;
    }

    private function FindSocketsByHostPort($host, $port)
    {
        $ids = IPS_GetInstanceListByModuleID('{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}');
        $result = array();
        foreach ($ids as $id) {
            $h = (string)IPS_GetProperty($id, 'Host');
            $p = (int)IPS_GetProperty($id, 'Port');
            if ($h === $host && $p === $port) {
                $result[] = $id;
            }
        }
        return $result;
    }

    private function GetChildrenByConnection($parentID)
    {
        $children = array();
        $ids = IPS_GetInstanceList();
        foreach ($ids as $id) {
            $inst = IPS_GetInstance($id);
            if (isset($inst['ConnectionID']) && (int)$inst['ConnectionID'] === (int)$parentID) {
                $children[] = $id;
            }
        }
        return $children;
    }

    private function FindSharedClientSocket($host, $port)
    {
        $moduleID = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
        $ids = IPS_GetInstanceListByModuleID($moduleID);
        foreach ($ids as $id) {
            if ($id === $this->GetParentInstanceID()) {
                continue;
            }
            $h = (string)IPS_GetProperty($id, 'Host');
            $p = (int)IPS_GetProperty($id, 'Port');
            if ($h === $host && $p === $port) {
                return $id;
            }
        }
        return 0;
    }

    private function IsParentConnected()
    {
        $parentID = $this->GetParentInstanceID();
        if ($parentID <= 0 || !IPS_InstanceExists($parentID)) {
            return false;
        }

        $st = IPS_GetInstance($parentID);
        if (!is_array($st) || !isset($st['InstanceStatus'])) {
            return false;
        }
        return ((int)$st['InstanceStatus'] == 102);
    }

    private function GetParentInstanceID()
    {
        $inst = IPS_GetInstance($this->InstanceID);
        if (!is_array($inst) || !isset($inst['ConnectionID'])) {
            return 0;
        }
        return (int)$inst['ConnectionID'];
    }

    private function SetCommError($message)
    {
        $this->SendDebug('MXA920 ERR', $message, 0);

        $onlineId = @$this->GetIDForIdent('Online');
        if ($onlineId > 0) {
            SetValueBoolean($onlineId, false);
        }

        $errId = @$this->GetIDForIdent('LastError');
        if ($errId > 0) {
            SetValueString($errId, $message);
        }

        $this->TryReconnectParent($message);
    }

    private function TryReconnectParent($reason)
    {
        $parentID = $this->GetParentInstanceID();
        if ($parentID <= 0 || !IPS_InstanceExists($parentID)) {
            return;
        }

        $now = time();
        $last = (int)$this->GetBuffer('LastReconnect');
        if (($now - $last) < 10) {
            return;
        }

        $this->SetBuffer('LastReconnect', (string)$now);
        $this->SendDebug('MXA920', 'Reconnect attempt: ' . $reason, 0);

        $host = (string)$this->ReadPropertyString('Host');
        $port = (int)$this->ReadPropertyInteger('Port');
        if ($this->TestTcp($host, $port, 300)) {
            $this->CallSilenced(function () use ($parentID) {
                IPS_SetProperty($parentID, 'Open', true);
            });
            $this->CallSilenced(function () use ($parentID) {
                IPS_ApplyChanges($parentID);
            });
        }
    }

    private function TestTcp($host, $port, $timeout)
    {
        $sock = @fsockopen($host, $port, $errno, $errstr, $timeout / 1000.0);
        if ($sock) {
            fclose($sock);
            return true;
        }
        return false;
    }

    private function CallSilenced($callback)
    {
        set_error_handler(function () {
        });
        try {
            $callback();
        } catch (Exception $e) {
        }
        restore_error_handler();
    }

    private function UpdatePollingInterval($fast)
    {
        if ($fast) {
            $duration = (int)$this->ReadPropertyInteger('FastAfterChangeSeconds');
            $this->SetBuffer('FastUntil', (string)(time() + $duration));
        }

        $fastUntil = (int)$this->GetBuffer('FastUntil');
        $isFast = (time() < $fastUntil);

        if ($isFast) {
            $interval = (int)$this->ReadPropertyInteger('PollFast');
        } else {
            $interval = (int)$this->ReadPropertyInteger('PollSlow');
        }

        $this->SetTimerInterval('PollTimer', $interval * 1000);
    }

    private function AddPending($ident, $value)
    {
        $map = json_decode($this->GetBuffer('PendingMap'), true);
        if (!is_array($map)) $map = array();
        $map[$ident] = $value;
        $this->SetBuffer('PendingMap', json_encode($map));

        $tsMap = json_decode($this->GetBuffer('PendingTSMap'), true);
        if (!is_array($tsMap)) $tsMap = array();
        $tsMap[$ident] = time();
        $this->SetBuffer('PendingTSMap', json_encode($tsMap));
    }

    private function IsPending($ident)
    {
        $map = json_decode($this->GetBuffer('PendingMap'), true);
        if (!is_array($map)) return false;
        return isset($map[$ident]);
    }

    private function GetPending($ident)
    {
        $map = json_decode($this->GetBuffer('PendingMap'), true);
        if (!is_array($map)) return null;
        return isset($map[$ident]) ? $map[$ident] : null;
    }

    private function ClearPending($ident)
    {
        $map = json_decode($this->GetBuffer('PendingMap'), true);
        if (!is_array($map)) $map = array();
        unset($map[$ident]);
        $this->SetBuffer('PendingMap', json_encode($map));

        $tsMap = json_decode($this->GetBuffer('PendingTSMap'), true);
        if (!is_array($tsMap)) $tsMap = array();
        unset($tsMap[$ident]);
        $this->SetBuffer('PendingTSMap', json_encode($tsMap));
    }

    private function CleanupOldPending()
    {
        $tsMap = json_decode($this->GetBuffer('PendingTSMap'), true);
        if (!is_array($tsMap)) return;

        $timeout = 10;
        $now = time();

        foreach ($tsMap as $ident => $ts) {
            if (($now - $ts) > $timeout) {
                $this->ClearPending($ident);
                $this->SendDebug('MXA920', 'Pending timeout: ' . $ident, 0);
            }
        }
    }

    private function CreateProfiles()
    {
        if (!IPS_VariableProfileExists('MXA920.Gain')) {
            IPS_CreateVariableProfile('MXA920.Gain', 2);
        }
        IPS_SetVariableProfileValues('MXA920.Gain', -110, 30, 0.5);
        IPS_SetVariableProfileDigits('MXA920.Gain', 1);
        IPS_SetVariableProfileText('MXA920.Gain', '', ' dB');

        if (!IPS_VariableProfileExists('MXA920.Preset')) {
            IPS_CreateVariableProfile('MXA920.Preset', 1);
            IPS_SetVariableProfileValues('MXA920.Preset', 0, 10, 1);
            IPS_SetVariableProfileAssociation('MXA920.Preset', 0, 'Kein Preset', '', -1);
            for ($i = 1; $i <= 10; $i++) {
                IPS_SetVariableProfileAssociation('MXA920.Preset', $i, 'Preset ' . $i, '', -1);
            }
        }

        if (!IPS_VariableProfileExists('MXA920.Level')) {
            IPS_CreateVariableProfile('MXA920.Level', 1);
            IPS_SetVariableProfileValues('MXA920.Level', -60, 0, 1);
            IPS_SetVariableProfileText('MXA920.Level', '', ' dBFS');
        }

        if (!IPS_VariableProfileExists('MXA920.CoverageArea')) {
            IPS_CreateVariableProfile('MXA920.CoverageArea', 1);
            IPS_SetVariableProfileValues('MXA920.CoverageArea', 0, 8, 1);
            IPS_SetVariableProfileAssociation('MXA920.CoverageArea', 0, '-', '', -1);
            for ($i = 1; $i <= 8; $i++) {
                IPS_SetVariableProfileAssociation('MXA920.CoverageArea', $i, 'Area ' . $i, '', -1);
            }
        }

        if (!IPS_VariableProfileExists('MXA920.Position')) {
            IPS_CreateVariableProfile('MXA920.Position', 1);
            IPS_SetVariableProfileText('MXA920.Position', '', ' cm');
        }

        if (!IPS_VariableProfileExists('MXA920.LEDBrightness')) {
            IPS_CreateVariableProfile('MXA920.LEDBrightness', 1);
            IPS_SetVariableProfileValues('MXA920.LEDBrightness', 0, 5, 1);
            IPS_SetVariableProfileAssociation('MXA920.LEDBrightness', 0, 'Aus', '', -1);
            IPS_SetVariableProfileAssociation('MXA920.LEDBrightness', 1, '20 %', '', -1);
            IPS_SetVariableProfileAssociation('MXA920.LEDBrightness', 2, '40 %', '', -1);
            IPS_SetVariableProfileAssociation('MXA920.LEDBrightness', 3, '60 %', '', -1);
            IPS_SetVariableProfileAssociation('MXA920.LEDBrightness', 4, '80 %', '', -1);
            IPS_SetVariableProfileAssociation('MXA920.LEDBrightness', 5, '100 %', '', -1);
        }

        if (!IPS_VariableProfileExists('MXA920.LEDColor')) {
            IPS_CreateVariableProfile('MXA920.LEDColor', 1);
            IPS_SetVariableProfileValues('MXA920.LEDColor', 0, 16, 1);
            $labels = array(
                'Rot', 'Orange', 'Gold', 'Gelb', 'Gelbgruen', 'Gruen',
                'Tuerkis', 'Hellblau', 'Cyan', 'Himmelblau', 'Blau', 'Lila',
                'Helllila', 'Violett', 'Orchidee', 'Pink', 'Weiss'
            );
            for ($i = 0; $i < count($labels); $i++) {
                IPS_SetVariableProfileAssociation('MXA920.LEDColor', $i, $labels[$i], '', -1);
            }
        }

        if (!IPS_VariableProfileExists('MXA920.LEDState')) {
            IPS_CreateVariableProfile('MXA920.LEDState', 1);
            IPS_SetVariableProfileValues('MXA920.LEDState', 0, 2, 1);
            IPS_SetVariableProfileAssociation('MXA920.LEDState', 0, 'Aus', '', -1);
            IPS_SetVariableProfileAssociation('MXA920.LEDState', 1, 'An', '', -1);
            IPS_SetVariableProfileAssociation('MXA920.LEDState', 2, 'Blinkt', '', -1);
        }
    }
}
