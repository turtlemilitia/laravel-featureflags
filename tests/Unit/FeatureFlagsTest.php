<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Cache\FlagCache;
use FeatureFlags\Client\ApiClient;
use FeatureFlags\Context;
use FeatureFlags\Context\RequestContext;
use FeatureFlags\ContextResolver;
use FeatureFlags\Evaluation\OperatorEvaluator;
use FeatureFlags\FeatureFlags;
use FeatureFlags\FeatureFlagsConfig;
use FeatureFlags\Telemetry\ConversionCollector;
use FeatureFlags\Telemetry\ErrorCollector;
use FeatureFlags\Telemetry\FlagStateTracker;
use FeatureFlags\Telemetry\TelemetryCollector;
use FeatureFlags\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

class FeatureFlagsTest extends TestCase
{
    private FeatureFlags $featureFlags;
    private ApiClient $mockApiClient;
    private FlagCache $mockCache;
    private TelemetryCollector $mockTelemetry;
    private ConversionCollector $mockConversions;
    private ErrorCollector $mockErrors;
    private FlagStateTracker $stateTracker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockApiClient = Mockery::mock(ApiClient::class);
        $this->mockCache = Mockery::mock(FlagCache::class);
        $this->mockTelemetry = Mockery::mock(TelemetryCollector::class);
        $this->mockTelemetry->shouldReceive('record')->andReturnNull();
        $this->mockConversions = Mockery::mock(ConversionCollector::class);
        $this->mockConversions->shouldReceive('track')->andReturnNull();
        $this->mockErrors = Mockery::mock(ErrorCollector::class);
        $this->mockErrors->shouldReceive('track')->andReturnNull();
        $this->stateTracker = new FlagStateTracker();

        $config = new FeatureFlagsConfig(
            $this->mockApiClient,
            $this->mockCache,
            new ContextResolver(),
            $this->mockTelemetry,
            $this->mockConversions,
            $this->mockErrors,
            $this->stateTracker,
            new OperatorEvaluator(),
            true,
        );

        $this->featureFlags = new FeatureFlags($config);
    }

    private function createFeatureFlags(
        $apiClient = null,
        $cache = null,
        $contextResolver = null,
        $telemetry = null,
        $conversions = null,
        $errors = null,
        $stateTracker = null,
        bool $cacheEnabled = true,
    ): FeatureFlags {
        $config = new FeatureFlagsConfig(
            $apiClient ?? $this->mockApiClient,
            $cache ?? $this->mockCache,
            $contextResolver ?? new ContextResolver(),
            $telemetry ?? $this->mockTelemetry,
            $conversions ?? $this->mockConversions,
            $errors ?? $this->mockErrors,
            $stateTracker ?? new FlagStateTracker(),
            new OperatorEvaluator(),
            $cacheEnabled,
        );

        return new FeatureFlags($config);
    }

    public function test_active_returns_true_for_enabled_flag(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('my-flag')->andReturn([
            'key' => 'my-flag',
            'enabled' => true,
            'default_value' => true,
            'rules' => [],
        ]);

        $result = $this->featureFlags->active('my-flag');

        $this->assertTrue($result);
    }

    public function test_active_returns_false_for_disabled_flag(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('my-flag')->andReturn([
            'key' => 'my-flag',
            'enabled' => false,
            'default_value' => false,
            'rules' => [],
        ]);

        $result = $this->featureFlags->active('my-flag');

        $this->assertFalse($result);
    }

    public function test_active_returns_false_for_unknown_flag(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('unknown-flag')->andReturn(null);

        $result = $this->featureFlags->active('unknown-flag');

        $this->assertFalse($result);
    }

    public function test_value_returns_default_value_when_flag_disabled(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('string-flag')->andReturn([
            'key' => 'string-flag',
            'type' => 'string',
            'enabled' => false,
            'default_value' => 'default-value',
            'rules' => [],
        ]);

        $result = $this->featureFlags->value('string-flag');

        $this->assertEquals('default-value', $result);
    }

    public function test_rule_evaluation_equals_operator(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('pro-feature')->andReturn([
            'key' => 'pro-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro'],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        $context = new Context('user-1', ['plan' => 'pro']);
        $result = $this->featureFlags->active('pro-feature', $context);

        $this->assertTrue($result);

        // Different plan should not match
        $freeContext = new Context('user-2', ['plan' => 'free']);
        $freeResult = $this->featureFlags->active('pro-feature', $freeContext);

        $this->assertFalse($freeResult);
    }

    public function test_rule_evaluation_in_operator(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('beta-feature')->andReturn([
            'key' => 'beta-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'country', 'operator' => 'in', 'value' => ['US', 'CA', 'UK']],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        $usContext = new Context('user-1', ['country' => 'US']);
        $this->assertTrue($this->featureFlags->active('beta-feature', $usContext));

        $deContext = new Context('user-2', ['country' => 'DE']);
        $this->assertFalse($this->featureFlags->active('beta-feature', $deContext));
    }

    public function test_rule_evaluation_contains_operator(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('internal-feature')->andReturn([
            'key' => 'internal-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'email', 'operator' => 'contains', 'value' => '@company.com'],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        $internalContext = new Context('user-1', ['email' => 'john@company.com']);
        $this->assertTrue($this->featureFlags->active('internal-feature', $internalContext));

        $externalContext = new Context('user-2', ['email' => 'jane@gmail.com']);
        $this->assertFalse($this->featureFlags->active('internal-feature', $externalContext));
    }

    public function test_rollout_requires_context(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('rollout-flag')->andReturn([
            'key' => 'rollout-flag',
            'enabled' => true,
            'default_value' => true,
            'rollout_percentage' => 10,
        ]);

        $this->assertFalse($this->featureFlags->active('rollout-flag', null));
    }

    public function test_date_comparison_missing_trait_does_not_match(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('date-flag')->andReturn([
            'key' => 'date-flag',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'created_at', 'operator' => 'after_date', 'value' => '2000-01-01'],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        $context = new Context('user-1', ['other' => 'x']);

        $this->assertFalse($this->featureFlags->active('date-flag', $context));
    }

    public function test_rules_with_same_priority_stay_stable(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('stable-flag')->andReturn([
            'key' => 'stable-flag',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [],
                    'value' => true,
                ],
                [
                    'priority' => 1,
                    'conditions' => [],
                    'value' => false,
                ],
            ],
        ]);

        $context = new Context('user-1', []);

        $this->assertTrue($this->featureFlags->active('stable-flag', $context));
    }

    public function test_flush_all_telemetry_and_reset(): void
    {
        RequestContext::initialize();

        $this->mockTelemetry->shouldReceive('flush')->once();
        $this->mockConversions->shouldReceive('flush')->once();
        $this->mockErrors->shouldReceive('flush')->once();

        $this->stateTracker->record('flag-1', true, new Context('user-1'));
        $this->assertEquals(1, $this->stateTracker->count());

        $this->featureFlags->flushAllTelemetryAndReset();

        $this->assertEquals(0, $this->stateTracker->count());
        $this->assertFalse(RequestContext::isInitialized());
    }

    public function test_rule_evaluation_comparison_operators(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('early-adopter')->andReturn([
            'key' => 'early-adopter',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'user_id', 'operator' => 'lt', 'value' => 1000],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        $earlyContext = new Context('user-1', ['user_id' => 500]);
        $this->assertTrue($this->featureFlags->active('early-adopter', $earlyContext));

        $lateContext = new Context('user-2', ['user_id' => 1500]);
        $this->assertFalse($this->featureFlags->active('early-adopter', $lateContext));
    }

    public function test_rule_evaluation_semver_operators(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('version-feature')->andReturn([
            'key' => 'version-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'app_version', 'operator' => 'semver_gte', 'value' => '2.0.0'],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        // Version 2.0.0 should match
        $v2Context = new Context('user-1', ['app_version' => '2.0.0']);
        $this->assertTrue($this->featureFlags->active('version-feature', $v2Context));

        // Version 2.5.1 should match
        $v251Context = new Context('user-2', ['app_version' => '2.5.1']);
        $this->assertTrue($this->featureFlags->active('version-feature', $v251Context));

        // Version 1.9.9 should not match
        $v1Context = new Context('user-3', ['app_version' => '1.9.9']);
        $this->assertFalse($this->featureFlags->active('version-feature', $v1Context));
    }

    public function test_rule_evaluation_semver_lt_operator(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('legacy-feature')->andReturn([
            'key' => 'legacy-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'app_version', 'operator' => 'semver_lt', 'value' => '3.0.0'],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        // Version 2.9.9 should match (less than 3.0.0)
        $v2Context = new Context('user-1', ['app_version' => '2.9.9']);
        $this->assertTrue($this->featureFlags->active('legacy-feature', $v2Context));

        // Version 3.0.0 should not match
        $v3Context = new Context('user-2', ['app_version' => '3.0.0']);
        $this->assertFalse($this->featureFlags->active('legacy-feature', $v3Context));
    }

    public function test_rule_evaluation_dot_notation_nested_traits(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('pro-feature')->andReturn([
            'key' => 'pro-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'subscription.plan.name', 'operator' => 'equals', 'value' => 'pro'],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        // Nested trait matching
        $proContext = new Context('user-1', [
            'subscription' => [
                'plan' => [
                    'name' => 'pro',
                    'price' => 29,
                ],
            ],
        ]);
        $this->assertTrue($this->featureFlags->active('pro-feature', $proContext));

        // Different plan should not match
        $freeContext = new Context('user-2', [
            'subscription' => [
                'plan' => [
                    'name' => 'free',
                    'price' => 0,
                ],
            ],
        ]);
        $this->assertFalse($this->featureFlags->active('pro-feature', $freeContext));

        // Missing nested path should not match
        $noSubContext = new Context('user-3', ['email' => 'test@example.com']);
        $this->assertFalse($this->featureFlags->active('pro-feature', $noSubContext));
    }

    public function test_rule_evaluation_matches_regex_operator(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('regex-feature')->andReturn([
            'key' => 'regex-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'email', 'operator' => 'matches_regex', 'value' => '/.*@company\.com$/'],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        // Email matching pattern
        $matchContext = new Context('user-1', ['email' => 'john@company.com']);
        $this->assertTrue($this->featureFlags->active('regex-feature', $matchContext));

        // Email not matching pattern
        $noMatchContext = new Context('user-2', ['email' => 'jane@gmail.com']);
        $this->assertFalse($this->featureFlags->active('regex-feature', $noMatchContext));
    }

    public function test_rule_evaluation_before_date_operator(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('early-user-feature')->andReturn([
            'key' => 'early-user-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'created_at', 'operator' => 'before_date', 'value' => '2025-01-01'],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        // User created before 2025
        $earlyContext = new Context('user-1', ['created_at' => '2024-06-15']);
        $this->assertTrue($this->featureFlags->active('early-user-feature', $earlyContext));

        // User created after 2025
        $lateContext = new Context('user-2', ['created_at' => '2025-06-15']);
        $this->assertFalse($this->featureFlags->active('early-user-feature', $lateContext));
    }

    public function test_rule_evaluation_after_date_operator(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('new-user-feature')->andReturn([
            'key' => 'new-user-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'subscription_start', 'operator' => 'after_date', 'value' => '2025-01-01'],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        // Subscription started after 2025
        $newContext = new Context('user-1', ['subscription_start' => '2025-03-15']);
        $this->assertTrue($this->featureFlags->active('new-user-feature', $newContext));

        // Subscription started before 2025
        $oldContext = new Context('user-2', ['subscription_start' => '2024-11-15']);
        $this->assertFalse($this->featureFlags->active('new-user-feature', $oldContext));
    }

    public function test_invalid_regex_returns_false(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('bad-regex-feature')->andReturn([
            'key' => 'bad-regex-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'email', 'operator' => 'matches_regex', 'value' => '[invalid regex'],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        // Invalid regex should not match (and not throw)
        $context = new Context('user-1', ['email' => 'test@example.com']);
        $this->assertFalse($this->featureFlags->active('bad-regex-feature', $context));
    }

    public function test_invalid_date_returns_false(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('bad-date-feature')->andReturn([
            'key' => 'bad-date-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'created_at', 'operator' => 'before_date', 'value' => 'not-a-date'],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        // Invalid date should not match (and not throw)
        $context = new Context('user-1', ['created_at' => '2024-01-01']);
        $this->assertFalse($this->featureFlags->active('bad-date-feature', $context));
    }

    public function test_percentage_of_operator_is_deterministic(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('percentage-feature')->andReturn([
            'key' => 'percentage-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'id', 'operator' => 'percentage_of', 'value' => 50],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        $context = new Context('user-123', ['id' => 'user-123']);

        // Same user should always get the same result
        $result1 = $this->featureFlags->active('percentage-feature', $context);
        $result2 = $this->featureFlags->active('percentage-feature', $context);
        $result3 = $this->featureFlags->active('percentage-feature', $context);

        $this->assertEquals($result1, $result2);
        $this->assertEquals($result2, $result3);
    }

    public function test_percentage_of_operator_distributes_correctly(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('fifty-percent-feature')->andReturn([
            'key' => 'fifty-percent-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'id', 'operator' => 'percentage_of', 'value' => 50],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        // Run 100 users through and check distribution
        $results = [];
        for ($i = 1; $i <= 100; $i++) {
            $context = new Context("user-{$i}", ['id' => "user-{$i}"]);
            $results[] = $this->featureFlags->active('fifty-percent-feature', $context);
        }

        // Should have roughly 50% true (with some variance due to hashing)
        $trueCount = count(array_filter($results));
        $this->assertGreaterThan(30, $trueCount);
        $this->assertLessThan(70, $trueCount);
    }

    public function test_percentage_of_operator_with_zero_percent(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('zero-percent-feature')->andReturn([
            'key' => 'zero-percent-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'id', 'operator' => 'percentage_of', 'value' => 0],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        // No one should match 0%
        for ($i = 1; $i <= 10; $i++) {
            $context = new Context("user-{$i}", ['id' => "user-{$i}"]);
            $this->assertFalse($this->featureFlags->active('zero-percent-feature', $context));
        }
    }

    public function test_percentage_of_operator_with_100_percent(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('full-percent-feature')->andReturn([
            'key' => 'full-percent-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'id', 'operator' => 'percentage_of', 'value' => 100],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        // Everyone should match 100%
        for ($i = 1; $i <= 10; $i++) {
            $context = new Context("user-{$i}", ['id' => "user-{$i}"]);
            $this->assertTrue($this->featureFlags->active('full-percent-feature', $context));
        }
    }

    public function test_percentage_of_operator_with_null_value_returns_false(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('null-value-feature')->andReturn([
            'key' => 'null-value-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'missing_trait', 'operator' => 'percentage_of', 'value' => 50],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        // Missing trait (null value) should not match
        $context = new Context('user-1', ['id' => 'user-1']);
        $this->assertFalse($this->featureFlags->active('null-value-feature', $context));
    }

    public function test_percentage_of_operator_combined_with_other_conditions(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('combo-feature')->andReturn([
            'key' => 'combo-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        // 50% of pro users
                        ['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro'],
                        ['trait' => 'id', 'operator' => 'percentage_of', 'value' => 50],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        // Free users should never match (fails plan condition)
        $freeContext = new Context('user-1', ['id' => 'user-1', 'plan' => 'free']);
        $this->assertFalse($this->featureFlags->active('combo-feature', $freeContext));

        // Pro users: some should match, some shouldn't (due to percentage)
        $proResults = [];
        for ($i = 1; $i <= 50; $i++) {
            $context = new Context("pro-user-{$i}", ['id' => "pro-user-{$i}", 'plan' => 'pro']);
            $proResults[] = $this->featureFlags->active('combo-feature', $context);
        }

        // Should have some true and some false
        $trueCount = count(array_filter($proResults));
        $this->assertGreaterThan(10, $trueCount);
        $this->assertLessThan(40, $trueCount);
    }

    public function test_percentage_of_operator_uses_flag_key_for_bucketing(): void
    {
        // Different flags with same percentage should bucket users differently
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('flag-a')->andReturn([
            'key' => 'flag-a',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'id', 'operator' => 'percentage_of', 'value' => 50],
                    ],
                    'value' => true,
                ],
            ],
        ]);
        $this->mockCache->shouldReceive('get')->with('flag-b')->andReturn([
            'key' => 'flag-b',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'id', 'operator' => 'percentage_of', 'value' => 50],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        // Run same users through both flags
        $flagAResults = [];
        $flagBResults = [];
        for ($i = 1; $i <= 100; $i++) {
            $context = new Context("user-{$i}", ['id' => "user-{$i}"]);
            $flagAResults[] = $this->featureFlags->active('flag-a', $context) ? 1 : 0;
            $flagBResults[] = $this->featureFlags->active('flag-b', $context) ? 1 : 0;
        }

        // The results should NOT be identical (different bucketing per flag)
        $this->assertNotEquals($flagAResults, $flagBResults);
    }

    public function test_rule_priority_order(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('prioritized-flag')->andReturn([
            'key' => 'prioritized-flag',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 2,
                    'conditions' => [
                        ['trait' => 'role', 'operator' => 'equals', 'value' => 'admin'],
                    ],
                    'value' => 'admin-value',
                ],
                [
                    'priority' => 1, // Higher priority (lower number)
                    'conditions' => [
                        ['trait' => 'role', 'operator' => 'equals', 'value' => 'admin'],
                    ],
                    'value' => 'super-admin-value',
                ],
            ],
        ]);

        $context = new Context('user-1', ['role' => 'admin']);
        $result = $this->featureFlags->value('prioritized-flag', $context);

        // Priority 1 should win
        $this->assertEquals('super-admin-value', $result);
    }

    public function test_percentage_rollout_is_deterministic(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('rollout-flag')->andReturn([
            'key' => 'rollout-flag',
            'enabled' => true,
            'default_value' => true,
            'rollout_percentage' => 50,
            'rules' => [],
        ]);

        $context = new Context('consistent-user-123', []);

        // Same user should always get same result
        $result1 = $this->featureFlags->active('rollout-flag', $context);
        $result2 = $this->featureFlags->active('rollout-flag', $context);
        $result3 = $this->featureFlags->active('rollout-flag', $context);

        $this->assertEquals($result1, $result2);
        $this->assertEquals($result2, $result3);
    }

    public function test_all_returns_all_flags(): void
    {
        $flags = [
            ['key' => 'flag-1', 'enabled' => true],
            ['key' => 'flag-2', 'enabled' => false],
        ];

        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('all')->andReturn($flags);

        $result = $this->featureFlags->all();

        $this->assertCount(2, $result);
        $this->assertEquals('flag-1', $result[0]['key']);
    }

    public function test_sync_fetches_and_caches_flags(): void
    {
        $apiResponse = [
            'flags' => [
                ['key' => 'synced-flag', 'enabled' => true],
            ],
            'segments' => [],
            'cache_ttl' => 600,
        ];

        $this->mockApiClient->shouldReceive('fetchFlags')->once()->andReturn($apiResponse);
        $this->mockCache->shouldReceive('put')->once()->with($apiResponse['flags'], 600);
        $this->mockCache->shouldReceive('putSegments')->once()->with([], 600);

        $this->featureFlags->sync();

        // Mockery verifies the expectations above
        $this->assertTrue(true);
    }

    public function test_flush_clears_cache(): void
    {
        $this->mockCache->shouldReceive('flush')->once();

        $this->featureFlags->flush();

        // Mockery verifies the expectation above
        $this->assertTrue(true);
    }

    public function test_sync_also_caches_segments(): void
    {
        $apiResponse = [
            'flags' => [
                ['key' => 'synced-flag', 'enabled' => true],
            ],
            'segments' => [
                ['key' => 'beta-testers', 'name' => 'Beta Testers', 'rules' => []],
            ],
            'cache_ttl' => 600,
        ];

        $this->mockApiClient->shouldReceive('fetchFlags')->once()->andReturn($apiResponse);
        $this->mockCache->shouldReceive('put')->once()->with($apiResponse['flags'], 600);
        $this->mockCache->shouldReceive('putSegments')->once()->with($apiResponse['segments'], 600);

        $this->featureFlags->sync();

        $this->assertTrue(true);
    }

    public function test_segment_condition_matches_when_user_in_segment(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('segment-feature')->andReturn([
            'key' => 'segment-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['type' => 'segment', 'segment' => 'beta-testers'],
                    ],
                    'value' => true,
                ],
            ],
        ]);
        $this->mockCache->shouldReceive('getSegment')->with('beta-testers')->andReturn([
            'key' => 'beta-testers',
            'name' => 'Beta Testers',
            'rules' => [
                [
                    'conditions' => [
                        ['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro'],
                    ],
                ],
            ],
        ]);

        // User with pro plan should match segment
        $proContext = new Context('user-1', ['plan' => 'pro']);
        $this->assertTrue($this->featureFlags->active('segment-feature', $proContext));

        // User with free plan should not match segment
        $freeContext = new Context('user-2', ['plan' => 'free']);
        $this->assertFalse($this->featureFlags->active('segment-feature', $freeContext));
    }

    public function test_segment_with_multiple_rules_uses_or_logic(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('multi-rule-feature')->andReturn([
            'key' => 'multi-rule-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['type' => 'segment', 'segment' => 'vip-users'],
                    ],
                    'value' => true,
                ],
            ],
        ]);
        $this->mockCache->shouldReceive('getSegment')->with('vip-users')->andReturn([
            'key' => 'vip-users',
            'name' => 'VIP Users',
            'rules' => [
                // Rule 1: Pro plan users
                [
                    'conditions' => [
                        ['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro'],
                    ],
                ],
                // Rule 2: Users with high spend (OR with Rule 1)
                [
                    'conditions' => [
                        ['trait' => 'total_spend', 'operator' => 'gte', 'value' => 1000],
                    ],
                ],
            ],
        ]);

        // Pro user matches Rule 1
        $proContext = new Context('user-1', ['plan' => 'pro', 'total_spend' => 100]);
        $this->assertTrue($this->featureFlags->active('multi-rule-feature', $proContext));

        // High spender matches Rule 2
        $spenderContext = new Context('user-2', ['plan' => 'free', 'total_spend' => 2000]);
        $this->assertTrue($this->featureFlags->active('multi-rule-feature', $spenderContext));

        // Neither pro nor high spender - no match
        $regularContext = new Context('user-3', ['plan' => 'free', 'total_spend' => 50]);
        $this->assertFalse($this->featureFlags->active('multi-rule-feature', $regularContext));
    }

    public function test_segment_with_multiple_conditions_uses_and_logic(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('and-logic-feature')->andReturn([
            'key' => 'and-logic-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['type' => 'segment', 'segment' => 'enterprise-us'],
                    ],
                    'value' => true,
                ],
            ],
        ]);
        $this->mockCache->shouldReceive('getSegment')->with('enterprise-us')->andReturn([
            'key' => 'enterprise-us',
            'name' => 'Enterprise US',
            'rules' => [
                [
                    'conditions' => [
                        // Both conditions must match (AND)
                        ['trait' => 'plan', 'operator' => 'equals', 'value' => 'enterprise'],
                        ['trait' => 'country', 'operator' => 'equals', 'value' => 'US'],
                    ],
                ],
            ],
        ]);

        // Enterprise + US matches
        $matchContext = new Context('user-1', ['plan' => 'enterprise', 'country' => 'US']);
        $this->assertTrue($this->featureFlags->active('and-logic-feature', $matchContext));

        // Enterprise + UK does not match (wrong country)
        $ukContext = new Context('user-2', ['plan' => 'enterprise', 'country' => 'UK']);
        $this->assertFalse($this->featureFlags->active('and-logic-feature', $ukContext));

        // Pro + US does not match (wrong plan)
        $proContext = new Context('user-3', ['plan' => 'pro', 'country' => 'US']);
        $this->assertFalse($this->featureFlags->active('and-logic-feature', $proContext));
    }

    public function test_segment_not_found_returns_false(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('missing-segment-feature')->andReturn([
            'key' => 'missing-segment-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['type' => 'segment', 'segment' => 'non-existent'],
                    ],
                    'value' => true,
                ],
            ],
        ]);
        $this->mockCache->shouldReceive('getSegment')->with('non-existent')->andReturn(null);

        $context = new Context('user-1', ['plan' => 'pro']);
        $this->assertFalse($this->featureFlags->active('missing-segment-feature', $context));
    }

    public function test_empty_segment_key_returns_false(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('empty-segment-feature')->andReturn([
            'key' => 'empty-segment-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['type' => 'segment', 'segment' => ''],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        $context = new Context('user-1', ['plan' => 'pro']);
        $this->assertFalse($this->featureFlags->active('empty-segment-feature', $context));
    }

    public function test_segment_with_empty_rules_returns_false(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('empty-rules-feature')->andReturn([
            'key' => 'empty-rules-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['type' => 'segment', 'segment' => 'empty-segment'],
                    ],
                    'value' => true,
                ],
            ],
        ]);
        $this->mockCache->shouldReceive('getSegment')->with('empty-segment')->andReturn([
            'key' => 'empty-segment',
            'name' => 'Empty Segment',
            'rules' => [],
        ]);

        $context = new Context('user-1', ['plan' => 'pro']);
        $this->assertFalse($this->featureFlags->active('empty-rules-feature', $context));
    }

    public function test_mixed_trait_and_segment_conditions(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('mixed-feature')->andReturn([
            'key' => 'mixed-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        // Must be in beta segment AND have verified email
                        ['type' => 'segment', 'segment' => 'beta-testers'],
                        ['type' => 'trait', 'trait' => 'email_verified', 'operator' => 'equals', 'value' => true],
                    ],
                    'value' => true,
                ],
            ],
        ]);
        $this->mockCache->shouldReceive('getSegment')->with('beta-testers')->andReturn([
            'key' => 'beta-testers',
            'name' => 'Beta Testers',
            'rules' => [
                [
                    'conditions' => [
                        ['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro'],
                    ],
                ],
            ],
        ]);

        // Pro plan + verified email matches both conditions
        $matchContext = new Context('user-1', ['plan' => 'pro', 'email_verified' => true]);
        $this->assertTrue($this->featureFlags->active('mixed-feature', $matchContext));

        // Pro plan but not verified - fails trait condition
        $unverifiedContext = new Context('user-2', ['plan' => 'pro', 'email_verified' => false]);
        $this->assertFalse($this->featureFlags->active('mixed-feature', $unverifiedContext));

        // Verified but free plan - fails segment condition
        $freeContext = new Context('user-3', ['plan' => 'free', 'email_verified' => true]);
        $this->assertFalse($this->featureFlags->active('mixed-feature', $freeContext));
    }

    // Local mode tests
    public function test_local_mode_returns_boolean_flag(): void
    {
        config(['featureflags.local.enabled' => true]);
        config(['featureflags.local.flags' => [
            'new-feature' => true,
            'disabled-feature' => false,
        ]]);

        $localFlags = $this->createFeatureFlags();

        $this->assertTrue($localFlags->isLocalMode());
        $this->assertTrue($localFlags->active('new-feature'));
        $this->assertFalse($localFlags->active('disabled-feature'));
    }

    public function test_local_mode_returns_string_flag_value(): void
    {
        config(['featureflags.local.enabled' => true]);
        config(['featureflags.local.flags' => [
            'welcome-message' => 'Hello, World!',
        ]]);

        $localFlags = $this->createFeatureFlags();

        $this->assertEquals('Hello, World!', $localFlags->value('welcome-message'));
    }

    public function test_local_mode_returns_false_for_unknown_flag(): void
    {
        config(['featureflags.local.enabled' => true]);
        config(['featureflags.local.flags' => [
            'known-feature' => true,
        ]]);

        $localFlags = $this->createFeatureFlags();

        $this->assertFalse($localFlags->active('unknown-feature'));
    }

    public function test_local_mode_supports_rollout_percentage(): void
    {
        config(['featureflags.local.enabled' => true]);
        config(['featureflags.local.flags' => [
            'beta-feature' => ['value' => true, 'rollout' => 50],
        ]]);

        $localFlags = $this->createFeatureFlags();

        // With 50% rollout, some users should get true, some false
        $results = [];
        for ($i = 1; $i <= 100; $i++) {
            $context = new Context("user-{$i}");
            $results[] = $localFlags->active('beta-feature', $context);
        }

        // Should have roughly 50% true (with some variance due to hashing)
        $trueCount = count(array_filter($results));
        $this->assertGreaterThan(30, $trueCount);
        $this->assertLessThan(70, $trueCount);
    }

    public function test_local_mode_skips_api_sync(): void
    {
        config(['featureflags.local.enabled' => true]);
        config(['featureflags.local.flags' => ['test' => true]]);

        // API client should never be called
        $this->mockApiClient->shouldNotReceive('fetchFlags');

        $localFlags = $this->createFeatureFlags();

        $localFlags->sync();
        $result = $localFlags->active('test');

        // Assert that local mode works and the flag value is correct
        $this->assertTrue($result);
        $this->assertTrue($localFlags->isLocalMode());
    }

    public function test_local_mode_all_returns_all_local_flags(): void
    {
        config(['featureflags.local.enabled' => true]);
        config(['featureflags.local.flags' => [
            'flag-a' => true,
            'flag-b' => false,
            'flag-c' => 'custom-value',
        ]]);

        $localFlags = $this->createFeatureFlags();

        $all = $localFlags->all();
        $this->assertCount(3, $all);

        $keys = array_column($all, 'key');
        $this->assertContains('flag-a', $keys);
        $this->assertContains('flag-b', $keys);
        $this->assertContains('flag-c', $keys);
    }

    // Conversion tracking tests
    public function test_track_conversion_records_event(): void
    {
        $this->mockConversions = Mockery::mock(ConversionCollector::class);
        $this->mockConversions->shouldReceive('track')
            ->once()
            ->with('purchase', Mockery::type(Context::class), ['revenue' => 99.99]);

        $featureFlags = $this->createFeatureFlags(stateTracker: $this->stateTracker);

        $context = new Context('user-123');
        $featureFlags->trackConversion('purchase', $context, ['revenue' => 99.99]);

        $this->assertTrue(true);
    }

    public function test_flush_conversions_flushes_collector(): void
    {
        $mockConversions = Mockery::mock(ConversionCollector::class);
        $mockConversions->shouldReceive('track')->andReturnNull();
        $mockConversions->shouldReceive('flush')->once();

        $featureFlags = $this->createFeatureFlags(conversions: $mockConversions, stateTracker: $this->stateTracker);

        $featureFlags->flushConversions();

        $this->assertTrue(true);
    }

    // Monitor tests
    public function test_monitor_returns_callback_result(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('new-algorithm')->andReturn([
            'key' => 'new-algorithm',
            'enabled' => true,
            'default_value' => true,
            'rules' => [],
        ]);

        $result = $this->featureFlags->monitor('new-algorithm', function ($flagValue) {
            return $flagValue ? 'new-result' : 'old-result';
        });

        $this->assertEquals('new-result', $result);
    }

    public function test_monitor_tracks_error_on_exception(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('buggy-feature')->andReturn([
            'key' => 'buggy-feature',
            'enabled' => true,
            'default_value' => true,
            'rules' => [],
        ]);

        $mockErrors = Mockery::mock(ErrorCollector::class);
        $mockErrors->shouldReceive('track')->andReturnNull();
        $mockErrors->shouldReceive('trackAutomatic')
            ->once()
            ->with('buggy-feature', true, Mockery::type(\RuntimeException::class), ['monitored' => true]);

        $featureFlags = $this->createFeatureFlags(errors: $mockErrors, stateTracker: $this->stateTracker);

        try {
            $featureFlags->monitor('buggy-feature', function () {
                throw new \RuntimeException('Something went wrong');
            });
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Something went wrong', $e->getMessage());
        }
    }

    public function test_monitor_rethrows_exception(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('test-flag')->andReturn([
            'key' => 'test-flag',
            'enabled' => true,
            'default_value' => true,
            'rules' => [],
        ]);

        $mockErrors = Mockery::mock(ErrorCollector::class);
        $mockErrors->shouldReceive('track')->andReturnNull();
        $mockErrors->shouldReceive('trackAutomatic');

        $featureFlags = $this->createFeatureFlags(errors: $mockErrors, stateTracker: $this->stateTracker);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Test exception');

        $featureFlags->monitor('test-flag', function () {
            throw new \InvalidArgumentException('Test exception');
        });
    }

    // Error tracking tests
    public function test_track_error_records_to_collector(): void
    {
        $exception = new \RuntimeException('Database connection failed');

        $mockErrors = Mockery::mock(ErrorCollector::class);
        $mockErrors->shouldReceive('track')
            ->once()
            ->with('database-feature', $exception, ['query' => 'SELECT * FROM users']);

        $featureFlags = $this->createFeatureFlags(errors: $mockErrors, stateTracker: $this->stateTracker);

        $featureFlags->trackError('database-feature', $exception, ['query' => 'SELECT * FROM users']);

        $this->assertTrue(true);
    }

    public function test_flush_errors_flushes_collector(): void
    {
        $mockErrors = Mockery::mock(ErrorCollector::class);
        $mockErrors->shouldReceive('track')->andReturnNull();
        $mockErrors->shouldReceive('flush')->once();

        $featureFlags = $this->createFeatureFlags(errors: $mockErrors, stateTracker: $this->stateTracker);

        $featureFlags->flushErrors();

        $this->assertTrue(true);
    }

    // State tracker tests
    public function test_get_evaluated_flags_returns_state_tracker_flags(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('flag-a')->andReturn([
            'key' => 'flag-a',
            'enabled' => true,
            'default_value' => true,
            'rules' => [],
        ]);
        $this->mockCache->shouldReceive('get')->with('flag-b')->andReturn([
            'key' => 'flag-b',
            'enabled' => true,
            'default_value' => 'variant-x',
            'rules' => [],
        ]);

        // Evaluate some flags
        $this->featureFlags->value('flag-a');
        $this->featureFlags->value('flag-b');

        $evaluated = $this->featureFlags->getEvaluatedFlags();

        $this->assertArrayHasKey('flag-a', $evaluated);
        $this->assertArrayHasKey('flag-b', $evaluated);
        $this->assertTrue($evaluated['flag-a']);
        $this->assertEquals('variant-x', $evaluated['flag-b']);
    }

    public function test_get_error_context_returns_formatted_context(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('test-flag')->andReturn([
            'key' => 'test-flag',
            'enabled' => true,
            'default_value' => true,
            'rules' => [],
        ]);

        $this->featureFlags->value('test-flag');

        $context = $this->featureFlags->getErrorContext();

        $this->assertArrayHasKey('flags', $context);
        $this->assertArrayHasKey('count', $context);
        $this->assertArrayHasKey('request_id', $context);
        $this->assertEquals(1, $context['count']);
        $this->assertTrue($context['flags']['test-flag']);
    }

    public function test_get_state_tracker_returns_tracker_instance(): void
    {
        $tracker = $this->featureFlags->getStateTracker();

        $this->assertInstanceOf(FlagStateTracker::class, $tracker);
        $this->assertSame($this->stateTracker, $tracker);
    }

    public function test_reset_state_tracker_clears_tracker(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('tracked-flag')->andReturn([
            'key' => 'tracked-flag',
            'enabled' => true,
            'default_value' => true,
            'rules' => [],
        ]);

        $this->featureFlags->value('tracked-flag');
        $this->assertEquals(1, $this->stateTracker->count());

        $this->featureFlags->resetStateTracker();

        $this->assertEquals(0, $this->stateTracker->count());
    }

    public function test_sync_throws_exception_on_failure_when_configured(): void
    {
        config(['featureflags.fallback.behavior' => 'exception']);

        $this->mockApiClient->shouldReceive('fetchFlags')
            ->andThrow(new \FeatureFlags\Exceptions\ApiException('API Error'));
        $this->mockCache->shouldReceive('has')->andReturn(false);

        $this->expectException(\FeatureFlags\Exceptions\FlagSyncException::class);
        $this->expectExceptionMessage('Failed to sync feature flags');

        $this->featureFlags->sync();
    }

    public function test_sync_continues_silently_when_cache_fallback_behavior(): void
    {
        config(['featureflags.fallback.behavior' => 'cache']);

        $this->mockApiClient->shouldReceive('fetchFlags')
            ->andThrow(new \FeatureFlags\Exceptions\ApiException('API Error'));
        $this->mockCache->shouldReceive('has')->andReturn(false);

        // Should not throw
        $this->featureFlags->sync();
        $this->assertTrue(true);
    }

    public function test_flag_dependency_evaluation(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('dependent-flag')->andReturn([
            'key' => 'dependent-flag',
            'enabled' => true,
            'default_value' => 'new-feature',
            'dependencies' => [
                ['flag_key' => 'parent-flag', 'required_value' => true],
            ],
            'rules' => [],
        ]);
        $this->mockCache->shouldReceive('get')->with('parent-flag')->andReturn([
            'key' => 'parent-flag',
            'enabled' => true,
            'default_value' => true,
            'rules' => [],
        ]);

        $result = $this->featureFlags->value('dependent-flag');
        $this->assertEquals('new-feature', $result);
    }

    public function test_flag_dependency_not_satisfied(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('dependent-flag')->andReturn([
            'key' => 'dependent-flag',
            'enabled' => true,
            'default_value' => 'fallback-value',
            'dependencies' => [
                ['flag_key' => 'parent-flag', 'required_value' => true],
            ],
            'rules' => [],
        ]);
        $this->mockCache->shouldReceive('get')->with('parent-flag')->andReturn([
            'key' => 'parent-flag',
            'enabled' => true,
            'default_value' => false, // Returns false, not true
            'rules' => [],
        ]);

        // Should return default because dependency is not satisfied
        $result = $this->featureFlags->value('dependent-flag');
        $this->assertEquals('fallback-value', $result);
    }

    public function test_circular_dependency_returns_default(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('flag-a')->andReturn([
            'key' => 'flag-a',
            'enabled' => true,
            'default_value' => 'a-default',
            'dependencies' => [
                ['flag_key' => 'flag-b', 'required_value' => true],
            ],
            'rules' => [],
        ]);
        $this->mockCache->shouldReceive('get')->with('flag-b')->andReturn([
            'key' => 'flag-b',
            'enabled' => true,
            'default_value' => true,
            'dependencies' => [
                ['flag_key' => 'flag-a', 'required_value' => 'a-default'], // Circular!
            ],
            'rules' => [],
        ]);

        // Should not hang and should return default value
        $result = $this->featureFlags->value('flag-a');
        $this->assertEquals('a-default', $result);
    }

    public function test_dependency_flag_not_found_returns_default(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('dependent-flag')->andReturn([
            'key' => 'dependent-flag',
            'enabled' => true,
            'default_value' => 'fallback',
            'dependencies' => [
                ['flag_key' => 'missing-flag', 'required_value' => true],
            ],
            'rules' => [],
        ]);
        $this->mockCache->shouldReceive('get')->with('missing-flag')->andReturn(null);

        $result = $this->featureFlags->value('dependent-flag');
        $this->assertEquals('fallback', $result);
    }

    public function test_normalize_context_with_array_without_id(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('test-flag')->andReturn([
            'key' => 'test-flag',
            'enabled' => true,
            'default_value' => 'default-value',
            'rules' => [],
        ]);

        // Array without 'id' key should result in null context
        $result = $this->featureFlags->value('test-flag', ['plan' => 'pro']);
        $this->assertEquals('default-value', $result);
    }

    public function test_normalize_context_with_id_and_traits(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('rule-flag')->andReturn([
            'key' => 'rule-flag',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro'],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        // Array with 'id' and 'traits' should work
        $result = $this->featureFlags->active('rule-flag', ['id' => 'user-1', 'traits' => ['plan' => 'pro']]);
        $this->assertTrue($result);
    }

    public function test_fallback_behavior_default_returns_synthetic_flag(): void
    {
        config(['featureflags.fallback.behavior' => 'default']);
        config(['featureflags.fallback.default_value' => 'synthetic-default']);

        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('non-existent-flag')->andReturn(null);

        $result = $this->featureFlags->value('non-existent-flag');
        $this->assertEquals('synthetic-default', $result);
    }

    public function test_rollout_miss_returns_false(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        // Use a rollout of 0% so no one is included
        $this->mockCache->shouldReceive('get')->with('zero-rollout-flag')->andReturn([
            'key' => 'zero-rollout-flag',
            'enabled' => true,
            'default_value' => 'should-not-see-this',
            'rollout_percentage' => 0,
            'rules' => [],
        ]);

        $context = new Context('user-123', []);
        $result = $this->featureFlags->value('zero-rollout-flag', $context);

        // With 0% rollout, result should be false
        $this->assertFalse($result);
    }

    public function test_not_equals_operator(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('not-equals-flag')->andReturn([
            'key' => 'not-equals-flag',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'plan', 'operator' => 'not_equals', 'value' => 'free'],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        $proContext = new Context('user-1', ['plan' => 'pro']);
        $this->assertTrue($this->featureFlags->active('not-equals-flag', $proContext));

        $freeContext = new Context('user-2', ['plan' => 'free']);
        $this->assertFalse($this->featureFlags->active('not-equals-flag', $freeContext));
    }

    public function test_not_contains_operator(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('not-contains-flag')->andReturn([
            'key' => 'not-contains-flag',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'email', 'operator' => 'not_contains', 'value' => '@competitor.com'],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        $ourUser = new Context('user-1', ['email' => 'john@ourcompany.com']);
        $this->assertTrue($this->featureFlags->active('not-contains-flag', $ourUser));

        $competitorUser = new Context('user-2', ['email' => 'spy@competitor.com']);
        $this->assertFalse($this->featureFlags->active('not-contains-flag', $competitorUser));
    }

    public function test_not_in_operator(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('not-in-flag')->andReturn([
            'key' => 'not-in-flag',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'country', 'operator' => 'not_in', 'value' => ['RU', 'CN', 'KP']],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        $usUser = new Context('user-1', ['country' => 'US']);
        $this->assertTrue($this->featureFlags->active('not-in-flag', $usUser));

        $blockedUser = new Context('user-2', ['country' => 'RU']);
        $this->assertFalse($this->featureFlags->active('not-in-flag', $blockedUser));
    }

    public function test_gt_gte_lte_operators(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('gt-flag')->andReturn([
            'key' => 'gt-flag',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'age', 'operator' => 'gt', 'value' => 18],
                    ],
                    'value' => true,
                ],
            ],
        ]);
        $this->mockCache->shouldReceive('get')->with('gte-flag')->andReturn([
            'key' => 'gte-flag',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'age', 'operator' => 'gte', 'value' => 18],
                    ],
                    'value' => true,
                ],
            ],
        ]);
        $this->mockCache->shouldReceive('get')->with('lte-flag')->andReturn([
            'key' => 'lte-flag',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'age', 'operator' => 'lte', 'value' => 65],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        $adult = new Context('user-1', ['age' => 25]);
        $this->assertTrue($this->featureFlags->active('gt-flag', $adult));
        $this->assertTrue($this->featureFlags->active('gte-flag', $adult));
        $this->assertTrue($this->featureFlags->active('lte-flag', $adult));

        $teenager = new Context('user-2', ['age' => 18]);
        $this->assertFalse($this->featureFlags->active('gt-flag', $teenager)); // 18 is not > 18
        $this->assertTrue($this->featureFlags->active('gte-flag', $teenager)); // 18 >= 18
    }

    public function test_semver_gt_lte_operators(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('semver-gt-flag')->andReturn([
            'key' => 'semver-gt-flag',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'app_version', 'operator' => 'semver_gt', 'value' => '2.0.0'],
                    ],
                    'value' => true,
                ],
            ],
        ]);
        $this->mockCache->shouldReceive('get')->with('semver-lte-flag')->andReturn([
            'key' => 'semver-lte-flag',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'app_version', 'operator' => 'semver_lte', 'value' => '2.0.0'],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        $v21 = new Context('user-1', ['app_version' => '2.1.0']);
        $this->assertTrue($this->featureFlags->active('semver-gt-flag', $v21));
        $this->assertFalse($this->featureFlags->active('semver-lte-flag', $v21));

        $v20 = new Context('user-2', ['app_version' => '2.0.0']);
        $this->assertFalse($this->featureFlags->active('semver-gt-flag', $v20));
        $this->assertTrue($this->featureFlags->active('semver-lte-flag', $v20));
    }

    public function test_unknown_operator_returns_false(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('unknown-op-flag')->andReturn([
            'key' => 'unknown-op-flag',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'plan', 'operator' => 'foobar', 'value' => 'pro'],
                    ],
                    'value' => true,
                ],
            ],
        ]);

        $context = new Context('user-1', ['plan' => 'pro']);
        $this->assertFalse($this->featureFlags->active('unknown-op-flag', $context));
    }

    public function test_segment_rule_with_empty_conditions(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('empty-conditions-feature')->andReturn([
            'key' => 'empty-conditions-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['type' => 'segment', 'segment' => 'empty-conditions-segment'],
                    ],
                    'value' => true,
                ],
            ],
        ]);
        $this->mockCache->shouldReceive('getSegment')->with('empty-conditions-segment')->andReturn([
            'key' => 'empty-conditions-segment',
            'name' => 'Empty Conditions',
            'rules' => [
                [
                    'conditions' => [], // Empty conditions
                ],
            ],
        ]);

        $context = new Context('user-1', ['plan' => 'pro']);
        $this->assertFalse($this->featureFlags->active('empty-conditions-feature', $context));
    }

    public function test_segment_trait_condition_with_empty_trait(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('empty-trait-feature')->andReturn([
            'key' => 'empty-trait-feature',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['type' => 'segment', 'segment' => 'empty-trait-segment'],
                    ],
                    'value' => true,
                ],
            ],
        ]);
        $this->mockCache->shouldReceive('getSegment')->with('empty-trait-segment')->andReturn([
            'key' => 'empty-trait-segment',
            'name' => 'Empty Trait',
            'rules' => [
                [
                    'conditions' => [
                        ['trait' => '', 'operator' => 'equals', 'value' => 'pro'], // Empty trait
                    ],
                ],
            ],
        ]);

        $context = new Context('user-1', ['plan' => 'pro']);
        $this->assertFalse($this->featureFlags->active('empty-trait-feature', $context));
    }

    protected function tearDown(): void
    {
        // Reset local mode config
        config(['featureflags.local.enabled' => false]);
        config(['featureflags.local.flags' => []]);
        config(['featureflags.fallback.behavior' => 'cache']);

        Mockery::close();
        parent::tearDown();
    }
}
