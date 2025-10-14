<?php

declare(strict_types=1);

class TileVisuWeatherClockTile extends IPSModule
{
    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Config
        $this->RegisterPropertyInteger('TemperatureVariableID', 0);
        $this->RegisterPropertyString('Location', '');
        $this->RegisterPropertyBoolean('ShowWeather', true);
        $this->RegisterPropertyBoolean('ShowForecast', true);
        $this->RegisterPropertyInteger('CustomMediaID', 0);
        $this->RegisterPropertyBoolean('ShowClock', true);
        $this->RegisterPropertyBoolean('ShowDate', true);
        // Width configuration (% of window)
        $this->RegisterPropertyInteger('ForecastWidthPercent', 25);
        $this->RegisterPropertyInteger('ClockWidthPercent', 70);
        // Date font size in px (0 = default CSS)
        $this->RegisterPropertyInteger('DateFontSizePx', 30);

        // Register timers only in Create(); interval is set in ApplyChanges()
        $this->RegisterTimer('UpdateTimer', 3600000, "IPS_RequestAction(\$_IPS['TARGET'], 'UpdateNow', 0);");


        // Runtime (Subscriptions)
        $this->RegisterAttributeInteger('LastTemperatureVarID', 0);
        $this->RegisterAttributeString('LastSlug', '');
        $this->RegisterAttributeString('LastTimeOfDay', '');
    }

    public function Destroy()
    {
        // Never delete this line!
        parent::Destroy();
    }
    
    protected function ProcessHookData()
    {
        // Nicht mehr verwendet (keine Medien/WebHook-Auslieferung)
        http_response_code(404);
        echo 'Not supported';
    }

    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();

        // Aktiviert die HTML-SDK Darstellung (HTML-Kachel)
        // Hinweis: Signatur SetVisualizationType kann je nach IPS-Version variieren; hier Standardaufruf
        if (method_exists($this, 'SetVisualizationType')) {
            // 1 = HTML (gemäß HTML-SDK Dokumentation)
            @$this->SetVisualizationType(1);
        }

        // WebHook registrieren (Bilderauslieferung über /hook/wetterbilder/<InstanceID>)
        $this->RegisterHook('/hook/wetterbilder/' . $this->InstanceID);

        $temperatureVarId = (int)$this->ReadPropertyInteger('TemperatureVariableID');
        $previousVarId = (int)$this->ReadAttributeInteger('LastTemperatureVarID');
        $showWeather = (bool)$this->ReadPropertyBoolean('ShowWeather');

        if ($previousVarId > 0 && $previousVarId !== $temperatureVarId) {
            @$this->UnregisterMessage($previousVarId, VM_UPDATE);
            $this->UnregisterReference($previousVarId);
        }

        if ($temperatureVarId > 0 && IPS_VariableExists($temperatureVarId)) {
            $this->RegisterMessage($temperatureVarId, VM_UPDATE);
            $this->RegisterReference($temperatureVarId);
            $this->WriteAttributeInteger('LastTemperatureVarID', $temperatureVarId);
        } else {
            $this->WriteAttributeInteger('LastTemperatureVarID', 0);
        }

        // Set periodic timer interval: every 60 minutes (3600000 ms)
        $this->SetTimerInterval('UpdateTimer', 3600000);

        // Trigger immediate update
        $this->sendImageUpdate();
        if ($showWeather) {
            $this->sendTemperatureUpdate();
            $this->sendForecastUpdate();
        }
    }

    public function GetVisualizationTile()
    {
        // Liefert den HTML-Inhalt der Kachel (HTML-SDK)
        $path = __DIR__ . DIRECTORY_SEPARATOR . 'module.html';
        if (is_file($path)) {
            return file_get_contents($path);
        }
        return '<div style="padding:1rem;color:#fff;background:#000;">module.html not found</div>';
    }

    /**
     * Manuell/Timer: Zustände aktualisieren (Open-Meteo basiert)
     */
    public function UpdateNow(): void
    {
        $this->sendImageUpdate();
        if ((bool)$this->ReadPropertyBoolean('ShowWeather')) {
            $this->sendTemperatureUpdate();
            $this->sendForecastUpdate();
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'UpdateNow':
                $this->UpdateNow();
                return true;
            case 'GetState':
                // Ermittele aktuellen Zustand (Slug/ToD) und referenziere Nutzer-Medienobjekt
                $this->UpdateNow();
                return true;
            case 'WebhookGetName':
                // Liefert den aktuellen Basisnamen (slug-day|night)
                $slug = strtolower(trim($this->ReadAttributeString('LastSlug') ?: ''));
                $tod  = strtolower(trim($this->ReadAttributeString('LastTimeOfDay') ?: ''));
                if ($slug !== '' && ($tod === 'day' || $tod === 'night')) {
                    return $slug . '-' . $tod;
                }
                return '';
        }
        throw new Exception('Invalid Ident');
    }

    // ----------------------------- Helpers -----------------------------

    /**
     * Registriert/aktualisiert einen WebHook-Eintrag im WebHook Control und verknüpft ihn
     * mit einem automatisch erzeugten Script, welches die Bildauslieferung übernimmt.
     */
    private function RegisterHook(string $hookPath): void
    {
        // WebHook Control Modul-ID
        $webhookModuleId = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';
        $ids = @IPS_GetInstanceListByModuleID($webhookModuleId);
        if (!is_array($ids) || count($ids) === 0) {
            $this->LogMessage($this->Translate('WebHook Control not found. Skipping hook registration.'), KL_WARNING);
            return;
        }
        $whId = $ids[0];

        // Sicherstellen, dass das Script existiert
        $scriptId = $this->ensureHookScript();

        // Hooks laden und anpassen
        $hooks = @json_decode(IPS_GetProperty($whId, 'Hooks'), true);
        if (!is_array($hooks)) {
            $hooks = [];
        }
        $found = false;
        foreach ($hooks as &$h) {
            if (isset($h['Hook']) && $h['Hook'] === $hookPath) {
                $h['TargetID'] = $scriptId;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $hooks[] = [
                'Hook' => $hookPath,
                'TargetID' => $scriptId
            ];
        }

        IPS_SetProperty($whId, 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($whId);
    }

    /**
     * Erzeugt/aktualisiert das Hook-Script, das vom WebHook Control aufgerufen wird.
     * Gibt die ScriptID zurück.
     */
    private function ensureHookScript(): int
    {
        $ident = 'HookScript';
        $sid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);

        $baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'wetterbilder';
        $assetDir = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'flipclock';
        $code = <<<'PHP'
<?php
if (isset($_IPS['SENDER']) && $_IPS['SENDER'] === 'WebHook') {
    $iid = %d;
    $baseDir = '%s';
    $assetDir = '%s';

    // Serve local assets (no CDN)
    if (isset($_GET['asset']) && is_string($_GET['asset'])) {
        $allowed = ['flipclock.min.js', 'flipclock.min.css'];
        $asset = basename($_GET['asset']);
        if (in_array($asset, $allowed, true)) {
            $path = rtrim($assetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $asset;
            if (@is_file($path)) {
                if (substr($asset, -3) === '.js') {
                    header('Content-Type: application/javascript; charset=utf-8');
                } elseif (substr($asset, -4) === '.css') {
                    header('Content-Type: text/css; charset=utf-8');
                } else {
                    header('Content-Type: application/octet-stream');
                }
                header('Cache-Control: public, max-age=86400');
                header('Pragma: cache');
                readfile($path);
                return;
            }
        }
        http_response_code(404);
        echo 'asset not found';
        return;
    }

    // Custom media override when weather is disabled or explicitly requested
    $showWeatherRaw = @IPS_GetProperty($iid, 'ShowWeather');
    $showWeather = false;
    if (is_bool($showWeatherRaw)) {
        $showWeather = $showWeatherRaw;
    } elseif (is_string($showWeatherRaw)) {
        $lr = strtolower(trim($showWeatherRaw));
        $showWeather = ($lr === 'true' || $lr === '1');
    } elseif (is_int($showWeatherRaw)) {
        $showWeather = ($showWeatherRaw !== 0);
    }
    $customMediaId = (int)@IPS_GetProperty($iid, 'CustomMediaID');
    $useCustom = (!$showWeather) && ($customMediaId > 0);
    if (isset($_GET['custom'])) { $useCustom = true; }
    if ($useCustom) {
        $b64 = @IPS_GetMediaContent($customMediaId);
        if (is_string($b64) && $b64 !== '') {
            // Try detecting content type from header
            $mime = 'image/jpeg';
            $bin = @base64_decode($b64, true);
            if ($bin !== false && strlen($bin) >= 12) {
                $h = substr($bin, 0, 12);
                if (strncmp($h, "\xFF\xD8\xFF", 3) === 0) {
                    $mime = 'image/jpeg';
                } elseif (strncmp($h, "\x89PNG\x0D\x0A\x1A\x0A", 8) === 0) {
                    $mime = 'image/png';
                } elseif (strncmp($h, 'GIF87a', 6) === 0 || strncmp($h, 'GIF89a', 6) === 0) {
                    $mime = 'image/gif';
                } elseif (substr($h, 0, 4) === 'RIFF' && substr($h, 8, 4) === 'WEBP') {
                    $mime = 'image/webp';
                }
            }
            $dataUri = 'data:' . $mime . ';base64,' . $b64;
            header('Content-Type: application/json');
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');
            echo json_encode(['dataUri' => $dataUri, 'name' => 'custom-media', 'ext' => '', 'ts' => time()]);
            return;
        }
        // do not fallback to weather images when custom is requested or weather is disabled
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['dataUri' => '', 'name' => 'custom-media', 'ext' => '', 'ts' => time()]);
        return;
    }

    // Determiniere Name: direkt via ?name=..., oder aus ?slug= & ?tod=, sonst vom Modul
    $name = '';
    if (isset($_GET['name']) && is_string($_GET['name'])) {
        $name = strtolower(trim($_GET['name']));
    } else {
        $slug = isset($_GET['slug']) ? strtolower(trim((string)$_GET['slug'])) : '';
        $tod  = isset($_GET['tod'])  ? strtolower(trim((string)$_GET['tod']))  : '';
        if ($slug !== '' && ($tod === 'day' || $tod === 'night')) {
            $name = $slug . '-' . $tod;
        }
    }
    if ($name === '') {
        $name = IPS_RequestAction($iid, 'WebhookGetName', 0);
    }
    if (!is_string($name) || $name === '') {
        http_response_code(404);
        echo 'Kein Bild verfügbar';
        return;
    }

    // Prüfe die möglichen Endungen im lokalen Verzeichnis und liefere das erste gefundene Bild aus
    $exts = ['jpg','jpeg','png','webp'];
    foreach ($exts as $ext) {
        $basePath = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $candidates = [
            $basePath . $name . '-min.' . $ext,
            $basePath . $name . '.' . $ext,
        ];
        foreach ($candidates as $file) {
            if (@is_file($file)) {
                $ct = 'image/jpeg';
                if ($ext === 'png') $ct = 'image/png';
                elseif ($ext === 'webp') $ct = 'image/webp';
                $bin = @file_get_contents($file);
                if ($bin !== false) {
                    $b64 = base64_encode($bin);
                    $dataUri = 'data:' . $ct . ';base64,' . $b64;
                    header('Content-Type: application/json');
                    header('Cache-Control: no-cache, must-revalidate');
                    header('Pragma: no-cache');
                    echo json_encode(['dataUri' => $dataUri, 'name' => $name, 'ext' => $ext, 'ts' => time()]);
                    return;
                }
            }
        }
    }
    http_response_code(404);
    echo 'Kein Bild verfügbar';
}
PHP;
        $code = sprintf($code, (int)$this->InstanceID, addslashes($baseDir), addslashes($assetDir));

        if ($sid && @IPS_ObjectExists($sid)) {
            IPS_SetScriptContent($sid, $code);
            return $sid;
        }

        $sid = IPS_CreateScript(0); // 0 = PHP Script
        IPS_SetName($sid, 'Wetterbilder WebHook');
        IPS_SetParent($sid, $this->InstanceID);
        IPS_SetIdent($sid, $ident);
        IPS_SetHidden($sid, true);
        IPS_SetScriptContent($sid, $code);
        return $sid;
    }
    

    private function buildVisualizationPayload(string $url, string $slug, string $tod): array
    {
        $ts = time();
        $assetBase = '/hook/wetterbilder/' . $this->InstanceID;
        // Clamp width percentages to [5..100]
        $fw = max(5, min(100, (int)$this->ReadPropertyInteger('ForecastWidthPercent')));
        $cw = max(5, min(100, (int)$this->ReadPropertyInteger('ClockWidthPercent')));
        // Clamp date font size to [0..200] (0 disables custom size)
        $df = (int)$this->ReadPropertyInteger('DateFontSizePx');
        if ($df < 0) { $df = 0; }
        if ($df > 200) { $df = 200; }
        $showForecast = (bool)$this->ReadPropertyBoolean('ShowForecast');
        return [
            'type'      => 'image',
            'url'       => $url,
            'slug'      => $slug,
            'timeOfDay' => $tod,
            'ts'        => $ts,
            'temperature' => $this->getTemperaturePayload(),
            'forecast'    => $this->getForecastPayload(),
            'showWeather' => (bool)$this->ReadPropertyBoolean('ShowWeather'),
            'showClock'   => (bool)$this->ReadPropertyBoolean('ShowClock'),
            'showDate'    => (bool)$this->ReadPropertyBoolean('ShowDate'),
            'assetBase'   => $assetBase,
            'forecastWidthPercent' => $fw,
            'clockWidthPercent'    => $cw,
            'dateFontSizePx'       => $df,
            'showForecast'         => $showForecast
        ];
    }

    private function sendImageUpdate(): void
    {
        if (!method_exists($this, 'UpdateVisualizationValue')) {
            return;
        }
        $showWeather = (bool)$this->ReadPropertyBoolean('ShowWeather');
        $showClock   = (bool)$this->ReadPropertyBoolean('ShowClock');
        $customMediaId = (int)$this->ReadPropertyInteger('CustomMediaID');
        $hasCustom = $this->mediaHasContent($customMediaId);
        // Clamp width percentages to [5..100]
        $fw = max(5, min(100, (int)$this->ReadPropertyInteger('ForecastWidthPercent')));
        $cw = max(5, min(100, (int)$this->ReadPropertyInteger('ClockWidthPercent')));
        $df = (int)$this->ReadPropertyInteger('DateFontSizePx');
        if ($df < 0) { $df = 0; }
        if ($df > 200) { $df = 200; }

        // If weather display is disabled: optionally show custom media image; no weather details
        if (!$showWeather) {
            $url = '';
            if ($hasCustom) {
                $url = '/hook/wetterbilder/' . $this->InstanceID . '?custom=1';
            }
            $payload = [
                'type'        => 'image',
                'url'         => $url,
                'slug'        => '',
                'timeOfDay'   => '',
                'ts'          => time(),
                'temperature' => null,
                'forecast'    => [],
                'showWeather' => false,
                'showClock'   => $showClock,
                'showDate'    => (bool)$this->ReadPropertyBoolean('ShowDate'),
                'assetBase'   => '/hook/wetterbilder/' . $this->InstanceID,
                'forecastWidthPercent' => $fw,
                'clockWidthPercent'    => $cw,
                'dateFontSizePx'       => $df,
                'showForecast'         => false
            ];
            $this->UpdateVisualizationValue(json_encode($payload));
            return;
        }

        // If a custom media is configured and has content, prefer it as background even when weather is enabled
        if ($hasCustom) {
            $url = '/hook/wetterbilder/' . $this->InstanceID . '?custom=1';
            $payload = $this->buildVisualizationPayload($url, '', '');
            $this->UpdateVisualizationValue(json_encode($payload));
            return;
        }

        // Dynamic weather image via Open-Meteo
        $data = $this->fetchOpenMeteo();
        $wmoCode = 0;
        $isDay = null;
        if (is_array($data) && isset($data['current']) && is_array($data['current'])) {
            if (isset($data['current']['weather_code'])) {
                $wmoCode = (int)$data['current']['weather_code'];
            }
            if (isset($data['current']['is_day'])) {
                $isDay = ((int)$data['current']['is_day'] === 1);
            }
        }
        if ($isDay === null) {
            $h = (int)date('G');
            $isDay = ($h >= 6 && $h < 20);
        }

        $name = $this->mapWMOToBackgroundName($wmoCode, $isDay);
        $slug = $name;
        $tod  = $isDay ? 'day' : 'night';
        if (preg_match('/-(day|night)$/i', $name, $m)) {
            $tod = strtolower($m[1]);
            $slug = substr($name, 0, - (strlen($m[1]) + 1));
        }

        $this->WriteAttributeString('LastSlug', $slug);
        $this->WriteAttributeString('LastTimeOfDay', $tod);

        $baseName = $slug . '-' . $tod;
        // Ensure the chosen background actually exists; otherwise pick a safe fallback
        $resolved = $this->resolveExistingBackground($baseName, $tod);
        if ($resolved !== $baseName) {
            $this->SendDebug('Image', 'Fallback background from ' . $baseName . ' to ' . $resolved, 0);
            $baseName = $resolved;
        } else {
            $this->SendDebug('Image', 'Using background ' . $baseName, 0);
        }
        $webhookUrl = '/hook/wetterbilder/' . $this->InstanceID . '?name=' . rawurlencode($baseName);

        $payload = $this->buildVisualizationPayload($webhookUrl, $slug, $tod);
        $this->UpdateVisualizationValue(json_encode($payload));
    }

    private function mediaHasContent(int $mediaId): bool
    {
        if ($mediaId <= 0) {
            return false;
        }
        if (function_exists('IPS_MediaExists') && !@IPS_MediaExists($mediaId)) {
            return false;
        }
        $b64 = @IPS_GetMediaContent($mediaId);
        return is_string($b64) && $b64 !== '';
    }

    private function resolveExistingBackground(string $baseName, string $tod): string
    {
        $dir = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'wetterbilder' . DIRECTORY_SEPARATOR;
        $exts = ['jpg','jpeg','png','webp'];
        $candidates = function(string $name) use ($exts, $dir): array {
            $items = [];
            foreach ($exts as $e) {
                $items[] = $dir . $name . '-min.' . $e;
                $items[] = $dir . $name . '.' . $e;
            }
            return $items;
        };
        // 1) given name
        foreach ($candidates($baseName) as $f) {
            if (@is_file($f)) return $baseName;
        }
        // 2) hazy-<tod>
        $hazy = 'hazy-' . $tod;
        foreach ($candidates($hazy) as $f) {
            if (@is_file($f)) return $hazy;
        }
        // 3) generic sunny/clear
        $generic = ($tod === 'day') ? 'sunny-day' : 'clear-sky-night';
        foreach ($candidates($generic) as $f) {
            if (@is_file($f)) return $generic;
        }
        return $baseName; // give original back; webhook may still handle
    }

    private function mapIconToG5(int|string $iconCode, ?string $dayOrNight = null): string
    {
        $code = (int)$iconCode;
        $dn   = (strtoupper((string)$dayOrNight) === 'N') ? 'night' : 'day';

        // 1) Codes mit festem, von TWC vorgegebenem Tag/Nacht-Status
        $exact = [
            29 => 'partly-cloudy-night',
            30 => 'sunny-intervals-day',
            31 => 'clear-sky-night',
            32 => 'sunny-day',
            33 => 'white-cloud-night',
            34 => 'white-cloud-day',
            39 => 'light-rain-shower-day',
            45 => 'light-rain-shower-night',
            41 => 'light-snow-shower-day',
            46 => 'light-snow-shower-night',
            38 => 'thunderstorm-shower-day',
            47 => 'thunderstorm-shower-night',
        ];
        if (isset($exact[$code])) return $exact[$code];

        // 2) Neutrale Codes -> Basis + -day/-night anhängen
        $byDn = [
            11 => 'light-rain-shower',
            12 => 'light-rain',
            40 => 'heavy-rain',
            9  => 'drizzle',
            8  => 'drizzle',
            10 => 'sleet',
            6  => 'sleet',
            7  => 'sleet',
            18 => 'sleet',
            17 => 'hail',
            35 => 'hail-shower',
            13 => 'light-snow',
            14 => 'light-snow-shower',
            15 => 'heavy-snow',
            16 => 'light-snow',
            42 => 'heavy-snow',
            43 => 'heavy-snow',
            3  => 'thunderstorm',
            4  => 'thunderstorm',
            20 => 'fog',
            21 => 'hazy',
            22 => 'hazy',
            26 => 'thick-cloud',
            27 => 'thick-cloud',
            28 => 'thick-cloud',
            23 => 'white-cloud',
            24 => 'white-cloud',
            19 => 'sandstorm',
            1  => 'tropicalstorm',
            2  => 'tropicalstorm',
            0  => 'thunderstorm',
            44 => 'hazy'
        ];
        if (isset($byDn[$code])) return $byDn[$code] . '-' . $dn;

        // Letzter Fallback
        return 'hazy-' . $dn;
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ($Message === VM_UPDATE) {
            $temperatureVarId = (int)$this->ReadPropertyInteger('TemperatureVariableID');
            if ($temperatureVarId > 0 && $SenderID === $temperatureVarId) {
                $this->sendTemperatureUpdate();
            }
        }
    }

    

    

    private function sendTemperatureUpdate(): void
    {
        if (!method_exists($this, 'UpdateVisualizationValue')) {
            return;
        }
        if (!(bool)$this->ReadPropertyBoolean('ShowWeather')) {
            return;
        }
        $fw = max(5, min(100, (int)$this->ReadPropertyInteger('ForecastWidthPercent')));
        $cw = max(5, min(100, (int)$this->ReadPropertyInteger('ClockWidthPercent')));
        $df = (int)$this->ReadPropertyInteger('DateFontSizePx');
        if ($df < 0) { $df = 0; }
        if ($df > 200) { $df = 200; }
        $payload = [
            'type' => 'temperature',
            'temperature' => $this->getTemperaturePayload(),
            'ts' => time(),
            'showWeather' => (bool)$this->ReadPropertyBoolean('ShowWeather'),
            'showClock'   => (bool)$this->ReadPropertyBoolean('ShowClock'),
            'showDate'    => (bool)$this->ReadPropertyBoolean('ShowDate'),
            'assetBase'   => '/hook/wetterbilder/' . $this->InstanceID,
            'forecastWidthPercent' => $fw,
            'clockWidthPercent'    => $cw,
            'dateFontSizePx'       => $df,
            'showForecast'         => (bool)$this->ReadPropertyBoolean('ShowForecast')
        ];
        $this->UpdateVisualizationValue(json_encode($payload));
    }

    private function getTemperaturePayload(): array
    {
        $result = [
            'value' => '',
            'icon' => '',
            'iconUrl' => ''
        ];

        $variableId = (int)$this->ReadPropertyInteger('TemperatureVariableID');
        if ($variableId > 0 && IPS_VariableExists($variableId)) {
            $formatted = @GetValueFormatted($variableId);
            if (is_string($formatted)) {
                $result['value'] = $formatted;
                return $result;
            }
        }

        // Fallback auf Open-Meteo aktuelle Temperatur
        $data = $this->fetchOpenMeteo();
        if (is_array($data) && isset($data['current']) && isset($data['current']['temperature_2m'])) {
            $t = (float)$data['current']['temperature_2m'];
            $result['value'] = $this->formatTemperatureValue($t);
        }

        return $result;
    }

    private function getForecastPayload(): array
    {
        $out = [];
        $data = $this->fetchOpenMeteo();
        if (!is_array($data) || !isset($data['daily']) || !is_array($data['daily'])) {
            $this->SendDebug('Forecast', 'No daily data in Open-Meteo response', 0);
            return $out;
        }
        $daily = $data['daily'];
        $times = isset($daily['time']) && is_array($daily['time']) ? $daily['time'] : [];
        $codes = isset($daily['weather_code']) && is_array($daily['weather_code']) ? $daily['weather_code'] : [];
        $tMax  = isset($daily['temperature_2m_max']) && is_array($daily['temperature_2m_max']) ? $daily['temperature_2m_max'] : [];
        $tMin  = isset($daily['temperature_2m_min']) && is_array($daily['temperature_2m_min']) ? $daily['temperature_2m_min'] : [];

        $count = min(4, count($times), count($codes), count($tMax), count($tMin));
        for ($i = 0; $i < $count; $i++) {
            $date = (string)($times[$i] ?? '');
            $label = $this->germanDayNameFromDate($date);
            $max   = is_numeric($tMax[$i] ?? null) ? (int)round($tMax[$i]) : null;
            $min   = is_numeric($tMin[$i] ?? null) ? (int)round($tMin[$i]) : null;
            $code  = (int)($codes[$i] ?? 0);
            // Use WU icon set (assume day icons for daily forecast)
            $wuCode = $this->mapWMOToWUCode($code, true);
            $iconUrl = $this->getWUIconDataURI($wuCode);
            $out[] = [
                'label' => $label,
                'max' => $max,
                'min' => $min,
                'icon' => '',
                'iconUrl' => $iconUrl
            ];
        }
        $this->SendDebug('Forecast', 'Built items: ' . count($out), 0);
        return $out;
    }

    private function mapWMOToWUCode(int $code, bool $isDay): int
    {
        switch ($code) {
            case 0:  return $isDay ? 32 : 31; // sunny / clear night
            case 1:  return $isDay ? 34 : 33; // fair
            case 2:  return $isDay ? 30 : 29; // partly cloudy
            case 3:  return 26;               // cloudy
            case 45:
            case 48: return 20;               // fog
            case 51:
            case 53:
            case 55: return 9;                // drizzle
            case 56:
            case 57: return 8;                // freezing drizzle
            case 61:
            case 63: return 11;               // showers/light rain
            case 65: return 12;               // heavy rain/showers
            case 66:
            case 67: return 10;               // freezing rain
            case 71: return 14;               // light snow showers
            case 73: return 16;               // snow
            case 75: return 41;               // heavy snow
            case 77: return 13;               // snow flurries
            case 80:
            case 81: return 11;               // rain showers
            case 82: return 12;               // heavy/violent rain showers
            case 85: return 14;               // snow showers slight
            case 86: return 46;               // snow showers heavy
            case 95: return 4;                // thunderstorm
            case 96:
            case 99: return 45;               // thundershowers (hail)
            default: return 26;               // cloudy fallback
        }
    }

    private function fetchOpenMeteo(): ?array
    {
        static $cache = null;
        static $cacheUrl = '';
        [$lat, $lon] = $this->resolveLocation();
        $url = $this->buildOpenMeteoUrl($lat, $lon);
        if ($cache !== null && $cacheUrl === $url) {
            return $cache;
        }
        $this->SendDebug('OpenMeteo', 'Fetch URL: ' . $url, 0);
        $content = '';
        if (function_exists('Sys_GetURLContentEx')) {
            // Timeout in ms; allow redirects
            $content = @Sys_GetURLContentEx($url, [
                'Timeout' => 15000,
                'FollowLocation' => true,
                'HttpHeader' => [
                    'Accept: application/json',
                    'User-Agent: TileVisuWeatherClockTile/1.0'
                ]
            ]);
        }
        if (!is_string($content) || $content === '') {
            if (function_exists('Sys_GetURLContent')) {
                $content = @Sys_GetURLContent($url);
            }
        }
        if (!is_string($content) || $content === '') {
            // Try cURL if available
            if (function_exists('curl_init')) {
                $ch = @curl_init($url);
                if ($ch) {
                    @curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_CONNECTTIMEOUT => 10,
                        CURLOPT_TIMEOUT => 15,
                        CURLOPT_HTTPHEADER => [
                            'Accept: application/json',
                            'User-Agent: TileVisuWeatherClockTile/1.0'
                        ],
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_SSL_VERIFYHOST => 2,
                        CURLOPT_IPRESOLVE => defined('CURL_IPRESOLVE_V4') ? CURL_IPRESOLVE_V4 : 1
                    ]);
                    $res = @curl_exec($ch);
                    $http = (int)@curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $err = @curl_error($ch);
                    @curl_close($ch);
                    if (is_string($res) && $res !== '' && ($http >= 200 && $http < 300)) {
                        $content = $res;
                    } else {
                        $this->SendDebug('OpenMeteo', 'cURL error: HTTP ' . $http . ' ' . $err, 0);
                    }
                }
            }
        }
        if (!is_string($content) || $content === '') {
            // Fallback via streams with UA + Accept header
            $headers = [
                'Accept: application/json',
                'User-Agent: TileVisuWeatherClockTile/1.0'
            ];
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 15,
                    'ignore_errors' => true,
                    'header' => implode("\r\n", $headers)
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true
                ]
            ]);
            $content = @file_get_contents($url, false, $ctx);
        }
        if (!is_string($content) || $content === '') {
            // Last resort: insecure (not recommended). Attempt only if everything else failed.
            $this->SendDebug('OpenMeteo', 'Retry insecure SSL fallback', 0);
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 15,
                    'ignore_errors' => true,
                    'header' => "Accept: application/json\r\nUser-Agent: TileVisuWeatherClockTile/1.0"
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]);
            $content = @file_get_contents($url, false, $ctx);
        }
        if (!is_string($content) || $content === '') {
            $this->SendDebug('OpenMeteo', 'Empty response or HTTP error', 0);
            return null;
        }
        $this->SendDebug('OpenMeteo', 'Response length: ' . strlen($content), 0);
        $data = @json_decode($content, true);
        $cache = is_array($data) ? $data : null;
        $cacheUrl = $url;
        if (!is_array($data)) {
            $this->SendDebug('OpenMeteo', 'JSON decode failed', 0);
        }
        return $cache;
    }

    private function resolveLocation(): array
    {
        // Default: North Pole
        $default = [90.0, 0.0];
        $raw = trim((string)$this->ReadPropertyString('Location'));
        if ($raw === '') {
            return $default;
        }
        $loc = @json_decode($raw, true);
        if (!is_array($loc)) {
            return $default;
        }
        $lat = $loc['latitude'] ?? $loc['lat'] ?? null;
        $lon = $loc['longitude'] ?? $loc['lon'] ?? null;
        if (is_numeric($lat) && is_numeric($lon)) {
            return [ (float)$lat, (float)$lon ];
        }
        return $default;
    }

    private function buildOpenMeteoUrl(float $lat, float $lon): string
    {
        $params = [
            'latitude' => (string)$lat,
            'longitude' => (string)$lon,
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min',
            'hourly' => 'is_day,weather_code,temperature_2m',
            'models' => 'icon_seamless',
            'current' => 'temperature_2m,is_day,weather_code',
            'timezone' => 'Europe/Berlin',
            'forecast_days' => 5
        ];
        return 'https://api.open-meteo.com/v1/forecast?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function mapWMOToBackgroundName(int $code, bool $isDay): string
    {
        $dn = $isDay ? 'day' : 'night';
        // WMO mapping to available wetterbilder slugs
        if ($code === 0) return $isDay ? 'sunny-day' : 'clear-sky-night';
        if (in_array($code, [1], true)) return $isDay ? 'white-cloud-day' : 'white-cloud-night';
        if (in_array($code, [2], true)) return $isDay ? 'sunny-intervals-day' : 'partly-cloudy-night';
        if (in_array($code, [3], true)) return 'thick-cloud-' . $dn;
        if (in_array($code, [45,48], true)) return 'fog-' . $dn; // or mist-
        if (in_array($code, [51,53,55], true)) return 'drizzle-' . $dn;
        if (in_array($code, [56,57], true)) return 'sleet-' . $dn; // freezing drizzle
        if ($code === 61) return 'light-rain-' . $dn;
        if ($code === 63) return 'light-rain-' . $dn;
        if ($code === 65) return 'heavy-rain-' . $dn;
        if (in_array($code, [66,67], true)) return 'sleet-' . $dn; // freezing rain
        if ($code === 71) return 'light-snow-shower-' . $dn;
        if ($code === 73) return 'heavy-snow-' . $dn;
        if ($code === 75) return 'heavy-snow-' . $dn;
        if ($code === 77) return 'light-snow-shower-' . $dn; // snow grains
        if ($code === 80) return 'light-rain-shower-' . $dn;
        if ($code === 81) return 'light-rain-shower-' . $dn;
        if ($code === 82) return 'heavy-rain-shower-' . $dn;
        if ($code === 85) return 'light-snow-shower-' . $dn;
        if ($code === 86) return 'heavy-snow-shower-' . $dn;
        if ($code === 95) return 'thunderstorm-' . $dn;
        if (in_array($code, [96,99], true)) return 'thunderstorm-shower-' . $dn;
        return 'hazy-' . $dn;
    }

    private function mapWMOToFA(int $code, bool $isDay): string
    {
        // Minimal FA mapping for forecast icons
        $base = 'cloud';
        switch ($code) {
            case 0:
            case 1:
                $base = $isDay ? 'sun' : 'moon';
                break;
            case 2:
                $base = $isDay ? 'cloud-sun' : 'cloud-moon';
                break;
            case 3:
                $base = 'clouds';
                break;
            case 45:
            case 48:
                $base = 'smog'; // fog
                break;
            case 51: case 53: case 55:
                $base = 'cloud-drizzle';
                break;
            case 61: case 63:
                $base = 'cloud-rain';
                break;
            case 65:
                $base = 'cloud-showers-heavy';
                break;
            case 66: case 67:
                $base = 'cloud-sleet';
                break;
            case 71: case 73: case 75: case 77:
                $base = 'snowflake';
                break;
            case 80: case 81:
                $base = 'cloud-sun-rain';
                break;
            case 82:
                $base = 'cloud-showers-heavy';
                break;
            case 85: case 86:
                $base = 'cloud-snow';
                break;
            case 95: case 96: case 99:
                $base = 'cloud-bolt';
                break;
        }
        return 'fa-light fa-' . $base;
    }

    private function germanDayNameFromDate(string $date): string
    {
        // $date format: YYYY-MM-DD
        try {
            $dt = new DateTime($date);
        } catch (Exception $e) {
            return '';
        }
        $en = $dt->format('l');
        $map = [
            'Monday' => 'Montag',
            'Tuesday' => 'Dienstag',
            'Wednesday' => 'Mittwoch',
            'Thursday' => 'Donnerstag',
            'Friday' => 'Freitag',
            'Saturday' => 'Samstag',
            'Sunday' => 'Sonntag'
        ];
        return $map[$en] ?? '';
    }

    private function formatTemperatureValue(float $t): string
    {
        return (string)round($t) . "°C";
    }

    private function getWUIconDataURI(int $code): string
    {
        $dir = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'icons' . DIRECTORY_SEPARATOR;
        $file = $dir . $code . '.png';
        if (!is_file($file)) {
            $file = $dir . 'na.png';
            if (!is_file($file)) {
                return '';
            }
        }
        $data = @file_get_contents($file);
        if ($data === false) {
            return '';
        }
        return 'data:image/png;base64,' . base64_encode($data);
    }

    private function sendForecastUpdate(): void
    {
        if (!method_exists($this, 'UpdateVisualizationValue')) {
            return;
        }
        if (!(bool)$this->ReadPropertyBoolean('ShowWeather')) {
            return;
        }
        $showForecast = (bool)$this->ReadPropertyBoolean('ShowForecast');
        $forecast = $this->getForecastPayload();
        $this->SendDebug('Forecast', 'Sending ' . count($forecast) . ' items (showForecast=' . ($showForecast ? 'true' : 'false') . ')', 0);
        $fw = max(5, min(100, (int)$this->ReadPropertyInteger('ForecastWidthPercent')));
        $cw = max(5, min(100, (int)$this->ReadPropertyInteger('ClockWidthPercent')));
        $df = (int)$this->ReadPropertyInteger('DateFontSizePx');
        if ($df < 0) { $df = 0; }
        if ($df > 200) { $df = 200; }
        $payload = [
            'type' => 'forecast',
            'forecast' => $forecast,
            'ts' => time(),
            'showWeather' => (bool)$this->ReadPropertyBoolean('ShowWeather'),
            'showClock'   => (bool)$this->ReadPropertyBoolean('ShowClock'),
            'showDate'    => (bool)$this->ReadPropertyBoolean('ShowDate'),
            'assetBase'   => '/hook/wetterbilder/' . $this->InstanceID,
            'forecastWidthPercent' => $fw,
            'clockWidthPercent'    => $cw,
            'dateFontSizePx'       => $df,
            'showForecast'         => $showForecast
        ];
        $this->UpdateVisualizationValue(json_encode($payload));
    }

    private function mapWUIconCodeToFA(int $code, bool $day): string
    {
        // Map Weather Underground/The Weather Company icon codes to Font Awesome slugs
        // Only a minimal subset needed for common conditions in our usage.
        $slug = 'cloud'; // default
        switch ($code) {
            case 32: // Sunny
                $slug = 'sun';
                break;
            case 31: // Clear night
            case 33: // Fair night
                $slug = 'moon';
                break;
            case 30: // Partly Cloudy (day)
                $slug = 'cloud-sun';
                break;
            case 29: // Partly Cloudy (night)
                $slug = 'cloud-moon';
                break;
            case 28: // Mostly Cloudy
                $slug = $day ? 'clouds-sun' : 'clouds-moon';
                break;
            case 27: // Mostly Cloudy (night)
                $slug = 'clouds-moon';
                break;
            case 26: // Cloudy
                $slug = 'clouds';
                break;
            case 11: // Light rain
            case 12: // Showers
                $slug = 'cloud-rain';
                break;
            case 39: // Scattered Showers (day)
                $slug = 'cloud-sun-rain';
                break;
            case 40: // Scattered Showers (night)
                $slug = 'cloud-moon-rain';
                break;
            case 15: // Thunderstorm
            case 4:
                $slug = 'cloud-bolt';
                break;
            case 13: // Snow flurries
            case 14: // Light snow
                $slug = 'snowflake';
                break;
        }
        // Ensure fa-light style
        return 'fa-light fa-' . $slug;
    }

    private function getIconHelper(): \TileVisu\Lib\IconHelper
    {
        static $helper = null;
        if ($helper === null) {
            require_once __DIR__ . '/libs/IconHelper.php';
            $helper = new \TileVisu\Lib\IconHelper();
        }
        return $helper;
    }

    // Externe Helfer
    private function httpGet(string $url): string { return ''; }

    private function httpGetBinary(string $url): string { return ''; }

    private function curlFetch(string $url, array $headers, bool $binary, bool $insecure): string { return ''; }

    private function buildReferer(string $url): string { return ''; }

    private function tryAlternatePageUrls(string $url): string { return ''; }

    private function rebuildUrlWithHost(array $parts, string $newHost): string { return ''; }
}

// PHP Stub-Funktionen (leer)
