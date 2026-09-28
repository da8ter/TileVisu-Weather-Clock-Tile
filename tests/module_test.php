<?php

declare(strict_types=1);

// Module Strict, Bild-Hook, Wetter-Zwischenspeicher und Nachrichtenfilter der Wetter-Uhr-Kachel, ohne laufendes
// Symcon und ohne Netz (bootstrap.php startet den Test ohne cURL und URL-fopen neu).
require __DIR__ . '/bootstrap.php';
// Jede Warnung wird zur Ausnahme; mit @ unterdrueckte (Symcon-Stil im Modul) bleiben still
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

const VOR_UMBAU = '2d14d26'; // Stand vor Module Strict, Hook, Zwischenspeicher und Nachrichtenfilter

$root = dirname(__DIR__);
$moduleDir = $root . '/TileVisu-Weather-Clock-Tile';
$source = (string) file_get_contents($moduleDir . '/module.php');

// Konfigurierte Kachel: Standort gesetzt, optional Temperaturvariable und eigenes Bild
function tile_module(int $id = 12345, array $properties = []): TileVisuWeatherClockTile
{
    $class = class_exists('HookProbeTile') ? 'HookProbeTile' : 'TileVisuWeatherClockTile';
    $m = new $class();
    $m->InstanceID = $id;
    $m->Create();
    // Standort je Instanz: jede Kachel hat ihre eigene Open-Meteo-Adresse
    $location = json_encode(['latitude' => 51.5, 'longitude' => round($id / 10000, 4)]);
    $m->properties = array_merge($m->properties, ['Location' => $location], $properties);
    return $m;
}

// Nachrichten an offene Kacheln nur bei echter Aenderung. Zeilen [Bezeichnung, erwartet, gesendet, behoben]:
// "behoben" markiert Verhalten, das diese Umstellung aendert - gegen den alten Stand muss genau das fallen.
function nachrichtenfilter(): array
{
    reset_world();
    $GLOBALS['weatherBody'] = weather_json(63, 1);
    variable(700, 21.5, '21,5 °C');
    $GLOBALS['media'][500] = ['content' => base64_encode("\x89PNG\x0D\x0A\x1A\x0A" . str_repeat('A', 40))];
    $m = tile_module(27001, ['TemperatureVariableID' => 700]);
    $m->ApplyChanges();
    $rows = [];
    $count = static function (string $label, int $expected, bool $fixed, callable $action) use ($m, &$rows): void {
        $before = count($m->updates);
        $action();
        $rows[] = [$label, $expected, count($m->updates) - $before, $fixed];
    };
    // $Data wie von Symcon: [neuer Wert, geaendert, alter Wert, Zeitstempel]
    $update = static fn (array $data): callable => static fn () => $m->MessageSink(0, 700, VM_UPDATE, $data);
    $count('Update without a new value ($Data[1] false) sends nothing', 0, true, $update([21.5, false, 21.5, 1]));
    $GLOBALS['variables'][700] = ['value' => 22.0, 'formatted' => '22,0 °C'];
    $count('Changed value sends its message', 1, false, $update([22.0, true, 21.5, 2]));
    // Neuer Wert, gleiche Anzeige: die Nachricht ist dieselbe
    $GLOBALS['variables'][700]['value'] = 22.04;
    $count('Identical message is not sent again (memory per key)', 0, true, $update([22.04, true, 22.0, 3]));
    // Die Bildnachricht traegt die Temperatur mit: nach deren Aenderung geht sie einmal hinaus
    $count('Hourly timer after a temperature change sends the image message once', 1, true, static fn () => $m->UpdateNow());
    $count('Hourly timer with unchanged weather sends nothing', 0, true, static fn () => $m->UpdateNow());
    $GLOBALS['weatherBody'] = weather_json(3, 1);
    $count('Hourly timer with changed weather sends one message', 1, true, static fn () => $m->UpdateNow());
    $m->properties['CustomMediaID'] = 500;
    $count('ApplyChanges sends the full state as one message', 1, true, static fn () => $m->ApplyChanges());
    $count('Unchanged custom image (MM_UPDATE) sends nothing', 0, true, static fn () => $m->MessageSink(0, 500, MM_UPDATE, []));
    $GLOBALS['media'][500]['content'] = base64_encode("\xFF\xD8\xFF\xE0" . str_repeat('B', 40));
    $count('Changed custom image sends one image message', 1, false, static fn () => $m->MessageSink(0, 500, MM_UPDATE, []));
    $count('After ApplyChanges the same temperature message goes out again', 1, false, $update([22.04, true, 22.0, 4]));
    $count('... but only once', 0, true, $update([22.04, true, 22.0, 5]));
    $m->GetVisualizationTile();
    $count('After the initial build of a tile it goes out again', 1, false, $update([22.04, true, 22.0, 6]));
    $m->GetVisualizationTile();
    $count('Without $Data[1] (other format) the update is sent', 1, false, $update([]));
    return $rows;
}

// Frische Wetterdaten vorgeben; nur Fassungen mit Zwischenspeicher (das Attribut WeatherCache) kennen das
function prime_cache(TileVisuWeatherClockTile $m): void
{
    if (array_key_exists('WeatherCache', $m->attributes)) {
        $url = (new ReflectionMethod($m, 'weatherUrl'))->invoke($m);
        $m->attributes['WeatherCache'] = json_encode(['url' => $url, 'at' => time(), 'data' => json_decode($GLOBALS['weatherBody'], true)]);
    }
}

// Antworttext des Hooks, auch fuer Fassungen ohne SendHeader/SendStatus (Kopfzeilen gehen dort ins Leere)
function hook_body(TileVisuWeatherClockTile $m, array $get): string
{
    if (method_exists($m, 'hook')) {
        return $m->hook($get);
    }
    $_GET = $get;
    ob_start();
    (new ReflectionMethod($m, 'ProcessHookData'))->invoke($m);
    return (string) ob_get_clean();
}

// Szenarien der Gegenprobe. Jedes laeuft gegen den alten Stand in einem eigenen Prozess: dessen static-Cache in
// fetchOpenMeteo hielte sonst Daten ueber Aufrufe hinweg, die in Symcon je Aufruf neu beginnen.
function szenarien(): array
{
    $setup = static function (int $id, array $properties = []): TileVisuWeatherClockTile {
        reset_world();
        $GLOBALS['weatherBody'] = weather_json(63, 1);
        variable(700, 21.5, '21,5 °C');
        // ApplyChanges ohne Wetteranzeige ruft in keiner Fassung ab; danach frische Daten und Wetter an
        $m = tile_module($id, ['TemperatureVariableID' => 700, 'ShowWeather' => false] + $properties);
        $m->ApplyChanges();
        prime_cache($m);
        $m->properties['ShowWeather'] = true;
        $GLOBALS['fetches'] = [];
        $m->updates = [];
        $m->timerCalls = [];
        return $m;
    };
    return [
        'oeffnen' => static function () use ($setup): array {
            $m = $setup(29001);
            $m->RequestAction('GetState', 0);
            return [['Opening the tile (GetState) with fresh data fetches nothing', 0, count($GLOBALS['fetches']), true],
                ['... and sends nothing to the open tiles', 0, count($m->updates), true]];
        },
        'temperatur' => static function () use ($setup): array {
            $m = $setup(29002);
            $GLOBALS['variables'][700] = ['value' => 22.0, 'formatted' => '22,0 °C'];
            $m->MessageSink(0, 700, VM_UPDATE, [22.0, true, 21.5, 1]);
            return [['A temperature update fetches nothing', 0, count($GLOBALS['fetches']), true],
                ['... and sends one temperature message', 1, count($m->updates), false]];
        },
        'filter' => static fn (): array => nachrichtenfilter(),
        'timer' => static function () use ($setup): array {
            $m = $setup(29003);
            $m->ApplyChanges();
            $restarts = count(array_filter($m->timerCalls, static fn (array $c): bool => $c[0] === 'SetTimerInterval'));
            $m->timerCalls = [];
            $m->UpdateNow();
            $m->RequestAction('GetState', 0);
            $m->MessageSink(0, 700, VM_UPDATE, [21.5, true, 21.0, 2]);
            return [['ApplyChanges keeps the running hourly timer', 0, $restarts, true],
                ['Fetch, open and update paths never set the timer', 0, count($m->timerCalls), false],
                ['The hourly timer fetches', 1, count($GLOBALS['fetches']), false]];
        },
        'hook' => static function () use ($setup): array {
            $m = $setup(29004);
            $token = $m->attributes['WebhookToken'];
            $file = (string) file_get_contents(dirname(MODULE_FILE) . '/assets/wetterbilder/fog-day.png');
            // Beobachtbar in beiden Fassungen: kommt die Bilddatei heraus (der alte Stand setzt den Status direkt)
            $delivers = static fn (array $get): int => hook_body($m, $get) === $file ? 1 : 0;
            return [['Hook refuses a path in the image name', 0, $delivers(['name' => '../wetterbilder/fog-day', 'token' => $token]), true],
                ['Hook delivers a background by name and token as before', 1, $delivers(['name' => 'fog-day', 'token' => $token]), false],
                ['Hook refuses a wrong token', 0, $delivers(['name' => 'fog-day', 'token' => str_repeat('0', 32)]), false]];
        },
        'webhook' => static function (): array {
            reset_world();
            $GLOBALS['webhookInstances'] = [15000];
            $m = tile_module(29005, ['ShowWeather' => false]);
            $m->ApplyChanges();
            $writes = array_filter($GLOBALS['webhookCalls'], static fn (array $c): bool => in_array($c[0], ['IPS_SetProperty', 'IPS_ApplyChanges'], true));
            return [['ApplyChanges leaves the WebHook Control alone', 0, $writes === [] ? 0 : 1, true]];
        },
        'dokument' => static function () use ($setup): array {
            $m = $setup(29006);
            $m->UpdateNow();
            $html = $m->GetVisualizationTile();
            return [['Tile document carries the initial state (no round trip via all tiles)', 1, str_contains($html, '<script>handleMessage(') ? 1 : 0, true],
                ['Messages carry no Base64 icons', 0, str_contains(implode('', $m->updates), 'base64,') ? 1 : 0, true]];
        },
        'altlast' => static function (): array {
            reset_world();
            $GLOBALS['objects'][501] = ['parent' => 29007, 'ident' => 'HookScript', 'name' => 'Wetterbilder WebHook',
                'script' => "<?php\nif (isset(\$_IPS['SENDER']) && \$_IPS['SENDER'] === 'WebHook') {\n    \$iid = 29007;\n}\n"];
            $m = tile_module(29007, ['ShowWeather' => false]);
            $m->ApplyChanges();
            return [['The orphaned hook script of this instance is removed', 0, isset($GLOBALS['objects'][501]) ? 1 : 0, true]];
        },
    ];
}

// Fuehrt ein Szenario mit einer anderen Fassung der Kachel in einem eigenen Prozess aus (ohne Netz): [Exitcode, Ausgabe]
function unterprozess(string $moduleFile, string $name): array
{
    $command = 'WC_MODULE=' . escapeshellarg($moduleFile) . ' ' . php_without_network() . ' '
        . escapeshellarg(__FILE__) . ' szenario ' . escapeshellarg($name) . ' 2>&1';
    exec($command, $lines, $code);
    return [$code, implode("\n", $lines)];
}

if (($argv[1] ?? '') === 'szenario') {
    // Gegenprobe: ein Szenario, Ausgaben des Moduls (Hook) verworfen, Ergebnis als JSON
    ob_start();
    $rows = szenarien()[$argv[2]]();
    ob_end_clean();
    echo json_encode($rows, JSON_THROW_ON_ERROR);
    exit(0);
}

echo '--- Module Strict' . PHP_EOL;
$class = new ReflectionClass('TileVisuWeatherClockTile');
check($class->getParentClass()->getName() === 'IPSModuleStrict', 'Module extends IPSModuleStrict');
$signature = static function (string $method) use ($class): string {
    $m = $class->getMethod($method);
    $params = array_map(static fn (ReflectionParameter $p): string => $p->getType() . ' $' . $p->getName(), $m->getParameters());
    return ($m->isProtected() ? 'protected ' : 'public ') . $method . '(' . implode(', ', $params) . '): ' . $m->getReturnType();
};
check($signature('Create') === 'public Create(): void' && $signature('ApplyChanges') === 'public ApplyChanges(): void'
    && $signature('Destroy') === 'public Destroy(): void', 'Create, ApplyChanges and Destroy are typed');
check($signature('MessageSink') === 'public MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void', 'MessageSink is typed');
check($signature('RequestAction') === 'public RequestAction(string $Ident, mixed $Value): void', 'RequestAction is typed');
check($signature('GetVisualizationTile') === 'public GetVisualizationTile(): string', 'GetVisualizationTile is typed');
check($signature('ProcessHookData') === 'protected ProcessHookData(): void', 'ProcessHookData is typed like the base class (HookInstance for Strict)');
check(!str_contains($source, 'method_exists($this'), 'No method_exists switches for SDK methods');
check(!preg_match('~function\s+RegisterHook\s*\(~i', $source), 'No own RegisterHook (the native one is used)');
check(!str_contains($source, '015A6EB8') && !preg_match('~IPS_(SetProperty|GetProperty|ApplyChanges)\s*\(~', $source),
    'Module source never touches the WebHook Control');

echo '--- Create' . PHP_EOL;
$m = new TileVisuWeatherClockTile();
$m->Create();
check(array_keys($m->properties) === ['TemperatureVariableID', 'Location', 'ShowWeather', 'ShowForecast', 'UseAnimatedIcons',
    'UseOutlineIcons', 'CustomMediaID', 'ShowClock', 'ShowDate', 'ForecastWidthPercent', 'ClockWidthPercent',
    'ClockVerticalPercent', 'DateFontSizePx', 'DateScaleFactor', 'ShowSeconds', 'StoreWeatherData', 'StoreImageUrl'],
    'Property names and order unchanged');
check($m->properties['ShowWeather'] === true && $m->properties['UseOutlineIcons'] === true && $m->properties['DateScaleFactor'] === 3
    && $m->properties['ForecastWidthPercent'] === 25 && $m->properties['Location'] === '', 'Property defaults unchanged');
check($m->hooks === ['wetterbilder/12345'], 'Hook is registered natively in Create, without /hook/ prefix (same path as before)');
check($m->buffers['ImageHook'] === '1', 'Registered hook is remembered in the buffer ImageHook');
check($m->visualizationType === 1, 'Tile uses the HTML SDK (set in Create)');
check(($m->timers['UpdateTimer']['interval'] ?? null) === 3600000
    && $m->timers['UpdateTimer']['script'] === "IPS_RequestAction(\$_IPS['TARGET'], 'UpdateNow', 0);", 'Hourly timer registered as before');
check($m->logs === [], 'No warning when the hook is registered');
$hookAvailable = false;
$n = new TileVisuWeatherClockTile();
$n->Create();
check($n->buffers['ImageHook'] === '' && count($n->logs) === 1 && $n->logs[0][0] === KL_WARNING, 'Failed hook registration is remembered and logged');
$hookAvailable = true;

echo '--- Kernelstart abwarten' . PHP_EOL;
$weatherBody = weather_json();
variable(700, 21.5, '21,5 °C');
$m = tile_module(12345, ['TemperatureVariableID' => 700]);
$runlevel = 0;
$m->ApplyChanges();
check($m->updates === [] && $m->references === [] && $m->messages === [0 => [IPS_KERNELSTARTED]] && $fetches === [],
    'Before KR_READY only the kernel start is awaited (no fetch, no update)');
$runlevel = KR_READY;
$m->MessageSink(0, 0, IPS_KERNELSTARTED, []);
check($m->updates !== [] && isset($m->messages[700]) && isset($m->references[700]), 'Kernel start completes ApplyChanges');

echo '--- Unbekannte Aktionen' . PHP_EOL;
foreach (['WebhookGetName', 'Unbekannt'] as $ident) {
    try {
        $m->RequestAction($ident, 0);
        $thrown = false;
    } catch (Exception $e) {
        $thrown = $e->getMessage() === 'Invalid Ident';
    }
    check($thrown, 'RequestAction("' . $ident . '") is rejected (Strict RequestAction returns nothing)');
}

echo '--- Altlasten des alten Hook-Wegs' . PHP_EOL;
reset_world();
$weatherBody = weather_json();
$webhookInstances = [15000];
$legacyScript = "<?php\nif (isset(\$_IPS['SENDER']) && \$_IPS['SENDER'] === 'WebHook') {\n    \$iid = 23456;\n    \$baseDir = '/x';\n}\n";
$objects = [
    501 => ['parent' => 23456, 'ident' => 'HookScript', 'name' => 'Wetterbilder WebHook', 'script' => $legacyScript],
    502 => ['parent' => 23456, 'ident' => 'HookScript', 'name' => 'Eigenes Skript', 'script' => "<?php\necho 'Nutzer';\n"],
    503 => ['parent' => 23456, 'ident' => 'Notiz', 'name' => 'Notiz', 'script' => $legacyScript],
    504 => ['parent' => 34567, 'ident' => 'HookScript', 'name' => 'Wetterbilder WebHook', 'script' => str_replace('23456', '34567', $legacyScript)],
    505 => ['parent' => 23456, 'ident' => 'HookScript', 'name' => 'Fremde Instanz', 'script' => str_replace('23456', '34567', $legacyScript)],
    506 => ['parent' => 23456, 'ident' => 'OpenMeteoRaw', 'name' => 'Variable'],
];
$l = tile_module(23456);
$l->ApplyChanges();
check(!isset($objects[501]), 'Own legacy hook script (ident, WebHook sender check and own ID) is deleted');
check(isset($objects[502], $objects[503], $objects[504], $objects[505], $objects[506]),
    'Scripts with other content, other ident, another parent or another instance ID stay, as do other children');
check(count(array_filter($l->logs, static fn (array $log): bool => str_contains($log[1], '#501'))) === 1, 'Deletion is logged once');
$l->ApplyChanges();
check(isset($objects[502]) && count($objects) === 5, 'Second ApplyChanges changes nothing');
check($webhookCalls === [], 'The WebHook Control is not touched (old entries pointing to this instance stay harmless)');

echo '--- Hook-Adressen statt Base64' . PHP_EOL;
$bytes = static fn (string $relPath): string => (string) file_get_contents($moduleDir . '/' . $relPath);
$dataUri = static fn (string $mime, string $relPath): string => 'data:' . $mime . ';base64,' . base64_encode($bytes($relPath));
reset_world();
$weatherBody = weather_json(63, 1);
variable(700, 21.5, '21,5 °C');
$h = tile_module(24001, ['TemperatureVariableID' => 700]);
$h->ApplyChanges();
$token = $h->attributes['WebhookToken'];
check(strlen($token) === 32 && ctype_xdigit($token), 'Hook token is created in ApplyChanges');
$image = last_message($h->updates, 'image');
$initial = tile($h)['message'];
$v16 = '[0-9a-f]{16}';
check(preg_match('~\A/hook/wetterbilder/24001\?name=light-rain-day&v=' . $v16 . '&token=' . $token . '\z~', $image['url']) === 1,
    'Background is a versioned hook address on the old path');
check(preg_match('~\A/hook/wetterbilder/24001\?asset=flipclock\.min\.css&v=' . $v16 . '&token=' . $token . '\z~', $initial['flipclock']['css']) === 1
    && preg_match('~\A/hook/wetterbilder/24001\?asset=flipclock\.min\.js&v=' . $v16 . '&token=' . $token . '\z~', $initial['flipclock']['js']) === 1,
    'FlipClock files come as finished versioned addresses from the module (initial state)');
check(!str_contains(implode('', $h->updates), 'flipclock'), 'Messages to open tiles do not repeat the FlipClock addresses');
check(preg_match('~\A/hook/wetterbilder/24001\?icon=full%2Fstatic%2Frain\.svg&v=' . $v16 . '&token=' . $token . '\z~', $image['temperature']['iconUrl']) === 1,
    'Weather icon is a versioned hook address');
check(count($image['forecast']) === 4 && array_filter($image['forecast'], static fn (array $d): bool => !str_starts_with($d['iconUrl'], '/hook/wetterbilder/24001?icon=')) === [],
    'Forecast icons are hook addresses');
check(!str_contains(implode('', $h->updates), 'base64,'), 'No message carries Base64 anymore');
check(array_filter($h->updates, static fn (string $u): bool => array_intersect(['ts', 'token', 'assetBase'], array_keys(json_decode($u, true))) !== []) === [],
    'Messages carry no ts (cache buster), no separate token and no assetBase');
check(array_keys($image) === ['type', 'url', 'slug', 'timeOfDay', 'temperature', 'forecast', 'showWeather', 'showClock', 'showDate',
    'showSeconds', 'forecastWidthPercent', 'clockWidthPercent', 'clockVerticalPercent', 'dateFontSizePx', 'dateScaleFactor',
    'showForecast', 'wmoCode', 'imageName'], 'Image message keys');
check(!preg_match('~[\s"\'()\\\\]~', $image['url']), 'Background address is valid inside CSS url()');

echo '--- Auslieferung ueber den Hook' . PHP_EOL;
$q = query($image['url']);
$body = $h->hook($q);
check($h->status === 200 && $body === $bytes('assets/wetterbilder/light-rain-day.png'), 'Hook delivers the background bytes');
check($h->header('Content-Type') === 'image/png' && $h->header('Content-Length') === (string) strlen($body), 'Hook sends image type and length');
check($h->header('Cache-Control') === 'public, max-age=31536000, immutable', 'Current version is cached long (public)');
check($h->header('ETag') === '"' . $q['v'] . '"' && $h->header('X-Content-Type-Options') === 'nosniff', 'ETag is the version, nosniff is set');
check($q['v'] === substr(hash_file('sha256', $moduleDir . '/assets/wetterbilder/light-rain-day.png'), 0, 16), 'Version is the content hash of the file');
$body = $h->hook($q, ['HTTP_IF_NONE_MATCH' => '"' . $q['v'] . '"']);
check($h->status === 304 && $body === '' && $h->header('Content-Type') === null, 'Matching If-None-Match answers 304 without body');
$body = $h->hook(['name' => 'light-rain-day', 'v' => 'alt', 'token' => $token], ['HTTP_IF_NONE_MATCH' => '"' . $q['v'] . '"']);
check($h->status === 200 && $body === $bytes('assets/wetterbilder/light-rain-day.png') && $h->header('Cache-Control') === 'no-cache',
    'Outdated version gets the current file uncached and never 304');
$body = $h->hook(query($initial['flipclock']['css']));
check($body === $bytes('assets/flipclock/flipclock.min.css') && $h->header('Content-Type') === 'text/css; charset=utf-8', 'FlipClock CSS is delivered');
$body = $h->hook(query($initial['flipclock']['js']));
check($body === $bytes('assets/flipclock/flipclock.min.js') && $h->header('Content-Type') === 'application/javascript; charset=utf-8'
    && $h->header('Cache-Control') === 'public, max-age=31536000, immutable', 'FlipClock JS is delivered and cached long');
$body = $h->hook(query($image['temperature']['iconUrl']));
check($body === $bytes('assets/icons/full/static/rain.svg') && $h->header('Content-Type') === 'image/svg+xml', 'Meteocon is delivered as SVG');
check($h->hook(['name' => 'light-rain-day', 'token' => $token]) === $bytes('assets/wetterbilder/light-rain-day.png')
    && $h->header('Cache-Control') === 'no-cache', 'Old address without version (name + token) still works, uncached');
check($h->hook(['asset' => 'flipclock.min.js', 'token' => $token]) === $bytes('assets/flipclock/flipclock.min.js'), 'Old FlipClock address still works');
check($h->hook(['token' => $token]) === $bytes('assets/wetterbilder/light-rain-day.png'), 'Address without name delivers the current background');
check($h->hook(['slug' => 'fog', 'tod' => 'night', 'token' => $token]) === $bytes('assets/wetterbilder/fog-night.png'), 'slug + tod still work');
check($h->hook(['name' => ' LIGHT-RAIN-DAY ', 'token' => $token]) === $bytes('assets/wetterbilder/light-rain-day.png'), 'Name is trimmed and lower-cased as before');

echo '--- Token und Pfade' . PHP_EOL;
foreach ([['wrong token', ['name' => 'fog-day', 'token' => str_repeat('0', 32)]], ['missing token', ['name' => 'fog-day']],
    ['token as array', ['name' => 'fog-day', 'token' => [$token]]], ['empty token', ['name' => 'fog-day', 'token' => '']]] as [$label, $get]) {
    $body = $h->hook($get);
    check($h->status === 403 && $h->sent === [] && $body === '', 'Hook rejects ' . $label . ' with 403');
}
$bad = [
    ['name' => '../wetterbilder/light-rain-day'], ['name' => '../../module'], ['name' => '/etc/passwd'], ['name' => 'light_rain_day'],
    ['name' => "light-rain\0-day"], ['name' => 'light-rain-day.png'], ['name' => 'unbekannt-day'], ['name' => ['fog-day']],
    ['slug' => '../fog', 'tod' => 'day'],
    ['icon' => '../module.php'], ['icon' => 'full/static/../../../module.php'], ['icon' => 'full/static/rain.php'],
    ['icon' => 'full\\static\\rain.svg'], ['icon' => 'other/static/rain.svg'], ['icon' => 'full/static/'], ['icon' => 'full/static/unbekannt.svg'],
    ['icon' => ['full/static/rain.svg']],
    ['asset' => 'module.php'], ['asset' => '../flipclock/flipclock.min.js'], ['asset' => 'flipclock.min.css.map'], ['asset' => ['flipclock.min.js']],
    ['custom' => '1'],
];
foreach ($bad as $get) {
    $body = $h->hook($get + ['token' => $token]);
    check($h->status === 404 && $h->sent === [] && $body === '', 'Hook refuses ' . json_encode($get) . ' with 404');
}
$h->buffers['ImageHook'] = '';
$h->hook($q);
check($h->status === 403, 'Without registered hook the endpoint answers 403 even with the right token');
$h->buffers['ImageHook'] = '1';

echo '--- Eigenes Hintergrundbild' . PHP_EOL;
$png = "\x89PNG\x0D\x0A\x1A\x0A" . str_repeat('A', 40);
$media[500] = ['content' => base64_encode($png)];
$h->properties['CustomMediaID'] = 500;
$h->ApplyChanges();
check(isset($h->references[500]) && isset($h->messages[500]), 'Custom media is referenced and watched');
$image = last_message($h->updates, 'image');
$old = $image['url'];
check(preg_match('~\A/hook/wetterbilder/24001\?custom=1&v=' . $v16 . '&token=' . $token . '\z~', $old) === 1 && $image['imageName'] === 'custom',
    'Custom background is a versioned hook address');
check($h->hook(query($old)) === $png && $h->header('Content-Type') === 'image/png'
    && $h->header('Cache-Control') === 'private, max-age=31536000, immutable', 'Custom background is delivered and cached long, but private');
$jpeg = "\xFF\xD8\xFF\xE0" . str_repeat('B', 40);
$media[500]['content'] = base64_encode($jpeg);
$h->MessageSink(0, 500, MM_UPDATE, []);
$new = last_message($h->updates, 'image')['url'];
check($new !== $old && $h->hook(query($new)) === $jpeg && $h->header('Content-Type') === 'image/jpeg', 'Changed custom image gets a new address');
$body = $h->hook(query($old), ['HTTP_IF_NONE_MATCH' => '"' . query($old)['v'] . '"']);
check($h->status === 200 && $body === $jpeg && $h->header('Cache-Control') === 'no-cache', 'Old address gets the new image uncached, never 304');
$h->properties['CustomMediaID'] = 0;
$h->ApplyChanges();

echo '--- Dateien ueber der Ausgabegrenze bleiben eingebettet' . PHP_EOL;
$options['ScriptOutputBufferLimit'] = 700000;
$weatherBody = weather_json(66, 0);
$big = tile_module(24002);
$big->ApplyChanges();
$image = last_message($big->updates, 'image');
check($image['imageName'] === 'sleet-night' && $image['url'] === $dataUri('image/png', 'assets/wetterbilder/sleet-night.png'),
    'Background above the limit (916 kB) stays a data URI');
check(str_starts_with($image['temperature']['iconUrl'], '/hook/') && str_starts_with(tile($big)['message']['flipclock']['js'], '/hook/'), 'Small files still use the hook');
$body = $big->hook(['name' => 'sleet-night', 'token' => $big->attributes['WebhookToken']]);
check($big->status === 404 && $big->sent === [] && $body === '', 'Hook refuses the file above the limit before any output');
check($big->hook(['name' => 'fog-day', 'token' => $big->attributes['WebhookToken']]) === $bytes('assets/wetterbilder/fog-day.png'), 'Files below the limit are delivered');
unset($options['ScriptOutputBufferLimit']);

echo '--- Rueckfall ohne registrierten Hook' . PHP_EOL;
$hookAvailable = false;
$weatherBody = weather_json(63, 1);
$f = tile_module(24003);
$f->ApplyChanges();
$image = last_message($f->updates, 'image');
check($f->attributes['WebhookToken'] === '', 'Without registered hook no token is created');
check($image['url'] === $dataUri('image/png', 'assets/wetterbilder/light-rain-day.png'), 'Background is embedded as data URI');
check(tile($f)['message']['flipclock'] === ['css' => $dataUri('text/css', 'assets/flipclock/flipclock.min.css'), 'js' => $dataUri('application/javascript', 'assets/flipclock/flipclock.min.js')],
    'FlipClock files are embedded as data URIs');
check($image['temperature']['iconUrl'] === $dataUri('image/svg+xml', 'assets/icons/full/static/rain.svg'), 'Icons are embedded as data URIs');
$f->hook(['name' => 'fog-day', 'token' => '']);
check($f->status === 403, 'Without hook the endpoint answers nothing');
$hookAvailable = true;

echo '--- Versionen aus dem Puffer' . PHP_EOL;
$versions = json_decode($h->buffers['AssetVersions'], true);
$key = 'assets/wetterbilder/light-rain-day.png';
check(isset($versions[$key]) && $versions[$key][1] === query(last_message($h->updates, 'image')['url'])['v'], 'Version is cached per file');
$versions[$key][1] = 'cachedversion000';
$h->buffers['AssetVersions'] = json_encode($versions);
$h->properties['CustomMediaID'] = 0;
$h->ApplyChanges();
check(query(last_message($h->updates, 'image')['url'])['v'] === 'cachedversion000', 'Version comes from the buffer (file not hashed again)');
$versions[$key][0] = 'geaenderte-datei';
$h->buffers['AssetVersions'] = json_encode($versions);
$h->ApplyChanges();
check(query(last_message($h->updates, 'image')['url'])['v'] === substr(hash_file('sha256', $moduleDir . '/' . $key), 0, 16), 'Changed file stamp hashes the file again');
check(strlen($h->buffers['AssetVersions']) < 262144, 'Version buffer stays far below 256 kB');

echo '--- Anfangszustand im Kacheldokument' . PHP_EOL;
reset_world();
$weatherBody = weather_json(63, 1);
$boese = "O'Neil \"x\" \\ Zeile1\nZeile2 </script><script>alert(1)</script> & <b> <!--";
variable(700, 21.5, $boese);
$d = tile_module(25001, ['TemperatureVariableID' => 700]);
$d->ApplyChanges();
check(count($fetches) === 1 && json_decode($d->attributes['WeatherCache'], true)['data'] === json_decode($weatherBody, true),
    'ApplyChanges without cached data fetches once and keeps the answer in the attribute WeatherCache');
$fetches = [];
$d->updates = [];
$t = tile($d);
check($fetches === [] && $d->updates === [], 'Opening the tile fetches nothing and sends nothing to other tiles');
check($t['message']['type'] === 'image' && $t['message']['imageName'] === 'light-rain-day' && count($t['message']['forecast']) === 4
    && str_starts_with($t['message']['temperature']['iconUrl'], '/hook/wetterbilder/25001?icon='), 'Document carries the complete state from the cache');
check(str_starts_with($t['message']['flipclock']['js'], '/hook/wetterbilder/25001?asset=flipclock.min.js&v=')
    && str_starts_with($t['message']['flipclock']['css'], '/hook/wetterbilder/25001?asset=flipclock.min.css&v='), 'Initial state carries the FlipClock addresses');
check($t['message']['temperature']['value'] === $boese, 'Value with quotes, backslash, newline and </script> arrives unchanged');
check(substr_count($t['script'], '</script>') === 1 && !str_contains($t['script'], '<b>') && !str_contains($t['script'], '<!--'),
    'No tag, comment or </script> from a value in the initial script block');
check(!str_contains($t['html'], 'base64,'), 'Tile document carries no Base64 with the hook');
$hookAvailable = false;
$e = tile_module(25002);
$e->ApplyChanges();
$et = tile($e);
check(str_starts_with($et['message']['url'], 'data:image/png;base64,') && str_starts_with($et['message']['flipclock']['js'], 'data:application/javascript;base64,'),
    'Without hook the document embeds background and FlipClock as data URIs');
$hookAvailable = true;
echo 'Kacheldokument mit/ohne Hook: ' . strlen($t['html']) . ' / ' . strlen($et['html']) . ' Bytes' . PHP_EOL;

echo '--- Oeffnen und Temperatur-Updates ohne Netzabruf' . PHP_EOL;
$fetches = [];
$d->updates = [];
$d->RequestAction('GetState', 0);
check($fetches === [] && $d->updates === [], 'GetState with fresh data fetches nothing and sends nothing');
$variables[700]['formatted'] = '22,0 °C';
$d->MessageSink(0, 700, VM_UPDATE, [22.0, true, 21.5, time()]);
$temp = last_message($d->updates, 'temperature');
check($fetches === [] && $temp['temperature']['value'] === '22,0 °C' && str_starts_with($temp['temperature']['iconUrl'], '/hook/'),
    'Temperature update fetches nothing and takes the weather icon from the cache');
$age = static function (TileVisuWeatherClockTile $m, int $seconds): void {
    $cache = json_decode($m->attributes['WeatherCache'], true);
    $cache['at'] = time() - $seconds;
    $m->attributes['WeatherCache'] = json_encode($cache);
};
$age($d, 5000);
$d->updates = [];
tile($d);
check($fetches === [] && $d->updates === [], 'Opening the tile with outdated data still fetches nothing (renders the cached state)');
$weatherBody = weather_json(3, 1);
$d->RequestAction('GetState', 0);
check(count($fetches) === 1 && last_message($d->updates, 'image')['imageName'] === 'thick-cloud-day',
    'GetState with data older than 70 minutes fetches once and sends the new state');
$d->RequestAction('GetState', 0);
check(count($fetches) === 1, 'Right afterwards GetState fetches nothing');
$age($d, 5000);
$before = $d->attributes['WeatherCache'];
$weatherBody = '';
$fetches = [];
$d->updates = [];
$d->RequestAction('GetState', 0);
check(count($fetches) === 1 && $d->updates === [] && $d->attributes['WeatherCache'] === $before, 'Failed fetch keeps the cached answer and sends nothing');
$d->RequestAction('GetState', 0);
$d->ApplyChanges();
check(count($fetches) === 1, 'After a failed fetch neither GetState nor ApplyChanges try again within 5 minutes');
check(last_message($d->updates, 'image')['imageName'] === 'thick-cloud-day', 'Meanwhile the tiles keep the last known weather');
$d->buffers['WeatherFetchFailed'] = (string) (time() - 301);
$d->RequestAction('GetState', 0);
check(count($fetches) === 2, 'After 5 minutes the next GetState tries again');
$weatherBody = '{"error":true,"reason":"Latitude must be in range of -90 to 90°."}';
$d->buffers['WeatherFetchFailed'] = '';
$d->RequestAction('GetState', 0);
check(count($fetches) === 3 && $d->attributes['WeatherCache'] === $before, 'Open-Meteo error answer counts as failure and keeps the cache');
$weatherBody = weather_json(0, 1);
$d->buffers['WeatherFetchFailed'] = '';
$fetches = [];
$d->UpdateNow();
check(count($fetches) === 1 && last_message($d->updates, 'image')['imageName'] === 'sunny-day', 'Timer (UpdateNow) always fetches');
$d->UpdateNow();
check(count($fetches) === 2, 'UpdateNow fetches even with fresh data (manual refresh)');
$d->properties['Location'] = json_encode(['latitude' => 48.1, 'longitude' => 11.6]);
$d->ApplyChanges();
check(count($fetches) === 3 && str_contains($fetches[2], 'latitude=48.1&longitude=11.6'), 'New location: ApplyChanges fetches although the old data is fresh');
$d->ApplyChanges();
check(count($fetches) === 3, 'ApplyChanges with fresh data fetches nothing');
$d->properties['ShowWeather'] = false;
$age($d, 99999);
$d->ApplyChanges();
$d->RequestAction('GetState', 0);
$d->UpdateNow();
tile($d);
check(count($fetches) === 3, 'Without weather display nothing is fetched');
$d->properties['ShowWeather'] = true;
$age($d, 30000);
$old6h = tile($d)['message'];
check($old6h['wmoCode'] === 0 && $old6h['forecast'] === [] && $old6h['temperature']['iconUrl'] === ''
    && in_array($old6h['imageName'], ['sunny-day', 'clear-sky-night'], true), 'Data older than 6 hours is not shown (as after a failed fetch)');
$weatherBody = weather_json(63, 1, 12.3, 'yesterday');
$d->UpdateNow();
$forecast = last_message($d->updates, 'image')['forecast'];
check(count($forecast) === 4 && $forecast[0]['label'] === german_day('today') && $forecast[0]['max'] === 16 && $forecast[0]['min'] === 8,
    'Forecast skips days before today (cached answer from yesterday)');
$d->properties['StoreWeatherData'] = true;
$d->ApplyChanges();
$d->UpdateNow();
check(GetValue($d->idents['OpenMeteoRaw']) === $weatherBody, 'OpenMeteoRaw still receives the raw answer of each fetch');

echo '--- Nur bei echter Aenderung' . PHP_EOL;
foreach (nachrichtenfilter() as [$label, $expected, $actual]) {
    check($actual === $expected, $label . ' (' . $actual . ' messages)');
}
reset_world();
$weatherBody = weather_json(63, 1);
variable(700, 21.5, '21,5 °C');
$u = tile_module(27002, ['TemperatureVariableID' => 700]);
$u->ApplyChanges();
$weatherBody = weather_json(95, 1);
$u->updates = [];
$u->UpdateNow();
check(count($u->updates) === 1 && last_message($u->updates, 'image')['temperature']['value'] === '21,5 °C'
    && count(last_message($u->updates, 'image')['forecast']) === 4, 'One image message carries temperature and forecast (no separate messages)');
$u->properties['ShowWeather'] = false;
$u->ApplyChanges();
$variables[700] = ['value' => 30.0, 'formatted' => '30,0 °C'];
$u->updates = [];
$u->MessageSink(0, 700, VM_UPDATE, [30.0, true, 21.5, 7]);
check($u->updates === [], 'Without weather display a temperature update sends nothing');
check(strlen($u->buffers['UpdateHashes']) < 200, 'Hash buffer stays tiny');

echo '--- Stundentimer' . PHP_EOL;
reset_world();
$weatherBody = weather_json(63, 1);
variable(700, 21.5, '21,5 °C');
$media[500] = ['content' => base64_encode("\x89PNG\x0D\x0A\x1A\x0A" . str_repeat('A', 40))];
$tm = tile_module(28001, ['TemperatureVariableID' => 700, 'CustomMediaID' => 500]);
check($tm->timerCalls === [['RegisterTimer', 'UpdateTimer', 3600000]], 'Create registers the hourly timer');
$tm->ApplyChanges();
$tm->ApplyChanges();
check($tm->timerCalls === [['RegisterTimer', 'UpdateTimer', 3600000]], 'ApplyChanges does not restart a running hourly timer');
$tm->timers['UpdateTimer']['interval'] = 0;
$tm->ApplyChanges();
check(array_slice($tm->timerCalls, 1) === [['SetTimerInterval', 'UpdateTimer', 3600000]], 'ApplyChanges sets the timer only when its interval differs');
$tm->timerCalls = [];
$tm->UpdateNow();
$tm->RequestAction('GetState', 0);
$age($tm, 5000);
$tm->RequestAction('GetState', 0);
$variables[700] = ['value' => 23.0, 'formatted' => '23,0 °C'];
$tm->MessageSink(0, 700, VM_UPDATE, [23.0, true, 21.5, 8]);
$tm->MessageSink(0, 500, MM_UPDATE, []);
tile($tm);
$tm->hook(['token' => $tm->attributes['WebhookToken']]);
check($tm->timerCalls === [] && count($fetches) >= 2, 'Fetching, opening, updates and the hook never set the timer');

echo '--- Kachel-JavaScript' . PHP_EOL;
$html = (string) file_get_contents($moduleDir . '/module.html');
check(!preg_match('~searchParams|webhookToken|withTs|assetBase~', $html), 'The tile builds no addresses and reads no token from a URL');
$outside = (string) preg_replace('~<!-- symcon-icons-shared:.*?<!-- /symcon-icons-shared -->~s', '', $html);
check(substr_count($html, '<!-- symcon-icons-shared:') === 1 && !str_contains($outside, '/icons.js'), 'Shared icon block instead of an own icons.js tag');
exec('command -v node 2>/dev/null', $unused, $nodeCode);
if ($nodeCode !== 0) {
    echo 'SKIP: node not available, no syntax check of the tile scripts' . PHP_EOL;
} else {
    reset_world();
    $weatherBody = weather_json(63, 1);
    $documents = ['module.html' => $html, 'tile document' => tile(tile_module(30001))['html']];
    $hookAvailable = false;
    $documents['tile document without hook'] = tile(tile_module(30002))['html'];
    $hookAvailable = true;
    foreach ($documents as $name => $document) {
        preg_match_all('~<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>~s', $document, $scripts);
        foreach ($scripts[1] as $i => $js) {
            $file = sys_get_temp_dir() . '/wc-js-' . bin2hex(random_bytes(6)) . '.js';
            file_put_contents($file, $js);
            exec('node --check ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            unlink($file);
            check($code === 0, 'Script ' . $i . ' of the ' . $name . ' passes node --check (' . strlen($js) . ' characters)');
        }
    }
}

echo '--- Gegenprobe gegen ' . VOR_UMBAU . PHP_EOL;
$neu = [];
foreach (szenarien() as $name => $szenario) {
    foreach ($szenario() as [$label, $expected, $actual, $fixed]) {
        check($actual === $expected, $label);
        $neu[$label] = $fixed;
    }
}
$repo = escapeshellarg($root);
exec('git -C ' . $repo . ' cat-file -e ' . escapeshellarg(VOR_UMBAU . '^{commit}') . ' 2>/dev/null', $unused, $gitCode);
if ($gitCode !== 0) {
    echo 'SKIP: ' . VOR_UMBAU . ' not available, no counter-check' . PHP_EOL;
} else {
    // module.php und module.html des alten Stands in ein Temp-Verzeichnis, assets/ als Verweis auf diese Arbeitskopie
    $dir = sys_get_temp_dir() . '/wc-test-' . bin2hex(random_bytes(6));
    mkdir($dir . '/TileVisu-Weather-Clock-Tile', 0700, true);
    foreach (['module.php', 'module.html'] as $datei) {
        file_put_contents($dir . '/TileVisu-Weather-Clock-Tile/' . $datei,
            (string) shell_exec('git -C ' . $repo . ' show ' . escapeshellarg(VOR_UMBAU . ':TileVisu-Weather-Clock-Tile/' . $datei)));
    }
    symlink($moduleDir . '/assets', $dir . '/TileVisu-Weather-Clock-Tile/assets');
    $alt = [];
    foreach (array_keys(szenarien()) as $name) {
        [$code, $json] = unterprozess($dir . '/TileVisu-Weather-Clock-Tile/module.php', $name);
        check($code === 0, 'Scenario "' . $name . '" runs against ' . VOR_UMBAU . ($code === 0 ? '' : ': ' . substr($json, 0, 300)));
        foreach (json_decode($json, true, 512, JSON_THROW_ON_ERROR) as [$label, $expected, $actual]) {
            $alt[$label] = $actual !== $expected;
        }
    }
    unlink($dir . '/TileVisu-Weather-Clock-Tile/assets');
    foreach (['module.php', 'module.html'] as $datei) {
        unlink($dir . '/TileVisu-Weather-Clock-Tile/' . $datei);
    }
    rmdir($dir . '/TileVisu-Weather-Clock-Tile');
    rmdir($dir);
    foreach ($neu as $label => $fixed) {
        echo '  ' . ($alt[$label] ?? null ? 'faellt ' : 'haelt  ') . ($fixed ? '[behoben]  ' : '[erhalten] ') . $label . PHP_EOL;
    }
    $fallend = array_keys(array_filter($alt));
    $behoben = array_keys(array_filter($neu));
    check(array_keys($alt) === array_keys($neu) && $fallend === $behoben,
        'Counter-check: against ' . VOR_UMBAU . ' exactly the ' . count($behoben) . ' fixed checks fail, the ' . (count($neu) - count($behoben)) . ' preserved ones hold');
}

echo 'OK' . PHP_EOL;
