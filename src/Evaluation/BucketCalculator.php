<?php

declare(strict_types=1);

namespace FeatureFlags\Evaluation;

class BucketCalculator
{
    public static function calculate(string $seed): int
    {
        return abs(crc32($seed)) % 100;
    }
}
