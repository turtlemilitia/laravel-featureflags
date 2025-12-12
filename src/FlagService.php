<?php

declare(strict_types=1);

namespace FeatureFlags;

use FeatureFlags\Cache\FlagCache;
use FeatureFlags\Client\ApiClient;
use FeatureFlags\Config\ConfigHelper;
use FeatureFlags\Contracts\HasFeatureFlagContext;
use FeatureFlags\Evaluation\ContextNormalizer;
use FeatureFlags\Evaluation\FlagEvaluator;
use FeatureFlags\Evaluation\MatchReason;
use FeatureFlags\Evaluation\ValueNormalizer;
use FeatureFlags\Events\FlagEvaluated;
use FeatureFlags\Events\FlagSyncCompleted;
use FeatureFlags\Exceptions\ApiException;
use FeatureFlags\Exceptions\CircularDependencyException;
use FeatureFlags\Exceptions\FlagSyncException;
use FeatureFlags\Telemetry\FlagStateTracker;
use FeatureFlags\Telemetry\TelemetryCollector;
use Psr\SimpleCache\InvalidArgumentException;

readonly class FlagService
{
    private bool $localModeEnabled;
    /** @var array<string, mixed> */
    private array $localFlags;

    public function __construct(
        private FlagCache $cache,
        private ApiClient $apiClient,
        private FlagEvaluator $flagEvaluator,
        private ContextNormalizer $contextNormalizer,
        private TelemetryCollector $telemetry,
        private FlagStateTracker $stateTracker,
        private bool $cacheEnabled = true,
    ) {
        $this->localModeEnabled = ConfigHelper::bool('featureflags.local.enabled', false);

        /** @var array<string, mixed> $flags */
        $flags = ConfigHelper::array('featureflags.local.flags', []);
        $this->localFlags = $flags;
    }

    public function isLocalMode(): bool
    {
        return $this->localModeEnabled;
    }

    /** @param Context|HasFeatureFlagContext|array<string, mixed>|null $context */
    public function active(string $key, Context|HasFeatureFlagContext|array|null $context = null): bool
    {
        return (bool) $this->value($key, $context);
    }

    /**
     * @param Context|HasFeatureFlagContext|array<string, mixed>|null $context
     * @return bool|int|float|string|array<string, mixed>|null
     */
    public function value(string $key, Context|HasFeatureFlagContext|array|null $context = null): bool|int|float|string|array|null
    {
        $startTime = hrtime(true);

        $flag = $this->getFlag($key);

        if ($flag === null) {
            $durationMs = (hrtime(true) - $startTime) / 1_000_000;
            $this->telemetry->record($key, false, null, null, MatchReason::NotFound->value, (int) $durationMs);
            $this->stateTracker->record($key, false, null);
            $this->dispatchFlagEvaluatedEvent($key, false, MatchReason::NotFound->value, null, $durationMs, null);
            return false;
        }

        $context = $this->contextNormalizer->normalize($context);
        $result = $this->evaluateWithMetadata($flag, $context);

        $durationMs = (hrtime(true) - $startTime) / 1_000_000;

        $this->telemetry->record(
            $key,
            $result['value'],
            $context,
            $result['matched_rule_index'],
            $result['match_reason'],
            (int) $durationMs,
        );

        $this->stateTracker->record($key, $result['value'], $context);

        $this->dispatchFlagEvaluatedEvent(
            $key,
            $result['value'],
            $result['match_reason'],
            $result['matched_rule_index'],
            $durationMs,
            $context?->id,
        );

        return $result['value'];
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws FlagSyncException
     * @throws InvalidArgumentException
     */
    public function all(): array
    {
        if ($this->localModeEnabled) {
            return array_values(array_filter(
                array_map(
                    fn(string $key): ?array => $this->getLocalFlag($key),
                    array_keys($this->localFlags),
                ),
                fn(?array $flag): bool => $flag !== null,
            ));
        }

        $this->ensureFlagsLoaded();

        return $this->cache->all() ?? [];
    }

    /**
     * @throws FlagSyncException When fallback behavior is 'exception' and sync fails
     */
    public function syncIfNeeded(): void
    {
        if ($this->localModeEnabled) {
            return;
        }

        if ($this->cacheEnabled && $this->cache->has()) {
            return;
        }

        $this->sync();
    }

    /**
     * @throws FlagSyncException When fallback behavior is 'exception' and sync fails
     */
    public function sync(): void
    {
        if ($this->localModeEnabled) {
            return;
        }

        $startTime = hrtime(true);

        try {
            $response = $this->apiClient->fetchFlags();
            $this->cache->put($response['flags']);
            $this->cache->putSegments($response['segments']);

            $durationMs = (hrtime(true) - $startTime) / 1_000_000;
            $this->dispatchFlagSyncCompletedEvent(
                count($response['flags']),
                count($response['segments']),
                $durationMs,
                'api',
            );
        } catch (ApiException $e) {
            $this->handleSyncFailure($e);
        }
    }

    public function flush(): void
    {
        $this->cache->flush();
    }

    public function getCache(): FlagCache
    {
        return $this->cache;
    }

    /**
     * @param Context|HasFeatureFlagContext|array<string, mixed>|null $context
     */
    public function normalizeContext(Context|HasFeatureFlagContext|array|null $context): ?Context
    {
        return $this->contextNormalizer->normalize($context);
    }

    /**
     * @return array<string, bool|int|float|string|array<string, mixed>|null>
     */
    public function getEvaluatedFlags(): array
    {
        return $this->stateTracker->getEvaluatedFlags();
    }

    /**
     * @return array{flags: array<string, bool|int|float|string|array<string, mixed>|null>, count: int, request_id: string|null}
     */
    public function getErrorContext(): array
    {
        return $this->stateTracker->toErrorContext();
    }

    public function getStateTracker(): FlagStateTracker
    {
        return $this->stateTracker;
    }

    public function resetStateTracker(): void
    {
        $this->stateTracker->reset();
    }

    public function flushTelemetryAndReset(): void
    {
        $this->telemetry->flush();
        $this->stateTracker->reset();
    }

    public function flushTelemetry(): void
    {
        $this->telemetry->flush();
    }

    public function discardHeldTelemetry(): void
    {
        $this->telemetry->discardHeld();
    }

    public function isHoldingTelemetry(): bool
    {
        return $this->telemetry->isHolding();
    }

    private function handleSyncFailure(ApiException $e): void
    {
        $behavior = ConfigHelper::string('featureflags.fallback.behavior', 'cache');

        match ($behavior) {
            'exception' => throw new FlagSyncException(
                'Failed to sync feature flags: ' . $e->getMessage(),
                0,
                $e,
            ),
            default => null,
        };
    }

    /** @return array<string, mixed>|null */
    private function getFlag(string $key): ?array
    {
        if ($this->localModeEnabled) {
            return $this->getLocalFlag($key);
        }

        $this->ensureFlagsLoaded();

        $flag = $this->cache->get($key);

        if ($flag === null && ConfigHelper::string('featureflags.fallback.behavior', 'cache') === 'default') {
            return [
                'key' => $key,
                'enabled' => false,
                'default_value' => config('featureflags.fallback.default_value', false),
            ];
        }

        return $flag;
    }

    /** @return array<string, mixed>|null */
    private function getLocalFlag(string $key): ?array
    {
        if (!array_key_exists($key, $this->localFlags)) {
            return null;
        }

        $value = $this->localFlags[$key];

        if (!is_array($value)) {
            return [
                'key' => $key,
                'enabled' => true,
                'default_value' => $value,
            ];
        }

        return [
            'key' => $key,
            'enabled' => true,
            'default_value' => $value['value'] ?? true,
            'rollout_percentage' => $value['rollout'] ?? null,
        ];
    }

    /**
     * @throws FlagSyncException
     */
    private function ensureFlagsLoaded(): void
    {
        if ($this->cacheEnabled && $this->cache->has()) {
            return;
        }

        $this->sync();
    }

    /**
     * @param array<string, mixed> $flag
     * @param array<string> $evaluating
     * @return array{value: bool|int|float|string|array<string, mixed>|null, matched_rule_index: int|null, match_reason: string}
     */
    private function evaluateWithMetadata(array $flag, ?Context $context, array $evaluating = []): array
    {
        try {
            return $this->flagEvaluator->evaluate(
                $flag,
                $context,
                $evaluating,
                fn(string $key, ?Context $ctx, array $eval) => $this->getDependencyFlagValue($key, $ctx, $eval),
            );
        } catch (CircularDependencyException $e) {
            if (empty($evaluating)) {
                $defaultValue = $flag['default_value'] ?? false;
                return [
                    'value' => ValueNormalizer::normalize($defaultValue),
                    'matched_rule_index' => null,
                    'match_reason' => MatchReason::Dependency->value,
                ];
            }
            throw $e;
        }
    }

    /**
     * @param array<string> $evaluating
     * @return bool|int|float|string|array<string, mixed>|null
     */
    private function getDependencyFlagValue(string $key, ?Context $context, array $evaluating): bool|int|float|string|array|null
    {
        $flag = $this->getFlag($key);
        if ($flag === null) {
            return null;
        }

        return $this->evaluateWithMetadata($flag, $context, $evaluating)['value'];
    }

    private function shouldDispatchEvent(string $eventKey): bool
    {
        if (!ConfigHelper::bool('featureflags.events.enabled', false)) {
            return false;
        }

        return ConfigHelper::bool("featureflags.events.dispatch.{$eventKey}", true);
    }

    /**
     * @param bool|int|float|string|array<string, mixed>|null $value
     */
    private function dispatchFlagEvaluatedEvent(
        string $flagKey,
        bool|int|float|string|array|null $value,
        string $matchReason,
        ?int $matchedRuleIndex,
        float $durationMs,
        string|int|null $contextId,
    ): void {
        if (!$this->shouldDispatchEvent('flag_evaluated')) {
            return;
        }

        FlagEvaluated::dispatch(
            $flagKey,
            $value,
            $matchReason,
            $matchedRuleIndex,
            $durationMs,
            $contextId,
        );
    }

    private function dispatchFlagSyncCompletedEvent(
        int $flagCount,
        int $segmentCount,
        float $durationMs,
        string $source,
    ): void {
        if (!$this->shouldDispatchEvent('flag_sync_completed')) {
            return;
        }

        FlagSyncCompleted::dispatch(
            $flagCount,
            $segmentCount,
            $durationMs,
            $source,
        );
    }
}
