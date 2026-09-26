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
    $m->properties = array_merge($m->properties, ['Location' => '{"latitude":51.5,"longitude":7.5}'], $properties);
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

echo 'OK' . PHP_EOL;
