<?php

if (!defined('ABSPATH')) {
    exit;
}

class MCWS_Fallback_Rates
{
    /**
     * Resolve cost for destination address, optionally considering cart subtotal.
     *
     * @param array<string, mixed> $destination
     * @param float $default
     * @param float $subtotal
     * @return float
     */
    public static function resolve_cost(array $destination, float $default = 0.0, float $subtotal = 0.0): float
    {
        $rate = self::resolve_rate($destination, $default, $subtotal);

        return $rate['cost'];
    }

    /**
     * Determine if free shipping was triggered by order subtotal threshold.
     *
     * @param array<string, mixed> $destination
     * @param float $subtotal
     * @return bool
     */
    public static function is_free_shipping_applied(array $destination, float $subtotal = 0.0): bool
    {
        $rate = self::resolve_rate($destination, 0.0, $subtotal);

        return $rate['is_free'];
    }

    /**
     * Resolve detailed rate info for a destination address.
     *
     * @param array<string, mixed> $destination
     * @param float $default
     * @param float $subtotal
     * @return array{cost: float, is_free: bool, matched: bool, min_amount: float}
     */
    public static function resolve_rate(array $destination, float $default = 0.0, float $subtotal = 0.0): array
    {
        $region = isset($destination['state']) ? self::normalize((string) $destination['state']) : '';
        $city = isset($destination['city']) ? self::normalize((string) $destination['city']) : '';

        $rows = class_exists('MCWS_Admin') ? MCWS_Admin::get_fixed_rates_table() : array();
        if (!is_array($rows)) {
            return array(
                'cost' => max(0.0, $default),
                'is_free' => false,
                'matched' => false,
                'min_amount' => 0.0,
            );
        }

        $regionMap = array();
        $communeByRegion = array();
        $communeGlobal = array();
        $excludeRules = array();

        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['scope'], $row['cost'])) {
                continue;
            }

            if (!is_numeric((string) $row['cost'])) {
                continue;
            }

            $cost = (float) $row['cost'];
            $min_amount = isset($row['min_amount']) && is_numeric((string) $row['min_amount']) && (float) $row['min_amount'] > 0
                ? (float) $row['min_amount']
                : 0.0;

            $rule_data = array(
                'cost' => $cost,
                'min_amount' => $min_amount,
            );

            $scope = (string) $row['scope'];
            if ($scope === 'region') {
                $regionCode = isset($row['region']) ? self::normalize((string) $row['region']) : '';
                if ($regionCode !== '') {
                    $regionMap[$regionCode] = $rule_data;
                }
                continue;
            }

            if ($scope !== 'commune') {
                continue;
            }

            $regionCode = isset($row['region']) ? self::normalize((string) $row['region']) : '';
            $mode = isset($row['commune_mode']) ? (string) $row['commune_mode'] : 'only';
            if (!in_array($mode, array('all', 'only', 'exclude'), true)) {
                $mode = 'only';
            }
            $communes = self::normalize_communes($row);

            if ($mode === 'all') {
                if ($regionCode !== '') {
                    $regionMap[$regionCode] = $rule_data;
                }
                continue;
            }

            if ($mode === 'exclude') {
                if ($regionCode === '' || empty($communes)) {
                    continue;
                }
                $excludeRules[] = array(
                    'region' => $regionCode,
                    'excluded' => array_fill_keys($communes, true),
                    'cost' => $cost,
                    'min_amount' => $min_amount,
                );
                continue;
            }

            if (empty($communes)) {
                continue;
            }
            foreach ($communes as $commune) {
                if ($regionCode !== '') {
                    if (!isset($communeByRegion[$regionCode])) {
                        $communeByRegion[$regionCode] = array();
                    }
                    $communeByRegion[$regionCode][$commune] = $rule_data;
                } else {
                    $communeGlobal[$commune] = $rule_data;
                }
            }
        }

        $matched = null;

        if ($city !== '' && $region !== '' && isset($communeByRegion[$region], $communeByRegion[$region][$city])) {
            $matched = $communeByRegion[$region][$city];
        } elseif ($city !== '' && isset($communeGlobal[$city])) {
            $matched = $communeGlobal[$city];
        } elseif ($region !== '' && $city !== '' && !empty($excludeRules)) {
            for ($i = count($excludeRules) - 1; $i >= 0; $i--) {
                $rule = $excludeRules[$i];
                if (!is_array($rule) || (string) ($rule['region'] ?? '') !== $region) {
                    continue;
                }
                $excluded = isset($rule['excluded']) && is_array($rule['excluded']) ? $rule['excluded'] : array();
                if (!isset($excluded[$city])) {
                    $matched = array(
                        'cost' => (float) ($rule['cost'] ?? 0.0),
                        'min_amount' => (float) ($rule['min_amount'] ?? 0.0),
                    );
                    break;
                }
            }
        } elseif ($region !== '' && isset($regionMap[$region])) {
            $matched = $regionMap[$region];
        }

        if ($matched !== null) {
            $base_cost = max(0.0, (float) $matched['cost']);
            $min_amount = max(0.0, (float) ($matched['min_amount'] ?? 0.0));
            $is_free = ($min_amount > 0.0 && $subtotal >= $min_amount);

            return array(
                'cost' => $is_free ? 0.0 : $base_cost,
                'is_free' => $is_free,
                'matched' => true,
                'min_amount' => $min_amount,
            );
        }

        return array(
            'cost' => max(0.0, $default),
            'is_free' => false,
            'matched' => false,
            'min_amount' => 0.0,
        );
    }

    private static function normalize(string $value): string
    {
        return strtoupper(remove_accents(trim($value)));
    }

    private static function normalize_communes(array $row): array
    {
        $communes = array();
        if (isset($row['communes']) && is_array($row['communes'])) {
            foreach ($row['communes'] as $commune) {
                $normalized = self::normalize((string) $commune);
                if ($normalized !== '') {
                    $communes[] = $normalized;
                }
            }
        } else if (isset($row['commune'])) {
            $normalized = self::normalize((string) $row['commune']);
            if ($normalized !== '') {
                $communes[] = $normalized;
            }
        }

        return array_values(array_unique($communes));
    }
}
