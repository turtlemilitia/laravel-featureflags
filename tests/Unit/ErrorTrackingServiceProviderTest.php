<?php

namespace FeatureFlags\Tests\Unit;

use Exception;
use FeatureFlags\Cache\FlagCache;
use FeatureFlags\Client\ApiClient;
use FeatureFlags\Context\RequestContext;
use FeatureFlags\ContextResolver;
use FeatureFlags\FeatureFlags;
use FeatureFlags\FeatureFlagsConfig;
use FeatureFlags\Integrations\ErrorTrackingServiceProvider;
use FeatureFlags\Telemetry\ConversionCollector;
use FeatureFlags\Telemetry\ErrorCollector;
use FeatureFlags\Telemetry\FlagStateTracker;
use FeatureFlags\Telemetry\TelemetryCollector;
use FeatureFlags\Tests\TestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Mockery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ErrorTrackingServiceProviderTest extends TestCase
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
        parent::tearDown();
    }

    public function test_does_not_register_callback_when_error_tracking_disabled(): void
    {
        config(['featureflags.error_tracking.enabled' => false]);

        $provider = new ErrorTrackingServiceProvider($this->app);
        $provider->boot();

        // The provider should not throw and should complete boot
        $this->assertTrue(true);
    }

    public function test_registers_callback_when_error_tracking_enabled(): void
    {
        config(['featureflags.error_tracking.enabled' => true]);

        $mockHandler = Mockery::mock(ExceptionHandler::class);
        $mockHandler->shouldReceive('reportable')->once();
        $this->app->instance(ExceptionHandler::class, $mockHandler);

        $provider = new ErrorTrackingServiceProvider($this->app);
        $provider->boot();

        $this->assertTrue(true);
    }

    public function test_tracks_error_with_evaluated_flags(): void
    {
        config(['featureflags.error_tracking.enabled' => true]);
        config(['featureflags.telemetry.enabled' => true]);

        // Set up a flag state tracker with some evaluated flags
        $stateTracker = new FlagStateTracker();
        $stateTracker->record('test-flag', true);
        $stateTracker->record('another-flag', 'variant-a');

        $mockErrorCollector = Mockery::mock(ErrorCollector::class);
        $mockErrorCollector->shouldReceive('trackAutomatic')
            ->twice(); // Once for each flag

        $this->registerFeatureFlagsWithMocks($stateTracker, $mockErrorCollector);

        // Call trackErrorWithFlags via reflection
        $provider = new ErrorTrackingServiceProvider($this->app);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('trackErrorWithFlags');
        $method->setAccessible(true);

        $exception = new Exception('Test exception');
        $method->invoke($provider, $exception);

        $this->assertTrue(true);
    }

    public function test_skips_tracking_when_no_flags_evaluated(): void
    {
        config(['featureflags.error_tracking.enabled' => true]);
        config(['featureflags.telemetry.enabled' => true]);

        // Empty state tracker - no flags evaluated
        $stateTracker = new FlagStateTracker();

        $mockErrorCollector = Mockery::mock(ErrorCollector::class);
        $mockErrorCollector->shouldNotReceive('trackAutomatic');

        $this->registerFeatureFlagsWithMocks($stateTracker, $mockErrorCollector);

        $provider = new ErrorTrackingServiceProvider($this->app);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('trackErrorWithFlags');
        $method->setAccessible(true);

        $exception = new Exception('Test exception');
        $method->invoke($provider, $exception);

        $this->assertTrue(true);
    }

    public function test_skips_not_found_http_exception(): void
    {
        config(['featureflags.error_tracking.enabled' => true]);

        $provider = new ErrorTrackingServiceProvider($this->app);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('shouldSkipException');
        $method->setAccessible(true);

        $exception = new NotFoundHttpException();
        $result = $method->invoke($provider, $exception);

        $this->assertTrue($result);
    }

    public function test_skips_validation_exception(): void
    {
        config(['featureflags.error_tracking.enabled' => true]);

        $provider = new ErrorTrackingServiceProvider($this->app);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('shouldSkipException');
        $method->setAccessible(true);

        $validator = \Illuminate\Support\Facades\Validator::make([], []);
        $exception = new ValidationException($validator);
        $result = $method->invoke($provider, $exception);

        $this->assertTrue($result);
    }

    public function test_skips_authentication_exception(): void
    {
        config(['featureflags.error_tracking.enabled' => true]);

        $provider = new ErrorTrackingServiceProvider($this->app);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('shouldSkipException');
        $method->setAccessible(true);

        $exception = new AuthenticationException();
        $result = $method->invoke($provider, $exception);

        $this->assertTrue($result);
    }

    public function test_skips_authorization_exception(): void
    {
        config(['featureflags.error_tracking.enabled' => true]);

        $provider = new ErrorTrackingServiceProvider($this->app);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('shouldSkipException');
        $method->setAccessible(true);

        $exception = new AuthorizationException();
        $result = $method->invoke($provider, $exception);

        $this->assertTrue($result);
    }

    public function test_does_not_skip_regular_exception(): void
    {
        config(['featureflags.error_tracking.enabled' => true]);

        $provider = new ErrorTrackingServiceProvider($this->app);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('shouldSkipException');
        $method->setAccessible(true);

        $exception = new Exception('Regular exception');
        $result = $method->invoke($provider, $exception);

        $this->assertFalse($result);
    }

    public function test_skips_telemetry_when_disabled(): void
    {
        config(['featureflags.error_tracking.enabled' => true]);
        config(['featureflags.telemetry.enabled' => false]);

        $stateTracker = new FlagStateTracker();
        $stateTracker->record('test-flag', true);

        $mockErrorCollector = Mockery::mock(ErrorCollector::class);
        $mockErrorCollector->shouldNotReceive('trackAutomatic');

        $this->registerFeatureFlagsWithMocks($stateTracker, $mockErrorCollector);

        $provider = new ErrorTrackingServiceProvider($this->app);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('trackErrorWithFlags');
        $method->setAccessible(true);

        $exception = new Exception('Test exception');
        $method->invoke($provider, $exception);

        $this->assertTrue(true);
    }

    public function test_adds_flag_context_to_laravel_context(): void
    {
        config(['featureflags.error_tracking.enabled' => true]);

        $provider = new ErrorTrackingServiceProvider($this->app);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('addFlagContextToException');
        $method->setAccessible(true);

        RequestContext::initialize();
        $flags = ['flag-1' => true, 'flag-2' => 'variant-b'];
        $method->invoke($provider, $flags);

        // Check Laravel Context has the flags
        if (class_exists(\Illuminate\Support\Facades\Context::class)) {
            $context = \Illuminate\Support\Facades\Context::all();
            $this->assertArrayHasKey('feature_flags', $context);
            $this->assertEquals($flags, $context['feature_flags']);
            $this->assertEquals(2, $context['feature_flags_count']);
        }

        $this->assertTrue(true);
    }

    public function test_register_method_does_nothing(): void
    {
        $provider = new ErrorTrackingServiceProvider($this->app);
        $provider->register();

        // Just ensure it doesn't throw
        $this->assertTrue(true);
    }

    private function registerFeatureFlagsWithMocks(FlagStateTracker $stateTracker, $mockErrorCollector): void
    {
        $config = new FeatureFlagsConfig(
            Mockery::mock(ApiClient::class),
            Mockery::mock(FlagCache::class),
            new ContextResolver(config('featureflags.context')),
            Mockery::mock(TelemetryCollector::class),
            Mockery::mock(ConversionCollector::class),
            $mockErrorCollector,
            $stateTracker,
            true,
        );

        $featureFlags = new FeatureFlags($config);

        $this->app->instance(FeatureFlags::class, $featureFlags);
        $this->app->instance('featureflags', $featureFlags);
        $this->app->instance(ErrorCollector::class, $mockErrorCollector);
        $this->app->instance(FlagStateTracker::class, $stateTracker);
    }
}
