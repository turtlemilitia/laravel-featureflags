<?php

declare(strict_types=1);

namespace FeatureFlags\Events;

use Illuminate\Foundation\Events\Dispatchable;

class TelemetryFlushed
{
    use Dispatchable;

    public function __construct(
        public readonly string $type,
        public readonly int $eventCount,
        public readonly bool $success,
        public readonly float $durationMs,
        public readonly ?string $error = null,
    ) {}
}
