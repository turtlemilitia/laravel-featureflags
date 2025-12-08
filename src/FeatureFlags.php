<?php

declare(strict_types=1);

namespace FeatureFlags;

use FeatureFlags\Context\RequestContext;
use FeatureFlags\Contracts\FeatureFlagsInterface;
use FeatureFlags\Contracts\HasFeatureFlagContext;
use FeatureFlags\Telemetry\ConversionCollector;
use FeatureFlags\Telemetry\ErrorCollector;
use FeatureFlags\Telemetry\FlagStateTracker;
use Throwable;

readonly class FeatureFlags implements FeatureFlagsInterface
{
    public function __construct(
        private FlagService $flagService,
        private ConversionCollector $conversions,
        private ErrorCollector $errors,
    ) {}

    public function isLocalMode(): bool
    {
        return $this->flagService->isLocalMode();
    }

    /** @param Context|HasFeatureFlagContext|array<string, mixed>|null $context */
    public function active(string $key, Context|HasFeatureFlagContext|array|null $context = null): bool
    {
        return $this->flagService->active($key, $context);
    }

    /**
     * @param Context|HasFeatureFlagContext|array<string, mixed>|null $context
     * @return bool|int|float|string|array<string, mixed>|null
     */
    public function value(string $key, Context|HasFeatureFlagContext|array|null $context = null): bool|int|float|string|array|null
    {
        return $this->flagService->value($key, $context);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->flagService->all();
    }

    public function sync(): void
    {
        $this->flagService->sync();
    }

    public function flush(): void
    {
        $this->flagService->flush();
    }

    /**
     * @param Context|HasFeatureFlagContext|array<string, mixed>|null $context
     * @param array<string, mixed> $properties
     */
    public function trackConversion(
        string $eventName,
        Context|HasFeatureFlagContext|array|null $context = null,
        array $properties = [],
    ): void {
        $normalizedContext = $this->flagService->normalizeContext($context);
        $this->conversions->track($eventName, $normalizedContext, $properties);
    }

    public function flushConversions(): void
    {
        $this->conversions->flush();
    }

    /**
     * @template T
     * @param callable(bool|int|float|string|array<string, mixed>|null): T $callback
     * @param Context|HasFeatureFlagContext|array<string, mixed>|null $context
     * @return T
     * @throws Throwable
     */
    public function monitor(string $flagKey, callable $callback, Context|HasFeatureFlagContext|array|null $context = null): mixed
    {
        $flagValue = $this->value($flagKey, $context);

        try {
            return $callback($flagValue);
        } catch (Throwable $e) {
            $this->errors->trackAutomatic($flagKey, $flagValue, $e, [
                'monitored' => true,
            ]);
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function trackError(string $flagKey, Throwable $exception, array $metadata = []): void
    {
        $this->errors->track($flagKey, $exception, $metadata);
    }

    public function flushErrors(): void
    {
        $this->errors->flush();
    }

    public function flushAllTelemetryAndReset(): void
    {
        $this->flagService->flushTelemetryAndReset();
        $this->conversions->flush();
        $this->errors->flush();
        RequestContext::reset();
    }

    /** @return array<string, bool|int|float|string|array<string, mixed>|null> */
    public function getEvaluatedFlags(): array
    {
        return $this->flagService->getEvaluatedFlags();
    }

    /** @return array{flags: array<string, bool|int|float|string|array<string, mixed>|null>, count: int, request_id: string|null} */
    public function getErrorContext(): array
    {
        return $this->flagService->getErrorContext();
    }

    public function getStateTracker(): FlagStateTracker
    {
        return $this->flagService->getStateTracker();
    }

    public function resetStateTracker(): void
    {
        $this->flagService->resetStateTracker();
    }
}
