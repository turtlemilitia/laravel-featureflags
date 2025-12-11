<?php

declare(strict_types=1);

namespace FeatureFlags\Contracts;

use FeatureFlags\Context;
use FeatureFlags\Telemetry\FlagStateTracker;
use Throwable;

interface FeatureFlagsInterface
{
    public function isLocalMode(): bool;

    /** @param Context|HasFeatureFlagContext|array<string, mixed>|null $context */
    public function active(string $key, Context|HasFeatureFlagContext|array|null $context = null): bool;

    /**
     * @param Context|HasFeatureFlagContext|array<string, mixed>|null $context
     * @return bool|int|float|string|array<string, mixed>|null
     */
    public function value(string $key, Context|HasFeatureFlagContext|array|null $context = null): bool|int|float|string|array|null;

    /** @return array<int, array<string, mixed>> */
    public function all(): array;

    public function sync(): void;

    public function flush(): void;

    /**
     * @param string $eventName The conversion event name (e.g., 'purchase', 'signup')
     * @param Context|HasFeatureFlagContext|array<string, mixed>|null $context The user context
     * @param array<string, mixed> $properties Additional event properties
     * @param string|null $flagKey Optional: explicitly attribute to a specific flag
     * @param bool|int|float|string|array<string, mixed>|null $flagValue Optional: the variant value
     */
    public function trackConversion(
        string $eventName,
        Context|HasFeatureFlagContext|array|null $context = null,
        array $properties = [],
        ?string $flagKey = null,
        bool|int|float|string|array|null $flagValue = null,
    ): void;

    public function flushConversions(): void;

    /**
     * @template T
     * @param callable(bool|int|float|string|array<string, mixed>|null): T $callback
     * @param Context|array<string, mixed>|null $context
     * @return T
     * @throws Throwable
     */
    public function monitor(string $flagKey, callable $callback, Context|array|null $context = null): mixed;

    /** @param array<string, mixed> $metadata */
    public function trackError(string $flagKey, Throwable $exception, array $metadata = []): void;

    public function flushErrors(): void;

    public function flushAllTelemetryAndReset(): void;

    /** @return array<string, bool|int|float|string|array<string, mixed>|null> */
    public function getEvaluatedFlags(): array;

    /** @return array{flags: array<string, bool|int|float|string|array<string, mixed>|null>, count: int, request_id: string|null} */
    public function getErrorContext(): array;

    public function getStateTracker(): FlagStateTracker;

    public function resetStateTracker(): void;

    public function grantConsent(): void;

    public function discardHeldTelemetry(): void;

    public function isHoldingTelemetry(): bool;

    public function revokeConsent(): void;
}
