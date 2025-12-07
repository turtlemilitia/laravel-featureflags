<?php

declare(strict_types=1);

namespace FeatureFlags\Events;

use Illuminate\Foundation\Events\Dispatchable;

class FlagEvaluated
{
    use Dispatchable;

    /**
     * @param array<array-key, mixed>|bool|int|float|string|null $value
     */
    public function __construct(
        public readonly string $flagKey,
        public readonly bool|int|float|string|array|null $value,
        public readonly string $matchReason,
        public readonly ?int $matchedRuleIndex,
        public readonly float $durationMs,
        public readonly string|int|null $contextId = null,
    ) {}
}
