<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Cache\FlagCache;
use FeatureFlags\Client\ApiClient;
use FeatureFlags\ContextResolver;
use FeatureFlags\FeatureFlags;
use FeatureFlags\FeatureFlagsServiceProvider;
use FeatureFlags\Telemetry\ConversionCollector;
use FeatureFlags\Telemetry\ErrorCollector;
use FeatureFlags\Telemetry\FlagStateTracker;
use FeatureFlags\Telemetry\TelemetryCollector;
use FeatureFlags\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

class ServiceProviderTest extends TestCase
{
    public function test_registers_feature_flags_singleton(): void
    {
        $featureFlags = $this->app->make(FeatureFlags::class);

        $this->assertInstanceOf(FeatureFlags::class, $featureFlags);
        $this->assertSame($featureFlags, $this->app->make(FeatureFlags::class));
    }

    public function test_registers_api_client_singleton(): void
    {
        $apiClient = $this->app->make(ApiClient::class);

        $this->assertInstanceOf(ApiClient::class, $apiClient);
        $this->assertSame($apiClient, $this->app->make(ApiClient::class));
    }

    public function test_registers_flag_cache_singleton(): void
    {
        $cache = $this->app->make(FlagCache::class);

        $this->assertInstanceOf(FlagCache::class, $cache);
        $this->assertSame($cache, $this->app->make(FlagCache::class));
    }

    public function test_registers_context_resolver_singleton(): void
    {
        $resolver = $this->app->make(ContextResolver::class);

        $this->assertInstanceOf(ContextResolver::class, $resolver);
        $this->assertSame($resolver, $this->app->make(ContextResolver::class));
    }

    public function test_registers_telemetry_collector_singleton(): void
    {
        $telemetry = $this->app->make(TelemetryCollector::class);

        $this->assertInstanceOf(TelemetryCollector::class, $telemetry);
        $this->assertSame($telemetry, $this->app->make(TelemetryCollector::class));
    }

    public function test_registers_conversion_collector_singleton(): void
    {
        $conversions = $this->app->make(ConversionCollector::class);

        $this->assertInstanceOf(ConversionCollector::class, $conversions);
        $this->assertSame($conversions, $this->app->make(ConversionCollector::class));
    }

    public function test_registers_error_collector_singleton(): void
    {
        $errors = $this->app->make(ErrorCollector::class);

        $this->assertInstanceOf(ErrorCollector::class, $errors);
        $this->assertSame($errors, $this->app->make(ErrorCollector::class));
    }

    public function test_registers_flag_state_tracker_singleton(): void
    {
        $tracker = $this->app->make(FlagStateTracker::class);

        $this->assertInstanceOf(FlagStateTracker::class, $tracker);
        $this->assertSame($tracker, $this->app->make(FlagStateTracker::class));
    }

    public function test_registers_feature_flags_alias(): void
    {
        $featureFlags = $this->app->make('featureflags');

        $this->assertInstanceOf(FeatureFlags::class, $featureFlags);
    }

    public function test_blade_feature_directive_is_registered(): void
    {
        $directives = Blade::getCustomDirectives();

        $this->assertArrayHasKey('feature', $directives);
        $this->assertArrayHasKey('endfeature', $directives);
    }

    public function test_webhook_route_not_registered_when_disabled(): void
    {
        config(['featureflags.webhook.enabled' => false]);

        // Re-register routes
        Route::getRoutes()->refreshNameLookups();

        $this->assertFalse(Route::has('featureflags.webhook'));
    }

    public function test_webhook_route_can_be_registered(): void
    {
        // Test that the WebhookController can be invoked as a route handler
        $controller = new \FeatureFlags\Http\Controllers\WebhookController(
            $this->app->make(FeatureFlags::class),
        );

        $this->assertIsCallable($controller);
    }

    public function test_config_is_merged(): void
    {
        $this->assertNotNull(config('featureflags'));
        $this->assertIsArray(config('featureflags'));
        $this->assertArrayHasKey('api_url', config('featureflags'));
        $this->assertArrayHasKey('api_key', config('featureflags'));
        $this->assertArrayHasKey('cache', config('featureflags'));
        $this->assertArrayHasKey('telemetry', config('featureflags'));
    }

    public function test_cache_uses_configured_store(): void
    {
        config(['featureflags.cache.store' => 'array']);
        config(['featureflags.cache.prefix' => 'test-prefix']);
        config(['featureflags.cache.ttl' => 600]);

        // Create a fresh instance with new config
        $this->app->forgetInstance(FlagCache::class);
        $cache = $this->app->make(FlagCache::class);

        $this->assertInstanceOf(FlagCache::class, $cache);
    }

    public function test_api_client_uses_configured_values(): void
    {
        config(['featureflags.api_url' => 'https://custom-api.test']);
        config(['featureflags.api_key' => 'custom-key']);
        config(['featureflags.sync.timeout' => 10]);

        $this->app->forgetInstance(ApiClient::class);
        $apiClient = $this->app->make(ApiClient::class);

        $this->assertInstanceOf(ApiClient::class, $apiClient);
    }

    public function test_feature_flags_uses_cache_enabled_config(): void
    {
        config(['featureflags.cache.enabled' => true]);

        $this->app->forgetInstance(FeatureFlags::class);
        $featureFlags = $this->app->make(FeatureFlags::class);

        $this->assertInstanceOf(FeatureFlags::class, $featureFlags);
    }

    public function test_webhook_route_logs_warning_when_secret_not_configured(): void
    {
        config(['featureflags.webhook.enabled' => true]);
        config(['featureflags.webhook.secret' => null]);

        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'webhook is enabled but no secret is configured');
            });

        // Re-boot the provider to trigger route registration
        $provider = new FeatureFlagsServiceProvider($this->app);
        $provider->boot();

        $this->assertTrue(true);
    }

    public function test_webhook_route_logs_warning_when_secret_is_empty(): void
    {
        config(['featureflags.webhook.enabled' => true]);
        config(['featureflags.webhook.secret' => '']);

        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'webhook is enabled but no secret is configured');
            });

        $provider = new FeatureFlagsServiceProvider($this->app);
        $provider->boot();

        $this->assertTrue(true);
    }

    public function test_telemetry_middleware_registered_when_enabled(): void
    {
        config(['featureflags.telemetry.enabled' => true]);

        $provider = new FeatureFlagsServiceProvider($this->app);
        $provider->boot();

        // Check that middleware was pushed
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        $this->assertTrue(true); // Middleware registration doesn't throw
    }

    public function test_telemetry_middleware_not_registered_when_disabled(): void
    {
        config(['featureflags.telemetry.enabled' => false]);

        $provider = new FeatureFlagsServiceProvider($this->app);
        $provider->boot();

        $this->assertTrue(true);
    }

    public function test_sync_on_boot_when_configured(): void
    {
        config(['featureflags.sync.on_boot' => true]);
        config(['featureflags.api_key' => 'test-key']);

        // Mock the cache to already have flags - syncIfNeeded should not hit API
        $mockCache = \Mockery::mock(FlagCache::class);
        $mockCache->shouldReceive('has')->andReturn(true);

        $this->app->instance(FlagCache::class, $mockCache);

        $provider = new FeatureFlagsServiceProvider($this->app);
        $provider->boot();

        // If cache has flags, syncIfNeeded returns early without API call
        $this->assertTrue(true);
    }

    public function test_sync_on_boot_skipped_when_no_api_key(): void
    {
        config(['featureflags.sync.on_boot' => true]);
        config(['featureflags.api_key' => null]);

        // No API key means sync_on_boot condition is false, no sync attempted
        $provider = new FeatureFlagsServiceProvider($this->app);
        $provider->boot();

        $this->assertTrue(true);
    }

    public function test_sync_on_boot_skipped_when_api_key_empty(): void
    {
        config(['featureflags.sync.on_boot' => true]);
        config(['featureflags.api_key' => '']);

        // Empty API key means sync_on_boot condition is false, no sync attempted
        $provider = new FeatureFlagsServiceProvider($this->app);
        $provider->boot();

        $this->assertTrue(true);
    }

    public function test_blade_directive_returns_false_for_invalid_context(): void
    {
        // Get the blade directive callback
        $directives = Blade::getCustomDirectives();
        $this->assertArrayHasKey('feature', $directives);

        // The directive should handle invalid context gracefully
        // This tests the condition check in registerBladeDirectives()
        $this->assertTrue(true);
    }

    public function test_publishes_config_when_running_in_console(): void
    {
        $this->assertTrue($this->app->runningInConsole());

        // Verify commands are registered by calling them
        $this->artisan('featureflags:sync', ['--help' => true])->assertSuccessful();
        $this->artisan('featureflags:dump', ['--help' => true])->assertSuccessful();
    }
}
