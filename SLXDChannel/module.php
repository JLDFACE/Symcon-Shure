<?php

class SLXDChannel extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RequireParent('{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}');

        $this->RegisterPropertyString('Host', '192.168.1.100');
        $this->RegisterPropertyInteger('Port', 2202);
        $this->RegisterPropertyInteger('Channel', 1);
        $this->RegisterPropertyString('DeviceFamily', 'auto');
        $this->RegisterPropertyString('ModelHint', '');

        $this->RegisterPropertyInteger('PollSlow', 15);
        $this->RegisterPropertyInteger('PollFast', 3);
        $this->RegisterPropertyInteger('FastAfterChangeSeconds', 30);
        $this->RegisterPropertyInteger('TimeoutMs', 1500);

        $this->RegisterTimer('PollTimer', 15000, 'SLXD_PollNow($_IPS[\'TARGET\']);');

        $this->SetBuffer('FastUntil', '0');
        $this->SetBuffer('RxBuf', '');
        $this->SetBuffer('PendingMap', '{}');
        $this->SetBuffer('PendingTSMap', '{}');
        $this->SetBuffer('DeviceID', '');
        $this->SetBuffer('Model', '');
        $this->SetBuffer('Rssi1', '');
        $this->SetBuffer('Rssi2', '');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterVariableBoolean('Online', 'Online', '~Alert.Reversed', 1);
        $this->RegisterVariableBoolean('Verbunden', 'Verbunden', 'SLXD.Connected', 2);
        $this->RegisterVariableString('LastError', 'LastError', '', 3);
        $this->RegisterVariableInteger('LastOKTimestamp', 'LastOKTimestamp', '~UnixTimestamp', 4);

        $ch = (int)$this->ReadPropertyInteger('Channel');

        $this->CreateProfiles();

        $this->RegisterVariableBoolean('Mute', 'Audio Mute', '~Switch', 10);
        $this->EnableAction('Mute');

        $this->RegisterVariableInteger('Gain', 'Audio Gain', 'SLXD.Gain', 11);
        $this->EnableAction('Gain');

        $freqId = @$this->GetIDForIdent('Frequency');
        if ($freqId > 0) {
            $freqVar = IPS_GetVariable($freqId);
            if (is_array($freqVar) && isset($freqVar['VariableType']) && (int)$freqVar['VariableType'] != 2) {
                IPS_DeleteVariable($freqId);
            }
        }
        $this->RegisterVariableFloat('Frequency', 'Frequency', 'SLXD.Frequency', 20);

        $this->RegisterVariableString('ChannelName', 'Channel Name', '', 21);

        $this->RegisterVariableInteger('Battery', 'Battery', 'SLXD.Battery', 30);
        $this->RegisterVariableInteger('BatteryMinutes', 'Battery Minutes', 'SLXD.BatteryMinutes', 31);
        $this->RegisterVariableInteger('RFLevel', 'RF Level', 'SLXD.RFLevel', 32);

        $this->RegisterVariableString('TXModel', 'TX Model', '', 40);
        $this->PrimeModelHint();
        $txId = @$this->GetIDForIdent('TXModel');
        if ($txId > 0) {
            $this->UpdateTxConnected(GetValueString($txId));
        }

        $this->EnsureParentClientSocket();

        $this->UpdatePollingInterval(false);

        $this->PollNow();

        $this->SetStatus(102);
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Mute') {
            $this->SetMute((bool)$Value);
        } elseif ($Ident === 'Gain') {
            $this->SetGain((int)$Value);
        }
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data['Buffer'])) return;

        $buffer = (string)$data['Buffer'];
        if ($buffer === '') return;

        $this->SendDebug('SLXD RX', $buffer, 0);

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
        $ch = (int)$this->ReadPropertyInteger('Channel');

        if (!$this->IsParentConnected()) {
            $this->SetCommError('Socket nicht verbunden');
            return;
        }

        $this->SendCommand("< GET " . $ch . " AUDIO_MUTE >", 'Mute');
        $this->SendCommand("< GET " . $ch . " AUDIO_GAIN >", 'Gain');
        $this->SendCommand("< GET " . $ch . " FREQUENCY >", 'Frequency');
        $this->SendCommand("< GET " . $ch . " CHAN_NAME >", 'ChannelName');
        $family = $this->GetDeviceFamily();
        if ($family === 'ulxd') {
            $this->SendCommand("< GET " . $ch . " BATT_BARS >", 'Battery');
            $this->SendCommand("< GET " . $ch . " BATT_CHARGE >", 'Battery');
            $this->SendCommand("< GET " . $ch . " BATT_RUN_TIME >", 'BatteryMinutes');
            $this->SendCommand("< GET " . $ch . " RX_RF_LVL >", 'RFLevel');
            $this->SendCommand("< GET " . $ch . " TX_TYPE >", 'TXModel');
        } else {
            $this->SendCommand("< GET " . $ch . " TX_BATT_BARS >", 'Battery');
            $this->SendCommand("< GET " . $ch . " TX_BATT_MINS >", 'BatteryMinutes');
            $this->SendCommand("< GET " . $ch . " RSSI >", 'RFLevel');
            $this->SendCommand("< GET " . $ch . " TX_MODEL >", 'TXModel');
        }

        SetValueInteger($this->GetIDForIdent('LastOKTimestamp'), time());
        SetValueBoolean($this->GetIDForIdent('Online'), true);
        SetValueString($this->GetIDForIdent('LastError'), '');

        $this->CleanupOldPending();
    }

    public function ReadDeviceID(): string
    {
        $resp = $this->TrimBraces($this->SendCommandSync("< GET DEVICE_ID >"));
        if ($resp !== '') {
            $this->SetBuffer('DeviceID', $resp);
        }
        IPS_LogMessage('SLXD', 'Device ID: ' . $resp);
        return $resp;
    }

    public function SetMute(bool $mute)
    {
        $ch = (int)$this->ReadPropertyInteger('Channel');
        $val = $mute ? 'ON' : 'OFF';
        if (!$this->SendCommand("< SET " . $ch . " AUDIO_MUTE " . $val . " >", 'Mute')) {
            return;
        }

        $this->AddPending('Mute', $mute ? 1 : 0);
        $this->UpdatePollingInterval(true);
    }

    public function SetGain(int $gain)
    {
        $ch = (int)$this->ReadPropertyInteger('Channel');
        $g = max(-18, min(42, (int)$gain));
        $raw = $this->GainDbToRaw($g);
        if (!$this->SendCommand("< SET " . $ch . " AUDIO_GAIN " . $raw . " >", 'Gain')) {
            return;
        }

        $this->AddPending('Gain', $g);
        $this->UpdatePollingInterval(true);
    }

    private function ProcessLine($line)
    {
        if (!preg_match('/^<\s*REP\s+(\d+)\s+([A-Z_]+)\s*(.*?)\s*>$/i', $line, $m)) {
            return;
        }

        $repCh = (int)$m[1];
        $param = strtoupper(trim($m[2]));
        $value = trim($m[3]);

        $myCh = (int)$this->ReadPropertyInteger('Channel');
        if ($repCh !== $myCh) return;

        $this->SendDebug('SLXD REP', "CH" . $repCh . " " . $param . "=" . $value, 0);

        switch ($param) {
            case 'AUDIO_MUTE':
                $val = (strtoupper($value) === 'ON') ? 1 : 0;
                $this->UpdateVariable('Mute', $val);
                break;
            case 'AUDIO_GAIN':
                $val = $this->GainRawToDb((int)$value);
                $this->UpdateVariable('Gain', $val);
                break;
            case 'FREQUENCY':
                $val = $this->ParseFrequency($value);
                if ($val !== null) {
                    $this->UpdateVariableFloat('Frequency', $val);
                }
                break;
            case 'CHAN_NAME':
                $val = $this->TrimBraces($value);
                $this->UpdateVariableString('ChannelName', $val);
                $this->UpdateInstanceName($val);
                break;
            case 'BATT_CHARGE':
                $val = $this->ParseNumericValue($value);
                if ($val !== null) {
                    $this->UpdateVariable('Battery', $val);
                }
                break;
            case 'BATT_BARS':
                $val = $this->ParseNumericValue($value);
                if ($val !== null) {
                    $percent = $this->BatteryBarsToPercent($val);
                    if ($percent !== null) {
                        $this->UpdateVariable('Battery', $percent);
                    }
                }
                break;
            case 'TX_BATT_BARS':
                $val = $this->ParseNumericValue($value);
                if ($val !== null) {
                    $percent = $this->BatteryBarsToPercent($val);
                    if ($percent !== null) {
                        $this->UpdateVariable('Battery', $percent);
                    }
                }
                break;
            case 'BATT_RUN_TIME':
                $val = $this->ParseNumericValue($value);
                if ($val !== null && $val < 65534) {
                    $this->UpdateVariable('BatteryMinutes', $val);
                }
                break;
            case 'TX_BATT_MINS':
                $val = $this->ParseNumericValue($value);
                if ($val !== null && $val < 65534) {
                    $this->UpdateVariable('BatteryMinutes', $val);
                }
                break;
            case 'RF_LEVEL':
                $val = $this->ParseNumericValue($value);
                if ($val !== null) {
                    $this->UpdateVariable('RFLevel', $val);
                }
                break;
            case 'TX_RF_LEVEL':
                $val = $this->ParseNumericValue($value);
                if ($val !== null) {
                    $this->UpdateVariable('RFLevel', $val);
                }
                break;
            case 'RX_RF_LVL':
                $this->UpdateRssiFromValue($value, -128, false);
                break;
            case 'RSSI':
                $this->UpdateRssiFromValue($value, -120, true);
                break;
            case 'TX_MODEL':
                $val = $this->TrimBraces($value);
                $this->UpdateVariableString('TXModel', $val);
                $this->UpdateTxConnected($val);
                break;
            case 'TX_TYPE':
                $val = $this->TrimBraces($value);
                $this->UpdateVariableString('TXModel', $val);
                $this->UpdateTxConnected($val);
                break;
        }
    }

    private function UpdateVariable($ident, $intValue)
    {
        if (!$this->IsPending($ident)) {
            $id = @$this->GetIDForIdent($ident);
            if ($id > 0) {
                $current = GetValueInteger($id);
                if ($current !== $intValue) {
                    SetValueInteger($id, $intValue);
                }
            }
        } else {
            $pending = $this->GetPending($ident);
            if ($pending === $intValue) {
                $this->ClearPending($ident);
                $id = @$this->GetIDForIdent($ident);
                if ($id > 0) {
                    SetValueInteger($id, $intValue);
                }
            }
        }
    }

    private function UpdateVariableString($ident, $stringValue)
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id > 0) {
            $current = GetValueString($id);
            if ($current !== $stringValue) {
                SetValueString($id, $stringValue);
            }
        }
    }

    private function UpdateTxConnected($txModel)
    {
        $val = strtoupper(trim((string)$txModel));
        $connected = ($val !== '' && $val !== 'UNKNOWN' && $val !== 'UNKN');

        $id = @$this->GetIDForIdent('Verbunden');
        if ($id > 0) {
            $current = GetValueBoolean($id);
            if ($current !== $connected) {
                SetValueBoolean($id, $connected);
            }
        }
    }

    private function UpdateVariableFloat($ident, $floatValue)
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id > 0) {
            $current = GetValueFloat($id);
            if (abs($current - $floatValue) > 0.0001) {
                SetValueFloat($id, $floatValue);
            }
        }
    }

    private function PrimeModelHint()
    {
        $hint = trim((string)$this->ReadPropertyString('ModelHint'));
        if ($hint === '') return;

        $current = trim((string)$this->GetBuffer('Model'));
        if ($current === '') {
            $this->SetBuffer('Model', $hint);
        }
    }

    private function GetDeviceFamily()
    {
        $family = $this->NormalizeFamily($this->ReadPropertyString('DeviceFamily'));
        if ($family !== 'auto') {
            return $family;
        }

        $model = trim((string)$this->GetBuffer('Model'));
        if ($model === '') {
            $model = trim((string)$this->ReadPropertyString('ModelHint'));
        }
        $family = $this->DetectFamilyFromModel($model);
        if ($family !== '') {
            return $family;
        }

        $txId = @$this->GetIDForIdent('TXModel');
        if ($txId > 0) {
            $family = $this->DetectFamilyFromModel(GetValueString($txId));
            if ($family !== '') {
                return $family;
            }
        }

        return 'slxd';
    }

    private function NormalizeFamily($family)
    {
        $family = strtolower(trim((string)$family));
        if ($family === 'qlxd') return 'ulxd';
        if ($family === 'ulxd' || $family === 'slxd') return $family;
        return 'auto';
    }

    private function DetectFamilyFromModel($model)
    {
        $m = strtoupper($this->TrimBraces((string)$model));
        if ($m === '') return '';
        if (strpos($m, 'ULXD') !== false || strpos($m, 'QLXD') !== false) return 'ulxd';
        if (strpos($m, 'SLXD') !== false || strpos($m, 'SLX') !== false) return 'slxd';
        return '';
    }

    private function TrimBraces($value)
    {
        $value = trim((string)$value);
        if (strlen($value) >= 2 && $value[0] == '{' && substr($value, -1) == '}') {
            return trim(substr($value, 1, -1));
        }
        return $value;
    }

    private function ParseFrequency($value)
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        if (strpos($value, '.') !== false) {
            return (float)$value;
        }
        return ((int)$value) / 1000.0;
    }

    private function UpdateRssiFromValue($value, $offset, $useBuffers)
    {
        $parts = preg_split('/\s+/', trim((string)$value));
        if (count($parts) >= 2) {
            $ant = $this->ParseNumericValue($parts[0]);
            $raw = $this->ParseNumericValue($parts[1]);
            if ($raw === null) {
                return;
            }
            if ($useBuffers && ($ant === 1 || $ant === 2)) {
                if ($ant === 1) {
                    $this->SetBuffer('Rssi1', (string)$raw);
                } else {
                    $this->SetBuffer('Rssi2', (string)$raw);
                }

                $rssi1 = $this->GetBufferedIntOrNull('Rssi1');
                $rssi2 = $this->GetBufferedIntOrNull('Rssi2');
                if ($rssi1 === null && $rssi2 === null) {
                    return;
                }

                $bestRaw = ($rssi1 === null) ? $rssi2 : (($rssi2 === null) ? $rssi1 : max($rssi1, $rssi2));
                $this->UpdateVariable('RFLevel', $this->RssiRawToDbm($bestRaw, $offset));
                return;
            }

            $this->UpdateVariable('RFLevel', $this->RssiRawToDbm($raw, $offset));
            return;
        }

        if (count($parts) >= 1) {
            $raw = $this->ParseNumericValue($parts[0]);
            if ($raw === null) {
                return;
            }
            $this->UpdateVariable('RFLevel', $this->RssiRawToDbm($raw, $offset));
        }
    }

    private function GetBufferedIntOrNull($name)
    {
        $raw = (string)$this->GetBuffer($name);
        if ($raw === '') return null;
        return (int)$raw;
    }

    private function RssiRawToDbm($raw, $offset)
    {
        return (int)$raw + (int)$offset;
    }

    private function BatteryBarsToPercent($bars)
    {
        if ($bars < 0) return null;
        if ($bars <= 5) return $bars * 20;
        if ($bars <= 100) return $bars;
        return null;
    }

    private function ParseNumericValue($value)
    {
        $value = $this->TrimBraces($value);
        if ($value === '') return null;
        if (preg_match('/-?\d+(?:\.\d+)?/', $value, $m)) {
            return (int)round((float)$m[0]);
        }

        $this->SendDebug('SLXD PARSE', 'Unparseable numeric value: ' . $value, 0);
        return null;
    }

    private function GainRawToDb($raw)
    {
        return (int)$raw - 18;
    }

    private function GainDbToRaw($db)
    {
        return (int)$db + 18;
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

    private function UpdateInstanceName($channelName)
    {
        $channelName = trim((string)$channelName);
        if ($channelName === '') return;

        $prefix = $this->GetNamePrefix();
        $name = $prefix . ' "' . $channelName . '"';
        @IPS_SetName($this->InstanceID, $name);
    }

    private function GetNamePrefix()
    {
        $model = trim((string)$this->GetBuffer('Model'));
        if ($model !== '') return $model;

        $deviceID = trim((string)$this->GetBuffer('DeviceID'));
        if ($deviceID !== '') return $deviceID;

        $host = trim((string)$this->ReadPropertyString('Host'));
        return ($host !== '') ? $host : 'SLXD';
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
            $this->SendDebug('SLXD TX', $cmd . ' (' . $context . ')', 0);
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

        if ($parentID > 0 && IPS_InstanceExists($parentID)) {
            IPS_SetProperty($parentID, 'Host', $host);
            IPS_SetProperty($parentID, 'Port', $port);

            $canConnect = $this->TestTcp($host, $port, 500);
            $this->SendDebug('SLXD', 'AutoOpen=' . ($canConnect ? 'true' : 'false') . ' for ' . $host . ':' . $port, 0);

            $this->CallSilenced(function () use ($parentID, $canConnect) {
                IPS_SetProperty($parentID, 'Open', $canConnect);
            });

            $this->CallSilenced(function () use ($parentID) {
                IPS_ApplyChanges($parentID);
            });
        }
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
        $this->SendDebug('SLXD ERR', $message, 0);

        $onlineId = @$this->GetIDForIdent('Online');
        if ($onlineId > 0) {
            SetValueBoolean($onlineId, false);
        }

        $errId = @$this->GetIDForIdent('LastError');
        if ($errId > 0) {
            SetValueString($errId, $message);
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
                $this->SendDebug('SLXD', 'Pending timeout: ' . $ident, 0);
            }
        }
    }

    private function CreateProfiles()
    {
        if (!IPS_VariableProfileExists('SLXD.Connected')) {
            IPS_CreateVariableProfile('SLXD.Connected', 0);
            IPS_SetVariableProfileAssociation('SLXD.Connected', 0, 'nicht verbunden', '', 0);
            IPS_SetVariableProfileAssociation('SLXD.Connected', 1, 'verbunden', '', 0);
        }

        if (!IPS_VariableProfileExists('SLXD.Gain')) {
            IPS_CreateVariableProfile('SLXD.Gain', 1);
        }
        IPS_SetVariableProfileValues('SLXD.Gain', -18, 42, 1);
        IPS_SetVariableProfileText('SLXD.Gain', '', ' dB');

        if (IPS_VariableProfileExists('SLXD.Frequency')) {
            $profile = IPS_GetVariableProfile('SLXD.Frequency');
            if (is_array($profile) && isset($profile['ProfileType']) && (int)$profile['ProfileType'] != 2) {
                IPS_DeleteVariableProfile('SLXD.Frequency');
            }
        }
        if (!IPS_VariableProfileExists('SLXD.Frequency')) {
            IPS_CreateVariableProfile('SLXD.Frequency', 2);
        }
        IPS_SetVariableProfileText('SLXD.Frequency', '', ' MHz');

        if (!IPS_VariableProfileExists('SLXD.Battery')) {
            IPS_CreateVariableProfile('SLXD.Battery', 1);
            IPS_SetVariableProfileValues('SLXD.Battery', 0, 100, 1);
            IPS_SetVariableProfileText('SLXD.Battery', '', ' %');
        }

        if (!IPS_VariableProfileExists('SLXD.BatteryMinutes')) {
            IPS_CreateVariableProfile('SLXD.BatteryMinutes', 1);
            IPS_SetVariableProfileValues('SLXD.BatteryMinutes', 0, 10000, 1);
            IPS_SetVariableProfileText('SLXD.BatteryMinutes', '', ' min');
        }

        if (!IPS_VariableProfileExists('SLXD.RFLevel')) {
            IPS_CreateVariableProfile('SLXD.RFLevel', 1);
            IPS_SetVariableProfileText('SLXD.RFLevel', '', ' dBm');
        }
    }
}
