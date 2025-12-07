<?php

declare(strict_types=1);

namespace FeatureFlags\Telemetry;

use FeatureFlags\Context;
use FeatureFlags\Context\RequestContext;

class FlagStateTracker
{
    /** @var array<string, array{value: bool|int|float|string|array<string, mixed>|null, timestamp: string, context_id: string|int|null}> */
    private array $evaluatedFlags = [];

    /** @param bool|int|float|string|array<string, mixed>|null $value */
    public function record(string $flagKey, bool|int|float|string|array|null $value, ?Context $context = null): void
    {
        $this->evaluatedFlags[$flagKey] = [
            'value' => $value,
            'timestamp' => now()->toIso8601String(),
            'context_id' => $context?->id,
        ];
    }

    /** @return array<string, bool|int|float|string|array<string, mixed>|null> */
    public function getEvaluatedFlags(): array
    {
        $flags = [];
        foreach ($this->evaluatedFlags as $key => $data) {
            $flags[$key] = $data['value'];
        }
        return $flags;
    }

    /** @return array<string, array{value: bool|int|float|string|array<string, mixed>|null, timestamp: string, context_id: string|int|null}> */
    public function getEvaluatedFlagsWithMetadata(): array
    {
        return $this->evaluatedFlags;
    }

    public function wasEvaluated(string $flagKey): bool
    {
        return isset($this->evaluatedFlags[$flagKey]);
    }

    /** @return bool|int|float|string|array<string, mixed>|null */
    public function getValue(string $flagKey): bool|int|float|string|array|null
    {
        return $this->evaluatedFlags[$flagKey]['value'] ?? null;
    }

    public function count(): int
    {
        return count($this->evaluatedFlags);
    }

    /** MUST be called after each request for Octane compatibility. */
    public function reset(): void
    {
        $this->evaluatedFlags = [];
    }

    /** @return array{flags: array<string, bool|int|float|string|array<string, mixed>|null>, count: int, request_id: string|null} */
    public function toErrorContext(): array
    {
        return [
            'flags' => $this->getEvaluatedFlags(),
            'count' => $this->count(),
            'request_id' => RequestContext::getRequestId(),
        ];
    }
}
