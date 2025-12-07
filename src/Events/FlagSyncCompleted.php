<?php

declare(strict_types=1);

namespace FeatureFlags\Events;

use Illuminate\Foundation\Events\Dispatchable;

class FlagSyncCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly int $flagCount,
        public readonly int $segmentCount,
        public readonly float $durationMs,
        public readonly string $source = 'api',
    ) {}
}
