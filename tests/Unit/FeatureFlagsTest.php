<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Cache\FlagCache;
use FeatureFlags\Client\ApiClient;
use FeatureFlags\Context;
use FeatureFlags\Context\RequestContext;
use FeatureFlags\ContextResolver;
use FeatureFlags\FeatureFlags;
use FeatureFlags\Telemetry\ConversionCollector;
use FeatureFlags\Telemetry\ErrorCollector;
use FeatureFlags\Telemetry\FlagStateTracker;
use FeatureFlags\Telemetry\TelemetryCollector;
use FeatureFlags\Tests\CreatesFeatureFlags;
use FeatureFlags\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

class FeatureFlagsTest extends TestCase
{
    use CreatesFeatureFlags;

    private FeatureFlags $featureFlags;
    private ApiClient&MockInterface $mockApiClient;
    private FlagCache&MockInterface $mockCache;
    private TelemetryCollector&MockInterface $mockTelemetry;
    private ConversionCollector&MockInterface $mockConversions;
    private ErrorCollector&MockInterface $mockErrors;
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

        $this->featureFlags = $this->createFeatureFlagsInstance(
            apiClient: $this->mockApiClient,
            cache: $this->mockCache,
            telemetry: $this->mockTelemetry,
            conversions: $this->mockConversions,
            errors: $this->mockErrors,
            stateTracker: $this->stateTracker,
        );
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
        return $this->createFeatureFlagsInstance(
            apiClient: $apiClient ?? $this->mockApiClient,
            cache: $cache ?? $this->mockCache,
            contextResolver: $contextResolver,
            telemetry: $telemetry ?? $this->mockTelemetry,
            conversions: $conversions ?? $this->mockConversions,
            errors: $errors ?? $this->mockErrors,
            stateTracker: $stateTracker,
            cacheEnabled: $cacheEnabled,
        );
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

    public function test_value_returns_string_value(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('variant-flag')->andReturn([
            'key' => 'variant-flag',
            'enabled' => true,
            'default_value' => 'variant-a',
            'rules' => [],
        ]);

        $result = $this->featureFlags->value('variant-flag');

        $this->assertEquals('variant-a', $result);
    }

    public function test_value_returns_numeric_value(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('numeric-flag')->andReturn([
            'key' => 'numeric-flag',
            'enabled' => true,
            'default_value' => 42,
            'rules' => [],
        ]);

        $result = $this->featureFlags->value('numeric-flag');

        $this->assertEquals(42, $result);
    }

    public function test_evaluates_rule_with_context(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('rule-flag')->andReturn([
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
        ]);

        $context = new Context('user-123', ['plan' => 'pro']);
        $result = $this->featureFlags->value('rule-flag', $context);

        $this->assertEquals('pro-value', $result);
    }

    public function test_returns_default_when_rule_does_not_match(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('rule-flag')->andReturn([
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
        ]);

        $context = new Context('user-123', ['plan' => 'free']);
        $result = $this->featureFlags->value('rule-flag', $context);

        $this->assertEquals('default', $result);
    }

    public function test_sync_fetches_flags_from_api(): void
    {
        $this->mockApiClient->shouldReceive('fetchFlags')->once()->andReturn([
            'flags' => [['key' => 'test-flag', 'enabled' => true]],
            'segments' => [],
            'cache_ttl' => 300,
        ]);
        $this->mockCache->shouldReceive('put')->once();
        $this->mockCache->shouldReceive('putSegments')->once();

        $this->featureFlags->sync();

        $this->addToAssertionCount(1); // Mockery expectations verify behavior
    }

    public function test_all_returns_cached_flags(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('all')->andReturn([
            ['key' => 'flag-1', 'enabled' => true],
            ['key' => 'flag-2', 'enabled' => false],
        ]);

        $result = $this->featureFlags->all();

        $this->assertCount(2, $result);
        $this->assertEquals('flag-1', $result[0]['key']);
    }

    public function test_accepts_context_object(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->andReturn([
            'key' => 'test',
            'enabled' => true,
            'default_value' => true,
            'rules' => [],
        ]);

        $context = new Context('user-123', ['plan' => 'pro']);
        $result = $this->featureFlags->active('test', $context);

        $this->assertTrue($result);
    }

    public function test_accepts_array_context(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->andReturn([
            'key' => 'test',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro']],
                    'value' => 'matched',
                ],
            ],
        ]);

        $result = $this->featureFlags->value('test', ['id' => 'user-1', 'plan' => 'pro']);

        $this->assertEquals('matched', $result);
    }

    public function test_tracks_conversion(): void
    {
        $mockConversions = Mockery::mock(ConversionCollector::class);
        $mockConversions->shouldReceive('track')
            ->once()
            ->withArgs(function ($event, $context, $props) {
                return $event === 'purchase' && $props['amount'] === 99.99;
            });

        $ff = $this->createFeatureFlags(conversions: $mockConversions);

        $ff->trackConversion('purchase', null, ['amount' => 99.99]);

        $this->addToAssertionCount(1); // Mockery expectations verify behavior
    }

    public function test_flush_conversions(): void
    {
        $mockConversions = Mockery::mock(ConversionCollector::class);
        $mockConversions->shouldReceive('track')->andReturnNull();
        $mockConversions->shouldReceive('flush')->once();

        $ff = $this->createFeatureFlags(conversions: $mockConversions);

        $ff->flushConversions();

        $this->addToAssertionCount(1); // Mockery expectations verify behavior
    }

    public function test_records_evaluated_flags(): void
    {
        $stateTracker = new FlagStateTracker();

        $mockCache = Mockery::mock(FlagCache::class);
        $mockCache->shouldReceive('has')->andReturn(true);
        $mockCache->shouldReceive('get')->with('flag-1')->andReturn([
            'key' => 'flag-1',
            'enabled' => true,
            'default_value' => true,
            'rules' => [],
        ]);
        $mockCache->shouldReceive('get')->with('flag-2')->andReturn([
            'key' => 'flag-2',
            'enabled' => true,
            'default_value' => 'variant-b',
            'rules' => [],
        ]);

        $ff = $this->createFeatureFlags(cache: $mockCache, stateTracker: $stateTracker);

        $ff->active('flag-1');
        $ff->value('flag-2');

        $evaluated = $ff->getEvaluatedFlags();

        $this->assertArrayHasKey('flag-1', $evaluated);
        $this->assertArrayHasKey('flag-2', $evaluated);
        $this->assertTrue($evaluated['flag-1']);
        $this->assertEquals('variant-b', $evaluated['flag-2']);
    }

    public function test_get_error_context_returns_structured_data(): void
    {
        RequestContext::initialize();

        $stateTracker = new FlagStateTracker();

        $mockCache = Mockery::mock(FlagCache::class);
        $mockCache->shouldReceive('has')->andReturn(true);
        $mockCache->shouldReceive('get')->with('my-flag')->andReturn([
            'key' => 'my-flag',
            'enabled' => true,
            'default_value' => true,
            'rules' => [],
        ]);

        $ff = $this->createFeatureFlags(cache: $mockCache, stateTracker: $stateTracker);

        $ff->active('my-flag');

        $context = $ff->getErrorContext();

        $this->assertArrayHasKey('flags', $context);
        $this->assertArrayHasKey('count', $context);
        $this->assertArrayHasKey('request_id', $context);
        $this->assertEquals(1, $context['count']);
        $this->assertNotNull($context['request_id']);

        RequestContext::reset();
    }

    public function test_reset_state_tracker(): void
    {
        $stateTracker = new FlagStateTracker();

        $mockCache = Mockery::mock(FlagCache::class);
        $mockCache->shouldReceive('has')->andReturn(true);
        $mockCache->shouldReceive('get')->andReturn([
            'key' => 'test',
            'enabled' => true,
            'default_value' => true,
            'rules' => [],
        ]);

        $ff = $this->createFeatureFlags(cache: $mockCache, stateTracker: $stateTracker);

        $ff->active('test');
        $this->assertNotEmpty($ff->getEvaluatedFlags());

        $ff->resetStateTracker();
        $this->assertEmpty($ff->getEvaluatedFlags());
    }

    public function test_get_state_tracker_returns_tracker(): void
    {
        $tracker = $this->featureFlags->getStateTracker();

        $this->assertInstanceOf(FlagStateTracker::class, $tracker);
    }

    public function test_evaluates_not_equals_operator(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->andReturn([
            'key' => 'test',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [['trait' => 'plan', 'operator' => 'not_equals', 'value' => 'free']],
                    'value' => 'paid',
                ],
            ],
        ]);

        $context = new Context('user-123', ['plan' => 'pro']);
        $result = $this->featureFlags->value('test', $context);

        $this->assertEquals('paid', $result);
    }

    public function test_evaluates_in_operator(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->andReturn([
            'key' => 'test',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [['trait' => 'country', 'operator' => 'in', 'value' => ['US', 'CA', 'UK']]],
                    'value' => 'regional',
                ],
            ],
        ]);

        $context = new Context('user-123', ['country' => 'CA']);
        $result = $this->featureFlags->value('test', $context);

        $this->assertEquals('regional', $result);
    }

    public function test_evaluates_not_in_operator(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->andReturn([
            'key' => 'test',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [['trait' => 'country', 'operator' => 'not_in', 'value' => ['US', 'CA']]],
                    'value' => 'international',
                ],
            ],
        ]);

        $context = new Context('user-123', ['country' => 'DE']);
        $result = $this->featureFlags->value('test', $context);

        $this->assertEquals('international', $result);
    }

    public function test_evaluates_contains_operator(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->andReturn([
            'key' => 'test',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [['trait' => 'email', 'operator' => 'contains', 'value' => '@company.com']],
                    'value' => 'internal',
                ],
            ],
        ]);

        $context = new Context('user-123', ['email' => 'john@company.com']);
        $result = $this->featureFlags->value('test', $context);

        $this->assertEquals('internal', $result);
    }

    public function test_evaluates_starts_with_operator(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->andReturn([
            'key' => 'test',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [['trait' => 'email', 'operator' => 'starts_with', 'value' => 'admin']],
                    'value' => 'admin-user',
                ],
            ],
        ]);

        $context = new Context('user-123', ['email' => 'admin@example.com']);
        $result = $this->featureFlags->value('test', $context);

        $this->assertEquals('admin-user', $result);
    }

    public function test_evaluates_ends_with_operator(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->andReturn([
            'key' => 'test',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [['trait' => 'email', 'operator' => 'ends_with', 'value' => '.edu']],
                    'value' => 'student',
                ],
            ],
        ]);

        $context = new Context('user-123', ['email' => 'student@university.edu']);
        $result = $this->featureFlags->value('test', $context);

        $this->assertEquals('student', $result);
    }

    public function test_evaluates_gt_operator(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->andReturn([
            'key' => 'test',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [['trait' => 'age', 'operator' => 'gt', 'value' => 18]],
                    'value' => 'adult',
                ],
            ],
        ]);

        $context = new Context('user-123', ['age' => 21]);
        $result = $this->featureFlags->value('test', $context);

        $this->assertEquals('adult', $result);
    }

    public function test_evaluates_percentage_of_operator(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->andReturn([
            'key' => 'test',
            'enabled' => true,
            'default_value' => false,
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [['trait' => 'id', 'operator' => 'percentage_of', 'value' => 100]],
                    'value' => true,
                ],
            ],
        ]);

        $context = new Context('any-user', ['id' => 'any-user']);
        $result = $this->featureFlags->active('test', $context);

        $this->assertTrue($result);
    }

    public function test_evaluates_semver_gte_operator(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->andReturn([
            'key' => 'test',
            'enabled' => true,
            'default_value' => 'old',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [['trait' => 'app_version', 'operator' => 'semver_gte', 'value' => '2.0.0']],
                    'value' => 'new',
                ],
            ],
        ]);

        $context = new Context('user-123', ['app_version' => '2.1.0']);
        $result = $this->featureFlags->value('test', $context);

        $this->assertEquals('new', $result);
    }

    public function test_evaluates_before_date_operator(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->andReturn([
            'key' => 'test',
            'enabled' => true,
            'default_value' => 'new-user',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [['trait' => 'created_at', 'operator' => 'before_date', 'value' => '2024-01-01']],
                    'value' => 'legacy-user',
                ],
            ],
        ]);

        $context = new Context('user-123', ['created_at' => '2023-06-15']);
        $result = $this->featureFlags->value('test', $context);

        $this->assertEquals('legacy-user', $result);
    }

    public function test_evaluates_segment_condition(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('test')->andReturn([
            'key' => 'test',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [['type' => 'segment', 'segment' => 'beta-users']],
                    'value' => 'beta',
                ],
            ],
        ]);
        $this->mockCache->shouldReceive('getSegment')->with('beta-users')->andReturn([
            'key' => 'beta-users',
            'rules' => [
                [
                    'conditions' => [['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro']],
                ],
            ],
        ]);

        $context = new Context('user-123', ['plan' => 'pro']);
        $result = $this->featureFlags->value('test', $context);

        $this->assertEquals('beta', $result);
    }

    public function test_segment_with_multiple_rules_uses_or_logic(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('test')->andReturn([
            'key' => 'test',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [['type' => 'segment', 'segment' => 'multi-rule-segment']],
                    'value' => 'matched',
                ],
            ],
        ]);
        $this->mockCache->shouldReceive('getSegment')->with('multi-rule-segment')->andReturn([
            'key' => 'multi-rule-segment',
            'rules' => [
                // Rule 1: plan = enterprise (won't match)
                ['conditions' => [['trait' => 'plan', 'operator' => 'equals', 'value' => 'enterprise']]],
                // Rule 2: country = US (will match) - OR logic means this is enough
                ['conditions' => [['trait' => 'country', 'operator' => 'equals', 'value' => 'US']]],
            ],
        ]);

        $context = new Context('user-123', ['plan' => 'free', 'country' => 'US']);
        $result = $this->featureFlags->value('test', $context);

        $this->assertEquals('matched', $result);
    }

    public function test_segment_with_multiple_conditions_uses_and_logic(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('test')->andReturn([
            'key' => 'test',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [['type' => 'segment', 'segment' => 'and-segment']],
                    'value' => 'matched',
                ],
            ],
        ]);
        $this->mockCache->shouldReceive('getSegment')->with('and-segment')->andReturn([
            'key' => 'and-segment',
            'rules' => [
                // Single rule with multiple conditions - ALL must match (AND logic)
                [
                    'conditions' => [
                        ['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro'],
                        ['trait' => 'country', 'operator' => 'equals', 'value' => 'US'],
                    ],
                ],
            ],
        ]);

        // Only plan matches, country doesn't - should NOT match
        $context = new Context('user-123', ['plan' => 'pro', 'country' => 'CA']);
        $result = $this->featureFlags->value('test', $context);

        $this->assertEquals('default', $result);
    }

    public function test_segment_with_empty_rules_returns_false(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('test')->andReturn([
            'key' => 'test',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [['type' => 'segment', 'segment' => 'empty-segment']],
                    'value' => 'matched',
                ],
            ],
        ]);
        $this->mockCache->shouldReceive('getSegment')->with('empty-segment')->andReturn([
            'key' => 'empty-segment',
            'rules' => [],
        ]);

        $context = new Context('user-123', ['plan' => 'pro']);
        $result = $this->featureFlags->value('test', $context);

        $this->assertEquals('default', $result);
    }

    public function test_mixed_trait_and_segment_conditions(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('test')->andReturn([
            'key' => 'test',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'age', 'operator' => 'gte', 'value' => 18],
                        ['type' => 'segment', 'segment' => 'beta-users'],
                    ],
                    'value' => 'matched',
                ],
            ],
        ]);
        $this->mockCache->shouldReceive('getSegment')->with('beta-users')->andReturn([
            'key' => 'beta-users',
            'rules' => [
                ['conditions' => [['trait' => 'beta_opt_in', 'operator' => 'equals', 'value' => true]]],
            ],
        ]);

        // Both trait (age >= 18) and segment (beta_opt_in = true) must match
        $context = new Context('user-123', ['age' => 25, 'beta_opt_in' => true]);
        $result = $this->featureFlags->value('test', $context);

        $this->assertEquals('matched', $result);
    }

    public function test_rules_with_same_priority_maintain_order(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->andReturn([
            'key' => 'test',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [['trait' => 'tier', 'operator' => 'equals', 'value' => 'gold']],
                    'value' => 'first-rule',
                ],
                [
                    'priority' => 1,
                    'conditions' => [['trait' => 'tier', 'operator' => 'equals', 'value' => 'gold']],
                    'value' => 'second-rule',
                ],
            ],
        ]);

        $context = new Context('user-123', ['tier' => 'gold']);

        // Run multiple times to ensure deterministic behavior
        for ($i = 0; $i < 5; $i++) {
            $result = $this->featureFlags->value('test', $context);
            $this->assertEquals('first-rule', $result, 'Rules with same priority should maintain original order');
        }
    }

    public function test_evaluates_rollout_percentage(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->andReturn([
            'key' => 'test',
            'enabled' => true,
            'default_value' => true,
            'rollout_percentage' => 100,
            'rules' => [],
        ]);

        $context = new Context('any-user', []);
        $result = $this->featureFlags->active('test', $context);

        $this->assertTrue($result);
    }

    public function test_rollout_percentage_zero_disables(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->andReturn([
            'key' => 'test',
            'enabled' => true,
            'default_value' => true,
            'rollout_percentage' => 0,
            'rules' => [],
        ]);

        $context = new Context('any-user', []);
        $result = $this->featureFlags->active('test', $context);

        $this->assertFalse($result);
    }

    public function test_evaluates_flag_dependencies(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('parent-flag')->andReturn([
            'key' => 'parent-flag',
            'enabled' => true,
            'default_value' => true,
            'rules' => [],
        ]);
        $this->mockCache->shouldReceive('get')->with('child-flag')->andReturn([
            'key' => 'child-flag',
            'enabled' => true,
            'default_value' => 'child-value',
            'dependencies' => [
                ['flag_key' => 'parent-flag', 'required_value' => true],
            ],
            'rules' => [],
        ]);

        $result = $this->featureFlags->value('child-flag');

        $this->assertEquals('child-value', $result);
    }

    public function test_dependency_not_met_returns_default_value(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('parent-flag')->andReturn([
            'key' => 'parent-flag',
            'enabled' => true,
            'default_value' => false,
            'rules' => [],
        ]);
        $this->mockCache->shouldReceive('get')->with('child-flag')->andReturn([
            'key' => 'child-flag',
            'enabled' => true,
            'default_value' => 'child-value',
            'dependencies' => [
                ['flag_key' => 'parent-flag', 'required_value' => true],
            ],
            'rules' => [],
        ]);

        $result = $this->featureFlags->value('child-flag');

        // When dependency is not met, returns the flag's default_value
        $this->assertEquals('child-value', $result);
    }

    public function test_rules_sorted_by_priority(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->andReturn([
            'key' => 'test',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 2,
                    'conditions' => [['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro']],
                    'value' => 'priority-2',
                ],
                [
                    'priority' => 1,
                    'conditions' => [['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro']],
                    'value' => 'priority-1',
                ],
            ],
        ]);

        $context = new Context('user-123', ['plan' => 'pro']);
        $result = $this->featureFlags->value('test', $context);

        $this->assertEquals('priority-1', $result);
    }

    public function test_multiple_conditions_require_all_to_match(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->andReturn([
            'key' => 'test',
            'enabled' => true,
            'default_value' => 'default',
            'rules' => [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro'],
                        ['trait' => 'country', 'operator' => 'equals', 'value' => 'US'],
                    ],
                    'value' => 'matched',
                ],
            ],
        ]);

        $context = new Context('user-123', ['plan' => 'pro', 'country' => 'CA']);
        $result = $this->featureFlags->value('test', $context);

        $this->assertEquals('default', $result);
    }

    public function test_flush_clears_cache(): void
    {
        $this->mockCache->shouldReceive('flush')->once();

        $this->featureFlags->flush();

        $this->addToAssertionCount(1); // Mockery expectations verify behavior
    }

    public function test_is_local_mode_returns_false_by_default(): void
    {
        $this->assertFalse($this->featureFlags->isLocalMode());
    }

    public function test_local_mode_returns_configured_values(): void
    {
        config(['featureflags.local.enabled' => true]);
        config(['featureflags.local.flags' => [
            'local-flag' => true,
            'variant-flag' => 'variant-b',
        ]]);

        $ff = $this->createFeatureFlags();

        $this->assertTrue($ff->isLocalMode());
        $this->assertTrue($ff->active('local-flag'));
        $this->assertEquals('variant-b', $ff->value('variant-flag'));
    }

    public function test_local_mode_with_array_config(): void
    {
        config(['featureflags.local.enabled' => true]);
        config(['featureflags.local.flags' => [
            'complex-flag' => [
                'value' => 'complex-value',
                'rollout' => 100,
            ],
        ]]);

        $ff = $this->createFeatureFlags();

        $result = $ff->value('complex-flag');

        $this->assertEquals('complex-value', $result);
    }

    public function test_monitor_tracks_error_on_exception(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->andReturn([
            'key' => 'test-flag',
            'enabled' => true,
            'default_value' => true,
            'rules' => [],
        ]);

        $mockErrors = Mockery::mock(ErrorCollector::class);
        $mockErrors->shouldReceive('trackAutomatic')
            ->once()
            ->withArgs(function ($flagKey, $flagValue, $exception, $metadata) {
                return $flagKey === 'test-flag'
                    && $flagValue === true
                    && $exception instanceof \RuntimeException
                    && $metadata['monitored'] === true;
            });

        $ff = $this->createFeatureFlags(errors: $mockErrors);

        $this->expectException(\RuntimeException::class);

        $ff->monitor('test-flag', function ($isEnabled) {
            throw new \RuntimeException('Something went wrong');
        });
    }

    public function test_monitor_returns_callback_result(): void
    {
        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->andReturn([
            'key' => 'test-flag',
            'enabled' => true,
            'default_value' => 'variant-a',
            'rules' => [],
        ]);

        $mockErrors = Mockery::mock(ErrorCollector::class);
        $mockErrors->shouldNotReceive('trackAutomatic');

        $ff = $this->createFeatureFlags(errors: $mockErrors);

        $result = $ff->monitor('test-flag', function ($flagValue) {
            return "Result with {$flagValue}";
        });

        $this->assertEquals('Result with variant-a', $result);
    }

    public function test_track_error_records_error(): void
    {
        $mockErrors = Mockery::mock(ErrorCollector::class);
        $mockErrors->shouldReceive('track')
            ->once()
            ->withArgs(function ($flagKey, $exception, $metadata) {
                return $flagKey === 'my-flag'
                    && $exception instanceof \Exception
                    && $metadata['custom'] === 'data';
            });

        $ff = $this->createFeatureFlags(errors: $mockErrors);

        $ff->trackError('my-flag', new \Exception('Test error'), ['custom' => 'data']);

        $this->addToAssertionCount(1); // Mockery expectations verify behavior
    }

    public function test_flush_errors(): void
    {
        $mockErrors = Mockery::mock(ErrorCollector::class);
        $mockErrors->shouldReceive('flush')->once();

        $ff = $this->createFeatureFlags(errors: $mockErrors);

        $ff->flushErrors();

        $this->addToAssertionCount(1); // Mockery expectations verify behavior
    }

    public function test_flush_all_telemetry_and_reset(): void
    {
        RequestContext::initialize();
        $requestId = RequestContext::getRequestId();

        $stateTracker = new FlagStateTracker();
        $stateTracker->record('test-flag', true, null);

        $mockTelemetry = Mockery::mock(TelemetryCollector::class);
        $mockTelemetry->shouldReceive('record')->andReturnNull();
        $mockTelemetry->shouldReceive('flush')->once();

        $mockConversions = Mockery::mock(ConversionCollector::class);
        $mockConversions->shouldReceive('flush')->once();

        $mockErrors = Mockery::mock(ErrorCollector::class);
        $mockErrors->shouldReceive('flush')->once();

        $ff = $this->createFeatureFlags(
            telemetry: $mockTelemetry,
            conversions: $mockConversions,
            errors: $mockErrors,
            stateTracker: $stateTracker,
        );

        $this->assertNotEmpty($ff->getEvaluatedFlags());
        $this->assertNotNull(RequestContext::getRequestId());

        $ff->flushAllTelemetryAndReset();

        $this->assertEmpty($ff->getEvaluatedFlags());
        $this->assertNull(RequestContext::getRequestId());
    }

    public function test_auto_syncs_when_cache_empty(): void
    {
        $mockCache = Mockery::mock(FlagCache::class);
        $mockCache->shouldReceive('has')->once()->andReturn(false);
        $mockCache->shouldReceive('put')->once();
        $mockCache->shouldReceive('putSegments')->once();
        $mockCache->shouldReceive('get')->with('test-flag')->andReturn([
            'key' => 'test-flag',
            'enabled' => true,
            'default_value' => true,
            'rules' => [],
        ]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('fetchFlags')->once()->andReturn([
            'flags' => [['key' => 'test-flag', 'enabled' => true]],
            'segments' => [],
            'cache_ttl' => 300,
        ]);

        $ff = $this->createFeatureFlags(
            apiClient: $mockApiClient,
            cache: $mockCache,
        );

        $result = $ff->active('test-flag');

        $this->assertTrue($result);
    }

    public function test_respects_cache_enabled_setting(): void
    {
        $mockCache = Mockery::mock(FlagCache::class);
        $mockCache->shouldReceive('has')->never();
        $mockCache->shouldReceive('put')->once();
        $mockCache->shouldReceive('putSegments')->once();
        $mockCache->shouldReceive('get')->with('test-flag')->andReturn([
            'key' => 'test-flag',
            'enabled' => true,
            'default_value' => true,
            'rules' => [],
        ]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('fetchFlags')->once()->andReturn([
            'flags' => [['key' => 'test-flag', 'enabled' => true]],
            'segments' => [],
            'cache_ttl' => 300,
        ]);

        $ff = $this->createFeatureFlags(
            apiClient: $mockApiClient,
            cache: $mockCache,
            cacheEnabled: false,
        );

        $result = $ff->active('test-flag');

        $this->assertTrue($result);
    }

    public function test_fallback_default_returns_configured_value(): void
    {
        config(['featureflags.fallback.behavior' => 'default']);
        config(['featureflags.fallback.default_value' => 'fallback-value']);

        $this->mockCache->shouldReceive('has')->andReturn(true);
        $this->mockCache->shouldReceive('get')->with('unknown-flag')->andReturn(null);

        $result = $this->featureFlags->value('unknown-flag');

        $this->assertEquals('fallback-value', $result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        RequestContext::reset();
        parent::tearDown();
    }
}
