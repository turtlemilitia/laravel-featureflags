<?php

declare(strict_types=1);

namespace FeatureFlags\Evaluation;

class ValueNormalizer
{
    /** @return bool|int|float|string|array<string, mixed>|null */
    public static function normalize(mixed $value): bool|int|float|string|array|null
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return $value;
        }

        return null;
    }
}
