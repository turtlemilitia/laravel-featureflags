<?php

declare(strict_types=1);

namespace FeatureFlags\Tests\Feature;

use FeatureFlags\FeatureFlags;
use FeatureFlags\Http\Middleware\FlushTelemetry;
use FeatureFlags\Telemetry\ConversionCollector;
use FeatureFlags\Telemetry\ErrorCollector;
use FeatureFlags\Telemetry\FlagStateTracker;
use FeatureFlags\Telemetry\TelemetryCollector;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TelemetryMiddlewareTest extends FeatureTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('featureflags.telemetry.enabled', true);
    }

    private function makeMiddleware(): FlushTelemetry
    {
        return new FlushTelemetry(
            $this->app->make(TelemetryCollector::class),
            $this->app->make(ConversionCollector::class),
            $this->app->make(ErrorCollector::class),
            $this->app->make(FlagStateTracker::class),
        );
    }

    public function test_middleware_initializes_request_context(): void
    {
        $this->seedFlags([
            $this->simpleFlag('test-flag', true),
        ]);

        $middleware = $this->makeMiddleware();

        $request = Request::create('/test', 'GET');
        $response = new Response('OK');

        $middleware->handle($request, fn() => $response);

        // Request context should be initialized
        $this->assertNotNull(\FeatureFlags\Context\RequestContext::getRequestId());
    }

    public function test_middleware_flushes_telemetry_on_terminate(): void
    {
        $this->seedFlags([
            $this->simpleFlag('telemetry-flag', true),
        ]);

        /** @var TelemetryCollector $telemetry */
        $telemetry = $this->app->make(TelemetryCollector::class);

        // Record some events
        $telemetry->record('test-flag', true, null, null, 'default', 1);

        $this->assertGreaterThan(0, $telemetry->pendingCount());

        // Terminate should flush
        $middleware = $this->makeMiddleware();
        $middleware->terminate(
            Request::create('/test', 'GET'),
            new Response('OK'),
        );

        // After terminate, pending should be 0 (flushed)
        $this->assertEquals(0, $telemetry->pendingCount());
    }

    public function test_middleware_resets_state_tracker_on_terminate(): void
    {
        $this->seedFlags([
            $this->simpleFlag('state-flag', true),
        ]);

        /** @var FeatureFlags $ff */
        $ff = $this->app->make(FeatureFlags::class);

        // Evaluate a flag
        $ff->active('state-flag');

        $this->assertNotEmpty($ff->getEvaluatedFlags());

        // Terminate should reset state
        $middleware = $this->makeMiddleware();
        $middleware->terminate(
            Request::create('/test', 'GET'),
            new Response('OK'),
        );

        $this->assertEmpty($ff->getEvaluatedFlags());
    }

    public function test_telemetry_sampling_reduces_recorded_events(): void
    {
        $this->app['config']->set('featureflags.telemetry.sample_rate', 0.0);

        $this->seedFlags([
            $this->simpleFlag('sampled-flag', true),
        ]);

        // Recreate collector with new config
        $this->app->forgetInstance(TelemetryCollector::class);

        /** @var TelemetryCollector $telemetry */
        $telemetry = $this->app->make(TelemetryCollector::class);

        // Record events - with 0% sample rate, none should be recorded
        for ($i = 0; $i < 100; $i++) {
            $telemetry->record('sampled-flag', true, null, null, 'default', 1);
        }

        $this->assertEquals(0, $telemetry->pendingCount());
    }

    public function test_telemetry_rate_limiting_prevents_excessive_flushes(): void
    {
        $this->app['config']->set('featureflags.telemetry.rate_limit.enabled', true);
        $this->app['config']->set('featureflags.telemetry.rate_limit.max_flushes_per_minute', 2);
        // Use large batch size to prevent auto-flush, we'll manually control flushes
        $this->app['config']->set('featureflags.telemetry.batch_size', 100);

        $this->seedFlags([
            $this->simpleFlag('rate-flag', true),
        ]);

        // Mock API response so telemetry sends don't fail
        $this->mockApiResponse([
            $this->simpleFlag('rate-flag', true),
        ]);

        // Recreate collector with mocked ApiClient
        $this->app->forgetInstance(TelemetryCollector::class);

        /** @var TelemetryCollector $telemetry */
        $telemetry = $this->app->make(TelemetryCollector::class);

        // Verify rate limit config is active
        $this->assertTrue((bool) config('featureflags.telemetry.rate_limit.enabled'));
        $this->assertEquals(2, config('featureflags.telemetry.rate_limit.max_flushes_per_minute'));

        // Check initial cache state
        $this->assertNull(\Cache::get('featureflags:telemetry_rate_limit'));

        // Record and manually flush - first flush should work
        $telemetry->record('rate-flag', true, null, null, 'default', 1);
        $this->assertEquals(1, $telemetry->pendingCount());
        $telemetry->flush();
        $this->assertEquals(0, $telemetry->pendingCount(), 'First flush failed');
        $this->assertEquals(1, \Cache::get('featureflags:telemetry_rate_limit'), 'Rate limit counter should be 1');

        // Record and manually flush - second flush should work
        $telemetry->record('rate-flag', true, null, null, 'default', 1);
        $this->assertEquals(1, $telemetry->pendingCount());
        $telemetry->flush();
        $this->assertEquals(0, $telemetry->pendingCount(), 'Second flush failed');
        $this->assertEquals(2, \Cache::get('featureflags:telemetry_rate_limit'), 'Rate limit counter should be 2');

        // Record and manually flush - third should be rate-limited
        $telemetry->record('rate-flag', true, null, null, 'default', 1);
        $this->assertEquals(1, $telemetry->pendingCount());
        $telemetry->flush(); // This should be blocked by rate limiting
        $this->assertEquals(1, $telemetry->pendingCount(), 'Third flush should have been blocked by rate limit');
    }
}
