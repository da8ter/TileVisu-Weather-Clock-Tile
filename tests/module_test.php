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
$v16 = '[0-9a-f]{16}';
check(preg_match('~\A/hook/wetterbilder/24001\?name=light-rain-day&v=' . $v16 . '&token=' . $token . '\z~', $image['url']) === 1,
    'Background is a versioned hook address on the old path');
check(preg_match('~\A/hook/wetterbilder/24001\?asset=flipclock\.min\.css&v=' . $v16 . '&token=' . $token . '\z~', $image['flipclock']['css']) === 1
    && preg_match('~\A/hook/wetterbilder/24001\?asset=flipclock\.min\.js&v=' . $v16 . '&token=' . $token . '\z~', $image['flipclock']['js']) === 1,
    'FlipClock files come as finished versioned addresses from the module');
check(preg_match('~\A/hook/wetterbilder/24001\?icon=full%2Fstatic%2Frain\.svg&v=' . $v16 . '&token=' . $token . '\z~', $image['temperature']['iconUrl']) === 1,
    'Weather icon is a versioned hook address');
check(count($image['forecast']) === 4 && array_filter($image['forecast'], static fn (array $d): bool => !str_starts_with($d['iconUrl'], '/hook/wetterbilder/24001?icon=')) === [],
    'Forecast icons are hook addresses');
check(!str_contains(implode('', $h->updates), 'base64,'), 'No message carries Base64 anymore');
check(array_filter($h->updates, static fn (string $u): bool => array_intersect(['ts', 'token', 'assetBase'], array_keys(json_decode($u, true))) !== []) === [],
    'Messages carry no ts (cache buster), no separate token and no assetBase');
check(array_keys($image) === ['type', 'url', 'slug', 'timeOfDay', 'temperature', 'forecast', 'showWeather', 'showClock', 'showDate',
    'showSeconds', 'forecastWidthPercent', 'clockWidthPercent', 'clockVerticalPercent', 'dateFontSizePx', 'dateScaleFactor',
    'showForecast', 'flipclock', 'wmoCode', 'imageName'], 'Image message keys');
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
$body = $h->hook(query($image['flipclock']['css']));
check($body === $bytes('assets/flipclock/flipclock.min.css') && $h->header('Content-Type') === 'text/css; charset=utf-8', 'FlipClock CSS is delivered');
$body = $h->hook(query($image['flipclock']['js']));
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
check(str_starts_with($image['temperature']['iconUrl'], '/hook/') && str_starts_with($image['flipclock']['js'], '/hook/'), 'Small files still use the hook');
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
check($image['flipclock'] === ['css' => $dataUri('text/css', 'assets/flipclock/flipclock.min.css'), 'js' => $dataUri('application/javascript', 'assets/flipclock/flipclock.min.js')],
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

echo 'OK' . PHP_EOL;
