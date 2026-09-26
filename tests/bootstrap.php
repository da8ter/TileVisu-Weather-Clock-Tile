<?php

declare(strict_types=1);

// Isolierte SDK-Attrappe: verbindet sich nie mit einer laufenden Symcon-Installation und nie mit Open-Meteo.

// Ohne Netz: der Test startet sich ohne cURL, Sockets und URL-fopen neu. Auch ein Fehler im Modul (oder die
// Gegenprobe mit einem alten Stand, der selbst abruft) erreicht so nie einen Server.
const NETWORK_FUNCTIONS = 'curl_init,curl_exec,curl_multi_init,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_create,socket_connect';
function php_without_network(): string
{
    return escapeshellarg(PHP_BINARY) . ' -d allow_url_fopen=0 -d disable_functions=' . escapeshellarg(NETWORK_FUNCTIONS);
}
if (function_exists('curl_init') || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
    passthru(php_without_network() . ' ' . implode(' ', array_map('escapeshellarg', $GLOBALS['argv'])), $exitCode);
    exit($exitCode);
}

const KR_READY = 10103, IPS_KERNELSTARTED = 10001, VM_UPDATE = 10603, MM_UPDATE = 10905, VARIABLETYPE_STRING = 3,
    KL_MESSAGE = 10201, KL_WARNING = 10204, KL_ERROR = 10205;

// Instanzzustand und die SDK-Methoden, die das Modul aufruft. Die Typen folgen IPSModuleStrict: ein falscher
// Typ faellt unter strict_types auch hier als TypeError auf.
class ModuleDouble
{
    public array $properties = [], $attributes = [], $buffers = [], $messages = [], $references = [],
        $updates = [], $hooks = [], $timers = [], $timerCalls = [], $logs = [], $idents = [];
    public int $InstanceID = 12345;
    public int $visualizationType = 0;

    protected function RegisterPropertyInteger(string $Name, int $Value): bool { $this->properties[$Name] = $Value; return true; }
    protected function RegisterPropertyFloat(string $Name, float $Value): bool { $this->properties[$Name] = $Value; return true; }
    protected function RegisterPropertyString(string $Name, string $Value): bool { $this->properties[$Name] = $Value; return true; }
    protected function RegisterPropertyBoolean(string $Name, bool $Value): bool { $this->properties[$Name] = $Value; return true; }
    protected function ReadPropertyInteger(string $Name): int { return $this->Property($Name); }
    protected function ReadPropertyFloat(string $Name): float { return $this->Property($Name); }
    protected function ReadPropertyString(string $Name): string { return $this->Property($Name); }
    protected function ReadPropertyBoolean(string $Name): bool { return $this->Property($Name); }
    protected function RegisterAttributeInteger(string $Name, int $Value): bool { $this->attributes[$Name] = $Value; return true; }
    protected function RegisterAttributeString(string $Name, string $Value): bool { $this->attributes[$Name] = $Value; return true; }
    protected function ReadAttributeInteger(string $Name): int { return $this->Attribute($Name); }
    protected function ReadAttributeString(string $Name): string { return $this->Attribute($Name); }
    protected function WriteAttributeInteger(string $Name, int $Value): bool { $this->Attribute($Name); $this->attributes[$Name] = $Value; return true; }
    protected function WriteAttributeString(string $Name, string $Value): bool { $this->Attribute($Name); $this->attributes[$Name] = $Value; return true; }
    protected function GetBuffer(string $Name): string { return $this->buffers[$Name] ?? ''; }
    protected function SetBuffer(string $Name, string $Data): bool
    {
        // Symcon warnt ab 256 kB und kuerzt ueber 512 kB: hier faellt das als Fehler auf
        if (strlen($Data) > 262144) {
            throw new RuntimeException('Buffer ' . $Name . ' exceeds 256 kB: ' . strlen($Data) . ' bytes');
        }
        $this->buffers[$Name] = $Data;
        return true;
    }
    protected function RegisterTimer(string $Ident, int $Milliseconds, string $ScriptText): bool
    {
        $this->timers[$Ident] = ['interval' => $Milliseconds, 'script' => $ScriptText];
        $this->timerCalls[] = ['RegisterTimer', $Ident, $Milliseconds];
        return true;
    }
    protected function SetTimerInterval(string $Ident, int $Milliseconds): bool
    {
        if (!isset($this->timers[$Ident])) {
            throw new RuntimeException('Timer not registered: ' . $Ident);
        }
        $this->timers[$Ident]['interval'] = $Milliseconds;
        $this->timerCalls[] = ['SetTimerInterval', $Ident, $Milliseconds];
        return true;
    }
    protected function GetTimerInterval(string $Ident): int
    {
        return $this->timers[$Ident]['interval'] ?? throw new RuntimeException('Timer not registered: ' . $Ident);
    }
    protected function SetVisualizationType(int $Type): bool { $this->visualizationType = $Type; return true; }
    protected function UpdateVisualizationValue(string $Value): bool { $this->updates[] = $Value; return true; }
    public function GetReferenceList(): array { return array_keys($this->references); }
    protected function RegisterReference(int $ID): bool { $this->references[$ID] = true; return true; }
    protected function UnregisterReference(int $ID): bool { unset($this->references[$ID]); return true; }
    protected function GetMessageList(): array { return $this->messages; }
    protected function RegisterMessage(int $SenderID, int $Message): bool
    {
        if (!in_array($Message, $this->messages[$SenderID] ?? [], true)) {
            $this->messages[$SenderID][] = $Message;
        }
        return true;
    }
    protected function UnregisterMessage(int $SenderID, int $Message): bool
    {
        $this->messages[$SenderID] = array_values(array_diff($this->messages[$SenderID] ?? [], [$Message]));
        if ($this->messages[$SenderID] === []) {
            unset($this->messages[$SenderID]);
        }
        return true;
    }
    protected function SendDebug(string $Message, string $Data, int $Format): bool { return true; }
    protected function LogMessage(string $Message, int $Type): bool { $this->logs[] = [$Type, $Message]; return true; }
    public function Translate(string $Text): string { return $Text; }
    // Variablen der Instanz (OpenMeteoRaw, CurrentImageUrl) als globale Variablen wie bei Symcon
    protected function MaintainVariable(string $Ident, string $Name, int $Type, string|array $Profile, int $Position, bool $Keep): bool
    {
        if ($Keep && !isset($this->idents[$Ident])) {
            $id = 90000 + count($GLOBALS['variables']);
            variable($id, '', '');
            $this->idents[$Ident] = $id;
        } elseif (!$Keep && isset($this->idents[$Ident])) {
            unset($GLOBALS['variables'][$this->idents[$Ident]], $this->idents[$Ident]);
        }
        return true;
    }
    protected function GetIDForIdent(string $Ident): int { return $this->idents[$Ident] ?? 0; }

    private function Property(string $Name): mixed
    {
        if (!array_key_exists($Name, $this->properties)) {
            throw new RuntimeException('Property not registered: ' . $Name);
        }
        return $this->properties[$Name];
    }

    private function Attribute(string $Name): mixed
    {
        if (!array_key_exists($Name, $this->attributes)) {
            throw new RuntimeException('Attribute not registered: ' . $Name);
        }
        return $this->attributes[$Name];
    }
}

// IPSModule ohne Typen, wie Symcon es fuer klassische Module bereitstellt - nur fuer die Gegenprobe mit dem Stand
// vor Module Strict. Wie in Symcon 9.1 ohne natives RegisterHook.
class IPSModule extends ModuleDouble
{
    public function Create() {}
    public function ApplyChanges() {}
    public function Destroy() {}
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {}
    public function RequestAction($Ident, $Value) {}
    public function GetVisualizationTile() { return ''; }
    protected function ProcessHookData() {}
}

// Die Methoden, die ein Modul ueberschreibt, mit den Signaturen von IPSModuleStrict: eine abweichende Signatur
// im Modul scheitert schon beim Laden, wie in Symcon.
class IPSModuleStrict extends ModuleDouble
{
    public function Create(): void {}
    public function ApplyChanges(): void {}
    public function Destroy(): void {}
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void {}
    public function RequestAction(string $Ident, mixed $Value): void {}
    public function GetVisualizationTile(): string { return ''; }
    protected function ProcessHookData(): void {}
    // Nativer Hook (Symcon 8.1+, bool laut Handbuch): ohne $hookAvailable gilt er als nicht registriert
    protected function RegisterHook(string $HookPath): bool { $this->hooks[] = $HookPath; return $GLOBALS['hookAvailable']; }
}

$runlevel = KR_READY;
$hookAvailable = true;
$options = $variables = $media = $objects = $writes = $fetches = $webhookCalls = [];
$weatherBody = '';
$webhookInstances = [];

// Setzt die Welt zwischen zwei Szenarien zurueck (Variablen, Medien, Objekte, Abrufe, WebHook Control)
function reset_world(): void
{
    $GLOBALS['runlevel'] = KR_READY;
    $GLOBALS['hookAvailable'] = true;
    $GLOBALS['options'] = $GLOBALS['variables'] = $GLOBALS['media'] = $GLOBALS['objects'] = [];
    $GLOBALS['writes'] = $GLOBALS['fetches'] = $GLOBALS['webhookCalls'] = $GLOBALS['webhookInstances'] = [];
    $GLOBALS['weatherBody'] = '';
}

function IPS_GetKernelRunlevel(): int { return $GLOBALS['runlevel']; }
function IPS_GetOption(string $Option): mixed { return $GLOBALS['options'][$Option] ?? 1048576; }
function IPS_VariableExists(int $VariableID): bool { return isset($GLOBALS['variables'][$VariableID]); }
function IPS_GetVariable(int $VariableID): array
{
    return $GLOBALS['variables'][$VariableID] ?? throw new RuntimeException('Variable #' . $VariableID . ' does not exist');
}
function GetValue(int $VariableID): mixed { return IPS_GetVariable($VariableID)['value']; }
function GetValueString(int $VariableID): string { return (string) IPS_GetVariable($VariableID)['value']; }
function GetValueFormatted(int $VariableID): string { return IPS_GetVariable($VariableID)['formatted']; }
function SetValue(int $VariableID, mixed $Value): bool
{
    IPS_GetVariable($VariableID);
    $GLOBALS['writes'][] = [$VariableID, $Value];
    $GLOBALS['variables'][$VariableID]['value'] = $Value;
    return true;
}
function SetValueString(int $VariableID, string $Value): bool { return SetValue($VariableID, $Value); }
function IPS_MediaExists(int $MediaID): bool { return isset($GLOBALS['media'][$MediaID]); }
function IPS_GetMedia(int $MediaID): array
{
    return $GLOBALS['media'][$MediaID] ?? throw new RuntimeException('Media #' . $MediaID . ' does not exist');
}
function IPS_GetMediaContent(int $MediaID): string { return IPS_GetMedia($MediaID)['content']; }

// Objektbaum fuer die Altlasten-Pruefung: Kinder der Instanz, Skripte mit Inhalt
function IPS_GetChildrenIDs(int $ID): array
{
    return array_keys(array_filter($GLOBALS['objects'], static fn (array $o): bool => $o['parent'] === $ID));
}
function IPS_GetObject(int $ID): array
{
    $o = $GLOBALS['objects'][$ID] ?? throw new RuntimeException('Object #' . $ID . ' does not exist');
    return ['ObjectID' => $ID, 'ParentID' => $o['parent'], 'ObjectIdent' => $o['ident'], 'ObjectName' => $o['name']];
}
function IPS_ScriptExists(int $ScriptID): bool { return isset($GLOBALS['objects'][$ScriptID]['script']); }
function IPS_GetScriptContent(int $ScriptID): string
{
    return $GLOBALS['objects'][$ScriptID]['script'] ?? throw new RuntimeException('Script #' . $ScriptID . ' does not exist');
}
function IPS_DeleteScript(int $ScriptID, bool $DeleteFile): bool
{
    if (!IPS_ScriptExists($ScriptID)) {
        return false;
    }
    unset($GLOBALS['objects'][$ScriptID]);
    return true;
}

// WebHook Control: das Modul darf es nicht mehr anfassen (Module Store); jeder Zugriff wird protokolliert
function IPS_GetInstanceListByModuleID(string $ModuleID): array
{
    $GLOBALS['webhookCalls'][] = ['IPS_GetInstanceListByModuleID', $ModuleID];
    return $ModuleID === '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}' ? $GLOBALS['webhookInstances'] : [];
}
function IPS_GetProperty(int $InstanceID, string $Name): mixed
{
    $GLOBALS['webhookCalls'][] = ['IPS_GetProperty', $InstanceID, $Name];
    return '[]';
}
function IPS_SetProperty(int $InstanceID, string $Name, mixed $Value): bool
{
    $GLOBALS['webhookCalls'][] = ['IPS_SetProperty', $InstanceID, $Name];
    return true;
}
function IPS_ApplyChanges(int $InstanceID): bool
{
    $GLOBALS['webhookCalls'][] = ['IPS_ApplyChanges', $InstanceID];
    return true;
}

// Open-Meteo als Attrappe: zaehlt jeden Abruf und liefert $weatherBody ('' = Abruf gescheitert)
function Sys_GetURLContentEx(string $URL, array $Options): string|false
{
    $GLOBALS['fetches'][] = $URL;
    return $GLOBALS['weatherBody'];
}
function Sys_GetURLContent(string $URL): string|false
{
    $GLOBALS['fetches'][] = $URL;
    return $GLOBALS['weatherBody'];
}

// Variable, wie das Modul sie ueber IPS_GetVariable, GetValue und GetValueFormatted sieht.
function variable(int $id, mixed $value, string $formatted): void
{
    $GLOBALS['variables'][$id] = ['value' => $value, 'formatted' => $formatted];
}

// Open-Meteo-Antwort mit den Feldern, die das Modul abfragt; Tage ab $firstDay (Standard: heute)
function weather_json(int $code = 61, int $isDay = 1, float $temperature = 12.3, ?string $firstDay = null): string
{
    $start = new DateTimeImmutable($firstDay ?? 'today');
    $days = array_map(static fn (int $i): string => $start->modify('+' . $i . ' days')->format('Y-m-d'), range(0, 4));
    return json_encode([
        'latitude' => 51.5, 'longitude' => 7.5, 'timezone' => 'Europe/Berlin',
        'current' => ['time' => $days[0] . 'T12:00', 'interval' => 900, 'temperature_2m' => $temperature, 'is_day' => $isDay, 'weather_code' => $code],
        'hourly' => ['time' => [$days[0] . 'T00:00', $days[0] . 'T01:00'], 'is_day' => [0, 0], 'weather_code' => [3, 3], 'temperature_2m' => [9.1, 8.7]],
        'daily' => ['time' => $days, 'weather_code' => [61, 3, 0, 95, 71], 'temperature_2m_max' => [14.6, 16.2, 18.9, 13.4, 2.4],
            'temperature_2m_min' => [7.2, 8.1, 9.5, 6.3, -1.6]],
    ], JSON_THROW_ON_ERROR);
}

function check(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    echo 'PASS: ' . $label . PHP_EOL;
}

function query(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    return $query;
}

// WC_MODULE waehlt eine andere Fassung der Kachel (Gegenprobe gegen einen frueheren Stand).
require getenv('WC_MODULE') ?: __DIR__ . '/../TileVisu-Weather-Clock-Tile/module.php';
