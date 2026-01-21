<?php

class ShureConfigurator extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('ScanSubnet', $this->GetDefaultScanSubnet());
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
        $this->SendDebug('SLXD CFG', 'Scan() gestartet, Instanz ' . $this->InstanceID, 0);

        $subnet = $this->GetEffectiveScanSubnet();
        $port = (int)$this->ReadPropertyInteger('Port');
        if ($subnet === '') {
            IPS_LogMessage('SLXD CFG', 'Scan abgebrochen: kein gueltiges Subnetz ermittelt.');
            $this->SendDebug('SLXD CFG', 'Scan abgebrochen: kein gueltiges Subnetz ermittelt.', 0);
            return;
        }

        $ips = $this->ExpandCIDR($subnet, 2048);
        $this->SendDebug('SLXD CFG', 'Subnetz=' . $subnet . ' Port=' . $port . ' IPs=' . count($ips), 0);
        $found = array();

        foreach ($ips as $ip) {
            $probe = $this->ProbeDevice($ip, $port);
            if ($probe['ok']) {
                $model = $probe['model'];
                $deviceID = $probe['deviceID'];
                $firmware = $probe['firmware'];
                $channels = $this->DetectChannels($model);
                $family = isset($probe['family']) ? $probe['family'] : $this->DetectFamily($model);

                $found[] = array(
                    'IP' => $ip,
                    'Port' => $port,
                    'Model' => $model,
                    'DeviceID' => $deviceID,
                    'Firmware' => $firmware,
                    'Channels' => $channels,
                    'Family' => $family
                );
                $this->SendDebug('SLXD CFG', 'Gefunden: ' . $ip . ' ' . $model . ' CH=' . $channels, 0);
            }
        }

        $values = $this->BuildValues($found);
        $this->WriteAttributeString('Discovered', json_encode($values));
        $this->UpdateFormField('DeviceList', 'values', $values);
        IPS_LogMessage('SLXD CFG', 'Scan beendet, gefunden: ' . count($found));
        $this->SendDebug('SLXD CFG', 'Scan beendet, gefunden: ' . count($found), 0);
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
        $family = isset($probe['family']) ? $probe['family'] : $this->DetectFamily($model);

        $newDevice = array(
            'IP' => $ip,
            'Port' => $port,
            'Model' => $model,
            'DeviceID' => $deviceID,
            'Firmware' => $firmware,
            'Channels' => $channels,
            'Family' => $family
        );

        $newRows = $this->BuildValues(array($newDevice));
        foreach ($newRows as $row) {
            $existing[] = $row;
        }

        $this->WriteAttributeString('Discovered', json_encode($existing));
        $this->UpdateFormField('DeviceList', 'values', $existing);
        echo 'Erfolg: Geraet ' . $ip . ' (' . $model . ') wurde hinzugefuegt.';
    }

    private function BuildValues($foundDevices)
    {
        $channelModuleID = '{3F4A5B6C-7D8E-9F0A-1B2C-3D4E5F6A7B8C}';
        $values = array();

        foreach ($foundDevices as $row) {
            $ip = isset($row['IP']) ? (string)$row['IP'] : '';
            $port = isset($row['Port']) ? (int)$row['Port'] : (int)$this->ReadPropertyInteger('Port');
            $model = isset($row['Model']) ? (string)$row['Model'] : '';
            $deviceID = isset($row['DeviceID']) ? (string)$row['DeviceID'] : '';
            $firmware = isset($row['Firmware']) ? (string)$row['Firmware'] : '';
            $channels = isset($row['Channels']) ? (int)$row['Channels'] : 1;
            $family = isset($row['Family']) ? strtolower(trim((string)$row['Family'])) : 'auto';
            $model = $this->TrimBraces($model);
            $deviceID = $this->TrimBraces($deviceID);
            $firmware = $this->TrimBraces($firmware);
            if ($family === '') $family = 'auto';
            $familyLabel = $family;
            if ($family === 'slxd') $familyLabel = 'SLX-D';
            if ($family === 'ulxd') $familyLabel = 'QLX-D/ULX-D';

            for ($ch = 1; $ch <= $channels; $ch++) {
                $existing = $this->FindInstanceByHostAndChannel($channelModuleID, $ip, $ch);

                $rowOut = array(
                    'IP' => $ip,
                    'Model' => $model,
                    'DeviceID' => $deviceID,
                    'Firmware' => $firmware,
                    'Channels' => $channels . ' (CH' . $ch . ')',
                    'Family' => $familyLabel,
                    'instanceID' => ($existing > 0) ? $existing : 0
                );

                if ($existing == 0) {
                    if ($deviceID !== '') {
                        $name = $deviceID . ' CH' . $ch;
                    } elseif ($model !== '') {
                        $name = $model . ' CH' . $ch;
                    } else {
                        $name = $ip . ' CH' . $ch;
                    }
                    $rowOut['create'] = array(
                        'moduleID' => $channelModuleID,
                        'name' => $name,
                        'configuration' => array(
                            'Host' => $ip,
                            'Port' => $port,
                            'Channel' => $ch,
                            'DeviceFamily' => $family,
                            'ModelHint' => $model
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
        $model = $this->SendCommandOnce($ip, $port, '< GET MODEL >', 'MODEL');
        if ($model === '') {
            $model = $this->SendCommandOnce($ip, $port, '< GET 0 ALL >', 'MODEL', 0.8, 1.5);
        }
        $family = $this->DetectFamily($model);
        if ($model === '' || $family === '') {
            return array('ok' => false);
        }

        $deviceID = $this->SendCommandOnce($ip, $port, '< GET DEVICE_ID >', 'DEVICE_ID');
        $firmware = $this->SendCommandOnce($ip, $port, '< GET FW_VER >', 'FW_VER');

        return array(
            'ok' => true,
            'model' => $model,
            'deviceID' => $deviceID,
            'firmware' => $firmware,
            'family' => $family
        );
    }

    private function SendCommandOnce($ip, $port, $cmd, $expect, $timeoutSec = 0.8, $readSeconds = 0.8)
    {
        $timeout = max(0.2, (float)$timeoutSec);
        $sock = @fsockopen($ip, $port, $errno, $errstr, $timeout);
        if (!$sock) return '';

        $readSeconds = max(0.2, (float)$readSeconds);
        stream_set_timeout($sock, 0, (int)($readSeconds * 1000000));

        @fwrite($sock, $cmd . "\r\n");
        usleep(50000);

        $response = '';
        $start = microtime(true);
        while (microtime(true) - $start < $readSeconds) {
            $chunk = fgets($sock, 1024);
            if ($chunk === false) break;
            $response .= $chunk;
            if (strlen($response) > 4096) break;
        }

        fclose($sock);

        if ($expect !== '') {
            return $this->ParseResponseFor($response, $expect);
        }
        return $this->ParseResponse($response);
    }

    private function ParseResponse($raw)
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

    private function ParseResponseFor($raw, $expect)
    {
        $expect = strtoupper(trim((string)$expect));
        if ($expect === '') {
            return $this->ParseResponse($raw);
        }

        if (!preg_match_all('/<\s*REP\s+(.+?)\s*>/i', $raw, $matches)) {
            return '';
        }

        foreach ($matches[1] as $payload) {
            $payload = trim($payload);
            if ($payload === '') continue;
            $parts = preg_split('/\s+/', $payload, 2);
            if (count($parts) == 0) continue;
            $key = strtoupper($parts[0]);
            if ($key === 'ERR') continue;
            if ($key !== $expect) continue;
            if (count($parts) >= 2) return trim($parts[1]);
            return '';
        }

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

    private function DetectFamily($model)
    {
        $m = strtoupper($this->TrimBraces((string)$model));
        if ($m === '') return '';

        if (strpos($m, 'SLXD') !== false || strpos($m, 'SLX') !== false) {
            return 'slxd';
        }
        if (strpos($m, 'QLXD') !== false || strpos($m, 'QLX') !== false || strpos($m, 'ULXD') !== false) {
            return 'ulxd';
        }

        return '';
    }

    private function DetectChannels($model)
    {
        $m = strtoupper(trim((string)$model));
        if (strpos($m, 'SLXD24D') !== false || strpos($m, 'SLXD44') !== false
            || strpos($m, 'ULXD4Q') !== false || strpos($m, 'QLXD4Q') !== false) return 4;
        if (strpos($m, 'SLXD4D') !== false || strpos($m, 'SLX4D') !== false
            || strpos($m, 'ULXD4D') !== false || strpos($m, 'QLXD4D') !== false) return 2;
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

    private function GetEffectiveScanSubnet()
    {
        $configured = trim($this->ReadPropertyString('ScanSubnet'));
        $auto = $this->DetectLocalSubnet();

        if ($configured === '' || !$this->IsValidCIDR($configured)) {
            return ($auto !== '') ? $auto : '';
        }

        if ($configured === '192.168.1.0/24' && $auto !== '' && $auto !== $configured) {
            return $auto;
        }

        return $configured;
    }

    private function GetDefaultScanSubnet()
    {
        $auto = $this->DetectLocalSubnet();
        if ($auto !== '') {
            return $auto;
        }
        return '192.168.1.0/24';
    }

    private function DetectLocalSubnet()
    {
        $entries = array();

        if (function_exists('Sys_GetNetworkInfo')) {
            $info = @Sys_GetNetworkInfo();
            $entries = array_merge($entries, $this->ExtractNetworkEntries($info));
        }
        if (function_exists('Sys_GetNetworkInfoEx')) {
            $info = @Sys_GetNetworkInfoEx();
            $entries = array_merge($entries, $this->ExtractNetworkEntries($info));
        }

        $best = '';
        foreach ($entries as $entry) {
            $cidr = $this->CidrFromEntry($entry);
            if ($cidr === '') continue;
            if ($this->EntryHasGateway($entry)) return $cidr;
            if ($best === '') $best = $cidr;
        }

        if ($best !== '') return $best;

        $fallbackIp = $this->GetFallbackIPv4();
        if ($fallbackIp !== '') {
            return $this->BuildCIDR($fallbackIp, '255.255.255.0', '');
        }

        return '';
    }

    private function ExtractNetworkEntries($info)
    {
        $entries = array();
        if (!is_array($info)) return $entries;

        if ($this->LooksLikeNetworkEntry($info)) {
            $entries[] = $info;
            return $entries;
        }

        foreach ($info as $entry) {
            if ($this->LooksLikeNetworkEntry($entry)) {
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    private function LooksLikeNetworkEntry($entry)
    {
        if (!is_array($entry)) return false;
        $keys = array('IP', 'ip', 'Address', 'Addr', 'IPv4', 'IPv4Address', 'Host');
        foreach ($keys as $key) {
            if (isset($entry[$key])) return true;
        }
        return false;
    }

    private function EntryHasGateway($entry)
    {
        if (!is_array($entry)) return false;
        $keys = array('Gateway', 'gateway', 'IPv4Gateway');
        foreach ($keys as $key) {
            if (!isset($entry[$key])) continue;
            $gw = trim((string)$entry[$key]);
            if ($gw !== '' && $gw !== '0.0.0.0') return true;
        }
        return false;
    }

    private function CidrFromEntry($entry)
    {
        if (!is_array($entry)) return '';

        $ip = $this->FirstValue($entry, array('IP', 'ip', 'Address', 'Addr', 'IPv4', 'IPv4Address', 'Host'));
        $mask = $this->FirstValue($entry, array('Subnet', 'SubnetMask', 'Netmask', 'Mask', 'IPv4Mask'));
        $prefix = $this->FirstValue($entry, array('Prefix', 'PrefixLength', 'CIDR'));

        if ($ip === '') return '';
        if (strpos($ip, '/') !== false) {
            $parts = explode('/', $ip, 2);
            $ip = trim($parts[0]);
            if ($prefix === '') $prefix = trim($parts[1]);
        }

        return $this->BuildCIDR($ip, $mask, $prefix);
    }

    private function BuildCIDR($ip, $mask, $prefix)
    {
        if (!$this->IsUsableIPv4($ip)) return '';

        $prefix = trim((string)$prefix);
        if ($prefix === '' && $mask !== '') {
            if (strpos($mask, '.') !== false) {
                $prefix = (string)$this->NetmaskToCidr($mask);
            } else {
                $prefix = (string)(int)$mask;
            }
        }

        $prefixInt = (int)$prefix;
        if ($prefixInt < 0 || $prefixInt > 32) return '';

        $ipLong = ip2long($ip);
        if ($ipLong === false) return '';

        $hostBits = 32 - $prefixInt;
        $netLong = $ipLong & (-1 << $hostBits);
        return long2ip($netLong) . '/' . $prefixInt;
    }

    private function NetmaskToCidr($mask)
    {
        $maskLong = ip2long($mask);
        if ($maskLong === false) return -1;
        if ($maskLong < 0) $maskLong += 4294967296;

        $bin = decbin($maskLong);
        $bin = str_pad($bin, 32, '0', STR_PAD_LEFT);
        if (!preg_match('/^1*0*$/', $bin)) return -1;
        return substr_count($bin, '1');
    }

    private function IsValidCIDR($cidr)
    {
        $cidr = trim((string)$cidr);
        if ($cidr === '') return false;
        $parts = explode('/', $cidr);
        if (count($parts) != 2) return false;

        $ip = trim($parts[0]);
        $prefix = (int)trim($parts[1]);
        if (!$this->IsUsableIPv4($ip)) return false;
        return ($prefix >= 0 && $prefix <= 32);
    }

    private function IsUsableIPv4($ip)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
        if ($ip === '0.0.0.0') return false;
        if (strpos($ip, '127.') === 0) return false;
        if (strpos($ip, '169.254.') === 0) return false;
        return true;
    }

    private function FirstValue($entry, $keys)
    {
        foreach ($keys as $key) {
            if (isset($entry[$key]) && $entry[$key] !== '') {
                return (string)$entry[$key];
            }
        }
        return '';
    }

    private function GetFallbackIPv4()
    {
        $host = '';
        if (isset($_SERVER['SERVER_ADDR'])) {
            $host = (string)$_SERVER['SERVER_ADDR'];
        } else {
            $host = (string)gethostbyname(gethostname());
        }
        return $this->IsUsableIPv4($host) ? $host : '';
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
