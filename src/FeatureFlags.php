<?php

declare(strict_types=1);

namespace FeatureFlags;

use FeatureFlags\Contracts\HasFeatureFlagContext;
use FeatureFlags\Events\FlagEvaluated;
use FeatureFlags\Events\FlagSyncCompleted;
use FeatureFlags\Exceptions\ApiException;
use FeatureFlags\Exceptions\FlagSyncException;
use FeatureFlags\Context\RequestContext;
use FeatureFlags\Telemetry\FlagStateTracker;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @phpstan-type FlagValue bool|int|float|string|array<string, mixed>|null
 */

class FeatureFlags
{
    private readonly bool $localModeEnabled;
    /** @var array<string, mixed> */
    private readonly array $localFlags;
    /** @var array<string, true> */
    private static array $loggedBadPatterns = [];

    public function __construct(
        private readonly FeatureFlagsConfig $config,
    ) {
        $enabled = config('featureflags.local.enabled', false);
        $this->localModeEnabled = is_bool($enabled) ? $enabled : false;

        $flags = config('featureflags.local.flags', []);
        /** @var array<string, mixed> $typedFlags */
        $typedFlags = is_array($flags) ? $flags : [];
        $this->localFlags = $typedFlags;
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
            $this->config->telemetry->record($key, false, null, null, 'not_found', (int) $durationMs);
            $this->config->stateTracker->record($key, false, null);
            $this->dispatchFlagEvaluatedEvent($key, false, 'not_found', null, $durationMs, null);
            return false;
        }

        $context = $this->normalizeContext($context);
        $result = $this->evaluateWithMetadata($flag, $context);

        $durationMs = (hrtime(true) - $startTime) / 1_000_000;

        $this->config->telemetry->record(
            $key,
            $result['value'],
            $context,
            $result['matched_rule_index'],
            $result['match_reason'],
            (int) $durationMs,
        );

        $this->config->stateTracker->record($key, $result['value'], $context);

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

    /** @return array<int, array<string, mixed>> */
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

        return $this->config->cache->all() ?? [];
    }

    /**
     * Sync flags from the API.
     *
     * @throws FlagSyncException When fallback behavior is 'exception' and sync fails
     */
    public function sync(): void
    {
        if ($this->localModeEnabled) {
            return;
        }

        $startTime = hrtime(true);

        try {
            $response = $this->config->apiClient->fetchFlags();
            $this->config->cache->put($response['flags'], $response['cache_ttl']);
            $this->config->cache->putSegments($response['segments'], $response['cache_ttl']);

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

    private function handleSyncFailure(ApiException $e): void
    {
        $behavior = config('featureflags.fallback.behavior', 'cache');

        match ($behavior) {
            'exception' => throw new FlagSyncException(
                'Failed to sync feature flags: ' . $e->getMessage(),
                0,
                $e,
            ),
            default => null, // Continue with cached/default values
        };
    }

    public function flush(): void
    {
        $this->config->cache->flush();
    }

    /**
     * Track a conversion event.
     *
     * The dashboard correlates conversions with flag evaluations
     * based on context ID and session.
     *
     * @param Context|HasFeatureFlagContext|array<string, mixed>|null $context
     * @param array<string, mixed> $properties
     */
    public function trackConversion(
        string $eventName,
        Context|HasFeatureFlagContext|array|null $context = null,
        array $properties = [],
    ): void {
        $context = $this->normalizeContext($context);
        $this->config->conversions->track($eventName, $context, $properties);
    }

    public function flushConversions(): void
    {
        $this->config->conversions->flush();
    }

    /**
     * Execute code with automatic error tracking for a flag.
     *
     * Example:
     *   $result = Feature::monitor('new-checkout', function ($isEnabled) {
     *       if ($isEnabled) {
     *           return $this->newCheckoutProcess();
     *       }
     *       return $this->legacyCheckoutProcess();
     *   });
     *
     * @template T
     * @param callable(bool|int|float|string|array<string, mixed>|null): T $callback
     * @param Context|array<string, mixed>|null $context
     * @return T
     * @throws Throwable
     */
    public function monitor(string $flagKey, callable $callback, Context|array|null $context = null): mixed
    {
        $flagValue = $this->value($flagKey, $context);

        try {
            return $callback($flagValue);
        } catch (Throwable $e) {
            $this->config->errors->trackAutomatic($flagKey, $flagValue, $e, [
                'monitored' => true,
            ]);
            throw $e;
        }
    }

    /**
     * Track an error that occurred while a feature flag was active.
     *
     * Use this to correlate application errors with feature flag states.
     * This helps identify if a new feature is causing increased error rates.
     *
     * Note: Consider using Feature::monitor() for automatic tracking,
     * or register ErrorTrackingServiceProvider for fully
     * automatic correlation without any code changes.
     *
     * Example:
     *   try {
     *       if (Feature::active('new-checkout')) {
     *           // New checkout code
     *       }
     *   } catch (\Exception $e) {
     *       Feature::trackError('new-checkout', $e);
     *       throw $e;
     *   }
     *
     * @param string $flagKey The flag key that may be related to this error
     * @param Throwable $exception The exception that occurred
     * @param array<string, mixed> $metadata Additional context about the error
     */
    public function trackError(string $flagKey, Throwable $exception, array $metadata = []): void
    {
        $this->config->errors->track($flagKey, $exception, $metadata);
    }

    public function flushErrors(): void
    {
        $this->config->errors->flush();
    }

    /**
     * Flush all telemetry collectors and reset request-scoped state.
     *
     * Useful for long-running workers (queues, daemons) to prevent
     * telemetry/state leakage between jobs.
     */
    public function flushAllTelemetryAndReset(): void
    {
        $this->config->telemetry->flush();
        $this->config->conversions->flush();
        $this->config->errors->flush();
        $this->config->stateTracker->reset();
        RequestContext::reset();
    }

    /** @return array<string, bool|int|float|string|array<string, mixed>|null> */
    public function getEvaluatedFlags(): array
    {
        return $this->config->stateTracker->getEvaluatedFlags();
    }

    /** @return array{flags: array<string, bool|int|float|string|array<string, mixed>|null>, count: int, request_id: string|null} */
    public function getErrorContext(): array
    {
        return $this->config->stateTracker->toErrorContext();
    }

    public function getStateTracker(): FlagStateTracker
    {
        return $this->config->stateTracker;
    }

    public function resetStateTracker(): void
    {
        $this->config->stateTracker->reset();
    }

    /** @return array<string, mixed>|null */
    private function getFlag(string $key): ?array
    {
        if ($this->localModeEnabled) {
            return $this->getLocalFlag($key);
        }

        $this->ensureFlagsLoaded();

        $flag = $this->config->cache->get($key);

        if ($flag === null && config('featureflags.fallback.behavior') === 'default') {
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

    private function ensureFlagsLoaded(): void
    {
        if ($this->config->cacheEnabled && $this->config->cache->has()) {
            return;
        }

        $this->sync();
    }

    /** @param Context|HasFeatureFlagContext|array<string, mixed>|null $context */
    private function normalizeContext(Context|HasFeatureFlagContext|array|null $context): ?Context
    {
        if ($context === null) {
            return $this->resolveDefaultContext();
        }

        if ($context instanceof Context) {
            return $this->enrichContextWithVersionTraits($context);
        }

        if ($context instanceof HasFeatureFlagContext) {
            // Version traits are merged in fromInterface()
            return $this->config->contextResolver->fromInterface($context);
        }

        if (isset($context['id'])) {
            /** @var string|int $id */
            $id = $context['id'];
            /** @var array<string, mixed> $traits */
            $traits = $context['traits'] ?? array_diff_key($context, ['id' => true]);
            $traits = $this->config->contextResolver->mergeVersionTraits($traits);
            return new Context($id, $traits);
        }

        return null;
    }

    /**
     * Enrich an existing Context with version traits.
     */
    private function enrichContextWithVersionTraits(Context $context): Context
    {
        $versionTraits = $this->config->contextResolver->resolveVersionTraits();

        if (empty($versionTraits)) {
            return $context;
        }

        // Merge version traits with lower priority - existing traits take precedence
        $mergedTraits = array_merge($versionTraits, $context->traits);

        return new Context($context->id, $mergedTraits);
    }

    private function resolveDefaultContext(): ?Context
    {
        return $this->config->contextResolver->resolve();
    }

    /**
     * @param array<string, mixed> $flag
     * @param array<string> $evaluating
     * @return bool|int|float|string|array<string, mixed>|null
     */
    private function evaluate(array $flag, ?Context $context, array $evaluating = []): bool|int|float|string|array|null
    {
        return $this->evaluateWithMetadata($flag, $context, $evaluating)['value'];
    }

    /**
     * @param array<string, mixed> $flag
     * @param array<string> $evaluating
     * @return array{value: bool|int|float|string|array<string, mixed>|null, matched_rule_index: int|null, match_reason: string}
     */
    private function evaluateWithMetadata(array $flag, ?Context $context, array $evaluating = []): array
    {
        /** @var bool $enabled */
        $enabled = $flag['enabled'] ?? false;
        if (!$enabled) {
            return [
                'value' => $this->normalizeFlagValue($flag['default_value'] ?? false),
                'matched_rule_index' => null,
                'match_reason' => 'disabled',
            ];
        }

        /** @var array<int, array<string, mixed>> $dependencies */
        $dependencies = $flag['dependencies'] ?? [];
        if (!empty($dependencies)) {
            if (!$this->dependenciesSatisfied($flag, $context, $evaluating)) {
                return [
                    'value' => $this->normalizeFlagValue($flag['default_value'] ?? false),
                    'matched_rule_index' => null,
                    'match_reason' => 'dependency',
                ];
            }
        }

        /** @var array<int, array<string, mixed>> $rules */
        $rules = $flag['rules'] ?? [];
        if (!empty($rules) && $context !== null) {
            /** @var string $flagKey */
            $flagKey = $flag['key'] ?? '';
            $ruleResult = $this->evaluateRulesWithIndex($rules, $context, $flagKey);
            if ($ruleResult !== null) {
                return [
                    'value' => $ruleResult['value'],
                    'matched_rule_index' => $ruleResult['index'],
                    'match_reason' => 'rule',
                ];
            }
        }

        /** @var int|null $rolloutPercentage */
        $rolloutPercentage = $flag['rollout_percentage'] ?? null;
        if ($rolloutPercentage !== null) {
            if ($context === null) {
                return [
                    'value' => false,
                    'matched_rule_index' => null,
                    'match_reason' => 'rollout_miss',
                ];
            }

            /** @var string $flagKey */
            $flagKey = $flag['key'] ?? '';
            if ($this->isInRollout($flagKey, $context->id, $rolloutPercentage)) {
                return [
                    'value' => $this->normalizeFlagValue($flag['default_value'] ?? true),
                    'matched_rule_index' => -1,
                    'match_reason' => 'rollout',
                ];
            }
            return [
                'value' => false,
                'matched_rule_index' => null,
                'match_reason' => 'rollout_miss',
            ];
        }

        return [
            'value' => $this->normalizeFlagValue($flag['default_value'] ?? true),
            'matched_rule_index' => null,
            'match_reason' => 'default',
        ];
    }

    /** @return bool|int|float|string|array<string, mixed>|null */
    private function normalizeFlagValue(mixed $value): bool|int|float|string|array|null
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

    /**
     * @param array<string, mixed> $flag
     * @param array<string> $evaluating
     */
    private function dependenciesSatisfied(array $flag, ?Context $context, array $evaluating = []): bool
    {
        /** @var array<int, array<string, mixed>> $dependencies */
        $dependencies = $flag['dependencies'] ?? [];

        if (empty($dependencies)) {
            return true;
        }

        // Track which flag we're evaluating to detect circular dependencies at runtime
        /** @var string $flagKey */
        $flagKey = $flag['key'] ?? '';
        if (in_array($flagKey, $evaluating, true)) {
            // Circular dependency detected at runtime - fail safe by not satisfying
            return false;
        }

        $evaluating[] = $flagKey;

        foreach ($dependencies as $dependency) {
            /** @var string|null $dependencyKey */
            $dependencyKey = $dependency['flag_key'] ?? null;
            /** @var mixed $requiredValue */
            $requiredValue = $dependency['required_value'] ?? null;

            if ($dependencyKey === null) {
                continue;
            }

            $dependencyFlag = $this->getFlag($dependencyKey);
            if ($dependencyFlag === null) {
                return false;
            }

            $actualValue = $this->evaluate($dependencyFlag, $context, $evaluating);
            if ($actualValue !== $requiredValue) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @return array{value: bool|int|float|string|array<string, mixed>|null, index: int}|null
     */
    private function evaluateRulesWithIndex(array $rules, Context $context, string $flagKey = ''): ?array
    {
        // Sort by priority (ascending) while preserving original indices
        /** @var array<int, array{rule: array<string, mixed>, original_index: int}> $indexedRules */
        $indexedRules = [];
        foreach ($rules as $index => $rule) {
            $indexedRules[] = ['rule' => $rule, 'original_index' => $index];
        }
        usort($indexedRules, function (array $a, array $b): int {
            /** @var int $priorityA */
            $priorityA = $a['rule']['priority'] ?? 0;
            /** @var int $priorityB */
            $priorityB = $b['rule']['priority'] ?? 0;
            $priorityComparison = $priorityA <=> $priorityB;

            if ($priorityComparison !== 0) {
                return $priorityComparison;
            }

            return $a['original_index'] <=> $b['original_index'];
        });

        foreach ($indexedRules as $item) {
            if ($this->ruleMatches($item['rule'], $context, $flagKey)) {
                return [
                    'value' => $this->normalizeFlagValue($item['rule']['value'] ?? null),
                    'index' => $item['original_index'],
                ];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $rule */
    private function ruleMatches(array $rule, Context $context, string $flagKey = ''): bool
    {
        /** @var array<int, array<string, mixed>> $conditions */
        $conditions = $rule['conditions'] ?? [];
        foreach ($conditions as $condition) {
            if (!$this->conditionMatches($condition, $context, $flagKey)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $condition */
    private function conditionMatches(array $condition, Context $context, string $flagKey = ''): bool
    {
        /** @var string $type */
        $type = $condition['type'] ?? 'trait';

        if ($type === 'segment') {
            /** @var string $segmentKey */
            $segmentKey = $condition['segment'] ?? '';
            return $this->segmentMatches($segmentKey, $context, $flagKey);
        }

        /** @var string $trait */
        $trait = $condition['trait'] ?? '';
        /** @var string $operator */
        $operator = $condition['operator'] ?? 'equals';
        /** @var mixed $expected */
        $expected = $condition['value'] ?? null;

        $actual = $context->get($trait);

        return $this->compareValues($actual, $operator, $expected, $flagKey);
    }

    private function compareValues(mixed $actual, string $operator, mixed $expected, string $flagKey = ''): bool
    {
        return match ($operator) {
            'equals' => $actual == $expected,
            'not_equals' => $actual != $expected,
            'contains' => is_string($actual) && is_string($expected) && str_contains($actual, $expected),
            'not_contains' => is_string($actual) && is_string($expected) && !str_contains($actual, $expected),
            'matches_regex' => $this->matchesRegex($actual, $expected),
            'gt' => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            'gte' => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
            'lt' => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            'lte' => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
            'in' => is_array($expected) && in_array($actual, $expected, false),
            'not_in' => is_array($expected) && !in_array($actual, $expected, false),
            'semver_gt' => is_string($actual) && is_string($expected) && version_compare($actual, $expected, '>'),
            'semver_gte' => is_string($actual) && is_string($expected) && version_compare($actual, $expected, '>='),
            'semver_lt' => is_string($actual) && is_string($expected) && version_compare($actual, $expected, '<'),
            'semver_lte' => is_string($actual) && is_string($expected) && version_compare($actual, $expected, '<='),
            'before_date' => $this->compareDates($actual, $expected, '<'),
            'after_date' => $this->compareDates($actual, $expected, '>'),
            'percentage_of' => $this->isInAttributePercentage($actual, $expected, $flagKey),
            default => false,
        };
    }

    /**
     * Check if a value falls within a percentage bucket (attribute-based rollout).
     *
     * This allows targeting like "50% of users with this attribute value".
     * Uses sticky bucketing based on the flag key + attribute value, so the
     * same attribute value will consistently be in or out of the percentage.
     *
     * Example condition: {"trait": "id", "operator": "percentage_of", "value": 50}
     * This targets 50% of users based on their ID.
     */
    private function isInAttributePercentage(mixed $attributeValue, mixed $percentage, string $flagKey): bool
    {
        if (!is_numeric($percentage)) {
            return false;
        }

        $pct = (int) $percentage;
        if ($pct <= 0) {
            return false;
        }
        if ($pct >= 100) {
            return true;
        }

        if ($attributeValue === null) {
            return false;
        }

        $valueString = is_scalar($attributeValue) ? (string) $attributeValue : json_encode($attributeValue);
        if ($valueString === false) {
            return false;
        }

        return $this->calculateBucket($flagKey . ':' . $valueString) < $pct;
    }

    private function compareDates(mixed $actual, mixed $expected, string $operator): bool
    {
        $actualDate = $this->toDate($actual);
        $expectedDate = $this->toDate($expected);

        if ($actualDate === null || $expectedDate === null) {
            return false;
        }

        return match ($operator) {
            '<' => $actualDate < $expectedDate,
            '>' => $actualDate > $expectedDate,
            '<=' => $actualDate <= $expectedDate,
            '>=' => $actualDate >= $expectedDate,
            default => false,
        };
    }

    private function matchesRegex(mixed $actual, mixed $pattern): bool
    {
        if (!is_string($actual) || !is_string($pattern)) {
            return false;
        }

        try {
            return preg_match($pattern, $actual) === 1;
        } catch (\Throwable $e) {
            if (!isset(self::$loggedBadPatterns[$pattern])) {
                self::$loggedBadPatterns[$pattern] = true;
                Log::warning('Feature flag regex evaluation failed', [
                    'pattern' => $pattern,
                    'error' => $e->getMessage(),
                ]);
            }
            return false;
        }
    }

    private function toDate(mixed $value): ?\DateTimeInterface
    {
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }

        try {
            if (is_int($value)) {
                return new \DateTime("@$value");
            }

            if (is_string($value) && $value !== '') {
                return new \DateTime($value);
            }

            return null;
        } catch (\Exception) {
            return null;
        }
    }

    /** Matches if ANY segment rule matches (OR logic). */
    private function segmentMatches(string $segmentKey, Context $context, string $flagKey = ''): bool
    {
        if ($segmentKey === '') {
            return false;
        }

        $segment = $this->config->cache->getSegment($segmentKey);

        if ($segment === null) {
            return false;
        }

        /** @var array<int, array<string, mixed>> $rules */
        $rules = $segment['rules'] ?? [];

        if (empty($rules)) {
            return false;
        }

        foreach ($rules as $rule) {
            if ($this->segmentRuleMatches($rule, $context, $flagKey)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $rule */
    private function segmentRuleMatches(array $rule, Context $context, string $flagKey = ''): bool
    {
        /** @var array<int, array<string, mixed>> $conditions */
        $conditions = $rule['conditions'] ?? [];

        if (empty($conditions)) {
            return false;
        }

        foreach ($conditions as $condition) {
            if (!$this->traitConditionMatches($condition, $context, $flagKey)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $condition */
    private function traitConditionMatches(array $condition, Context $context, string $flagKey = ''): bool
    {
        /** @var string $trait */
        $trait = $condition['trait'] ?? '';
        /** @var string $operator */
        $operator = $condition['operator'] ?? 'equals';
        /** @var mixed $expected */
        $expected = $condition['value'] ?? '';

        if ($trait === '') {
            return false;
        }

        $actual = $context->get($trait);

        return $this->compareValues($actual, $operator, $expected, $flagKey);
    }

    private function isInRollout(string $flagKey, string|int $contextId, int $percentage): bool
    {
        return $this->calculateBucket($flagKey . (string) $contextId) < $percentage;
    }

    private function calculateBucket(string $seed): int
    {
        return abs(crc32($seed)) % 100;
    }

    /**
     * Dispatch FlagEvaluated event if enabled.
     *
     * @param bool|int|float|string|array<string, mixed>|null $value
     * @param string|int|null $contextId
     */
    private function dispatchFlagEvaluatedEvent(
        string $flagKey,
        bool|int|float|string|array|null $value,
        string $matchReason,
        ?int $matchedRuleIndex,
        float $durationMs,
        string|int|null $contextId,
    ): void {
        if (!config('featureflags.events.enabled', false)) {
            return;
        }

        if (!config('featureflags.events.dispatch.flag_evaluated', true)) {
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

    /**
     * Dispatch FlagSyncCompleted event if enabled.
     */
    private function dispatchFlagSyncCompletedEvent(
        int $flagCount,
        int $segmentCount,
        float $durationMs,
        string $source,
    ): void {
        if (!config('featureflags.events.enabled', false)) {
            return;
        }

        if (!config('featureflags.events.dispatch.flag_sync_completed', true)) {
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
