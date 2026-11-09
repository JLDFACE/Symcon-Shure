<?php

class SpeakerTracking extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('MicInstanceID', 0);
        $this->RegisterPropertyInteger('CameraInstanceID', 0);
        $this->RegisterPropertyString('AreaPresets', $this->DefaultMapping());
        $this->RegisterPropertyInteger('DwellMs', 1500);
        $this->RegisterPropertyInteger('HoldSeconds', 5);
        $this->RegisterPropertyInteger('SilenceSeconds', 15);
        $this->RegisterPropertyInteger('OverviewPreset', 0);

        $this->RegisterAttributeBoolean('ActiveInitialized', false);

        $this->RegisterTimer('SilenceTimer', 0, 'SHTRACK_CheckSilence($_IPS[\'TARGET\']);');

        $this->SetBuffer('CandidateArea', '0');
        $this->SetBuffer('CandidateSince', '0');
        $this->SetBuffer('LastSwitch', '0');
        $this->SetBuffer('LastTalker', '0');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->CreateProfiles();

        $this->RegisterVariableBoolean('Active', 'Tracking aktiv', '~Switch', 1);
        $this->EnableAction('Active');
        if (!$this->ReadAttributeBoolean('ActiveInitialized')) {
            $this->WriteAttributeBoolean('ActiveInitialized', true);
            $id = @$this->GetIDForIdent('Active');
            if ($id > 0) {
                SetValueBoolean($id, true);
            }
        }

        $this->RegisterVariableInteger('CurrentArea', 'Aktuelle Zone', 'SHTRACK.Area', 2);
        $this->RegisterVariableInteger('CurrentPreset', 'Aktuelles Kamera-Preset', '', 3);

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        $varID = $this->GetTalkerAreaVarID();
        if ($varID > 0) {
            $this->RegisterMessage($varID, VM_UPDATE);
            $this->SetTimerInterval('SilenceTimer', 1000);
            $this->WarnIfTalkerRateOff();
            $this->SetStatus(102);
        } else {
            $this->SetTimerInterval('SilenceTimer', 0);
            $this->SetStatus(104);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Active') {
            $id = @$this->GetIDForIdent('Active');
            if ($id > 0) {
                SetValueBoolean($id, (bool)$Value);
            }
            if ((bool)$Value) {
                $this->SetBuffer('CandidateArea', '0');
                $this->SetBuffer('CandidateSince', '0');
            }
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ((int)$Message !== VM_UPDATE) {
            return;
        }
        if ((int)$SenderID !== $this->GetTalkerAreaVarID()) {
            return;
        }

        $area = is_array($Data) && isset($Data[0]) ? (int)$Data[0] : 0;
        $this->HandleTalkerArea($area);
    }

    public function CheckSilence()
    {
        if (!$this->IsActive()) {
            return;
        }

        $last = (float)$this->GetBuffer('LastTalker');
        if ($last <= 0) {
            return;
        }

        $silence = max(1, (int)$this->ReadPropertyInteger('SilenceSeconds'));
        if ((microtime(true) - $last) < $silence) {
            return;
        }

        $currentId = @$this->GetIDForIdent('CurrentArea');
        $current = ($currentId > 0) ? GetValueInteger($currentId) : 0;
        if ($current === 0) {
            return;
        }

        $overview = (int)$this->ReadPropertyInteger('OverviewPreset');
        if ($overview > 0) {
            if (!$this->CallPreset($overview)) {
                return;
            }
            $pid = @$this->GetIDForIdent('CurrentPreset');
            if ($pid > 0) {
                SetValueInteger($pid, $overview);
            }
            $this->SendDebug('SHTRACK', 'Stille: Uebersichts-Preset ' . $overview . ' geladen', 0);
        }

        if ($currentId > 0) {
            SetValueInteger($currentId, 0);
        }
        $this->SetBuffer('CandidateArea', '0');
        $this->SetBuffer('CandidateSince', '0');
    }

    private function HandleTalkerArea($area)
    {
        $now = microtime(true);
        $this->SetBuffer('LastTalker', (string)$now);

        if (!$this->IsActive()) {
            return;
        }

        // Nur Coverage Areas 1-8 (Auto-Coverage ON); 0 = keine Zuordnung
        if ($area < 1 || $area > 8) {
            return;
        }

        $candidate = (int)$this->GetBuffer('CandidateArea');
        $since = (float)$this->GetBuffer('CandidateSince');

        if ($area !== $candidate) {
            $this->SetBuffer('CandidateArea', (string)$area);
            $this->SetBuffer('CandidateSince', (string)$now);
            return;
        }

        $dwell = max(0, (int)$this->ReadPropertyInteger('DwellMs')) / 1000.0;
        if (($now - $since) < $dwell) {
            return;
        }

        $currentId = @$this->GetIDForIdent('CurrentArea');
        $current = ($currentId > 0) ? GetValueInteger($currentId) : 0;
        if ($area === $current) {
            return;
        }

        $hold = max(0, (int)$this->ReadPropertyInteger('HoldSeconds'));
        $lastSwitch = (float)$this->GetBuffer('LastSwitch');
        if (($now - $lastSwitch) < $hold) {
            return;
        }

        $preset = $this->PresetForArea($area);
        if ($preset <= 0) {
            $this->SendDebug('SHTRACK', 'Kein Preset fuer Area ' . $area . ' konfiguriert', 0);
            return;
        }

        if (!$this->CallPreset($preset)) {
            return;
        }

        $this->SetBuffer('LastSwitch', (string)$now);
        if ($currentId > 0) {
            SetValueInteger($currentId, $area);
        }
        $pid = @$this->GetIDForIdent('CurrentPreset');
        if ($pid > 0) {
            SetValueInteger($pid, $preset);
        }
        $this->SendDebug('SHTRACK', 'Area ' . $area . ' -> Preset ' . $preset, 0);
    }

    private function PresetForArea($area)
    {
        $rows = json_decode($this->ReadPropertyString('AreaPresets'), true);
        if (!is_array($rows)) {
            return 0;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            if (isset($row['Area']) && (int)$row['Area'] === (int)$area) {
                return isset($row['Preset']) ? (int)$row['Preset'] : 0;
            }
        }

        return 0;
    }

    private function CallPreset($preset)
    {
        $cam = (int)$this->ReadPropertyInteger('CameraInstanceID');
        if ($cam <= 0 || !IPS_InstanceExists($cam)) {
            $this->SendDebug('SHTRACK', 'Keine Kamera-Instanz konfiguriert', 0);
            return false;
        }

        if (!function_exists('PLPTZ_PresetLoad')) {
            $this->SendDebug('SHTRACK', 'PLPTZ_PresetLoad nicht verfuegbar (PureLink PTZ100-Modul installiert?)', 0);
            return false;
        }

        try {
            PLPTZ_PresetLoad($cam, (int)$preset);
            return true;
        } catch (Exception $e) {
            $this->SendDebug('SHTRACK', 'PresetLoad fehlgeschlagen: ' . $e->getMessage(), 0);
            return false;
        }
    }

    private function GetTalkerAreaVarID()
    {
        $mic = (int)$this->ReadPropertyInteger('MicInstanceID');
        if ($mic <= 0 || !IPS_InstanceExists($mic)) {
            return 0;
        }
        $id = @IPS_GetObjectIDByIdent('TalkerArea', $mic);
        return ($id > 0) ? (int)$id : 0;
    }

    private function WarnIfTalkerRateOff()
    {
        $mic = (int)$this->ReadPropertyInteger('MicInstanceID');
        if ($mic <= 0 || !IPS_InstanceExists($mic)) {
            return;
        }

        $rate = 0;
        $this->CallSilenced(function () use ($mic, &$rate) {
            $rate = (int)IPS_GetProperty($mic, 'TalkerPositionRate');
        });

        if ($rate <= 0) {
            IPS_LogMessage('SHTRACK', 'Hinweis: TalkerPositionRate der MXA920-Instanz ' . $mic . ' ist 0 - es kommen keine Sprecher-Positionen. In der MXA920-Instanz z.B. 300 ms einstellen.');
        }
    }

    private function IsActive()
    {
        $id = @$this->GetIDForIdent('Active');
        return ($id > 0) ? GetValueBoolean($id) : false;
    }

    private function DefaultMapping()
    {
        $rows = array();
        for ($i = 1; $i <= 8; $i++) {
            $rows[] = array('Area' => $i, 'Preset' => $i);
        }
        return json_encode($rows);
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

    private function CreateProfiles()
    {
        if (!IPS_VariableProfileExists('SHTRACK.Area')) {
            IPS_CreateVariableProfile('SHTRACK.Area', 1);
            IPS_SetVariableProfileValues('SHTRACK.Area', 0, 8, 1);
            IPS_SetVariableProfileAssociation('SHTRACK.Area', 0, 'Uebersicht', '', -1);
            for ($i = 1; $i <= 8; $i++) {
                IPS_SetVariableProfileAssociation('SHTRACK.Area', $i, 'Area ' . $i, '', -1);
            }
        }
    }
}
