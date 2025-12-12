<?php

declare(strict_types=1);

namespace FeatureFlags\Config;

class ConfigHelper
{
    public static function bool(string $key, bool $default = false): bool
    {
        $value = config($key, $default);

        return is_bool($value) ? $value : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = config($key, $default);

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $value = config($key, $default);

        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        return $default;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }

    public static function stringOrNull(string $key): ?string
    {
        $value = config($key);

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<mixed> $default
     * @return array<mixed>
     */
    public static function array(string $key, array $default = []): array
    {
        $value = config($key, $default);

        return is_array($value) ? $value : $default;
    }
}
