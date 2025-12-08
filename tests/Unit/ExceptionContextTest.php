<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Cache\FlagCache;
use FeatureFlags\Context\RequestContext;
use FeatureFlags\Contracts\FeatureFlagsInterface;
use FeatureFlags\FeatureFlags;
use FeatureFlags\Integrations\ExceptionContext;
use FeatureFlags\Telemetry\FlagStateTracker;
use FeatureFlags\Tests\CreatesFeatureFlags;
use FeatureFlags\Tests\TestCase;
use Illuminate\Support\Facades\Context;
use Mockery;

class ExceptionContextTest extends TestCase
{
    use CreatesFeatureFlags;

    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::reset();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        RequestContext::reset();

        if (class_exists(Context::class)) {
            Context::flush();
        }

        parent::tearDown();
    }

    public function test_add_flags_to_context_adds_flags_when_available(): void
    {
        $stateTracker = new FlagStateTracker();
        $stateTracker->record('test-flag', true, null);
        $stateTracker->record('variant-flag', 'variant-a', null);

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
        $stateTracker->record('checkout-v2', true, null);
        $stateTracker->record('new-pricing', 'control', null);

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
        $stateTracker->record('my-flag', true, null);
        $this->registerFeatureFlagsWithMocks($stateTracker);

        $flags = ExceptionContext::getFlags();

        $this->assertArrayHasKey('request_id', $flags);
        $this->assertNotNull($flags['request_id']);
    }

    public function test_get_flags_returns_null_request_id_when_not_initialized(): void
    {
        RequestContext::reset();

        $stateTracker = new FlagStateTracker();
        $stateTracker->record('my-flag', true, null);
        $this->registerFeatureFlagsWithMocks($stateTracker);

        $flags = ExceptionContext::getFlags();

        $this->assertArrayHasKey('request_id', $flags);
        $this->assertNull($flags['request_id']);
    }

    public function test_get_flags_can_be_used_in_exception_context(): void
    {
        RequestContext::initialize();

        $stateTracker = new FlagStateTracker();
        $stateTracker->record('payment-flow', 'new', null);
        $this->registerFeatureFlagsWithMocks($stateTracker);

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
        $mockFeatureFlags = Mockery::mock(FeatureFlagsInterface::class);
        $mockFeatureFlags->shouldReceive('getEvaluatedFlags')
            ->andThrow(new \RuntimeException('Something went wrong'));

        $this->app->instance(FeatureFlags::class, $mockFeatureFlags);
        $this->app->instance('featureflags', $mockFeatureFlags);

        ExceptionContext::addFlagsToContext();

        $this->assertTrue(true);
    }

    public function test_get_flags_catches_exceptions_silently(): void
    {
        $mockFeatureFlags = Mockery::mock(FeatureFlagsInterface::class);
        $mockFeatureFlags->shouldReceive('getErrorContext')
            ->andThrow(new \RuntimeException('Something went wrong'));

        $this->app->instance(FeatureFlags::class, $mockFeatureFlags);
        $this->app->instance('featureflags', $mockFeatureFlags);

        $flags = ExceptionContext::getFlags();

        $this->assertEquals([], $flags);
    }

    private function registerFeatureFlagsWithMocks(FlagStateTracker $stateTracker): void
    {
        $mockCache = Mockery::mock(FlagCache::class);

        $featureFlags = $this->createFeatureFlagsInstance(
            cache: $mockCache,
            stateTracker: $stateTracker,
        );

        $this->app->instance(FeatureFlags::class, $featureFlags);
        $this->app->instance('featureflags', $featureFlags);
        $this->app->instance(FlagStateTracker::class, $stateTracker);
    }
}
