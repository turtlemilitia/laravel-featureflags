<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Cache\FlagCache;
use FeatureFlags\Client\ApiClient;
use FeatureFlags\ContextResolver;
use FeatureFlags\FeatureFlags;
use FeatureFlags\FeatureFlagsConfig;
use FeatureFlags\Telemetry\ConversionCollector;
use FeatureFlags\Telemetry\ErrorCollector;
use FeatureFlags\Telemetry\FlagStateTracker;
use FeatureFlags\Telemetry\TelemetryCollector;
use FeatureFlags\Tests\TestCase;
use Mockery;

class SyncCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_syncs_flags_successfully(): void
    {
        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('fetchFlags')
            ->once()
            ->andReturn([
                'flags' => [
                    ['key' => 'flag-1', 'enabled' => true],
                    ['key' => 'flag-2', 'enabled' => false],
                ],
                'segments' => [],
                'cache_ttl' => 300,
            ]);

        $mockCache = Mockery::mock(FlagCache::class);
        $mockCache->shouldReceive('put')->once();
        $mockCache->shouldReceive('putSegments')->once();
        $mockCache->shouldReceive('has')->andReturn(true);
        $mockCache->shouldReceive('all')->andReturn([
            ['key' => 'flag-1', 'enabled' => true],
            ['key' => 'flag-2', 'enabled' => false],
        ]);

        $this->app->instance(FeatureFlags::class, $this->createFeatureFlags($mockApiClient, $mockCache));

        $this->artisan('featureflags:sync')
            ->expectsOutput('Syncing feature flags...')
            ->expectsOutput('Synced 2 flag(s).')
            ->assertSuccessful();
    }

    public function test_shows_synced_flag_count(): void
    {
        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('fetchFlags')
            ->once()
            ->andReturn([
                'flags' => [
                    ['key' => 'flag-1', 'enabled' => true],
                    ['key' => 'flag-2', 'enabled' => false],
                    ['key' => 'flag-3', 'enabled' => true],
                ],
                'segments' => [],
                'cache_ttl' => 300,
            ]);

        $mockCache = Mockery::mock(FlagCache::class);
        $mockCache->shouldReceive('put')->once();
        $mockCache->shouldReceive('putSegments')->once();
        $mockCache->shouldReceive('has')->andReturn(true);
        $mockCache->shouldReceive('all')->andReturn([
            ['key' => 'flag-1', 'enabled' => true],
            ['key' => 'flag-2', 'enabled' => false],
            ['key' => 'flag-3', 'enabled' => true],
        ]);

        $this->app->instance(FeatureFlags::class, $this->createFeatureFlags($mockApiClient, $mockCache));

        $this->artisan('featureflags:sync')
            ->expectsOutput('Synced 3 flag(s).')
            ->assertSuccessful();
    }

    public function test_verbose_mode_lists_each_flag(): void
    {
        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('fetchFlags')
            ->once()
            ->andReturn([
                'flags' => [
                    ['key' => 'new-checkout', 'enabled' => true],
                    ['key' => 'dark-mode', 'enabled' => false],
                ],
                'segments' => [],
                'cache_ttl' => 300,
            ]);

        $mockCache = Mockery::mock(FlagCache::class);
        $mockCache->shouldReceive('put')->once();
        $mockCache->shouldReceive('putSegments')->once();
        $mockCache->shouldReceive('has')->andReturn(true);
        $mockCache->shouldReceive('all')->andReturn([
            ['key' => 'new-checkout', 'enabled' => true],
            ['key' => 'dark-mode', 'enabled' => false],
        ]);

        $this->app->instance(FeatureFlags::class, $this->createFeatureFlags($mockApiClient, $mockCache));

        $this->artisan('featureflags:sync', ['-v' => true])
            ->expectsOutput('  - new-checkout (enabled)')
            ->expectsOutput('  - dark-mode (disabled)')
            ->assertSuccessful();
    }

    public function test_handles_zero_flags(): void
    {
        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('fetchFlags')
            ->once()
            ->andReturn([
                'flags' => [],
                'segments' => [],
                'cache_ttl' => 300,
            ]);

        $mockCache = Mockery::mock(FlagCache::class);
        $mockCache->shouldReceive('put')->once();
        $mockCache->shouldReceive('putSegments')->once();
        $mockCache->shouldReceive('has')->andReturn(true);
        $mockCache->shouldReceive('all')->andReturn([]);

        $this->app->instance(FeatureFlags::class, $this->createFeatureFlags($mockApiClient, $mockCache));

        $this->artisan('featureflags:sync')
            ->expectsOutput('Synced 0 flag(s).')
            ->assertSuccessful();
    }

    public function test_returns_success_exit_code(): void
    {
        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('fetchFlags')
            ->once()
            ->andReturn([
                'flags' => [['key' => 'test', 'enabled' => true]],
                'segments' => [],
                'cache_ttl' => 300,
            ]);

        $mockCache = Mockery::mock(FlagCache::class);
        $mockCache->shouldReceive('put')->once();
        $mockCache->shouldReceive('putSegments')->once();
        $mockCache->shouldReceive('has')->andReturn(true);
        $mockCache->shouldReceive('all')->andReturn([['key' => 'test', 'enabled' => true]]);

        $this->app->instance(FeatureFlags::class, $this->createFeatureFlags($mockApiClient, $mockCache));

        $this->artisan('featureflags:sync')
            ->assertExitCode(0);
    }

    /**
     * @param \Mockery\MockInterface&ApiClient $mockApiClient
     * @param \Mockery\MockInterface&FlagCache $mockCache
     */
    private function createFeatureFlags($mockApiClient, $mockCache): FeatureFlags
    {
        $mockContextResolver = Mockery::mock(ContextResolver::class);
        $mockContextResolver->shouldReceive('resolve')->andReturn(null);

        $mockTelemetry = Mockery::mock(TelemetryCollector::class);
        $mockTelemetry->shouldReceive('record');

        $mockStateTracker = Mockery::mock(FlagStateTracker::class);
        $mockStateTracker->shouldReceive('record');

        $config = new FeatureFlagsConfig(
            $mockApiClient,
            $mockCache,
            $mockContextResolver,
            $mockTelemetry,
            Mockery::mock(ConversionCollector::class),
            Mockery::mock(ErrorCollector::class),
            $mockStateTracker,
            true,
        );

        return new FeatureFlags($config);
    }
}
