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

        $this->RegisterPropertyInteger('PollSlow', 15);
        $this->RegisterPropertyInteger('PollFast', 3);
        $this->RegisterPropertyInteger('FastAfterChangeSeconds', 30);
        $this->RegisterPropertyInteger('TimeoutMs', 1500);

        $this->RegisterTimer('PollTimer', 15000, 'SLXD_PollNow($_IPS[\'TARGET\']);');

        $this->SetBuffer('FastUntil', '0');
        $this->SetBuffer('RxBuf', '');
        $this->SetBuffer('PendingMap', '{}');
        $this->SetBuffer('PendingTSMap', '{}');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterVariableBoolean('Online', 'Online', '~Alert.Reversed', 1);
        $this->RegisterVariableString('LastError', 'LastError', '', 2);
        $this->RegisterVariableInteger('LastOKTimestamp', 'LastOKTimestamp', '~UnixTimestamp', 3);

        $ch = (int)$this->ReadPropertyInteger('Channel');

        $this->CreateProfiles();

        $this->RegisterVariableBoolean('Mute', 'Audio Mute', '~Switch', 10);
        $this->EnableAction('Mute');

        $this->RegisterVariableInteger('Gain', 'Audio Gain', 'SLXD.Gain', 11);
        $this->EnableAction('Gain');

        $this->RegisterVariableInteger('Frequency', 'Frequency', 'SLXD.Frequency', 20);

        $this->RegisterVariableString('ChannelName', 'Channel Name', '', 21);

        $this->RegisterVariableInteger('Battery', 'Battery', 'SLXD.Battery', 30);

        $this->RegisterVariableInteger('RFLevel', 'RF Level', 'SLXD.RFLevel', 31);

        $this->RegisterVariableString('TXModel', 'TX Model', '', 40);

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
        $lines = array();

        while (true) {
            $pos = strpos($buf, "\n");
            if ($pos === false) break;
            $line = substr($buf, 0, $pos);
            $buf = substr($buf, $pos + 1);
            $lines[] = trim($line);
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
        $this->SendCommand("< GET " . $ch . " BATT_CHARGE >", 'Battery');
        $this->SendCommand("< GET " . $ch . " RF_LEVEL >", 'RFLevel');
        $this->SendCommand("< GET " . $ch . " TX_MODEL >", 'TXModel');

        SetValueInteger($this->GetIDForIdent('LastOKTimestamp'), time());
        SetValueBoolean($this->GetIDForIdent('Online'), true);
        SetValueString($this->GetIDForIdent('LastError'), '');

        $this->CleanupOldPending();
    }

    public function ReadDeviceID()
    {
        $resp = $this->SendCommandSync("< GET DEVICE_ID >");
        IPS_LogMessage('SLXD', 'Device ID: ' . $resp);
        return $resp;
    }

    public function SetMute($mute)
    {
        $ch = (int)$this->ReadPropertyInteger('Channel');
        $val = $mute ? 'ON' : 'OFF';
        if (!$this->SendCommand("< SET " . $ch . " AUDIO_MUTE " . $val . " >", 'Mute')) {
            return;
        }

        $this->AddPending('Mute', $mute ? 1 : 0);
        $this->UpdatePollingInterval(true);
    }

    public function SetGain($gain)
    {
        $ch = (int)$this->ReadPropertyInteger('Channel');
        $g = max(-25, min(10, (int)$gain));
        if (!$this->SendCommand("< SET " . $ch . " AUDIO_GAIN " . $g . " >", 'Gain')) {
            return;
        }

        $this->AddPending('Gain', $g);
        $this->UpdatePollingInterval(true);
    }

    private function ProcessLine($line)
    {
        if (!preg_match('/^<\s*REP\s+(\d+)\s+([A-Z_]+)\s+(.+?)\s*>$/i', $line, $m)) {
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
                $val = (int)$value;
                $this->UpdateVariable('Gain', $val);
                break;
            case 'FREQUENCY':
                $val = (int)$value;
                $this->UpdateVariable('Frequency', $val);
                break;
            case 'CHAN_NAME':
                $val = trim($value, '{}');
                $this->UpdateVariableString('ChannelName', $val);
                break;
            case 'BATT_CHARGE':
                $val = (int)$value;
                $this->UpdateVariable('Battery', $val);
                break;
            case 'RF_LEVEL':
                $val = (int)$value;
                $this->UpdateVariable('RFLevel', $val);
                break;
            case 'TX_MODEL':
                $val = trim($value);
                $this->UpdateVariableString('TXModel', $val);
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

        if (preg_match('/^<\s*REP\s+(.+?)\s*>$/im', $response, $m)) {
            $parts = preg_split('/\s+/', trim($m[1]), 2);
            if (count($parts) >= 2) {
                return trim($parts[1]);
            }
        }

        return '';
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
        if (!IPS_VariableProfileExists('SLXD.Gain')) {
            IPS_CreateVariableProfile('SLXD.Gain', 1);
            IPS_SetVariableProfileValues('SLXD.Gain', -25, 10, 1);
            IPS_SetVariableProfileText('SLXD.Gain', '', ' dB');
        }

        if (!IPS_VariableProfileExists('SLXD.Frequency')) {
            IPS_CreateVariableProfile('SLXD.Frequency', 1);
            IPS_SetVariableProfileText('SLXD.Frequency', '', ' MHz');
        }

        if (!IPS_VariableProfileExists('SLXD.Battery')) {
            IPS_CreateVariableProfile('SLXD.Battery', 1);
            IPS_SetVariableProfileValues('SLXD.Battery', 0, 100, 1);
            IPS_SetVariableProfileText('SLXD.Battery', '', ' %');
        }

        if (!IPS_VariableProfileExists('SLXD.RFLevel')) {
            IPS_CreateVariableProfile('SLXD.RFLevel', 1);
            IPS_SetVariableProfileText('SLXD.RFLevel', '', ' dBm');
        }
    }
}
