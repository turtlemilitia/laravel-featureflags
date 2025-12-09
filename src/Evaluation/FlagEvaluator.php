<?php

declare(strict_types=1);

namespace FeatureFlags\Evaluation;

use FeatureFlags\Cache\FlagCache;
use FeatureFlags\Context;
use FeatureFlags\Exceptions\CircularDependencyException;

readonly class FlagEvaluator
{
    public function __construct(
        private FlagCache         $cache,
        private OperatorEvaluator $operatorEvaluator,
    ) {}

    /**
     * @param array<string, mixed> $flag
     * @param array<string> $evaluating
     * @param callable(string, Context|null, array<string>): (bool|int|float|string|array<string, mixed>|null) $getFlagValue
     * @return array{value: bool|int|float|string|array<string, mixed>|null, matched_rule_index: int|null, match_reason: string}
     */
    public function evaluate(array $flag, ?Context $context, array $evaluating, callable $getFlagValue): array
    {
        $flagKey = $flag['key'] ?? '';
        if (in_array($flagKey, $evaluating, true)) {
            throw new CircularDependencyException($flag);
        }

        if (!$this->isEnabled($flag)) {
            return $this->result($flag['default_value'] ?? false, MatchReason::Disabled);
        }

        if (!$this->dependenciesSatisfied($flag, $context, $evaluating, $getFlagValue)) {
            return $this->result($flag['default_value'] ?? false, MatchReason::Dependency);
        }

        $ruleResult = $this->evaluateRules($flag, $context);
        if ($ruleResult !== null) {
            return $this->result($ruleResult['value'], MatchReason::Rule, $ruleResult['index']);
        }

        return $this->evaluateRollout($flag, $context);
    }

    /**
     * @param array<string, mixed> $flag
     */
    private function isEnabled(array $flag): bool
    {
        /** @var bool $enabled */
        $enabled = $flag['enabled'] ?? false;
        return $enabled;
    }

    /**
     * @return array{value: bool|int|float|string|array<string, mixed>|null, matched_rule_index: int|null, match_reason: string}
     */
    private function result(mixed $value, MatchReason $reason, ?int $matchedIndex = null): array
    {
        return [
            'value' => ValueNormalizer::normalize($value),
            'matched_rule_index' => $matchedIndex,
            'match_reason' => $reason->value,
        ];
    }

    /**
     * @param array<string, mixed> $flag
     * @param array<string> $evaluating
     * @param callable(string, Context|null, array<string>): (bool|int|float|string|array<string, mixed>|null) $getFlagValue
     */
    private function dependenciesSatisfied(array $flag, ?Context $context, array $evaluating, callable $getFlagValue): bool
    {
        /** @var array<int, array<string, mixed>> $dependencies */
        $dependencies = $flag['dependencies'] ?? [];

        if (empty($dependencies)) {
            return true;
        }

        /** @var string $flagKey */
        $flagKey = $flag['key'] ?? '';
        $evaluating[] = $flagKey;

        foreach ($dependencies as $dependency) {
            /** @var string|null $dependencyKey */
            $dependencyKey = $dependency['flag_key'] ?? null;
            /** @var mixed $requiredValue */
            $requiredValue = $dependency['required_value'] ?? null;

            if ($dependencyKey === null) {
                continue;
            }

            $actualValue = $getFlagValue($dependencyKey, $context, $evaluating);
            if ($actualValue !== $requiredValue) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $flag
     * @return array{value: bool|int|float|string|array<string, mixed>|null, index: int}|null
     */
    private function evaluateRules(array $flag, ?Context $context): ?array
    {
        /** @var array<int, array<string, mixed>> $rules */
        $rules = $flag['rules'] ?? [];

        if (empty($rules) || $context === null) {
            return null;
        }

        /** @var string $flagKey */
        $flagKey = $flag['key'] ?? '';

        return $this->evaluateRulesWithIndex($rules, $context, $flagKey);
    }

    /**
     * @param array<string, mixed> $flag
     * @return array{value: bool|int|float|string|array<string, mixed>|null, matched_rule_index: int|null, match_reason: string}
     */
    private function evaluateRollout(array $flag, ?Context $context): array
    {
        /** @var int|null $rolloutPercentage */
        $rolloutPercentage = $flag['rollout_percentage'] ?? null;
        $defaultValue = $flag['default_value'] ?? true;

        if ($rolloutPercentage === null) {
            return $this->result($defaultValue, MatchReason::Default);
        }

        if ($rolloutPercentage >= 100) {
            return $this->result($defaultValue, MatchReason::Rollout, -1);
        }

        if ($rolloutPercentage <= 0 || $context === null) {
            return $this->result(false, MatchReason::RolloutMiss);
        }

        /** @var string $flagKey */
        $flagKey = $flag['key'] ?? '';

        if ($this->isInRollout($flagKey, $context, $rolloutPercentage)) {
            return $this->result($defaultValue, MatchReason::Rollout, -1);
        }

        return $this->result(false, MatchReason::RolloutMiss);
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @return array{value: bool|int|float|string|array<string, mixed>|null, index: int}|null
     */
    private function evaluateRulesWithIndex(array $rules, Context $context, string $flagKey): ?array
    {
        $indexedRules = $this->sortRulesByPriority($rules);

        foreach ($indexedRules as $item) {
            if ($this->ruleMatches($item['rule'], $context, $flagKey)) {
                return [
                    'value' => ValueNormalizer::normalize($item['rule']['value'] ?? null),
                    'index' => $item['original_index'],
                ];
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @return array<int, array{rule: array<string, mixed>, original_index: int}>
     */
    private function sortRulesByPriority(array $rules): array
    {
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

        return $indexedRules;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function ruleMatches(array $rule, Context $context, string $flagKey): bool
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

    /**
     * @param array<string, mixed> $condition
     */
    private function conditionMatches(array $condition, Context $context, string $flagKey): bool
    {
        /** @var string $type */
        $type = $condition['type'] ?? 'trait';

        if ($type === 'segment') {
            /** @var string $segmentKey */
            $segmentKey = $condition['segment'] ?? '';
            return $this->segmentMatches($segmentKey, $context, $flagKey);
        }

        return $this->evaluateTraitCondition($condition, $context, $flagKey);
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function evaluateTraitCondition(array $condition, Context $context, string $flagKey): bool
    {
        /** @var string $trait */
        $trait = $condition['trait'] ?? '';

        if ($trait === '') {
            return false;
        }

        /** @var string $operator */
        $operator = $condition['operator'] ?? 'equals';
        /** @var mixed $expected */
        $expected = $condition['value'] ?? null;

        $actual = $context->get($trait);

        return $this->operatorEvaluator->evaluate($actual, $operator, $expected, $flagKey);
    }

    private function segmentMatches(string $segmentKey, Context $context, string $flagKey): bool
    {
        if ($segmentKey === '') {
            return false;
        }

        $segment = $this->cache->getSegment($segmentKey);

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

    /**
     * @param array<string, mixed> $rule
     */
    private function segmentRuleMatches(array $rule, Context $context, string $flagKey): bool
    {
        /** @var array<int, array<string, mixed>> $conditions */
        $conditions = $rule['conditions'] ?? [];

        if (empty($conditions)) {
            return false;
        }

        foreach ($conditions as $condition) {
            if (!$this->evaluateTraitCondition($condition, $context, $flagKey)) {
                return false;
            }
        }

        return true;
    }

    private function isInRollout(string $flagKey, Context $context, int $percentage): bool
    {
        return BucketCalculator::calculate($flagKey . $context->getBucketingId()) < ($percentage * 100);
    }
}
