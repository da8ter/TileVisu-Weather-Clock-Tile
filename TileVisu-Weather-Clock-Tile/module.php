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
        $this->RegisterPropertyInteger('ForecastVariableID', 0);

        // Runtime (Subscriptions)
        $this->RegisterAttributeInteger('LastTemperatureVarID', 0);
        $this->RegisterAttributeInteger('LastForecastVarID', 0);
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

        // Forecast variable subscription
        $forecastVarId = (int)$this->ReadPropertyInteger('ForecastVariableID');
        $prevForecast = (int)$this->ReadAttributeInteger('LastForecastVarID');
        if ($prevForecast > 0 && $prevForecast !== $forecastVarId) {
            @$this->UnregisterMessage($prevForecast, VM_UPDATE);
            $this->UnregisterReference($prevForecast);
        }
        if ($forecastVarId > 0 && IPS_VariableExists($forecastVarId)) {
            $this->RegisterMessage($forecastVarId, VM_UPDATE);
            $this->RegisterReference($forecastVarId);
            $this->WriteAttributeInteger('LastForecastVarID', $forecastVarId);
        } else {
            $this->WriteAttributeInteger('LastForecastVarID', 0);
        }

        $this->sendImageUpdate();
        $this->sendTemperatureUpdate();
        $this->sendForecastUpdate();
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
     * Manuell/Timer: Zustände aktualisieren (WU basiert)
     */
    public function UpdateNow(): void
    {
        $this->sendImageUpdate();
        $this->sendTemperatureUpdate();
        $this->sendForecastUpdate();
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
        return [
            'type'      => 'image',
            'url'       => $url,
            'slug'      => $slug,
            'timeOfDay' => $tod,
            'ts'        => $ts,
            'temperature' => $this->getTemperaturePayload(),
            'forecast'    => $this->getForecastPayload()
        ];
    }

    private function sendImageUpdate(): void
    {
        if (!method_exists($this, 'UpdateVisualizationValue')) {
            return;
        }
        // Icon-Code aus der WU Forecast-Variable extrahieren
        $code = null;
        $forecastVarId = (int)$this->ReadPropertyInteger('ForecastVariableID');
        if ($forecastVarId > 0 && IPS_VariableExists($forecastVarId)) {
            $raw = @GetValue($forecastVarId);
            if (!is_string($raw) || trim($raw) === '') {
                $raw = @GetValueFormatted($forecastVarId);
            }
            $data = @json_decode((string)$raw, true);
            if (is_array($data)) {
                $dp = [];
                if (isset($data['daypart']) && is_array($data['daypart']) && isset($data['daypart'][0]) && is_array($data['daypart'][0])) {
                    $dp = $data['daypart'][0];
                }
                $dpIcon = isset($dp['iconCode']) && is_array($dp['iconCode']) ? $dp['iconCode'] : [];
                $dpDN   = isset($dp['dayOrNight']) && is_array($dp['dayOrNight']) ? $dp['dayOrNight'] : [];
                // Wähle den ersten DayPart passend zu aktuellem D/N
                $hour = (int)date('G');
                $dnLetter = ($hour >= 20 || $hour < 6) ? 'N' : 'D';
                for ($j = 0; $j < count($dpIcon); $j++) {
                    if (($dpDN[$j] ?? '') === $dnLetter && $dpIcon[$j] !== null) {
                        $code = (int)$dpIcon[$j];
                        break;
                    }
                }
                // Wenn nichts gefunden, nimm das erste vorhandene
                if ($code === null) {
                    for ($j = 0; $j < count($dpIcon); $j++) {
                        if ($dpIcon[$j] !== null) { $code = (int)$dpIcon[$j]; break; }
                    }
                }
            }
        }

        // Day/Night bestimmen (lokale Zeit, falls nicht anders vorhanden)
        $hour = (int)date('G');
        $dnLetter = ($hour >= 20 || $hour < 6) ? 'N' : 'D';

        $name = $this->mapIconToG5($code ?? 44, $dnLetter); // 44 -> N/A -> hazy
        $slug = $name;
        $tod  = ($dnLetter === 'N') ? 'night' : 'day';
        if (preg_match('/-(day|night)$/i', $name, $m)) {
            $tod = strtolower($m[1]);
            $slug = substr($name, 0, - (strlen($m[1]) + 1));
        }

        // Speichere letzten Zustand, damit WebHook ohne Parameter ausliefern kann
        $this->WriteAttributeString('LastSlug', $slug);
        $this->WriteAttributeString('LastTimeOfDay', $tod);

        // WebHook-URL bereitstellen
        $baseName = $slug . '-' . $tod;
        $webhookUrl = '/hook/wetterbilder/' . $this->InstanceID . '?name=' . rawurlencode($baseName);

        $payload = $this->buildVisualizationPayload($webhookUrl, $slug, $tod);
        $this->UpdateVisualizationValue(json_encode($payload));
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
            $forecastVarId = (int)$this->ReadPropertyInteger('ForecastVariableID');
            if ($forecastVarId > 0 && $SenderID === $forecastVarId) {
                $this->sendForecastUpdate();
                $this->sendImageUpdate();
                $this->sendTemperatureUpdate();
            }
        }
    }

    

    

    private function sendTemperatureUpdate(): void
    {
        if (!method_exists($this, 'UpdateVisualizationValue')) {
            return;
        }
        $payload = [
            'type' => 'temperature',
            'temperature' => $this->getTemperaturePayload(),
            'ts' => time()
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
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return $result;
        }

        $formatted = @GetValueFormatted($variableId);

        if (is_string($formatted)) {
            $result['value'] = $formatted;
        }

        return $result;
    }

    private function getForecastPayload(): array
    {
        $out = [];
        $variableId = (int)$this->ReadPropertyInteger('ForecastVariableID');
        if ($variableId <= 0 || !IPS_VariableExists($variableId)) {
            return $out;
        }

        $raw = @GetValue($variableId);
        if (!is_string($raw) || trim($raw) === '') {
            $raw = @GetValueFormatted($variableId);
            if (!is_string($raw)) {
                return $out;
            }
        }

        $data = @json_decode($raw, true);
        if (!is_array($data)) {
            return $out;
        }

        $days = isset($data['dayOfWeek']) && is_array($data['dayOfWeek']) ? $data['dayOfWeek'] : [];
        $tMax = isset($data['temperatureMax']) && is_array($data['temperatureMax']) ? $data['temperatureMax'] : (isset($data['calendarDayTemperatureMax']) && is_array($data['calendarDayTemperatureMax']) ? $data['calendarDayTemperatureMax'] : []);
        $tMin = isset($data['temperatureMin']) && is_array($data['temperatureMin']) ? $data['temperatureMin'] : (isset($data['calendarDayTemperatureMin']) && is_array($data['calendarDayTemperatureMin']) ? $data['calendarDayTemperatureMin'] : []);

        $dp = [];
        if (isset($data['daypart']) && is_array($data['daypart']) && isset($data['daypart'][0]) && is_array($data['daypart'][0])) {
            $dp = $data['daypart'][0];
        }
        $dpIcon = isset($dp['iconCode']) && is_array($dp['iconCode']) ? $dp['iconCode'] : [];
        $dpDN   = isset($dp['dayOrNight']) && is_array($dp['dayOrNight']) ? $dp['dayOrNight'] : [];

        $count = min(4, count($days), count($tMax), count($tMin));
        for ($i = 0; $i < $count; $i++) {
            $label = is_string($days[$i] ?? '') ? (string)$days[$i] : '';
            $max   = is_numeric($tMax[$i] ?? null) ? (int)$tMax[$i] : null;
            $min   = is_numeric($tMin[$i] ?? null) ? (int)$tMin[$i] : null;

            // Try daypart icon: sequence is typically D,N,D,N,... so use index i*2
            $idx = $i * 2;
            $code = null;
            if (isset($dpIcon[$idx]) && $dpIcon[$idx] !== null && ($dpDN[$idx] ?? '') === 'D') {
                $code = (int)$dpIcon[$idx];
            }
            $iconCode = (int)($code ?? 26); // default to cloudy
            $iconUrl = $this->getWUIconDataURI($iconCode);
            // Keep FA as a fallback, though frontend will prefer iconUrl
            $icon = $this->mapWUIconCodeToFA($iconCode, true);

            $out[] = [
                'label' => $label,
                'max' => $max,
                'min' => $min,
                'icon' => $icon,
                'iconUrl' => $iconUrl
            ];
        }

        return $out;
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
        $payload = [
            'type' => 'forecast',
            'forecast' => $this->getForecastPayload(),
            'ts' => time()
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
