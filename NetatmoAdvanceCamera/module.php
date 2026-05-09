<?php

declare(strict_types=1);

class NetatmoAdvanceCamera extends IPSModule
{
    private const TOKEN_URL = 'https://api.netatmo.com/oauth2/token';
    private const API_BASE = 'https://api.netatmo.com/api/';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyString('RefreshToken', '');
        $this->RegisterPropertyString('HomeID', '');
        $this->RegisterPropertyString('CameraID', '');
        $this->RegisterPropertyInteger('Interval', 300);
        $this->RegisterPropertyBoolean('PreferLocal', true);
        $this->RegisterPropertyString('Resolution', 'medium');
        $this->RegisterPropertyBoolean('CreateHtml', true);
        $this->RegisterPropertyBoolean('DebugRaw', false);

        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshTokenRuntime', '');
        $this->RegisterAttributeInteger('TokenExpires', 0);
        $this->RegisterAttributeString('DetectedHomeID', '');
        $this->RegisterAttributeString('DetectedCameraID', '');

        $this->RegisterTimer('UpdateData', 0, 'NETATMOADV_UpdateData($_IPS[\'TARGET\']);');

        $this->RegisterVariableBoolean('Status', 'Status', '~Switch', 10);
        $this->RegisterVariableString('HomeName', 'Home', '', 20);
        $this->RegisterVariableString('CameraName', 'Kamera', '', 30);
        $this->RegisterVariableString('DeviceType', 'Gerätetyp', '', 40);
        $this->RegisterVariableBoolean('Reachable', 'Erreichbar', '~Switch', 50);
        $this->RegisterVariableInteger('WifiStatus', 'WLAN Status', '', 60);
        $this->RegisterVariableInteger('SDCardStatus', 'SD-Karte Status', '', 70);
        $this->RegisterVariableInteger('AlimStatus', 'Stromversorgung Status', '', 80);
        $this->RegisterVariableInteger('LastSeen', 'Zuletzt gesehen', '~UnixTimestamp', 90);
        $this->RegisterVariableString('LastEvent', 'Letztes Ereignis', '', 100);
        $this->RegisterVariableString('LiveSnapshotUrl', 'Live Snapshot URL', '', 110);
        $this->RegisterVariableString('LiveVideoUrl', 'Live Video URL', '', 120);
        $this->RegisterVariableString('VpnUrl', 'VPN URL', '', 130);
        $this->RegisterVariableString('LocalUrl', 'Lokale URL', '', 140);
        $this->RegisterVariableString('Html', 'Kamera Kachel', '~HTMLBox', 150);
        $this->RegisterVariableString('RawData', 'Rohdaten', '', 900);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (!$this->ReadPropertyString('ClientID') || !$this->ReadPropertyString('ClientSecret') || !$this->getRefreshToken()) {
            $this->SetStatus(201);
            $this->SetTimerInterval('UpdateData', 0);
            return;
        }

        $interval = max(30, $this->ReadPropertyInteger('Interval')) * 1000;
        $this->SetTimerInterval('UpdateData', $interval);
        $this->SetStatus(102);
    }

    public function RefreshAccessToken(): bool
    {
        $refreshToken = $this->getRefreshToken();
        if ($refreshToken === '') {
            $this->LogMessage('RefreshToken fehlt', KL_ERROR);
            return false;
        }

        $post = http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->ReadPropertyString('ClientID'),
            'client_secret' => $this->ReadPropertyString('ClientSecret'),
        ]);

        $result = $this->curl('POST', self::TOKEN_URL, [], $post, false);
        if (!is_array($result) || empty($result['access_token'])) {
            $this->SetStatus(202);
            return false;
        }

        $this->WriteAttributeString('AccessToken', (string)$result['access_token']);
        $this->WriteAttributeInteger('TokenExpires', time() + (int)($result['expires_in'] ?? 10800) - 120);
        if (!empty($result['refresh_token'])) {
            $this->WriteAttributeString('RefreshTokenRuntime', (string)$result['refresh_token']);
        }

        return true;
    }

    public function AutoDetect(): bool
    {
        $homes = $this->api('homesdata');
        if (!is_array($homes)) {
            return false;
        }

        [$home, $camera] = $this->findCamera($homes);
        if (!$home || !$camera) {
            $this->SetStatus(203);
            return false;
        }

        $this->WriteAttributeString('DetectedHomeID', (string)($home['id'] ?? ''));
        $this->WriteAttributeString('DetectedCameraID', (string)($camera['id'] ?? ''));
        $this->UpdateData();
        return true;
    }

    public function UpdateData(): bool
    {
        $homes = $this->api('homesdata');
        if (!is_array($homes)) {
            $this->SetStatus(202);
            return false;
        }

        [$home, $camera] = $this->findCamera($homes);
        if (!$home || !$camera) {
            $this->SetStatus(203);
            return false;
        }

        $homeId = (string)($home['id'] ?? '');
        $cameraId = (string)($camera['id'] ?? '');

        $status = $this->api('homestatus', ['home_id' => $homeId]);
        $events = $this->api('getevents', ['home_id' => $homeId, 'device_id' => $cameraId, 'size' => 10]);

        $cameraStatus = $this->findCameraStatus($status, $cameraId);
        $urls = $this->extractUrls($camera, $cameraStatus);
        $eventText = $this->extractLastEventText($events);

        $this->SetValue('HomeName', (string)($home['name'] ?? $homeId));
        $this->SetValue('CameraName', (string)($camera['name'] ?? $cameraId));
        $this->SetValue('DeviceType', (string)($camera['type'] ?? ''));
        $this->SetValue('Reachable', (bool)($cameraStatus['is_connected'] ?? $camera['is_connected'] ?? false));
        $this->SetValue('Status', (bool)($cameraStatus['is_connected'] ?? $camera['is_connected'] ?? false));
        $this->SetValue('WifiStatus', (int)($cameraStatus['wifi_status'] ?? $camera['wifi_status'] ?? 0));
        $this->SetValue('SDCardStatus', (int)($cameraStatus['sd_status'] ?? $cameraStatus['sdcard_status'] ?? 0));
        $this->SetValue('AlimStatus', (int)($cameraStatus['alim_status'] ?? 0));
        $this->SetValue('LastSeen', (int)($cameraStatus['last_seen'] ?? $camera['last_seen'] ?? 0));
        $this->SetValue('LastEvent', $eventText);
        $this->SetValue('VpnUrl', $urls['vpn']);
        $this->SetValue('LocalUrl', $urls['local']);
        $this->SetValue('LiveSnapshotUrl', $this->buildLiveSnapshotUrl($urls));
        $this->SetValue('LiveVideoUrl', $this->buildLiveVideoUrl($urls, $this->ReadPropertyString('Resolution')));

        if ($this->ReadPropertyBoolean('DebugRaw')) {
            $this->SetValue('RawData', json_encode([
                'home' => $home,
                'camera' => $camera,
                'cameraStatus' => $cameraStatus,
                'events' => $events,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        if ($this->ReadPropertyBoolean('CreateHtml')) {
            $this->SetValue('Html', $this->buildHtml($home, $camera, $cameraStatus, $eventText, $urls));
        }

        $this->SetStatus(102);
        return true;
    }

    public function GetLiveSnapshotUrl(bool $preferLocal = true)
    {
        $vpn = $this->GetValue('VpnUrl');
        $local = $this->GetValue('LocalUrl');
        $urls = ['vpn' => $vpn, 'local' => $local];
        return $this->buildLiveSnapshotUrl($urls, $preferLocal);
    }

    public function GetLiveVideoUrl(string $resolution = 'medium', bool $preferLocal = true)
    {
        $vpn = $this->GetValue('VpnUrl');
        $local = $this->GetValue('LocalUrl');
        $urls = ['vpn' => $vpn, 'local' => $local];
        return $this->buildLiveVideoUrl($urls, $resolution, $preferLocal);
    }

    private function api(string $endpoint, array $params = [])
    {
        if (!$this->ensureToken()) {
            return false;
        }

        $accessToken = trim($this->ReadAttributeString('AccessToken'));
        if ($accessToken === '') {
            $this->LogMessage('AccessToken ist leer - API-Aufruf abgebrochen', KL_ERROR);
            return false;
        }

        // Netatmo akzeptiert normalerweise den OAuth Bearer Header.
        // Zur Sicherheit wird der Token zusaetzlich als Query-Parameter mitgegeben,
        // da manche Netatmo-Endpunkte/Proxy-Kombinationen sonst "Access token is missing" liefern.
        $params['access_token'] = $accessToken;

        $url = self::API_BASE . $endpoint;
        if ($params) {
            $url .= '?' . http_build_query($params);
        }

        return $this->curl('GET', $url, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json'
        ]);
    }

    private function ensureToken(): bool
    {
        if ($this->ReadAttributeString('AccessToken') === '' || time() >= $this->ReadAttributeInteger('TokenExpires')) {
            return $this->RefreshAccessToken();
        }
        return true;
    }

    private function getRefreshToken(): string
    {
        $runtime = $this->ReadAttributeString('RefreshTokenRuntime');
        if ($runtime !== '') {
            return $runtime;
        }
        return $this->ReadPropertyString('RefreshToken');
    }

    private function curl(string $method, string $url, array $headers = [], string $body = '', bool $json = true)
    {
        $ch = curl_init();

        if ($json) {
            if (!$this->hasHeader($headers, 'Accept')) {
                $headers[] = 'Accept: application/json';
            }
        } else {
            if (!$this->hasHeader($headers, 'Content-Type')) {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
            if (!$this->hasHeader($headers, 'Accept')) {
                $headers[] = 'Accept: application/json';
            }
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err || $code < 200 || $code >= 300) {
            $safeUrl = preg_replace('/access_token=[^&]+/', 'access_token=***', $url);
            $this->LogMessage('Netatmo API Fehler HTTP ' . $code . ' ' . $err . ' URL=' . $safeUrl . ' Response=' . (string)$response, KL_ERROR);
            return false;
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            $this->LogMessage('Netatmo API Antwort ist kein JSON: ' . substr((string)$response, 0, 500), KL_ERROR);
            return false;
        }

        return $data['body'] ?? $data;
    }

    private function hasHeader(array $headers, string $name): bool
    {
        $needle = strtolower($name) . ':';
        foreach ($headers as $header) {
            if (str_starts_with(strtolower(trim($header)), $needle)) {
                return true;
            }
        }
        return false;
    }

    private function findCamera(array $homes): array
    {
        $wantedHome = $this->ReadPropertyString('HomeID') ?: $this->ReadAttributeString('DetectedHomeID');
        $wantedCamera = $this->ReadPropertyString('CameraID') ?: $this->ReadAttributeString('DetectedCameraID');

        // Bekannte Security-Kamera-Typen. NPC ist fuer die neue Indoor Camera Advance relevant.
        $types = ['NPC', 'NACamera', 'NOC', 'NDB'];

        foreach (($homes['homes'] ?? []) as $home) {
            if ($wantedHome !== '' && (string)($home['id'] ?? '') !== $wantedHome) {
                continue;
            }

            $candidates = [];
            foreach (['cameras', 'modules', 'devices'] as $key) {
                foreach (($home[$key] ?? []) as $item) {
                    if (is_array($item)) {
                        $candidates[] = $item;
                    }
                }
            }

            foreach ($candidates as $camera) {
                $id = (string)($camera['id'] ?? $camera['_id'] ?? '');
                $type = (string)($camera['type'] ?? $camera['module_type'] ?? '');

                if ($wantedCamera !== '' && $id !== $wantedCamera) {
                    continue;
                }

                if ($wantedCamera !== '' || in_array($type, $types, true)) {
                    if (!isset($camera['id']) && $id !== '') {
                        $camera['id'] = $id;
                    }
                    if (!isset($camera['type']) && $type !== '') {
                        $camera['type'] = $type;
                    }
                    return [$home, $camera];
                }
            }
        }

        if ($this->ReadPropertyBoolean('DebugRaw')) {
            $this->SetValue('RawData', json_encode([
                'error' => 'Keine passende Kamera gefunden',
                'homesdata' => $homes,
                'searched_types' => $types,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return [null, null];
    }

    private function findCameraStatus($status, string $cameraId): array
    {
        if (!is_array($status)) {
            return [];
        }
        foreach (($status['home']['cameras'] ?? $status['cameras'] ?? []) as $camera) {
            if (($camera['id'] ?? '') === $cameraId) {
                return $camera;
            }
        }
        foreach (($status['home']['modules'] ?? $status['modules'] ?? []) as $module) {
            if (($module['id'] ?? '') === $cameraId) {
                return $module;
            }
        }
        return [];
    }

    private function extractUrls(array $camera, array $status): array
    {
        $vpn = (string)($status['vpn_url'] ?? $camera['vpn_url'] ?? '');
        $local = (string)($status['local_url'] ?? $camera['local_url'] ?? '');
        return ['vpn' => rtrim($vpn, '/'), 'local' => rtrim($local, '/')];
    }

    private function buildLiveSnapshotUrl(array $urls, ?bool $preferLocal = null): string
    {
        $preferLocal = $preferLocal ?? $this->ReadPropertyBoolean('PreferLocal');
        $base = ($preferLocal && !empty($urls['local'])) ? $urls['local'] : ($urls['vpn'] ?? '');
        return $base ? $base . '/live/snapshot_720.jpg' : '';
    }

    private function buildLiveVideoUrl(array $urls, string $resolution = 'medium', ?bool $preferLocal = null): string
    {
        $preferLocal = $preferLocal ?? $this->ReadPropertyBoolean('PreferLocal');
        $base = ($preferLocal && !empty($urls['local'])) ? $urls['local'] : ($urls['vpn'] ?? '');
        $allowed = ['poor', 'low', 'medium', 'high'];
        if (!in_array($resolution, $allowed, true)) {
            $resolution = 'medium';
        }
        return $base ? $base . '/live/index_' . $resolution . '.m3u8' : '';
    }

    private function extractLastEventText($events): string
    {
        if (!is_array($events)) {
            return '';
        }
        $event = $events['events'][0] ?? null;
        if (!$event) {
            return '';
        }
        $type = (string)($event['type'] ?? 'event');
        $time = !empty($event['time']) ? date('d.m.Y H:i:s', (int)$event['time']) : '';
        $message = (string)($event['message'] ?? $event['event_type'] ?? '');
        return trim($time . ' ' . $type . ' ' . $message);
    }

    private function buildHtml(array $home, array $camera, array $status, string $eventText, array $urls): string
    {
        $name = htmlspecialchars((string)($camera['name'] ?? 'Netatmo Kamera'));
        $homeName = htmlspecialchars((string)($home['name'] ?? ''));
        $type = htmlspecialchars((string)($camera['type'] ?? ''));
        $online = (bool)($status['is_connected'] ?? $camera['is_connected'] ?? false);
        $color = $online ? '#5ce05c' : '#ff5c5c';
        $onlineText = $online ? 'Online' : 'Offline';
        $snapshot = htmlspecialchars($this->buildLiveSnapshotUrl($urls));
        $lastSeen = (int)($status['last_seen'] ?? $camera['last_seen'] ?? 0);
        $lastSeenText = $lastSeen > 0 ? date('d.m.Y H:i:s', $lastSeen) : '-';
        $eventText = htmlspecialchars($eventText);

        $image = $snapshot !== '' ? "<img src='{$snapshot}' style='width:100%;height:160px;object-fit:cover;border-radius:14px;background:#111;'>" : "<div style='height:160px;border-radius:14px;background:#111;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.45);'>Kein Snapshot</div>";

        return "
        <div style='box-sizing:border-box;width:100%;font-family:Arial,Helvetica,sans-serif;color:#f5f5f5;overflow:hidden;'>
            <div style='background:linear-gradient(145deg,#1b2430,#101316);border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:14px;'>
                <div style='display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;'>
                    <div><div style='font-size:20px;font-weight:800;'>{$name}</div><div style='font-size:12px;color:rgba(255,255,255,.55);'>{$homeName} · {$type}</div></div>
                    <div style='font-size:13px;font-weight:800;color:{$color};'>{$onlineText}</div>
                </div>
                {$image}
                <div style='display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px;font-size:12px;'>
                    <div style='background:rgba(255,255,255,.06);border-radius:10px;padding:8px;'>WLAN<br><b>" . (int)($status['wifi_status'] ?? 0) . "</b></div>
                    <div style='background:rgba(255,255,255,.06);border-radius:10px;padding:8px;'>SD<br><b>" . (int)($status['sd_status'] ?? 0) . "</b></div>
                    <div style='background:rgba(255,255,255,.06);border-radius:10px;padding:8px;'>Strom<br><b>" . (int)($status['alim_status'] ?? 0) . "</b></div>
                    <div style='background:rgba(255,255,255,.06);border-radius:10px;padding:8px;'>Zuletzt<br><b>{$lastSeenText}</b></div>
                </div>
                <div style='margin-top:10px;font-size:12px;color:rgba(255,255,255,.65);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;'>{$eventText}</div>
            </div>
        </div>";
    }
}
