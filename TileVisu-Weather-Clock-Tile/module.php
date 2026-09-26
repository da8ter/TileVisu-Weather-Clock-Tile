<?php

declare(strict_types=1);

class TileVisuWeatherClockTile extends IPSModuleStrict
{
    // Bild-Hook unter /hook/ (ohne Praefix, wie RegisterHook es erwartet): derselbe Pfad wie bisher,
    // bestehende Adressen bleiben gueltig
    private const HOOK_PATH = 'wetterbilder/';
    // Ident des Skripts, das Versionen bis Oktober 2025 als Ziel des WebHook-Eintrags unter der Instanz anlegten
    private const LEGACY_HOOK_SCRIPT_IDENT = 'HookScript';
    // Was der Hook ausliefert: Wetterbilder (Endung => Typ), Meteocons unter assets/icons und die FlipClock-Dateien
    private const BACKGROUND_TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    private const ICON_TYPES = ['webp' => 'image/webp', 'gif' => 'image/gif', 'png' => 'image/png', 'svg' => 'image/svg+xml'];
    private const FLIPCLOCK_FILES = ['flipclock.min.css' => 'text/css', 'flipclock.min.js' => 'application/javascript'];
    // Erlaubte Angaben: Wetterbild ohne Endung, Symbol als Pfad unter assets/icons - andere Zeichen und .. nie
    private const BACKGROUND_NAME_PATTERN = '~\A[a-z0-9-]+\z~';
    private const ICON_PATH_PATTERN = '~\A(?:(?:full|outline)/)?(?:animated|static)/[a-z0-9-]+\.(webp|gif|png|svg)\z~';

    public function Create(): void
    {
        // Never delete this line!
        parent::Create();

        // Config
        $this->RegisterPropertyInteger('TemperatureVariableID', 0);
        $this->RegisterPropertyString('Location', '');
        $this->RegisterPropertyBoolean('ShowWeather', true);
        $this->RegisterPropertyBoolean('ShowForecast', true);
        $this->RegisterPropertyBoolean('UseAnimatedIcons', false);
        $this->RegisterPropertyBoolean('UseOutlineIcons', true);
        $this->RegisterPropertyInteger('CustomMediaID', 0);
        $this->RegisterPropertyBoolean('ShowClock', true);
        $this->RegisterPropertyBoolean('ShowDate', true);
        // Width configuration (% of window)
        $this->RegisterPropertyInteger('ForecastWidthPercent', 25);
        $this->RegisterPropertyInteger('ClockWidthPercent', 70);
        $this->RegisterPropertyInteger('ClockVerticalPercent', 50);
        // Date font size in px (0 = default CSS)
        $this->RegisterPropertyInteger('DateFontSizePx', 0);
        // Date scale factor (1..5), multiplies (clockFontPx / 3)
        $this->RegisterPropertyInteger('DateScaleFactor', 3);
        $this->RegisterPropertyBoolean('ShowSeconds', false);
        $this->RegisterPropertyBoolean('StoreWeatherData', false);
        $this->RegisterPropertyBoolean('StoreImageUrl', false);

        // Register timers only in Create(); interval is set in ApplyChanges()
        $this->RegisterTimer('UpdateTimer', 3600000, "IPS_RequestAction(\$_IPS['TARGET'], 'UpdateNow', 0);");


        // Runtime (Subscriptions)
        $this->RegisterAttributeInteger('LastTemperatureVarID', 0);
        $this->RegisterAttributeInteger('LastCustomMediaID', 0);
        $this->RegisterAttributeString('LastSlug', '');
        $this->RegisterAttributeString('LastTimeOfDay', '');
        $this->RegisterAttributeString('WebhookToken', '');

        // Aktiviert die HTML-SDK Darstellung (HTML-Kachel)
        $this->SetVisualizationType(1);

        // Bilder und FlipClock-Dateien ueber den nativen Hook /hook/wetterbilder/<InstanceID>. Die Registrierung
        // ist fluechtig, deshalb in Create (laeuft bei jedem Systemstart); das WebHook Control bleibt unangetastet.
        $hookRegistered = $this->RegisterHook(self::HOOK_PATH . $this->InstanceID);
        $this->SetBuffer('ImageHook', $hookRegistered ? '1' : '');
        if (!$hookRegistered) {
            $this->LogMessage(sprintf($this->Translate('The image hook /hook/wetterbilder/%d could not be registered.'), $this->InstanceID), KL_WARNING);
        }
    }

    public function Destroy(): void
    {
        // Never delete this line!
        parent::Destroy();
    }
    
    // /hook/wetterbilder/<ID>?<Datei>&v=<Version>&token=<Token>. Datei: name=<Wetterbild> (auch slug+tod, ohne
    // Angabe das aktuelle), icon=<Pfad unter assets/icons>, asset=<FlipClock-Datei> oder custom=1 (eigenes Bild).
    // Ohne gueltiges Token 403; Unbekanntes, Pfadangaben und Dateien ueber der Ausgabegrenze 404.
    // Symcon ruft Hooks ueber einen Aufsatz der Modulklasse auf; bei IPSModuleStrict mit ": void"
    protected function ProcessHookData(): void
    {
        $token = $this->hookToken();
        $given = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
        if ($token === '' || !hash_equals($token, $given)) {
            $this->SendStatus(403);
            return;
        }
        $resource = $this->hookResource($_GET);
        // Die Grenze muss vor der Ausgabe greifen, sonst ersetzt Symcon die ganze Antwort durch einen Fehlertext
        if ($resource === null || $resource['size'] > $this->hookBodyLimit()) {
            $this->SendStatus(404);
            return;
        }
        $version = $resource['version'];
        $current = isset($_GET['v']) && $_GET['v'] === $version;
        // Nur die passende Version darf lange gecacht werden, eine alte Adresse bekommt den neuen Inhalt ungecacht.
        // Mitgelieferte Dateien sind oeffentlich, das eigene Hintergrundbild bleibt privat.
        $scope = $resource['public'] ? 'public' : 'private';
        $this->SendHeader('Cache-Control: ' . ($current ? $scope . ', max-age=31536000, immutable' : 'no-cache'));
        $this->SendHeader('ETag: "' . $version . '"');
        $this->SendHeader('X-Content-Type-Options: nosniff');
        if ($current && trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === '"' . $version . '"') {
            $this->SendStatus(304);
            return;
        }
        $bytes = $resource['bytes'] ?? @file_get_contents($resource['path']);
        if (!is_string($bytes)) {
            $this->SendStatus(500);
            return;
        }
        $mime = $resource['mime'];
        $this->SendHeader('Content-Type: ' . (in_array($mime, self::FLIPCLOCK_FILES, true) ? $mime . '; charset=utf-8' : $mime));
        $this->SendHeader('Content-Length: ' . strlen($bytes));
        echo $bytes;
    }

    // Eigene Methoden fuer Kopfzeilen und Status, damit die Tests den Hook ohne Webserver pruefen koennen
    protected function SendHeader(string $header): void
    {
        header($header);
    }

    protected function SendStatus(int $code): void
    {
        http_response_code($code);
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        // Kein Heavy Work vor KR_READY: IPS_KERNELSTARTED ruft ApplyChanges erneut auf
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        $this->ensureHookToken();
        $this->removeLegacyHookScript();

        $this->MaintainVariable('OpenMeteoRaw', $this->Translate('Open-Meteo weather data'), VARIABLETYPE_STRING, '', 0, (bool)$this->ReadPropertyBoolean('StoreWeatherData'));
        $this->MaintainVariable('CurrentImageUrl', $this->Translate('Current weather image URL'), VARIABLETYPE_STRING, '', 1, (bool)$this->ReadPropertyBoolean('StoreImageUrl'));

        $temperatureVarId = (int)$this->ReadPropertyInteger('TemperatureVariableID');
        $previousVarId = (int)$this->ReadAttributeInteger('LastTemperatureVarID');
        $showWeather = (bool)$this->ReadPropertyBoolean('ShowWeather');
        $customMediaId = (int)$this->ReadPropertyInteger('CustomMediaID');
        $previousMediaId = (int)$this->ReadAttributeInteger('LastCustomMediaID');

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

        if ($previousMediaId > 0 && $previousMediaId !== $customMediaId) {
            @$this->UnregisterMessage($previousMediaId, MM_UPDATE);
            $this->UnregisterReference($previousMediaId);
        }

        if ($customMediaId > 0 && function_exists('IPS_MediaExists') && @IPS_MediaExists($customMediaId)) {
            $this->RegisterMessage($customMediaId, MM_UPDATE);
            $this->RegisterReference($customMediaId);
            $this->WriteAttributeInteger('LastCustomMediaID', $customMediaId);
        } else {
            $this->WriteAttributeInteger('LastCustomMediaID', 0);
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

    public function GetVisualizationTile(): string
    {
        // Liefert den HTML-Inhalt der Kachel (HTML-SDK)
        $path = __DIR__ . DIRECTORY_SEPARATOR . 'module.html';
        $html = is_file($path) ? file_get_contents($path) : false;
        if (is_string($html)) {
            return $html;
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

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'UpdateNow':
                $this->UpdateNow();
                return;
            case 'GetState':
                // Ermittele aktuellen Zustand (Slug/ToD) und referenziere Nutzer-Medienobjekt
                $this->UpdateNow();
                return;
        }
        throw new Exception('Invalid Ident');
    }

    // ----------------------------- Helpers -----------------------------

    // Aktuelles Wetterbild (slug-day|night, mit Rueckfall wie in der Kachel) fuer Hook-Anfragen ohne Namen
    private function currentBackgroundName(): string
    {
        $slug = strtolower(trim($this->ReadAttributeString('LastSlug')));
        $tod = strtolower(trim($this->ReadAttributeString('LastTimeOfDay')));
        if ($slug === '' || ($tod !== 'day' && $tod !== 'night')) {
            return '';
        }
        return $this->resolveExistingBackground($slug . '-' . $tod, $tod);
    }

    // Versionen bis Oktober 2025 legten unter der Instanz ein verstecktes Skript als Ziel des WebHook-Eintrags an
    // (Ident HookScript, Name "Wetterbilder WebHook"). Seitdem zeigt der Hook auf die Instanz, das Skript ist
    // verwaist. Geloescht wird nur, was erkennbar dieses Skript dieser Instanz ist.
    private function removeLegacyHookScript(): void
    {
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            if (!IPS_ScriptExists($childID) || IPS_GetObject($childID)['ObjectIdent'] !== self::LEGACY_HOOK_SCRIPT_IDENT) {
                continue;
            }
            $content = @IPS_GetScriptContent($childID);
            if (!is_string($content) || !str_contains($content, "\$_IPS['SENDER'] === 'WebHook'")
                || !str_contains($content, '$iid = ' . $this->InstanceID . ';')) {
                continue;
            }
            if (IPS_DeleteScript($childID, true)) {
                $this->LogMessage(sprintf($this->Translate('Removed the obsolete hook script #%d of an earlier module version.'), $childID), KL_MESSAGE);
            }
        }
    }

    private function imageHookActive(): bool
    {
        return $this->GetBuffer('ImageHook') === '1';
    }

    // Token der Hook-Adressen; '' solange der Hook in diesem Kernel-Lauf nicht registriert ist
    private function hookToken(): string
    {
        return $this->imageHookActive() ? $this->ReadAttributeString('WebhookToken') : '';
    }

    // Einmal erzeugt und behalten (Attribut WebhookToken wie bisher): bestehende Adressen bleiben gueltig
    private function ensureHookToken(): void
    {
        if ($this->imageHookActive() && $this->ReadAttributeString('WebhookToken') === '') {
            $this->WriteAttributeString('WebhookToken', bin2hex(random_bytes(16)));
        }
    }

    // Groesste Antwort, die Symcon unveraendert ausliefert (ScriptOutputBufferLimit, ab Werk 1 MiB), mit Reserve
    private function hookBodyLimit(): int
    {
        $limit = 1048576;
        try {
            $option = IPS_GetOption('ScriptOutputBufferLimit');
            if (is_numeric($option) && (int) $option > 0) {
                $limit = (int) $option;
            }
        } catch (\Throwable $e) {
            $this->SendDebug('Hook', 'ScriptOutputBufferLimit: ' . $e->getMessage(), 0);
        }
        return max(0, $limit - 1024);
    }

    // Datei bzw. Bild zu einer Hook-Anfrage, null fuer Unbekanntes
    private function hookResource(array $query): ?array
    {
        if (isset($query['asset'])) {
            $file = is_string($query['asset']) ? $query['asset'] : '';
            return isset(self::FLIPCLOCK_FILES[$file]) ? $this->fileResource('assets/flipclock/' . $file, self::FLIPCLOCK_FILES[$file]) : null;
        }
        if (isset($query['icon'])) {
            $icon = is_string($query['icon']) ? $query['icon'] : '';
            return preg_match(self::ICON_PATH_PATTERN, $icon, $match) === 1
                ? $this->fileResource('assets/icons/' . $icon, self::ICON_TYPES[$match[1]]) : null;
        }
        if (isset($query['custom'])) {
            $image = $this->customImage();
            $bytes = $image === null ? false : base64_decode($image['base64'], true);
            return is_string($bytes) && $bytes !== ''
                ? ['bytes' => $bytes, 'size' => strlen($bytes), 'mime' => $image['mime'], 'version' => $image['version'], 'public' => false]
                : null;
        }
        $name = $this->requestedBackgroundName($query);
        $file = $name === '' ? null : $this->backgroundFile($name);
        return $file === null ? null : $this->fileResource($file[0], $file[1]);
    }

    // Wetterbild einer Anfrage: name, sonst slug+tod, sonst das aktuelle; '' fuer alles ausser [a-z0-9-]
    private function requestedBackgroundName(array $query): string
    {
        $name = '';
        if (isset($query['name'])) {
            if (!is_string($query['name'])) {
                return '';
            }
            $name = strtolower(trim($query['name']));
        } else {
            $slug = isset($query['slug']) && is_string($query['slug']) ? strtolower(trim($query['slug'])) : '';
            $tod = isset($query['tod']) && is_string($query['tod']) ? strtolower(trim($query['tod'])) : '';
            if ($slug !== '' && ($tod === 'day' || $tod === 'night')) {
                $name = $slug . '-' . $tod;
            }
        }
        if ($name === '') {
            $name = $this->currentBackgroundName();
        }
        return preg_match(self::BACKGROUND_NAME_PATTERN, $name) === 1 ? $name : '';
    }

    // Mitgelieferte Datei als Hook-Antwort (oeffentlich, Inhaltsversion), null wenn sie fehlt
    private function fileResource(string $relPath, string $mime): ?array
    {
        $path = $this->assetPath($relPath);
        if ($path === null) {
            return null;
        }
        return ['path' => $path, 'size' => (int) filesize($path), 'mime' => $mime, 'version' => $this->fileVersion($relPath, $path), 'public' => true];
    }

    // Pfad einer mitgelieferten Datei unter assets/, null wenn sie fehlt oder ausserhalb von assets/ laege
    private function assetPath(string $relPath): ?string
    {
        $base = realpath(__DIR__ . '/assets');
        $path = realpath(__DIR__ . '/' . $relPath);
        if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
            return null;
        }
        return $path;
    }

    // Inhaltsversion einer mitgelieferten Datei: 16 Zeichen ihres SHA-256. Je Datei im Puffer AssetVersions mit
    // Stempel aus Groesse und Aenderungszeit, damit die Bilder nicht bei jedem Aufbau neu gelesen werden.
    private function fileVersion(string $relPath, string $path): string
    {
        $stamp = filesize($path) . '-' . filemtime($path);
        $versions = json_decode($this->GetBuffer('AssetVersions'), true);
        $versions = is_array($versions) ? $versions : [];
        $cached = $versions[$relPath] ?? null;
        if (is_array($cached) && ($cached[0] ?? null) === $stamp && is_string($cached[1] ?? null)) {
            return $cached[1];
        }
        $hash = hash_file('sha256', $path);
        $version = substr(is_string($hash) ? $hash : hash('sha256', $relPath . $stamp), 0, 16);
        $versions[$relPath] = [$stamp, $version];
        $this->SetBuffer('AssetVersions', (string) json_encode($versions, JSON_UNESCAPED_SLASHES));
        return $version;
    }

    // Hook-Adresse einer mitgelieferten Datei, ohne Hook oder ueber der Ausgabegrenze die Data-URI wie frueher;
    // '' ohne Datei. Die Adresse traegt die Inhaltsversion: neuer Inhalt, neue Adresse, der Browser darf lange cachen.
    private function fileSource(string $param, string $value, string $relPath, string $mime): string
    {
        $path = $this->assetPath($relPath);
        if ($path === null) {
            return '';
        }
        $token = $this->hookToken();
        if ($token !== '' && filesize($path) <= $this->hookBodyLimit()) {
            return $this->hookUrl([$param => $value], $this->fileVersion($relPath, $path), $token);
        }
        $bytes = file_get_contents($path);
        return $bytes === false ? '' : 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    private function hookUrl(array $query, string $version, string $token): string
    {
        return '/hook/' . self::HOOK_PATH . $this->InstanceID . '?'
            . http_build_query($query + ['v' => $version, 'token' => $token], '', '&', PHP_QUERY_RFC3986);
    }

    // Die beiden FlipClock-Dateien fuer die Kachel (Hook-Adressen bzw. Data-URIs)
    private function flipClockSources(): array
    {
        $sources = [];
        foreach (self::FLIPCLOCK_FILES as $file => $mime) {
            $sources[$file === 'flipclock.min.css' ? 'css' : 'js'] = $this->fileSource('asset', $file, 'assets/flipclock/' . $file, $mime);
        }
        return $sources;
    }

    // Eigenes Hintergrundbild: nur das konfigurierte Medienobjekt mit Inhalt, sonst null
    private function customImage(): ?array
    {
        $mediaId = $this->ReadPropertyInteger('CustomMediaID');
        if ($mediaId <= 0 || !IPS_MediaExists($mediaId)) {
            return null;
        }
        $base64 = @IPS_GetMediaContent($mediaId);
        if (!is_string($base64) || $base64 === '') {
            return null;
        }
        // 16 Zeichen Base64 = die 12 Bytes, an denen der Bildtyp erkannt wird
        $head = base64_decode(substr($base64, 0, 16), true);
        $padding = str_ends_with($base64, '==') ? 2 : (str_ends_with($base64, '=') ? 1 : 0);
        return [
            'base64'  => $base64,
            'size'    => intdiv(strlen($base64), 4) * 3 - $padding,
            'mime'    => $this->detectImageMime(is_string($head) ? $head : ''),
            'version' => substr(hash('sha256', $base64), 0, 16),
        ];
    }

    // Hook-Adresse des eigenen Bilds (neuer Inhalt, neue Adresse), sonst Data-URI; '' ohne Bild
    private function customImageSource(): string
    {
        $image = $this->customImage();
        if ($image === null) {
            return '';
        }
        $token = $this->hookToken();
        if ($token !== '' && $image['size'] <= $this->hookBodyLimit()) {
            return $this->hookUrl(['custom' => '1'], $image['version'], $token);
        }
        return 'data:' . $image['mime'] . ';base64,' . $image['base64'];
    }

    private function detectImageMime(string $head): string
    {
        if (strncmp($head, "\x89PNG\x0D\x0A\x1A\x0A", 8) === 0) {
            return 'image/png';
        }
        if (strncmp($head, 'GIF87a', 6) === 0 || strncmp($head, 'GIF89a', 6) === 0) {
            return 'image/gif';
        }
        if (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        return 'image/jpeg';
    }
    

    private function buildVisualizationPayload(string $url, string $slug, string $tod): array
    {
        // Clamp width percentages to [5..100]
        $fw = max(5, min(100, (int)$this->ReadPropertyInteger('ForecastWidthPercent')));
        $cw = max(5, min(100, (int)$this->ReadPropertyInteger('ClockWidthPercent')));
        $cv = (int)$this->ReadPropertyInteger('ClockVerticalPercent');
        if ($cv < 0) { $cv = 0; }
        if ($cv > 100) { $cv = 100; }
        // Clamp date font size to [0..200] (0 disables custom size)
        $df = (int)$this->ReadPropertyInteger('DateFontSizePx');
        if ($df < 0) { $df = 0; }
        if ($df > 200) { $df = 200; }
        // Clamp date scale factor to [1..5]
        $ds = (int)$this->ReadPropertyInteger('DateScaleFactor');
        if ($ds < 1) { $ds = 1; }
        if ($ds > 5) { $ds = 5; }
        $showForecast = (bool)$this->ReadPropertyBoolean('ShowForecast');
        return [
            'type'      => 'image',
            'url'       => $url,
            'slug'      => $slug,
            'timeOfDay' => $tod,
            'temperature' => $this->getTemperaturePayload(),
            'forecast'    => $this->getForecastPayload(),
            'showWeather' => (bool)$this->ReadPropertyBoolean('ShowWeather'),
            'showClock'   => (bool)$this->ReadPropertyBoolean('ShowClock'),
            'showDate'    => (bool)$this->ReadPropertyBoolean('ShowDate'),
            'showSeconds' => (bool)$this->ReadPropertyBoolean('ShowSeconds'),
            'forecastWidthPercent' => $fw,
            'clockWidthPercent'    => $cw,
            'clockVerticalPercent' => $cv,
            'dateFontSizePx'       => $df,
            'dateScaleFactor'      => $ds,
            'showForecast'         => $showForecast,
            'flipclock'            => $this->flipClockSources()
        ];
    }

    private function sendImageUpdate(): void
    {
        $showWeather = (bool)$this->ReadPropertyBoolean('ShowWeather');
        $showClock   = (bool)$this->ReadPropertyBoolean('ShowClock');
        $hasCustom = $this->customImage() !== null;
        // Clamp width percentages to [5..100]
        $fw = max(5, min(100, (int)$this->ReadPropertyInteger('ForecastWidthPercent')));
        $cw = max(5, min(100, (int)$this->ReadPropertyInteger('ClockWidthPercent')));
        $cv = (int)$this->ReadPropertyInteger('ClockVerticalPercent');
        if ($cv < 0) { $cv = 0; }
        if ($cv > 100) { $cv = 100; }
        $df = (int)$this->ReadPropertyInteger('DateFontSizePx');
        if ($df < 0) { $df = 0; }
        if ($df > 200) { $df = 200; }

        // If weather display is disabled: optionally show custom media image; no weather details
        if (!$showWeather) {
            $url = $this->customImageSource();
            $payload = [
                'type'        => 'image',
                'url'         => $url,
                'slug'        => '',
                'timeOfDay'   => '',
                'wmoCode'     => null,
                'imageName'   => '',
                'temperature' => null,
                'forecast'    => [],
                'showWeather' => false,
                'showClock'   => $showClock,
                'showDate'    => (bool)$this->ReadPropertyBoolean('ShowDate'),
                'showSeconds' => (bool)$this->ReadPropertyBoolean('ShowSeconds'),
                'forecastWidthPercent' => $fw,
                'clockWidthPercent'    => $cw,
                'clockVerticalPercent' => $cv,
                'dateFontSizePx'       => $df,
                'dateScaleFactor'      => max(1, min(5, (int)$this->ReadPropertyInteger('DateScaleFactor'))),
                'showForecast'         => false,
                'flipclock'            => $this->flipClockSources()
            ];
            $this->sendPayload($payload);
            $this->updateCurrentImageUrl($url);
            return;
        }

        // If a custom media is configured and has content, prefer it as background even when weather is enabled
        if ($hasCustom) {
            $url = $this->customImageSource();
            $payload = $this->buildVisualizationPayload($url, '', '');
            $payload['wmoCode'] = null;
            $payload['imageName'] = 'custom';
            $this->sendPayload($payload);
            $this->updateCurrentImageUrl($url);
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
        $webhookUrl = $this->backgroundSource($baseName);

        $payload = $this->buildVisualizationPayload($webhookUrl, $slug, $tod);
        $payload['wmoCode'] = $wmoCode;
        $payload['imageName'] = $baseName;
        $this->sendPayload($payload);
        $this->updateCurrentImageUrl($webhookUrl);
    }

    // Nachricht an alle offenen Kacheln; ein nicht kodierbarer Wert geht nicht als leerer Text hinaus
    private function sendPayload(array $payload): void
    {
        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            $this->SendDebug('Payload', 'JSON encoding failed: ' . json_last_error_msg(), 0);
            return;
        }
        $this->UpdateVisualizationValue($json);
    }

    private function updateCurrentImageUrl(string $url): void
    {
        // Die Variable gibt es nur, solange die Option aktiv ist (MaintainVariable in ApplyChanges)
        if (!$this->ReadPropertyBoolean('StoreImageUrl')) {
            return;
        }
        $varId = @$this->GetIDForIdent('CurrentImageUrl');
        if ($varId > 0) {
            // Eine eingebettete Data-URI (ohne Hook) gehoert nicht in eine Variable
            @SetValue($varId, str_starts_with($url, 'data:') ? '' : $url);
        }
    }

    private function sendCustomImageUpdate(): void
    {
        $url = $this->customImageSource();
        if ($url === '') {
            return;
        }
        $payload = [
            'type'      => 'image',
            'url'       => $url,
            'slug'      => '',
            'timeOfDay' => ''
        ];
        $this->sendPayload($payload);
    }

    private function resolveExistingBackground(string $baseName, string $tod): string
    {
        // 1) given name, 2) hazy-<tod>, 3) generic sunny/clear
        foreach ([$baseName, 'hazy-' . $tod, ($tod === 'day') ? 'sunny-day' : 'clear-sky-night'] as $name) {
            if ($this->backgroundFile($name) !== null) {
                return $name;
            }
        }
        return $baseName; // give original back; webhook may still handle
    }

    // Datei eines Wetterbilds: [Pfad relativ zum Modul, Bildtyp] der ersten vorhandenen Variante, sonst null
    private function backgroundFile(string $name): ?array
    {
        if (preg_match(self::BACKGROUND_NAME_PATTERN, $name) !== 1) {
            return null;
        }
        foreach (self::BACKGROUND_TYPES as $ext => $mime) {
            foreach ([$name . '-min.' . $ext, $name . '.' . $ext] as $file) {
                if (is_file(__DIR__ . '/assets/wetterbilder/' . $file)) {
                    return ['assets/wetterbilder/' . $file, $mime];
                }
            }
        }
        return null;
    }

    // Adresse eines Wetterbilds fuer die Kachel (Hook oder Data-URI), '' ohne Datei
    private function backgroundSource(string $name): string
    {
        $file = $this->backgroundFile($name);
        return $file === null ? '' : $this->fileSource('name', $name, $file[0], $file[1]);
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

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message === VM_UPDATE) {
            $temperatureVarId = (int)$this->ReadPropertyInteger('TemperatureVariableID');
            if ($temperatureVarId > 0 && $SenderID === $temperatureVarId) {
                $this->sendTemperatureUpdate();
            }
        }
        if ($Message === MM_UPDATE) {
            $customMediaId = (int)$this->ReadPropertyInteger('CustomMediaID');
            if ($customMediaId > 0 && $SenderID === $customMediaId) {
                $this->sendCustomImageUpdate();
            }
        }
    }

    

    

    private function sendTemperatureUpdate(): void
    {
        if (!(bool)$this->ReadPropertyBoolean('ShowWeather')) {
            return;
        }
        $fw = max(5, min(100, (int)$this->ReadPropertyInteger('ForecastWidthPercent')));
        $cw = max(5, min(100, (int)$this->ReadPropertyInteger('ClockWidthPercent')));
        $cv = (int)$this->ReadPropertyInteger('ClockVerticalPercent');
        if ($cv < 0) { $cv = 0; }
        if ($cv > 100) { $cv = 100; }
        $df = (int)$this->ReadPropertyInteger('DateFontSizePx');
        if ($df < 0) { $df = 0; }
        if ($df > 200) { $df = 200; }
        $ds = (int)$this->ReadPropertyInteger('DateScaleFactor');
        if ($ds < 1) { $ds = 1; }
        if ($ds > 5) { $ds = 5; }
        $payload = [
            'type' => 'temperature',
            'temperature' => $this->getTemperaturePayload(),
            'showWeather' => (bool)$this->ReadPropertyBoolean('ShowWeather'),
            'showClock'   => (bool)$this->ReadPropertyBoolean('ShowClock'),
            'showDate'    => (bool)$this->ReadPropertyBoolean('ShowDate'),
            'showSeconds' => (bool)$this->ReadPropertyBoolean('ShowSeconds'),
            'forecastWidthPercent' => $fw,
            'clockWidthPercent'    => $cw,
            'clockVerticalPercent' => $cv,
            'dateFontSizePx'       => $df,
            'dateScaleFactor'      => $ds,
            'showForecast'         => (bool)$this->ReadPropertyBoolean('ShowForecast')
        ];
        $this->sendPayload($payload);
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
            }
        }

        // Open-Meteo aktuelle Daten
        $data = $this->fetchOpenMeteo();
        if (is_array($data) && isset($data['current']) && is_array($data['current'])) {
            if ($result['value'] === '' && isset($data['current']['temperature_2m'])) {
                $t = (float)$data['current']['temperature_2m'];
                $result['value'] = $this->formatTemperatureValue($t);
            }
            $code = isset($data['current']['weather_code']) ? (int)$data['current']['weather_code'] : 0;
            $isDay = null;
            if (isset($data['current']['is_day'])) {
                $isDay = ((int)$data['current']['is_day'] === 1);
            }
            $uri = $this->iconSource($code, $isDay ?? $this->isDayByClock());
            if ($uri !== '') {
                $result['iconUrl'] = $uri;
            }
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

        // Determine current day/night for icon variant
        $isDayNow = null;
        if (isset($data['current']) && is_array($data['current']) && isset($data['current']['is_day'])) {
            $isDayNow = ((int)$data['current']['is_day'] === 1);
        }
        if ($isDayNow === null) {
            $isDayNow = $this->isDayByClock();
        }

        $count = min(4, count($times), count($codes), count($tMax), count($tMin));
        for ($i = 0; $i < $count; $i++) {
            $date = (string)($times[$i] ?? '');
            $label = $this->germanDayNameFromDate($date);
            $max   = is_numeric($tMax[$i] ?? null) ? (int)round($tMax[$i]) : null;
            $min   = is_numeric($tMin[$i] ?? null) ? (int)round($tMin[$i]) : null;
            $code  = (int)($codes[$i] ?? 0);
            // Use OpenMeteo WMO code directly for icon filename with current day/night variant
            $iconUrl = $this->iconSource($code, $isDayNow);
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
        $varId = $this->ReadPropertyBoolean('StoreWeatherData') ? @$this->GetIDForIdent('OpenMeteoRaw') : 0;
        if ($varId > 0) {
            @SetValueString($varId, $content);
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
        if ($code === 51) return 'light-drizzle-' . $dn;
        if ($code === 52) return 'moderate-drizzle-' . $dn;
        if ($code === 53) return 'dense-drizzle-' . $dn;
        if (in_array($code, [56,57], true)) return 'sleet-' . $dn; // freezing drizzle
        if ($code === 61) return 'light-rain-shower' . $dn;
        if ($code === 63) return 'light-rain-' . $dn;
        if ($code === 65) return 'heavy-rain-' . $dn;
        if (in_array($code, [66,67], true)) return 'sleet-' . $dn; // freezing rain
        if ($code === 71) return 'light-snow-shower-' . $dn;
        if ($code === 73) return 'heavy-snow-shower' . $dn;
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

    // Tag oder Nacht nach der Uhrzeit, wenn Open-Meteo kein is_day liefert
    private function isDayByClock(): bool
    {
        $h = (int)date('G');
        return $h >= 6 && $h < 20;
    }

    // Symbol zum WMO-Code: Hook-Adresse bzw. Data-URI der ersten vorhandenen Datei, '' ohne Treffer
    private function iconSource(int $code, bool $isDay): string
    {
        $relPath = $this->iconFile($code, $isDay);
        if ($relPath === null) {
            return '';
        }
        $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));
        return $this->fileSource('icon', substr($relPath, strlen('assets/icons/')), $relPath, self::ICON_TYPES[$ext]);
    }

    // Symboldatei (Pfad relativ zum Modul): beschreibende Namen, dann der Zahlencode, je Ordner; zuletzt overcast/cloudy
    private function iconFile(int $code, bool $isDay): ?string
    {
        $variant = $this->ReadPropertyBoolean('UseAnimatedIcons') ? 'animated' : 'static';
        // Bisherige Zuordnung: UseOutlineIcons waehlt den Ordner "full"
        $style = $this->ReadPropertyBoolean('UseOutlineIcons') ? 'full' : 'outline';
        $dirs = [$style . '/' . $variant, $variant];
        $find = static function (string $dir, array $names): ?string {
            foreach ($names as $name) {
                foreach (array_keys(self::ICON_TYPES) as $ext) {
                    $relPath = 'assets/icons/' . $dir . '/' . $name . '.' . $ext;
                    if (is_file(__DIR__ . '/' . $relPath)) {
                        return $relPath;
                    }
                }
            }
            return null;
        };
        foreach ($dirs as $dir) {
            $relPath = $find($dir, [...$this->mapWMOToIconBasenames($code, $isDay), (string) $code]);
            if ($relPath !== null) {
                return $relPath;
            }
        }
        return $find($dirs[0], ['overcast', 'cloudy']);
    }

    private function mapWMOToIconBasenames(int $code, bool $assumeDay): array
    {
        $day = $assumeDay;
        switch ($code) {
            case 0: // Clear sky
                return [$day ? 'clear-day' : 'clear-night'];
            case 1: // Mainly clear
                return [$day ? 'partly-cloudy-day' : 'partly-cloudy-night', 'clear-day'];
            case 2: // Partly cloudy
                return [$day ? 'partly-cloudy-day' : 'partly-cloudy-night', 'cloudy'];
            case 3: // Overcast
                return ['overcast', 'cloudy'];
            case 45: // Fog
            case 48: // Depositing rime fog
                return [$day ? 'fog-day' : 'fog-night', 'fog', $day ? 'overcast-day-fog' : 'overcast-night-fog', 'overcast'];
            case 51: // Drizzle light
            case 53: // Drizzle moderate
            case 55: // Drizzle dense
                return [$day ? 'partly-cloudy-day-drizzle' : 'partly-cloudy-night-drizzle', 'drizzle', $day ? 'partly-cloudy-day-rain' : 'partly-cloudy-night-rain', 'rain'];
            case 56: // Freezing Drizzle light
            case 57: // Freezing Drizzle dense
                return [$day ? 'partly-cloudy-day-sleet' : 'partly-cloudy-night-sleet', 'sleet'];
            case 61: // Rain slight
                return [$day ? 'partly-cloudy-day-rain' : 'partly-cloudy-night-rain', 'rain'];
            case 63: // Rain moderate
                return ['rain', $day ? 'partly-cloudy-day-rain' : 'partly-cloudy-night-rain'];
            case 65: // Rain heavy
                return [$day ? 'extreme-day-rain' : 'extreme-night-rain', 'rain'];
            case 66: // Freezing Rain light
            case 67: // Freezing Rain heavy
                return [
                    $day ? 'partly-cloudy-day-hail' : 'partly-cloudy-night-hail',
                    $day ? 'overcast-day-hail' : 'overcast-night-hail',
                    $day ? 'extreme-day-hail' : 'extreme-night-hail',
                    'hail'
                ];
            case 71: // Snow fall slight
            case 73: // Snow fall moderate
                return [$day ? 'partly-cloudy-day-snow' : 'partly-cloudy-night-snow', 'snow'];
            case 75: // Snow fall heavy
                return [$day ? 'extreme-day-snow' : 'extreme-night-snow', 'snow'];
            case 77: // Snow grains
                return ['snow', 'snowflake'];
            case 80: // Rain showers slight
            case 81: // Rain showers moderate
                return [$day ? 'partly-cloudy-day-rain' : 'partly-cloudy-night-rain', 'rain'];
            case 82: // Rain showers violent
                return [$day ? 'extreme-day-rain' : 'extreme-night-rain', 'rain'];
            case 85: // Snow showers slight
                return [$day ? 'partly-cloudy-day-snow' : 'partly-cloudy-night-snow', 'snow'];
            case 86: // Snow showers heavy
                return [$day ? 'extreme-day-snow' : 'extreme-night-snow', 'snow'];
            case 95: // Thunderstorm slight or moderate
                return [
                    $day ? 'thunderstorms-day' : 'thunderstorms-night',
                    $day ? 'thunderstorms-day-overcast' : 'thunderstorms-night-overcast',
                    $day ? 'thunderstorms-day-rain' : 'thunderstorms-night-rain',
                    $day ? 'thunderstorms-day-extreme' : 'thunderstorms-night-extreme'
                ];
            case 96: // Thunderstorm with slight hail
            case 99: // Thunderstorm with heavy hail
                return [
                    $day ? 'thunderstorms-day-extreme' : 'thunderstorms-night-extreme',
                    $day ? 'overcast-day-hail' : 'overcast-night-hail',
                    'hail'
                ];
            default:
                return ['overcast', 'cloudy'];
        }
    }

    private function sendForecastUpdate(): void
    {
        if (!(bool)$this->ReadPropertyBoolean('ShowWeather')) {
            return;
        }
        $showForecast = (bool)$this->ReadPropertyBoolean('ShowForecast');
        $forecast = $this->getForecastPayload();
        $this->SendDebug('Forecast', 'Sending ' . count($forecast) . ' items (showForecast=' . ($showForecast ? 'true' : 'false') . ')', 0);
        $fw = max(5, min(100, (int)$this->ReadPropertyInteger('ForecastWidthPercent')));
        $cw = max(5, min(100, (int)$this->ReadPropertyInteger('ClockWidthPercent')));
        $cv = (int)$this->ReadPropertyInteger('ClockVerticalPercent');
        if ($cv < 0) { $cv = 0; }
        if ($cv > 100) { $cv = 100; }
        $df = (int)$this->ReadPropertyInteger('DateFontSizePx');
        if ($df < 0) { $df = 0; }
        if ($df > 200) { $df = 200; }
        $payload = [
            'type' => 'forecast',
            'forecast' => $forecast,
            'showWeather' => (bool)$this->ReadPropertyBoolean('ShowWeather'),
            'showClock'   => (bool)$this->ReadPropertyBoolean('ShowClock'),
            'showDate'    => (bool)$this->ReadPropertyBoolean('ShowDate'),
            'forecastWidthPercent' => $fw,
            'clockWidthPercent'    => $cw,
            'clockVerticalPercent' => $cv,
            'dateFontSizePx'       => $df,
            'showForecast'         => $showForecast
        ];
        $this->sendPayload($payload);
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
