<?php

declare(strict_types=1);

namespace FeatureFlags\Evaluation;

class BucketCalculator
{
    private const MAX_BUCKET = 10000;

    public static function calculate(string $seed): int
    {
        return hexdec(hash('murmur3a', $seed)) % self::MAX_BUCKET;
    }
}
