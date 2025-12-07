<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Cache\FlagCache;
use FeatureFlags\Client\ApiClient;
use FeatureFlags\Context\RequestContext;
use FeatureFlags\ContextResolver;
use FeatureFlags\FeatureFlags;
use FeatureFlags\FeatureFlagsConfig;
use FeatureFlags\Integrations\ExceptionContext;
use FeatureFlags\Telemetry\ConversionCollector;
use FeatureFlags\Telemetry\ErrorCollector;
use FeatureFlags\Telemetry\FlagStateTracker;
use FeatureFlags\Telemetry\TelemetryCollector;
use FeatureFlags\Tests\TestCase;
use Illuminate\Support\Facades\Context;
use Mockery;

class ExceptionContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::reset();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        RequestContext::reset();

        // Clear Laravel Context if available
        if (class_exists(Context::class)) {
            Context::flush();
        }

        parent::tearDown();
    }

    public function test_add_flags_to_context_adds_flags_when_available(): void
    {
        // Set up a state tracker with some evaluated flags
        $stateTracker = new FlagStateTracker();
        $stateTracker->record('test-flag', true);
        $stateTracker->record('variant-flag', 'variant-a');

        $this->registerFeatureFlagsWithMocks($stateTracker);

        ExceptionContext::addFlagsToContext();

        if (class_exists(Context::class)) {
            $context = Context::all();
            $this->assertArrayHasKey('feature_flags', $context);
            $this->assertEquals(['test-flag' => true, 'variant-flag' => 'variant-a'], $context['feature_flags']);
            $this->assertEquals(2, $context['feature_flags_count']);
        } else {
            $this->assertTrue(true);
        }
    }

    public function test_add_flags_to_context_does_nothing_when_no_flags(): void
    {
        // Empty state tracker
        $stateTracker = new FlagStateTracker();
        $this->registerFeatureFlagsWithMocks($stateTracker);

        ExceptionContext::addFlagsToContext();

        if (class_exists(Context::class)) {
            $context = Context::all();
            $this->assertArrayNotHasKey('feature_flags', $context);
        }

        $this->assertTrue(true);
    }

    public function test_get_flags_returns_error_context(): void
    {
        RequestContext::initialize();

        $stateTracker = new FlagStateTracker();
        $stateTracker->record('checkout-v2', true);
        $stateTracker->record('new-pricing', 'control');

        $this->registerFeatureFlagsWithMocks($stateTracker);

        $flags = ExceptionContext::getFlags();

        $this->assertArrayHasKey('flags', $flags);
        $this->assertArrayHasKey('count', $flags);
        $this->assertArrayHasKey('request_id', $flags);
        $this->assertEquals(2, $flags['count']);
        $this->assertEquals(['checkout-v2' => true, 'new-pricing' => 'control'], $flags['flags']);
    }

    public function test_get_flags_returns_empty_array_when_no_flags(): void
    {
        $stateTracker = new FlagStateTracker();
        $this->registerFeatureFlagsWithMocks($stateTracker);

        $flags = ExceptionContext::getFlags();

        $this->assertArrayHasKey('flags', $flags);
        $this->assertArrayHasKey('count', $flags);
        $this->assertEquals(0, $flags['count']);
        $this->assertEmpty($flags['flags']);
    }

    public function test_get_flags_includes_request_id_when_initialized(): void
    {
        RequestContext::initialize();

        $stateTracker = new FlagStateTracker();
        $stateTracker->record('my-flag', true);
        $this->registerFeatureFlagsWithMocks($stateTracker);

        $flags = ExceptionContext::getFlags();

        $this->assertArrayHasKey('request_id', $flags);
        $this->assertNotNull($flags['request_id']);
    }

    public function test_get_flags_returns_null_request_id_when_not_initialized(): void
    {
        RequestContext::reset();

        $stateTracker = new FlagStateTracker();
        $stateTracker->record('my-flag', true);
        $this->registerFeatureFlagsWithMocks($stateTracker);

        $flags = ExceptionContext::getFlags();

        $this->assertArrayHasKey('request_id', $flags);
        $this->assertNull($flags['request_id']);
    }

    public function test_get_flags_can_be_used_in_exception_context(): void
    {
        RequestContext::initialize();

        $stateTracker = new FlagStateTracker();
        $stateTracker->record('payment-flow', 'new');
        $this->registerFeatureFlagsWithMocks($stateTracker);

        // Simulate usage in exception context() method
        $exceptionContext = array_merge(
            ['payment_id' => 'pay_123'],
            ExceptionContext::getFlags(),
        );

        $this->assertArrayHasKey('payment_id', $exceptionContext);
        $this->assertArrayHasKey('flags', $exceptionContext);
        $this->assertEquals('pay_123', $exceptionContext['payment_id']);
        $this->assertEquals(['payment-flow' => 'new'], $exceptionContext['flags']);
    }

    public function test_add_flags_catches_exceptions_silently(): void
    {
        // Register a broken FeatureFlags instance that throws
        $mockFeatureFlags = Mockery::mock(FeatureFlags::class);
        $mockFeatureFlags->shouldReceive('getEvaluatedFlags')
            ->andThrow(new \RuntimeException('Something went wrong'));

        $this->app->instance(FeatureFlags::class, $mockFeatureFlags);
        $this->app->instance('featureflags', $mockFeatureFlags);

        // Should not throw
        ExceptionContext::addFlagsToContext();

        $this->assertTrue(true);
    }

    public function test_get_flags_catches_exceptions_silently(): void
    {
        // Register a broken FeatureFlags instance that throws
        $mockFeatureFlags = Mockery::mock(FeatureFlags::class);
        $mockFeatureFlags->shouldReceive('getErrorContext')
            ->andThrow(new \RuntimeException('Something went wrong'));

        $this->app->instance(FeatureFlags::class, $mockFeatureFlags);
        $this->app->instance('featureflags', $mockFeatureFlags);

        // Should not throw, returns empty array
        $flags = ExceptionContext::getFlags();

        $this->assertEquals([], $flags);
    }

    private function registerFeatureFlagsWithMocks(FlagStateTracker $stateTracker): void
    {
        $config = new FeatureFlagsConfig(
            Mockery::mock(ApiClient::class),
            Mockery::mock(FlagCache::class),
            new ContextResolver(config('featureflags.context')),
            Mockery::mock(TelemetryCollector::class),
            Mockery::mock(ConversionCollector::class),
            Mockery::mock(ErrorCollector::class),
            $stateTracker,
            true,
        );

        $featureFlags = new FeatureFlags($config);

        $this->app->instance(FeatureFlags::class, $featureFlags);
        $this->app->instance('featureflags', $featureFlags);
        $this->app->instance(FlagStateTracker::class, $stateTracker);
    }
}
