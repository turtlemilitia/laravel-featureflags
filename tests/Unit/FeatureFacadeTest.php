<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Cache\FlagCache;
use FeatureFlags\Client\ApiClient;
use FeatureFlags\Context;
use FeatureFlags\ContextResolver;
use FeatureFlags\Facades\Feature;
use FeatureFlags\FeatureFlags;
use FeatureFlags\FeatureFlagsConfig;
use FeatureFlags\Telemetry\ConversionCollector;
use FeatureFlags\Telemetry\ErrorCollector;
use FeatureFlags\Telemetry\FlagStateTracker;
use FeatureFlags\Telemetry\TelemetryCollector;
use FeatureFlags\Tests\TestCase;
use Mockery;

class FeatureFacadeTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_facade_resolves_to_feature_flags_instance(): void
    {
        $this->assertInstanceOf(FeatureFlags::class, Feature::getFacadeRoot());
    }

    public function test_facade_active_method_works(): void
    {
        $mockCache = Mockery::mock(FlagCache::class);
        $mockCache->shouldReceive('has')->andReturn(true);
        $mockCache->shouldReceive('get')->with('test-flag')->andReturn([
            'key' => 'test-flag',
            'enabled' => true,
            'default_value' => true,
            'rules' => [],
        ]);

        $this->registerFeatureFlagsWithMock($mockCache);

        $result = Feature::active('test-flag');

        $this->assertTrue($result);
    }

    public function test_facade_value_method_works(): void
    {
        $mockCache = Mockery::mock(FlagCache::class);
        $mockCache->shouldReceive('has')->andReturn(true);
        $mockCache->shouldReceive('get')->with('string-flag')->andReturn([
            'key' => 'string-flag',
            'enabled' => true,
            'default_value' => 'variant-b',
            'rules' => [],
        ]);

        $this->registerFeatureFlagsWithMock($mockCache);

        $result = Feature::value('string-flag');

        $this->assertEquals('variant-b', $result);
    }

    public function test_facade_is_local_mode_method_works(): void
    {
        config(['featureflags.local.enabled' => false]);

        $this->assertFalse(Feature::isLocalMode());
    }

    public function test_facade_get_evaluated_flags_works(): void
    {
        $mockCache = Mockery::mock(FlagCache::class);
        $mockCache->shouldReceive('has')->andReturn(true);
        $mockCache->shouldReceive('get')->with('my-flag')->andReturn([
            'key' => 'my-flag',
            'enabled' => true,
            'default_value' => true,
            'rules' => [],
        ]);

        $this->registerFeatureFlagsWithMock($mockCache);

        Feature::value('my-flag');

        $evaluated = Feature::getEvaluatedFlags();

        $this->assertArrayHasKey('my-flag', $evaluated);
    }

    public function test_facade_get_error_context_works(): void
    {
        $context = Feature::getErrorContext();

        $this->assertArrayHasKey('flags', $context);
        $this->assertArrayHasKey('count', $context);
        $this->assertArrayHasKey('request_id', $context);
    }

    public function test_facade_get_state_tracker_works(): void
    {
        $tracker = Feature::getStateTracker();

        $this->assertInstanceOf(FlagStateTracker::class, $tracker);
    }

    private function registerFeatureFlagsWithMock($mockCache): void
    {
        $mockTelemetry = Mockery::mock(TelemetryCollector::class);
        $mockTelemetry->shouldReceive('record');

        $config = new FeatureFlagsConfig(
            Mockery::mock(ApiClient::class),
            $mockCache,
            new ContextResolver(config('featureflags.context')),
            $mockTelemetry,
            Mockery::mock(ConversionCollector::class),
            Mockery::mock(ErrorCollector::class),
            new FlagStateTracker(),
            true,
        );

        $featureFlags = new FeatureFlags($config);

        $this->app->instance(FeatureFlags::class, $featureFlags);
        $this->app->instance('featureflags', $featureFlags);
    }
}
