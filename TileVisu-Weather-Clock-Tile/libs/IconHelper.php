<?php

declare(strict_types=1);

namespace TileVisu\Lib;

/**
 * Helper to resolve icon information (icon class or image url) for IP-Symcon variables.
 * Mirrors the behaviour used across TileVisu modules so tiles render consistent icons.
 */
class IconHelper
{
    private ?array $iconMapping = null;
    /**
     * Returns an array with `icon` (FontAwesome/IPS icon name) and optional `iconUrl` (absolute URL).
     */
    public function getIconForVariable(int $variableId): array
    {
        $result = [
            'icon' => '',
            'iconUrl' => ''
        ];

        if ($variableId <= 0 || !function_exists('IPS_VariableExists') || !IPS_VariableExists($variableId)) {
            return $result;
        }

        $variable = @IPS_GetVariable($variableId);
        if (!is_array($variable)) {
            return $result;
        }

        // 1) Custom presentation defined directly on the variable (high priority)
        $this->extractFromPresentation($result, $variable['VariableCustomPresentation'] ?? null);

        // 2) Presentation assigned via IPS 6.3+ API helper
        if ($result['icon'] === '' && $result['iconUrl'] === '' && function_exists('IPS_GetVariablePresentation')) {
            $presentation = @IPS_GetVariablePresentation($variableId);
            $this->extractFromPresentation($result, $presentation);
        }

        // 3) Normal variable presentation array (if available)
        if ($result['icon'] === '' && $result['iconUrl'] === '' && isset($variable['VariablePresentation'])) {
            $this->extractFromPresentation($result, $variable['VariablePresentation']);
        }

        // 4) Profile icon / association icon fallback
        if ($result['icon'] === '' && $result['iconUrl'] === '') {
            $profileName = $variable['VariableCustomProfile'] ?? '';
            if ($profileName === '' && isset($variable['VariableProfile'])) {
                $profileName = $variable['VariableProfile'];
            }

            if (is_string($profileName) && $profileName !== '' && function_exists('IPS_VariableProfileExists') && IPS_VariableProfileExists($profileName)) {
                $profile = @IPS_GetVariableProfile($profileName);
                if (is_array($profile)) {
                    if (!empty($profile['Icon']) && is_string($profile['Icon'])) {
                        $result['icon'] = $this->normalizeIconName($profile['Icon']);
                    }

                    if ($result['icon'] === '' && isset($profile['Associations']) && is_array($profile['Associations'])) {
                        $currentValue = @GetValue($variableId);
                        foreach ($profile['Associations'] as $association) {
                            if (!is_array($association)) {
                                continue;
                            }
                            if (array_key_exists('Value', $association) && $association['Value'] == $currentValue) {
                                if (!empty($association['Icon']) && is_string($association['Icon'])) {
                                    $result['icon'] = $this->normalizeIconName($association['Icon']);
                                }
                                if (!empty($association['Image']) && is_string($association['Image'])) {
                                    $result['iconUrl'] = $association['Image'];
                                }
                                break;
                            }
                        }
                    }
                }
            }
        }

        // Final mapping: if we have an icon but it's not a FA class, try to map
        if ($result['icon'] !== '') {
            $result['icon'] = $this->mapToFontAwesome($result['icon']);
        }

        return $result;
    }

    private function extractFromPresentation(array &$result, $presentation): void
    {
        if (!is_array($presentation)) {
            return;
        }

        if ($result['iconUrl'] === '' && !empty($presentation['Image']) && is_string($presentation['Image'])) {
            $result['iconUrl'] = $presentation['Image'];
        }

        if ($result['icon'] === '') {
            if (!empty($presentation['ICON']) && is_string($presentation['ICON'])) {
                $result['icon'] = $this->normalizeIconName($presentation['ICON']);
            } elseif (!empty($presentation['Icon']) && is_string($presentation['Icon'])) {
                $result['icon'] = $this->normalizeIconName($presentation['Icon']);
            }
        }
    }

    private function normalizeIconName(string $icon): string
    {
        $icon = trim($icon);
        if ($icon === '' || strcasecmp($icon, 'Transparent') === 0) {
            return '';
        }

        // Support multi-token inputs like "fa-solid fa-temperature-full" or "ipsIcon-temperature-full"
        // - Leave FontAwesome tokens (fa-*) intact (style+slug)
        // - Remove ipsIcon- prefix if present
        $tokens = preg_split('/\s+/', $icon) ?: [];
        $out = [];
        foreach ($tokens as $tok) {
            $t = trim($tok);
            if ($t === '') continue;
            if (stripos($t, 'ipsIcon-') === 0) {
                $t = substr($t, 8); // drop ipsIcon-
            }
            // keep fa-* tokens unchanged and keep other bare slugs
            $out[] = $t;
        }

        return trim(implode(' ', $out));
    }

    private function loadIconMapping(): void
    {
        if ($this->iconMapping !== null) return;
        $this->iconMapping = [];
        $file = __DIR__ . '/iconMapping.json';
        if (@is_file($file)) {
            $json = @file_get_contents($file);
            if (is_string($json) && $json !== '') {
                $data = @json_decode($json, true);
                if (is_array($data)) {
                    $this->iconMapping = $data;
                }
            }
        }
        // Built-in minimal defaults
        if (empty($this->iconMapping)) {
            $this->iconMapping = [
                'temperature' => 'fa-light fa-temperature-half',
                'temperature-full' => 'fa-light fa-temperature-full',
                'thermometer' => 'fa-light fa-temperature-half',
                'heat' => 'fa-light fa-temperature-high',
                'cold' => 'fa-light fa-temperature-low',
                'humidity' => 'fa-light fa-droplet',
                'rain' => 'fa-light fa-cloud-rain',
                'snow' => 'fa-light fa-snowflake',
                'wind' => 'fa-light fa-wind',
                'sun' => 'fa-light fa-sun',
                'moon' => 'fa-light fa-moon',
                'power' => 'fa-light fa-power-off',
                'light' => 'fa-light fa-lightbulb'
            ];
        }
    }

    private function mapToFontAwesome(string $iconName): string
    {
        $iconName = trim($iconName);
        if ($iconName === '' || str_contains($iconName, 'fa-')) {
            // Already a FA class or empty -> return as-is
            return $iconName;
        }
        $this->loadIconMapping();
        $key = strtolower(str_replace(['_', '  '], ['-', ' '], $iconName));
        $key = preg_replace('/\s+/', '-', $key ?? '') ?? '';
        if (isset($this->iconMapping[$key]) && is_string($this->iconMapping[$key])) {
            return $this->iconMapping[$key];
        }
        // Fallback: best-effort FA class (light weight)
        return 'fa-light fa-' . $key;
    }
}
