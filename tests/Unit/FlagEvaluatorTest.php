<?php

declare(strict_types=1);

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Cache\FlagCache;
use FeatureFlags\Context;
use FeatureFlags\Evaluation\FlagEvaluator;
use FeatureFlags\Evaluation\MatchReason;
use FeatureFlags\Evaluation\OperatorEvaluator;
use FeatureFlags\Exceptions\CircularDependencyException;
use FeatureFlags\Tests\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

class FlagEvaluatorTest extends TestCase
{
    private FlagEvaluator $evaluator;
    private FlagCache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $cacheStore = new Repository(new ArrayStore());
        $this->cache = new FlagCache($cacheStore, 'test', 300);
        $this->evaluator = new FlagEvaluator($this->cache, new OperatorEvaluator());
    }

    // =========================================================================
    // BASIC EVALUATION
    // =========================================================================

    public function test_disabled_flag_returns_default_value(): void
    {
        $flag = [
            'key' => 'disabled-flag',
            'enabled' => false,
            'default_value' => 'fallback',
        ];

        $result = $this->evaluate($flag);

        $this->assertEquals('fallback', $result['value']);
        $this->assertEquals(MatchReason::Disabled->value, $result['match_reason']);
        $this->assertNull($result['matched_rule_index']);
    }

    public function test_enabled_flag_without_rules_returns_default(): void
    {
        $flag = [
            'key' => 'simple-flag',
            'enabled' => true,
            'default_value' => true,
            'rules' => [],
        ];

        $result = $this->evaluate($flag);

        $this->assertTrue($result['value']);
        $this->assertEquals(MatchReason::Default->value, $result['match_reason']);
    }

    public function test_missing_enabled_defaults_to_false(): void
    {
        $flag = [
            'key' => 'no-enabled-key',
            'default_value' => 'should-not-see',
        ];

        $result = $this->evaluate($flag);

        // Missing 'enabled' defaults to false, so flag is disabled
        $this->assertEquals('should-not-see', $result['value']);
        $this->assertEquals(MatchReason::Disabled->value, $result['match_reason']);
    }

    public function test_missing_default_value_returns_false(): void
    {
        $flag = [
            'key' => 'no-default',
            'enabled' => false,
        ];

        $result = $this->evaluate($flag);

        $this->assertFalse($result['value']);
    }

    // =========================================================================
    // CIRCULAR DEPENDENCY DETECTION
    // =========================================================================

    public function test_circular_dependency_throws_exception(): void
    {
        $flag = [
            'key' => 'flag-a',
            'enabled' => true,
            'default_value' => true,
        ];

        $this->expectException(CircularDependencyException::class);

        // Simulate flag-a being in the evaluating chain already
        $this->evaluator->evaluate($flag, null, ['flag-a'], fn() => null);
    }

    // =========================================================================
    // DEPENDENCIES
    // =========================================================================

    public function test_no_dependencies_passes(): void
    {
        $flag = [
            'key' => 'no-deps',
            'enabled' => true,
            'default_value' => 'success',
            'dependencies' => [],
        ];

        $result = $this->evaluate($flag);

        $this->assertEquals('success', $result['value']);
        $this->assertEquals(MatchReason::Default->value, $result['match_reason']);
    }

    public function test_satisfied_dependency_passes(): void
    {
        $flag = [
            'key' => 'dependent-flag',
            'enabled' => true,
            'default_value' => 'enabled-value',
            'dependencies' => [
                ['flag_key' => 'parent-flag', 'required_value' => true],
            ],
        ];

        $getFlagValue = fn(string $key) => $key === 'parent-flag' ? true : null;

        $result = $this->evaluator->evaluate($flag, null, [], $getFlagValue);

        $this->assertEquals('enabled-value', $result['value']);
        $this->assertEquals(MatchReason::Default->value, $result['match_reason']);
    }

    public function test_unsatisfied_dependency_returns_default(): void
    {
        $flag = [
            'key' => 'dependent-flag',
            'enabled' => true,
            'default_value' => 'fallback',
            'dependencies' => [
                ['flag_key' => 'parent-flag', 'required_value' => true],
            ],
        ];

        $getFlagValue = fn(string $key) => $key === 'parent-flag' ? false : null;

        $result = $this->evaluator->evaluate($flag, null, [], $getFlagValue);

        $this->assertEquals('fallback', $result['value']);
        $this->assertEquals(MatchReason::Dependency->value, $result['match_reason']);
    }

    public function test_multiple_dependencies_all_must_pass(): void
    {
        $flag = [
            'key' => 'multi-dep',
            'enabled' => true,
            'default_value' => 'success',
            'dependencies' => [
                ['flag_key' => 'dep-1', 'required_value' => true],
                ['flag_key' => 'dep-2', 'required_value' => 'active'],
            ],
        ];

        $getFlagValue = fn(string $key) => match ($key) {
            'dep-1' => true,
            'dep-2' => 'active',
            default => null,
        };

        $result = $this->evaluator->evaluate($flag, null, [], $getFlagValue);

        $this->assertEquals('success', $result['value']);
    }

    public function test_multiple_dependencies_one_fails(): void
    {
        $flag = [
            'key' => 'multi-dep',
            'enabled' => true,
            'default_value' => 'fallback',
            'dependencies' => [
                ['flag_key' => 'dep-1', 'required_value' => true],
                ['flag_key' => 'dep-2', 'required_value' => 'active'],
            ],
        ];

        $getFlagValue = fn(string $key) => match ($key) {
            'dep-1' => true,
            'dep-2' => 'inactive', // Doesn't match required
            default => null,
        };

        $result = $this->evaluator->evaluate($flag, null, [], $getFlagValue);

        $this->assertEquals('fallback', $result['value']);
        $this->assertEquals(MatchReason::Dependency->value, $result['match_reason']);
    }

    public function test_null_dependency_key_is_skipped(): void
    {
        $flag = [
            'key' => 'skip-null-dep',
            'enabled' => true,
            'default_value' => 'success',
            'dependencies' => [
                ['flag_key' => null, 'required_value' => true], // Should be skipped
                ['required_value' => true], // Missing flag_key, should be skipped
            ],
        ];

        $result = $this->evaluate($flag);

        $this->assertEquals('success', $result['value']);
    }

    // =========================================================================
    // RULE EVALUATION
    // =========================================================================

    public function test_matching_rule_returns_rule_value(): void
    {
        $flag = [
            'key' => 'rule-flag',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro'],
                    ],
                    'value' => 'pro-value',
                ],
            ],
        ];

        $context = new Context('user-1', ['plan' => 'pro']);
        $result = $this->evaluate($flag, $context);

        $this->assertEquals('pro-value', $result['value']);
        $this->assertEquals(MatchReason::Rule->value, $result['match_reason']);
        $this->assertEquals(0, $result['matched_rule_index']);
    }

    public function test_no_matching_rule_falls_through_to_default(): void
    {
        $flag = [
            'key' => 'rule-flag',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'plan', 'operator' => 'equals', 'value' => 'enterprise'],
                    ],
                    'value' => 'enterprise-value',
                ],
            ],
        ];

        $context = new Context('user-1', ['plan' => 'free']);
        $result = $this->evaluate($flag, $context);

        $this->assertEquals('default', $result['value']);
        $this->assertEquals(MatchReason::Default->value, $result['match_reason']);
    }

    public function test_rules_sorted_by_priority_lowest_first(): void
    {
        $flag = [
            'key' => 'priority-flag',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 3,
                    'conditions' => [['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro']],
                    'value' => 'low-priority',
                ],
                [
                    'priority' => 1,
                    'conditions' => [['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro']],
                    'value' => 'high-priority',
                ],
                [
                    'priority' => 2,
                    'conditions' => [['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro']],
                    'value' => 'medium-priority',
                ],
            ],
        ];

        $context = new Context('user-1', ['plan' => 'pro']);
        $result = $this->evaluate($flag, $context);

        $this->assertEquals('high-priority', $result['value']);
        $this->assertEquals(1, $result['matched_rule_index']); // Original index
    }

    public function test_same_priority_uses_original_index_order(): void
    {
        $flag = [
            'key' => 'same-priority',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro']],
                    'value' => 'first',
                ],
                [
                    'priority' => 1,
                    'conditions' => [['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro']],
                    'value' => 'second',
                ],
            ],
        ];

        $context = new Context('user-1', ['plan' => 'pro']);
        $result = $this->evaluate($flag, $context);

        $this->assertEquals('first', $result['value']);
        $this->assertEquals(0, $result['matched_rule_index']);
    }

    public function test_null_context_skips_rule_evaluation(): void
    {
        $flag = [
            'key' => 'null-context',
            'enabled' => true,
            'default_value' => 'no-context-default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro']],
                    'value' => 'should-not-match',
                ],
            ],
        ];

        $result = $this->evaluate($flag, null);

        $this->assertEquals('no-context-default', $result['value']);
        $this->assertEquals(MatchReason::Default->value, $result['match_reason']);
    }

    public function test_multiple_conditions_all_must_match(): void
    {
        $flag = [
            'key' => 'multi-condition',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro'],
                        ['trait' => 'country', 'operator' => 'in', 'value' => ['US', 'CA']],
                    ],
                    'value' => 'matched',
                ],
            ],
        ];

        // Both conditions match
        $context = new Context('user-1', ['plan' => 'pro', 'country' => 'US']);
        $result = $this->evaluate($flag, $context);
        $this->assertEquals('matched', $result['value']);

        // One condition doesn't match
        $context = new Context('user-2', ['plan' => 'pro', 'country' => 'UK']);
        $result = $this->evaluate($flag, $context);
        $this->assertEquals('default', $result['value']);
    }

    public function test_empty_trait_in_condition_returns_false(): void
    {
        $flag = [
            'key' => 'empty-trait',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => '', 'operator' => 'equals', 'value' => 'test'],
                    ],
                    'value' => 'should-not-match',
                ],
            ],
        ];

        $context = new Context('user-1', ['anything' => 'test']);
        $result = $this->evaluate($flag, $context);

        $this->assertEquals('default', $result['value']);
    }

    // =========================================================================
    // ROLLOUT EVALUATION
    // =========================================================================

    public function test_no_rollout_returns_default(): void
    {
        $flag = [
            'key' => 'no-rollout',
            'enabled' => true,
            'default_value' => 'default-value',
            'rollout_percentage' => null,
        ];

        $result = $this->evaluate($flag);

        $this->assertEquals('default-value', $result['value']);
        $this->assertEquals(MatchReason::Default->value, $result['match_reason']);
    }

    public function test_100_percent_rollout_returns_default(): void
    {
        $flag = [
            'key' => 'full-rollout',
            'enabled' => true,
            'default_value' => 'rolled-out',
            'rollout_percentage' => 100,
        ];

        $context = new Context('any-user', []);
        $result = $this->evaluate($flag, $context);

        $this->assertEquals('rolled-out', $result['value']);
        $this->assertEquals(MatchReason::Rollout->value, $result['match_reason']);
        $this->assertEquals(-1, $result['matched_rule_index']);
    }

    public function test_0_percent_rollout_returns_false(): void
    {
        $flag = [
            'key' => 'no-rollout-pct',
            'enabled' => true,
            'default_value' => 'should-not-see',
            'rollout_percentage' => 0,
        ];

        $context = new Context('any-user', []);
        $result = $this->evaluate($flag, $context);

        $this->assertFalse($result['value']);
        $this->assertEquals(MatchReason::RolloutMiss->value, $result['match_reason']);
    }

    public function test_null_context_with_rollout_returns_false(): void
    {
        $flag = [
            'key' => 'rollout-no-context',
            'enabled' => true,
            'default_value' => 'should-not-see',
            'rollout_percentage' => 50,
        ];

        $result = $this->evaluate($flag, null);

        $this->assertFalse($result['value']);
        $this->assertEquals(MatchReason::RolloutMiss->value, $result['match_reason']);
    }

    public function test_rollout_is_deterministic_per_user(): void
    {
        $flag = [
            'key' => 'deterministic-rollout',
            'enabled' => true,
            'default_value' => true,
            'rollout_percentage' => 50,
        ];

        $context = new Context('consistent-user-123', []);

        // Same user should always get the same result
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->evaluate($flag, $context)['value'];
        }

        $this->assertCount(1, array_unique($results));
    }

    public function test_rollout_distributes_users(): void
    {
        $flag = [
            'key' => 'distribution-test',
            'enabled' => true,
            'default_value' => true,
            'rollout_percentage' => 50,
        ];

        $inRollout = 0;
        $total = 100;

        for ($i = 0; $i < $total; $i++) {
            $context = new Context("user-{$i}", []);
            $result = $this->evaluate($flag, $context);
            if ($result['value'] === true) {
                $inRollout++;
            }
        }

        // Should be roughly 50%, allow some variance
        $this->assertGreaterThan(30, $inRollout);
        $this->assertLessThan(70, $inRollout);
    }

    // =========================================================================
    // SEGMENT EVALUATION
    // =========================================================================

    public function test_segment_condition_matches(): void
    {
        $this->cache->putSegments([
            [
                'key' => 'beta-users',
                'rules' => [
                    [
                        'conditions' => [
                            ['trait' => 'beta', 'operator' => 'equals', 'value' => true],
                        ],
                    ],
                ],
            ],
        ], 300);

        $flag = [
            'key' => 'segment-flag',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['type' => 'segment', 'segment' => 'beta-users'],
                    ],
                    'value' => 'beta-value',
                ],
            ],
        ];

        $context = new Context('user-1', ['beta' => true]);
        $result = $this->evaluate($flag, $context);

        $this->assertEquals('beta-value', $result['value']);
        $this->assertEquals(MatchReason::Rule->value, $result['match_reason']);
    }

    public function test_missing_segment_returns_false(): void
    {
        $flag = [
            'key' => 'missing-segment-flag',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['type' => 'segment', 'segment' => 'nonexistent-segment'],
                    ],
                    'value' => 'should-not-match',
                ],
            ],
        ];

        $context = new Context('user-1', ['anything' => true]);
        $result = $this->evaluate($flag, $context);

        $this->assertEquals('default', $result['value']);
    }

    public function test_empty_segment_key_returns_false(): void
    {
        $flag = [
            'key' => 'empty-segment',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['type' => 'segment', 'segment' => ''],
                    ],
                    'value' => 'should-not-match',
                ],
            ],
        ];

        $context = new Context('user-1', []);
        $result = $this->evaluate($flag, $context);

        $this->assertEquals('default', $result['value']);
    }

    public function test_segment_with_empty_rules_returns_false(): void
    {
        $this->cache->putSegments([
            [
                'key' => 'empty-segment',
                'rules' => [],
            ],
        ], 300);

        $flag = [
            'key' => 'empty-rules-segment',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['type' => 'segment', 'segment' => 'empty-segment'],
                    ],
                    'value' => 'should-not-match',
                ],
            ],
        ];

        $context = new Context('user-1', []);
        $result = $this->evaluate($flag, $context);

        $this->assertEquals('default', $result['value']);
    }

    public function test_segment_with_multiple_rules_uses_or_logic(): void
    {
        $this->cache->putSegments([
            [
                'key' => 'multi-rule-segment',
                'rules' => [
                    ['conditions' => [['trait' => 'plan', 'operator' => 'equals', 'value' => 'enterprise']]],
                    ['conditions' => [['trait' => 'vip', 'operator' => 'equals', 'value' => true]]],
                ],
            ],
        ], 300);

        $flag = [
            'key' => 'multi-segment-flag',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [['type' => 'segment', 'segment' => 'multi-rule-segment']],
                    'value' => 'segment-matched',
                ],
            ],
        ];

        // Matches first segment rule
        $context = new Context('user-1', ['plan' => 'enterprise']);
        $this->assertEquals('segment-matched', $this->evaluate($flag, $context)['value']);

        // Matches second segment rule
        $context = new Context('user-2', ['vip' => true]);
        $this->assertEquals('segment-matched', $this->evaluate($flag, $context)['value']);

        // Matches neither
        $context = new Context('user-3', ['plan' => 'free', 'vip' => false]);
        $this->assertEquals('default', $this->evaluate($flag, $context)['value']);
    }

    public function test_segment_rule_with_empty_conditions_returns_false(): void
    {
        $this->cache->putSegments([
            [
                'key' => 'no-conditions-segment',
                'rules' => [
                    ['conditions' => []],
                ],
            ],
        ], 300);

        $flag = [
            'key' => 'no-conditions-flag',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [['type' => 'segment', 'segment' => 'no-conditions-segment']],
                    'value' => 'should-not-match',
                ],
            ],
        ];

        $context = new Context('user-1', ['anything' => true]);
        $result = $this->evaluate($flag, $context);

        $this->assertEquals('default', $result['value']);
    }

    // =========================================================================
    // VALUE NORMALIZATION
    // =========================================================================

    public function test_normalizes_various_value_types(): void
    {
        $testCases = [
            ['default' => true, 'expected' => true],
            ['default' => false, 'expected' => false],
            ['default' => 'string-value', 'expected' => 'string-value'],
            ['default' => 42, 'expected' => 42],
            ['default' => 3.14, 'expected' => 3.14],
            ['default' => ['key' => 'value'], 'expected' => ['key' => 'value']],
        ];

        foreach ($testCases as $case) {
            $flag = [
                'key' => 'type-test',
                'enabled' => true,
                'default_value' => $case['default'],
            ];

            $result = $this->evaluate($flag);
            $this->assertSame($case['expected'], $result['value']);
        }
    }

    public function test_null_default_value_coalesces_to_true(): void
    {
        $flag = [
            'key' => 'null-default',
            'enabled' => true,
            'default_value' => null,
        ];

        $result = $this->evaluate($flag);

        // null default_value is coalesced to true by ?? operator
        $this->assertTrue($result['value']);
    }

    /**
     * @param array<string, mixed> $flag
     * @return array{value: mixed, matched_rule_index: int|null, match_reason: string}
     */
    private function evaluate(array $flag, ?Context $context = null): array
    {
        return $this->evaluator->evaluate($flag, $context, [], fn() => null);
    }
}
