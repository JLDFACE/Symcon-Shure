<?php

class SLXDConfigurator extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('ScanSubnet', '192.168.1.0/24');
        $this->RegisterPropertyInteger('Port', 2202);

        $this->RegisterPropertyString('ManualIP', '');
        $this->RegisterPropertyInteger('ManualPort', 2202);

        $this->RegisterAttributeString('Discovered', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(102);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if (!is_array($form)) $form = array();

        $values = json_decode($this->ReadAttributeString('Discovered'), true);
        if (!is_array($values)) $values = array();

        if (!isset($form['actions']) || !is_array($form['actions'])) $form['actions'] = array();

        for ($i = 0; $i < count($form['actions']); $i++) {
            if (isset($form['actions'][$i]['type']) && $form['actions'][$i]['type'] == 'Configurator'
                && isset($form['actions'][$i]['name']) && $form['actions'][$i]['name'] == 'DeviceList') {
                $form['actions'][$i]['values'] = $values;
            }
        }

        return json_encode($form);
    }

    public function Scan()
    {
        IPS_LogMessage('SLXD CFG', 'Scan() gestartet, Instanz ' . $this->InstanceID);

        $subnet = trim($this->ReadPropertyString('ScanSubnet'));
        $port = (int)$this->ReadPropertyInteger('Port');

        $ips = $this->ExpandCIDR($subnet, 2048);
        $found = array();

        foreach ($ips as $ip) {
            $probe = $this->ProbeDevice($ip, $port);
            if ($probe['ok']) {
                $model = $probe['model'];
                $deviceID = $probe['deviceID'];
                $firmware = $probe['firmware'];
                $channels = $this->DetectChannels($model);

                $found[] = array(
                    'IP' => $ip,
                    'Model' => $model,
                    'DeviceID' => $deviceID,
                    'Firmware' => $firmware,
                    'Channels' => $channels
                );
            }
        }

        $this->WriteAttributeString('Discovered', json_encode($this->BuildValues($found)));
        IPS_LogMessage('SLXD CFG', 'Scan beendet, gefunden: ' . count($found));
        $this->SendDebug('SLXD CFG', 'Discovered=' . json_encode($found), 0);
    }

    public function AddManual()
    {
        $ip = trim($this->ReadPropertyString('ManualIP'));
        $port = (int)$this->ReadPropertyInteger('ManualPort');

        if ($ip === '') {
            echo 'Fehler: IP-Adresse darf nicht leer sein.';
            return;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            echo 'Fehler: Ungueltige IP-Adresse.';
            return;
        }

        $probe = $this->ProbeDevice($ip, $port);
        if (!$probe['ok']) {
            echo 'Fehler: Geraet unter ' . $ip . ':' . $port . ' nicht erreichbar oder nicht kompatibel.';
            return;
        }

        $existing = json_decode($this->ReadAttributeString('Discovered'), true);
        if (!is_array($existing)) $existing = array();

        foreach ($existing as $item) {
            if (isset($item['IP']) && $item['IP'] === $ip) {
                echo 'Info: Geraet ' . $ip . ' ist bereits in der Liste.';
                return;
            }
        }

        $model = $probe['model'];
        $deviceID = $probe['deviceID'];
        $firmware = $probe['firmware'];
        $channels = $this->DetectChannels($model);

        $newDevice = array(
            'IP' => $ip,
            'Model' => $model,
            'DeviceID' => $deviceID,
            'Firmware' => $firmware,
            'Channels' => $channels
        );

        $newRows = $this->BuildValues(array($newDevice));
        foreach ($newRows as $row) {
            $existing[] = $row;
        }

        $this->WriteAttributeString('Discovered', json_encode($existing));
        echo 'Erfolg: Geraet ' . $ip . ' (' . $model . ') wurde hinzugefuegt.';
    }

    private function BuildValues($foundDevices)
    {
        $channelModuleID = '{3F4A5B6C-7D8E-9F0A-1B2C-3D4E5F6A7B8C}';
        $values = array();

        foreach ($foundDevices as $row) {
            $ip = isset($row['IP']) ? (string)$row['IP'] : '';
            $model = isset($row['Model']) ? (string)$row['Model'] : '';
            $deviceID = isset($row['DeviceID']) ? (string)$row['DeviceID'] : '';
            $firmware = isset($row['Firmware']) ? (string)$row['Firmware'] : '';
            $channels = isset($row['Channels']) ? (int)$row['Channels'] : 1;

            for ($ch = 1; $ch <= $channels; $ch++) {
                $existing = $this->FindInstanceByHostAndChannel($channelModuleID, $ip, $ch);

                $rowOut = array(
                    'IP' => $ip,
                    'Model' => $model,
                    'DeviceID' => $deviceID,
                    'Firmware' => $firmware,
                    'Channels' => $channels . ' (CH' . $ch . ')',
                    'instanceID' => ($existing > 0) ? $existing : 0
                );

                if ($existing == 0) {
                    $name = ($deviceID !== '') ? ($deviceID . ' CH' . $ch) : ($ip . ' CH' . $ch);
                    $rowOut['create'] = array(
                        'moduleID' => $channelModuleID,
                        'name' => $name,
                        'configuration' => array(
                            'Host' => $ip,
                            'Port' => (int)$this->ReadPropertyInteger('Port'),
                            'Channel' => $ch
                        )
                    );
                }

                $values[] = $rowOut;
            }
        }

        return $values;
    }

    private function ProbeDevice($ip, $port)
    {
        $timeout = 0.5;
        $sock = @fsockopen($ip, $port, $errno, $errstr, $timeout);
        if (!$sock) return array('ok' => false);

        stream_set_timeout($sock, 0, 500000);

        $deviceID = $this->SendCommandSync($sock, '< GET DEVICE_ID >');
        $model = $this->SendCommandSync($sock, '< GET MODEL >');
        $firmware = $this->SendCommandSync($sock, '< GET FW_VER >');

        fclose($sock);

        if ($model === '' || (stripos($model, 'SLX') === false && stripos($model, 'SLXD') === false)) {
            return array('ok' => false);
        }

        return array(
            'ok' => true,
            'model' => $model,
            'deviceID' => $deviceID,
            'firmware' => $firmware
        );
    }

    private function SendCommandSync($sock, $cmd)
    {
        @fwrite($sock, $cmd . "\r\n");
        usleep(50000);

        $response = '';
        $start = microtime(true);
        while (microtime(true) - $start < 0.5) {
            $chunk = fgets($sock, 1024);
            if ($chunk === false) break;
            $response .= $chunk;
            if (strlen($response) > 4096) break;
        }

        return $this->ParseResponse($response);
    }

    private function ParseResponse($raw)
    {
        $lines = preg_split('/[\r\n]+/', trim($raw));
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') continue;

            if (preg_match('/^<\s*REP\s+(.+?)\s*>$/i', $t, $m)) {
                $parts = preg_split('/\s+/', trim($m[1]), 2);
                if (count($parts) >= 2) {
                    return trim($parts[1]);
                }
            }
        }
        return '';
    }

    private function DetectChannels($model)
    {
        $m = strtoupper(trim((string)$model));
        if (strpos($m, 'SLXD4D') !== false || strpos($m, 'SLX4D') !== false) return 2;
        if (strpos($m, 'SLXD24D') !== false || strpos($m, 'SLXD44') !== false) return 4;
        return 1;
    }

    private function ExpandCIDR($cidr, $limit)
    {
        $cidr = trim($cidr);
        $parts = explode('/', $cidr);
        if (count($parts) != 2) return array();

        $base = trim($parts[0]);
        $mask = (int)$parts[1];
        if ($mask < 0 || $mask > 32) return array();

        $baseLong = ip2long($base);
        if ($baseLong === false) return array();

        $hostBits = 32 - $mask;
        $count = 1 << $hostBits;
        if ($count < 0) $count = 0;

        if ($count > (int)$limit) $count = (int)$limit;

        $netLong = $baseLong & (-1 << $hostBits);

        $ips = array();
        for ($i = 1; $i < $count - 1; $i++) {
            $ips[] = long2ip($netLong + $i);
        }

        if (count($ips) == 0) $ips[] = $base;
        return $ips;
    }

    private function FindInstanceByHostAndChannel($moduleID, $host, $channel)
    {
        $ids = IPS_GetInstanceListByModuleID($moduleID);
        foreach ($ids as $id) {
            $h = (string)IPS_GetProperty($id, 'Host');
            $c = (int)IPS_GetProperty($id, 'Channel');
            if ($h === $host && $c === $channel) {
                return $id;
            }
        }
        return 0;
    }
}
