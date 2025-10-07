<?php

declare(strict_types=1);

class TileVisuWeatherHeaderTile extends IPSModule
{
    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Config
        $this->RegisterPropertyString('PageUrl', '');
        $this->RegisterPropertyInteger('IntervalSeconds', 7200);
        $this->RegisterPropertyInteger('TemperatureVariableID', 0);
        $this->RegisterPropertyInteger('ForecastVariableID', 0);
        $this->RegisterPropertyBoolean('UseCSSResolver', true);
        $this->RegisterPropertyBoolean('AllowInsecureTLS', false);

        // Runtime
        $this->RegisterAttributeString('KnownImageHashes', '[]');
        $this->RegisterAttributeInteger('LastImageMediaID', 0);
        $this->RegisterAttributeString('LastImageHash', '');
        $this->RegisterAttributeString('LastImageB64', '');
        $this->RegisterAttributeInteger('ImagesCategoryID', 0);
        $this->RegisterAttributeInteger('LastTemperatureVarID', 0);
        $this->RegisterAttributeInteger('LastForecastVarID', 0);

        // Timer (ms)
        $this->RegisterTimer('UpdateTimer', 0, 'BBCWI_UpdateNow($_IPS["TARGET"]);');
    }

    public function Destroy()
    {
        // Never delete this line!
        parent::Destroy();
    }
    
    protected function ProcessHookData()
    {
        // Webhook: Liefere das letzte Bild als Binary aus
        $b64 = $this->ReadAttributeString('LastImageB64');
        
        if ($b64 === '') {
            http_response_code(404);
            echo 'Kein Bild verfügbar';
            return;
        }
        
        $binary = base64_decode($b64, true);
        if ($binary === false) {
            http_response_code(500);
            echo 'Fehler beim Dekodieren';
            return;
        }
        
        // Sende Bild mit korrekten Headern
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . strlen($binary));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo $binary;
    }

    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();

        $interval = max(0, (int)$this->ReadPropertyInteger('IntervalSeconds')) * 1000;
        $this->SetTimerInterval('UpdateTimer', $interval);

        // Aktiviert die HTML-SDK Darstellung (HTML-Kachel)
        // Hinweis: Signatur SetVisualizationType kann je nach IPS-Version variieren; hier Standardaufruf
        if (method_exists($this, 'SetVisualizationType')) {
            // 1 = HTML (gemäß HTML-SDK Dokumentation)
            @$this->SetVisualizationType(1);
        }

        // Hook nach Kernel-Ready registrieren/aktualisieren
        $this->RegisterHook('/hook/bbcweather/' . $this->InstanceID);

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
     * Manuell/Timer: Bild aktualisieren
     */
    public function UpdateNow(): void
    {
        // TODO: TESTMODUS - Cache leeren, damit Bilder nicht übersprungen werden
        $this->WriteAttributeString('KnownImageHashes', '[]');
        $this->LogMessage('TEST: Bilder-Cache geleert', KL_MESSAGE);
        
        $pageUrl = trim($this->ReadPropertyString('PageUrl'));
        if ($pageUrl === '') {
            $this->LogMessage('Keine PageUrl konfiguriert.', KL_WARNING);
            return;
        }

        $html = $this->httpGet($pageUrl);
        if ($html === '') {
            // Versuche alternative Domainvarianten (bbc.com <-> bbc.co.uk)
            $this->LogMessage('Konnte HTML nicht abrufen (leer). Versuche alternative Domain...', KL_WARNING);
            $html = $this->tryAlternatePageUrls($pageUrl);
            if ($html === '') {
                $this->LogMessage('Konnte HTML nicht abrufen (auch alternative Domain fehlgeschlagen).', KL_WARNING);
                return;
            }
        }
        
        $this->LogMessage('HTML erfolgreich abgerufen (' . strlen($html) . ' Bytes)', KL_MESSAGE);
        
        // Debug: Ersten Teil des HTML loggen
        $htmlPreview = substr($html, 0, 500);
        $this->LogMessage('HTML Preview (erste 500 Zeichen): ' . $htmlPreview, KL_DEBUG);

        $rendererVersion = $this->extractRendererVersion($html);
        $this->LogMessage('Renderer Version: ' . ($rendererVersion !== null ? (string)$rendererVersion : '(null)'), KL_MESSAGE);
        $useCSS = (bool)$this->ReadPropertyBoolean('UseCSSResolver');
        $slug = '';
        $tod = '';

        // Versuche, aus Observations-Block zu lesen (Current conditions)
        $segment = $this->extractObservationsSegment($html);
        if ($segment === '') {
            $this->LogMessage('Observations-Segment nicht gefunden, nutze gesamtes HTML', KL_MESSAGE);
            $segment = $html; // Fallback: gesamtes HTML
        } else {
            $this->LogMessage('Observations-Segment gefunden (' . strlen($segment) . ' Bytes)', KL_MESSAGE);
        }

        // WICHTIG: Verwende IMMER die lokale Zeit, nicht die aus dem HTML!
        // BBC zeigt day/night basierend auf UK-Zeit, nicht auf lokaler Zeit
        $hour = (int)date('G');
        $tod = ($hour >= 20 || $hour < 6) ? 'night' : 'day';
        $this->LogMessage('TimeOfDay (lokale Zeit ' . $hour . ':00): ' . $tod, KL_MESSAGE);
        
        // 1) Bevorzuge Code -> Slug Mapping, ist stabiler
        $code = $this->extractWeatherCode($segment);
        if ($code === null) {
            // Fallback: gesamtes HTML durchsuchen
            $code = $this->extractWeatherCode($html);
        }
        $this->LogMessage('Weather Code extrahiert: ' . ($code !== null ? (string)$code : '(null)'), KL_MESSAGE);
        
        $slug = '';
        if ($code !== null) {
            $slug = $this->mapCodeToSlug($code);
            $this->LogMessage('Slug aus Code gemappt: ' . ($slug !== '' ? $slug : '(leer)'), KL_MESSAGE);
        }
        // 2) Falls noch leer, versuche Slug direkt aus Klassen
        if ($slug === '') {
            $slug = $this->extractWeatherSlug($segment); // e.g. 'mist'
            $this->LogMessage('Slug aus HTML-Klassen extrahiert: ' . ($slug !== '' ? $slug : '(leer)'), KL_MESSAGE);
        }

        if ($slug === '') {
            $this->LogMessage('Konnte Wetter-Slug nicht ermitteln.', KL_WARNING);
            return;
        }

        $this->LogMessage('Ermittelt: renderer=' . (string)$rendererVersion . ', slug=' . $slug . ', tod=' . $tod . ', code=' . ($code !== null ? (string)$code : '(null)'), KL_MESSAGE);

        $imageUrl = '';
        
        // WICHTIG: Wenn wir einen Weather Code haben, verwende direkt den Fallback-Pfad
        // Das ist zuverlässiger als CSS-Parsing, besonders bei unbekannten Codes
        if ($code !== null && $rendererVersion !== null) {
            $candidate = $this->buildFallbackImageUrl($rendererVersion, $slug, $tod, $code);
            $this->LogMessage('Verwende direkte URL basierend auf Weather Code ' . $code . ': ' . $candidate, KL_MESSAGE);
            $imageUrl = $candidate;
        }
        
        // Falls kein Code verfügbar, versuche CSS-Resolver als Fallback
        if ($imageUrl === '' && $useCSS && $rendererVersion !== null) {
            $this->LogMessage('Kein Weather Code verfügbar, versuche CSS-Resolver mit: slug=' . $slug . ', tod=' . $tod, KL_MESSAGE);
            $imageUrl = $this->resolveImageUrlFromCSS($rendererVersion, $slug, $tod);
            if ($imageUrl !== '') {
                $this->LogMessage('CSS-Resolver gefunden: ' . $imageUrl, KL_MESSAGE);
            } else {
                $this->LogMessage('CSS-Resolver fand keine URL', KL_MESSAGE);
            }
        }

        if ($imageUrl === '') {
            $this->LogMessage('Bild-URL konnte nicht aus CSS ermittelt werden und kein Fallback möglich.', KL_WARNING);
            return;
        }

        $imageData = $this->httpGetBinary($imageUrl);
        if ($imageData === '') {
            $this->LogMessage('Bild-Download fehlgeschlagen: ' . $imageUrl, KL_WARNING);
            return;
        }

        $hash = hash('sha256', $imageData);
        $known = json_decode($this->ReadAttributeString('KnownImageHashes') ?: '[]', true);
        if (!is_array($known)) {
            $known = [];
        }

        if (in_array($hash, $known, true)) {
            $this->LogMessage('Bild bereits bekannt, Download wird übersprungen.', KL_MESSAGE);
            return;
        }

        // Speichern: Neues Medienobjekt unter Kategorie "Wetterbilder"
        $mediaId = $this->createAndStoreImageMedia($slug, $tod, $imageData);
        
        // Verwende Webhook-URL für Bild-Auslieferung (inkl. Media-ID)
        $webhookUrl = '/hook/bbcweather/' . $this->InstanceID . '?id=' . $mediaId;
        $this->LogMessage('Webhook-URL: ' . $webhookUrl, KL_MESSAGE);

        // Frontend (HTML-SDK) informieren
        if (method_exists($this, 'UpdateVisualizationValue')) {
            $payload = $this->buildVisualizationPayload($webhookUrl, $slug, $tod);
            $this->UpdateVisualizationValue(json_encode($payload));
        }

        // Hash-Liste aktualisieren (max. 100 Einträge)
        $known[] = $hash;
        if (count($known) > 100) {
            $known = array_slice($known, -100);
        }
        $this->WriteAttributeString('KnownImageHashes', json_encode($known));
        $this->WriteAttributeInteger('LastImageMediaID', $mediaId);
        $this->WriteAttributeString('LastImageHash', $hash);

        $this->LogMessage('Aktualisiert: ' . $imageUrl, KL_MESSAGE);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'UpdateNow':
                $this->UpdateNow();
                return true;
            case 'GetState':
                // Aktuellen Zustand (letztes Bild) ans Frontend pushen
                $lastId = $this->ReadAttributeInteger('LastImageMediaID');
                $webhookUrl = '/hook/bbcweather/' . $this->InstanceID . ($lastId > 0 ? ('?id=' . $lastId) : '');
                if (method_exists($this, 'UpdateVisualizationValue')) {
                    $payload = $this->buildVisualizationPayload($webhookUrl, '', '');
                    $this->UpdateVisualizationValue(json_encode($payload));
                }
                return true;
            case 'WebhookGet':
                // Liefert die letzte Bild-Base64 (für Hook-Script)
                $b64 = $this->ReadAttributeString('LastImageB64');
                if ($b64 === '') {
                    $mid = $this->ReadAttributeInteger('LastImageMediaID');
                    if ($mid > 0 && @IPS_GetMedia($mid)) {
                        $b64 = @IPS_GetMediaContent($mid);
                        if (!is_string($b64)) {
                            $b64 = '';
                        }
                    }
                }
                return $b64;
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
            $this->LogMessage('WebHook Control nicht gefunden. Überspringe Hook-Registrierung.', KL_WARNING);
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

        $code = <<<'PHP'
<?php
if (isset($_IPS['SENDER']) && $_IPS['SENDER'] === 'WebHook') {
    $iid = %d;
    // Optional: Media-ID direkt per Query verwenden
    $b64 = '';
    if (isset($_GET['id'])) {
        $mid = (int)$_GET['id'];
        if ($mid > 0 && @IPS_MediaExists($mid)) {
            $tmp = @IPS_GetMediaContent($mid);
            if (is_string($tmp)) {
                $b64 = $tmp;
            }
        }
    }
    // Fallback: über Modul anfordern
    if ($b64 === '') {
        $b64 = IPS_RequestAction($iid, 'WebhookGet', 0);
    }
    if (is_string($b64) && $b64 !== '') {
        $bin = base64_decode($b64, true);
        if ($bin !== false) {
            header('Content-Type: image/jpeg');
            header('Content-Length: ' . strlen($bin));
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');
            echo $bin;
            return;
        }
    }
    http_response_code(404);
    echo 'Kein Bild verfügbar';
}
PHP;
        $code = sprintf($code, (int)$this->InstanceID);

        if ($sid && @IPS_ObjectExists($sid)) {
            IPS_SetScriptContent($sid, $code);
            return $sid;
        }

        $sid = IPS_CreateScript(0); // 0 = PHP Script
        IPS_SetName($sid, 'BBCWeatherImage WebHook');
        IPS_SetParent($sid, $this->InstanceID);
        IPS_SetIdent($sid, $ident);
        IPS_SetHidden($sid, true);
        IPS_SetScriptContent($sid, $code);
        return $sid;
    }

    private function extractObservationsSegment(string $html): string
    {
        if (preg_match('/<section[^>]*class="[^"]*wr-c-observations[^"]*"[^>]*>(.*?)<\/section>/si', $html, $m)) {
            return $m[1];
        }
        return '';
    }

    private function buildFallbackImageUrl(int $rendererVersion, string $slug, string $tod, int $code): string
    {
        // Verwende die größte verfügbare Größe: G5 mit @1x-G5_ Namensschema
        $base = 'https://weather.files.bbci.co.uk/weather-web-lambda-forecast-renderer/' . $rendererVersion . '/src/images/';
        $folder = 'G5';
        $file = '@1x-G5_' . $slug . '-' . $tod . '.jpg';
        return $base . $folder . '/' . $file;
    }

    private function preferG5(string $url): string
    {
        // Ersetze beliebige G1-G5/@x-Pfade auf die größte Variante G5 mit @1x-G5_
        $u = $url;
        // Nur auf den Pfad-/Dateinamen-Teil anwenden
        $u = preg_replace('#/src/images/G[1-5]/@(?:1|2)x-G[1-5]_#i', '/src/images/G5/@1x-G5_', $u ?? '');
        // Falls alternative Schreibweise (ohne /src) vorkommt, zusätzlich abdecken
        $u = preg_replace('#/images/G[1-5]/@(?:1|2)x-G[1-5]_#i', '/images/G5/@1x-G5_', $u ?? '');
        return is_string($u) ? $u : $url;
    }

    private function extractRendererVersion(string $html): ?int
    {
        if (preg_match('#weather-web-lambda-forecast-renderer/(\d+)/css/forecast\.css#i', $html, $m)) {
            return (int)$m[1];
        }
        // Fallback: irgend ein Vorkommen der Renderer-Basis
        if (preg_match('#weather-web-lambda-forecast-renderer/(\d+)/#i', $html, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    private function extractTimeOfDay(string $html): string
    {
        if (preg_match('/wr-weather-type--(day|night)\b/i', $html, $m)) {
            return strtolower($m[1]);
        }
        return '';
    }

    private function extractWeatherSlug(string $html): string
    {
        // Sammle alle wr-weather-type--<slug> und filtere day/night
        if (preg_match_all('/wr-weather-type--([a-z][a-z-]*)\b/i', $html, $m)) {
            $candidates = array_map('strtolower', $m[1]);
            $deny = ['day', 'night', 'time-slot'];
            $candidates = array_values(array_filter($candidates, function ($s) use ($deny) {
                return !in_array($s, $deny, true);
            }));
            if (!empty($candidates)) {
                // Nimm den ersten Kandidaten
                return $candidates[0];
            }
        }
        return '';
    }

    private function extractWeatherCode(string $html): ?int
    {
        if (preg_match('/<use[^>]+href="#wr-icon-weather-type--(\d+)"/i', $html, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    private function mapCodeToSlug(int $code): string
    {
        // Erweiterte Mapping-Tabelle für BBC Weather Codes
        $map = [
            0 => 'clear-night',
            1 => 'sunny',
            2 => 'partly-cloudy',
            3 => 'partly-cloudy',
            4 => 'mist',
            5 => 'fog',
            6 => 'cloudy',
            7 => 'overcast',
            8 => 'light-rain-shower',
            9 => 'light-rain',
            10 => 'heavy-rain',
            11 => 'sleet',
            12 => 'hail',
            13 => 'thunder',
            14 => 'snow',
            15 => 'heavy-snow',
            16 => 'drizzle',
            17 => 'light-snow',
            18 => 'heavy-sleet',
            19 => 'light-sleet',
            20 => 'light-snow-shower',
            21 => 'snow-shower',
            22 => 'sleet-shower',
            23 => 'hail-shower',
            24 => 'thunder-shower',
            25 => 'thunder-rain',
            26 => 'thunder-snow',
            27 => 'thunder-sleet',
            28 => 'thunder-hail',
            29 => 'heavy-rain-shower',
            30 => 'heavy-snow-shower',
            31 => 'hail',
            32 => 'cloudy',
            33 => 'cloudy',
            34 => 'mist',  // Nebel
            35 => 'cloudy',
            36 => 'cloudy',
            37 => 'cloudy',
            38 => 'cloudy',
            39 => 'cloudy',
            40 => 'cloudy'
        ];
        
        // Fallback für unbekannte Codes: verwende 'cloudy' als Default
        if (!isset($map[$code])) {
            $this->LogMessage('Unbekannter Weather Code: ' . $code . ', verwende Fallback "cloudy"', KL_WARNING);
            return 'cloudy';
        }
        
        return $map[$code];
    }

    private function resolveImageUrlFromCSS(int $rendererVersion, string $slug, string $tod): string
    {
        $base = 'https://weather.files.bbci.co.uk/weather-web-lambda-forecast-renderer/' . $rendererVersion . '/';
        $cssUrls = [
            $base . 'css/forecast.css',
            $base . 'css/observations.css'
        ];
        $ext = '(?:jpg|jpeg|png|webp)';
        foreach ($cssUrls as $cssUrl) {
            $css = $this->httpGet($cssUrl);
            if ($css === '') {
                $this->LogMessage(basename($cssUrl) . ' konnte nicht geladen werden.', KL_WARNING);
                continue;
            }
            // 1) Selektor-basiert: .wr-weather-type--<slug> {... url(...-<tod>.<ext>) }
            $patternSel = '/\\.wr-weather-type--' . preg_quote($slug, '/') . '[^{]*\{[^}]*url\(([^)]+-' . preg_quote($tod, '/') . '\.' . $ext . ')\)/i';
            if (preg_match($patternSel, $css, $m)) {
                $url = trim($m[1], "'\"");
                // Prefer largest variant by rewriting to G5/@1x-G5
                $url = $this->preferG5($url);
                if (stripos($url, 'http') === 0) {
                    return $url;
                }
                if (strpos($url, '/') === 0) {
                    return 'https://weather.files.bbci.co.uk' . $url;
                }
                return $this->resolveAbsoluteUrl($base, $url);
            }
            // 2) Breiter Fallback: irgendein url(...<slug>...-<tod>.<ext>)
            if (preg_match_all('/url\(([^)]+)\)/i', $css, $all)) {
                $bestU = '';
                $bestScore = -1;
                foreach ($all[1] as $uRaw) {
                    $u = trim($uRaw, "'\"");
                    $t = strtolower($u);
                    $score = 0;
                    if (strpos($t, $slug) !== false) $score += 3;
                    if (strpos($t, '-' . $tod . '.') !== false) $score += 3;
                    if (strpos($t, '/src/images/') !== false) $score += 2;
                    // Prefer largest set G5
                    if (preg_match('#/src/images/g5/#i', $t)) $score += 5;
                    else if (preg_match('#/src/images/g[34]/#i', $t)) $score += 3;
                    else if (preg_match('#/src/images/g2/#i', $t)) $score += 1;
                    // Prefer @1x naming as requested
                    if (strpos($t, '@1x-g5_') !== false) $score += 3;
                    else if (strpos($t, '@1x') !== false) $score += 2; else if (strpos($t, '@2x') !== false) $score += 1;
                    if (preg_match('/\.(jpg|jpeg)$/i', $t)) $score += 2; else if (preg_match('/\.(png|webp)$/i', $t)) $score += 1;
                    if (strpos($t, '/images/g') !== false) $score += 1;
                    if ($score > $bestScore) { $bestScore = $score; $bestU = $u; }
                }
                if ($bestU !== '' && $bestScore >= 5) { // Mindestscore
                    $this->LogMessage('CSS-Kandidat gewählt (Score ' . $bestScore . '): ' . $bestU, KL_MESSAGE);
                    $bestU = $this->preferG5($bestU);
                    if (stripos($bestU, 'http') === 0) {
                        return $bestU;
                    }
                    if (strpos($bestU, '/') === 0) {
                        return 'https://weather.files.bbci.co.uk' . $bestU;
                    }
                    return $this->resolveAbsoluteUrl($base, $bestU);
                }
            }
        }
        return '';
    }

    private function resolveAbsoluteUrl(string $base, string $relative): string
    {
        // Base example: https://.../renderer/329/
        $p = @parse_url($base);
        if (!is_array($p) || !isset($p['scheme'], $p['host'])) {
            return $base . $relative;
        }
        $scheme = $p['scheme'];
        $host = $p['host'];
        $path = $p['path'] ?? '/';
        // Ensure directory path
        if (substr($path, -1) !== '/') {
            $path = preg_replace('#/[^/]*$#', '/', $path);
        }
        $full = $path . $relative;
        // Normalize .. and .
        $parts = explode('/', $full);
        $stack = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($stack);
            } else {
                $stack[] = $part;
            }
        }
        $normPath = '/' . implode('/', $stack);
        return $scheme . '://' . $host . $normPath;
    }

    private function ensureImagesCategory(): int
    {
        $catId = $this->ReadAttributeInteger('ImagesCategoryID');
        if ($catId && @IPS_GetObject($catId)) {
            return $catId;
        }
        $catId = IPS_CreateCategory();
        IPS_SetName($catId, 'Wetterbilder');
        IPS_SetParent($catId, $this->InstanceID);
        $this->RegisterReference($catId);
        $this->WriteAttributeInteger('ImagesCategoryID', $catId);
        return $catId;
    }

    private function createAndStoreImageMedia(string $slug, string $tod, string $binary): int
    {
        $catId = $this->ensureImagesCategory();
        $mediaId = IPS_CreateMedia(1); // 1 = Image
        $name = date('Y-m-d_H-i-s') . ($slug !== '' ? ('_' . $slug) : '') . ($tod !== '' ? ('_' . $tod) : '');
        IPS_SetName($mediaId, $name);
        IPS_SetParent($mediaId, $catId);
        $this->writeImageToMedia($mediaId, $binary);
        $this->WriteAttributeInteger('LastImageMediaID', $mediaId);
        return $mediaId;
    }

    private function writeImageToMedia(int $mediaId, string $binary): void
    {
        // Base64-kodierte Daten
        $b64 = base64_encode($binary);
        
        // Setze Dateiname (NICHT Pfad!), false = keine echte Datei verwenden
        IPS_SetMediaFile($mediaId, 'weather.jpg', false);
        
        // Setze den Inhalt direkt als Base64
        IPS_SetMediaContent($mediaId, $b64);
        
        // Sende Media Event
        IPS_SendMediaEvent($mediaId);
        
        // Persist last image base64 for HTML SDK access
        $this->WriteAttributeString('LastImageB64', $b64);
    }

    private function getDataURIFromBinary(string $binary): string
    {
        $mime = $this->detectMime($binary);
        return 'data:' . $mime . ';base64,' . base64_encode($binary);
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
            }
        }
    }

    private function getLastImageDataURI(): string
    {
        $b64 = $this->ReadAttributeString('LastImageB64');
        if ($b64 !== '') {
            $bin = base64_decode($b64, true);
            if ($bin !== false) {
                $mime = $this->detectMime($bin);
                return 'data:' . $mime . ';base64,' . $b64;
            }
        }
        return '';
    }

    private function detectMime(string $binary): string
    {
        // very simple check
        if (substr($binary, 0, 3) === "\xFF\xD8\xFF") {
            return 'image/jpeg';
        }
        if (substr($binary, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") {
            return 'image/png';
        }
        return 'image/jpeg';
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
        if (!is_string($formatted) || $formatted === '') {
            $value = @GetValue($variableId);
            if ($value !== false) {
                $formatted = (string)$value;
            }
        }

        if (is_string($formatted)) {
            $result['value'] = $formatted;
        }

        $iconInfo = $this->getIconHelper()->getIconForVariable($variableId);
        $result['icon'] = $iconInfo['icon'];
        $result['iconUrl'] = $iconInfo['iconUrl'];

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

        $count = min(3, count($days), count($tMax), count($tMin));
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
    private function httpGet(string $url): string
    {
        $opts = [
            'HttpVersion' => '1.1',
            'Timeout' => 10000,
            'Headers' => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0 Safari/537.36',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-GB,en-US;q=0.9,en;q=0.8,de;q=0.7',
                'Accept-Encoding: identity',
                'Sec-Fetch-Site: same-origin',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Dest: document',
                'Upgrade-Insecure-Requests: 1',
                'DNT: 1',
                'Connection: keep-alive',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
                'Referer: ' . $this->buildReferer($url)
            ]
        ];
        $data = @Sys_GetURLContentEx($url, $opts);
        if (is_string($data) && $data !== '') {
            return $data;
        }
        // Fallback via cURL (secure)
        $this->LogMessage('httpGet: Sys_GetURLContentEx leer. Versuche cURL...', KL_MESSAGE);
        $res = $this->curlFetch($url, $opts['Headers'], false, false);
        if ($res !== '') {
            $this->LogMessage('httpGet: cURL erfolgreich (' . strlen($res) . ' Bytes)', KL_MESSAGE);
            return $res;
        }
        $this->LogMessage('httpGet: cURL (secure) lieferte leeres Ergebnis', KL_WARNING);
        
        // Optional insecure fallback
        if ((bool)$this->ReadPropertyBoolean('AllowInsecureTLS')) {
            $this->LogMessage('httpGet: cURL (secure) leer. Versuche cURL mit deaktivierter TLS-Prüfung...', KL_WARNING);
            $res = $this->curlFetch($url, $opts['Headers'], false, true);
            if ($res !== '') {
                $this->LogMessage('httpGet: cURL (insecure) erfolgreich (' . strlen($res) . ' Bytes)', KL_MESSAGE);
            }
            return $res;
        }
        $this->LogMessage('httpGet: Alle Versuche fehlgeschlagen, gebe leeren String zurück', KL_WARNING);
        return '';
    }

    private function httpGetBinary(string $url): string
    {
        $opts = [
            'HttpVersion' => '1.1',
            'Timeout' => 15000,
            'Headers' => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0 Safari/537.36',
                'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                'Accept-Encoding: identity',
                'Sec-Fetch-Site: same-origin',
                'Sec-Fetch-Mode: no-cors',
                'Sec-Fetch-Dest: image',
                'DNT: 1',
                'Connection: keep-alive',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
                'Referer: ' . $this->buildReferer($url)
            ]
        ];
        $data = @Sys_GetURLContentEx($url, $opts);
        if (is_string($data) && $data !== '') {
            return $data;
        }
        // Fallback via cURL (secure)
        $this->LogMessage('httpGetBinary: Sys_GetURLContentEx leer. Versuche cURL...', KL_MESSAGE);
        $res = $this->curlFetch($url, $opts['Headers'], true, false);
        if ($res !== '') {
            return $res;
        }
        // Optional insecure fallback
        if ((bool)$this->ReadPropertyBoolean('AllowInsecureTLS')) {
            $this->LogMessage('httpGetBinary: cURL (secure) leer. Versuche cURL mit deaktivierter TLS-Prüfung...', KL_WARNING);
            return $this->curlFetch($url, $opts['Headers'], true, true);
        }
        return '';
    }

    private function curlFetch(string $url, array $headers, bool $binary, bool $insecure): string
    {
        if (!function_exists('curl_init')) {
            return '';
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 5000);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $binary ? 20000 : 15000);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($insecure) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        }
        curl_setopt($ch, CURLOPT_ACCEPT_ENCODING, ''); // identity
        $result = curl_exec($ch);
        if ($result === false) {
            $this->LogMessage('cURL Fehler: ' . curl_error($ch), KL_WARNING);
            curl_close($ch);
            return '';
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        
        $this->LogMessage('cURL Response: Status=' . $status . ', URL=' . $effectiveUrl . ', Bytes=' . (is_string($result) ? strlen($result) : 0), KL_MESSAGE);
        
        if ($status >= 200 && $status < 300 && is_string($result)) {
            return $result;
        }
        $this->LogMessage('cURL HTTP Status nicht OK: ' . $status, KL_WARNING);
        return '';
    }

    private function buildReferer(string $url): string
    {
        $p = @parse_url($url);
        if (is_array($p) && isset($p['scheme'], $p['host'])) {
            return $p['scheme'] . '://' . $p['host'] . '/';
        }
        return 'https://www.bbc.com/';
    }

    private function tryAlternatePageUrls(string $url): string
    {
        $alternatives = [];
        $parts = @parse_url($url);
        if (is_array($parts) && isset($parts['host'])) {
            $host = $parts['host'];
            if (stripos($host, 'bbc.com') !== false) {
                $altHost = str_ireplace('bbc.com', 'bbc.co.uk', $host);
                $alternatives[] = $this->rebuildUrlWithHost($parts, $altHost);
            } elseif (stripos($host, 'bbc.co.uk') !== false) {
                $altHost = str_ireplace('bbc.co.uk', 'bbc.com', $host);
                $alternatives[] = $this->rebuildUrlWithHost($parts, $altHost);
            }
        }
        foreach ($alternatives as $alt) {
            $this->LogMessage('Versuche alternativen Link: ' . $alt, KL_MESSAGE);
            $html = $this->httpGet($alt);
            if ($html !== '') {
                return $html;
            }
        }
        return '';
    }

    private function rebuildUrlWithHost(array $parts, string $newHost): string
    {
        $scheme = $parts['scheme'] ?? 'https';
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
        return $scheme . '://' . $newHost . $path . $query;
    }
}

// PHP Stub-Funktionen für Editor (werden in IP-Symcon bereitgestellt)
if (!function_exists('IPS_SendMediaEvent')) {
    function IPS_SendMediaEvent(int $MediaID): void {}
}
