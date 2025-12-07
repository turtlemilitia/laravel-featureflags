<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Context\RequestContext;
use FeatureFlags\Http\Middleware\FlushTelemetry;
use FeatureFlags\Telemetry\ConversionCollector;
use FeatureFlags\Telemetry\ErrorCollector;
use FeatureFlags\Telemetry\FlagStateTracker;
use FeatureFlags\Telemetry\TelemetryCollector;
use FeatureFlags\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mockery;

class FlushTelemetryMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::reset();
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        Mockery::close();
        parent::tearDown();
    }

    public function test_handle_passes_request_through(): void
    {
        $mockTelemetry = Mockery::mock(TelemetryCollector::class);
        $mockConversions = Mockery::mock(ConversionCollector::class);
        $mockErrors = Mockery::mock(ErrorCollector::class);
        $mockStateTracker = Mockery::mock(FlagStateTracker::class);

        $middleware = new FlushTelemetry(
            $mockTelemetry,
            $mockConversions,
            $mockErrors,
            $mockStateTracker,
        );

        $request = Request::create('/test', 'GET');
        $expectedResponse = new Response('OK');

        $response = $middleware->handle($request, function () use ($expectedResponse) {
            return $expectedResponse;
        });

        $this->assertSame($expectedResponse, $response);
    }

    public function test_terminate_flushes_telemetry(): void
    {
        $mockTelemetry = Mockery::mock(TelemetryCollector::class);
        $mockTelemetry->shouldReceive('flush')->once();

        $mockConversions = Mockery::mock(ConversionCollector::class);
        $mockConversions->shouldReceive('flush')->once();

        $mockErrors = Mockery::mock(ErrorCollector::class);
        $mockErrors->shouldReceive('flush')->once();

        $mockStateTracker = Mockery::mock(FlagStateTracker::class);
        $mockStateTracker->shouldReceive('reset')->once();

        $middleware = new FlushTelemetry(
            $mockTelemetry,
            $mockConversions,
            $mockErrors,
            $mockStateTracker,
        );

        $request = Request::create('/test', 'GET');
        $response = new Response('OK');

        $middleware->terminate($request, $response);

        // Mockery verifies all expectations on tearDown
        $this->assertTrue(true);
    }

    public function test_terminate_flushes_conversions(): void
    {
        $mockTelemetry = Mockery::mock(TelemetryCollector::class);
        $mockTelemetry->shouldReceive('flush');

        $mockConversions = Mockery::mock(ConversionCollector::class);
        $mockConversions->shouldReceive('flush')->once();

        $mockErrors = Mockery::mock(ErrorCollector::class);
        $mockErrors->shouldReceive('flush');

        $mockStateTracker = Mockery::mock(FlagStateTracker::class);
        $mockStateTracker->shouldReceive('reset');

        $middleware = new FlushTelemetry(
            $mockTelemetry,
            $mockConversions,
            $mockErrors,
            $mockStateTracker,
        );

        $middleware->terminate(Request::create('/test'), new Response());

        $this->assertTrue(true);
    }

    public function test_terminate_flushes_errors(): void
    {
        $mockTelemetry = Mockery::mock(TelemetryCollector::class);
        $mockTelemetry->shouldReceive('flush');

        $mockConversions = Mockery::mock(ConversionCollector::class);
        $mockConversions->shouldReceive('flush');

        $mockErrors = Mockery::mock(ErrorCollector::class);
        $mockErrors->shouldReceive('flush')->once();

        $mockStateTracker = Mockery::mock(FlagStateTracker::class);
        $mockStateTracker->shouldReceive('reset');

        $middleware = new FlushTelemetry(
            $mockTelemetry,
            $mockConversions,
            $mockErrors,
            $mockStateTracker,
        );

        $middleware->terminate(Request::create('/test'), new Response());

        $this->assertTrue(true);
    }

    public function test_terminate_resets_state_tracker(): void
    {
        $mockTelemetry = Mockery::mock(TelemetryCollector::class);
        $mockTelemetry->shouldReceive('flush');

        $mockConversions = Mockery::mock(ConversionCollector::class);
        $mockConversions->shouldReceive('flush');

        $mockErrors = Mockery::mock(ErrorCollector::class);
        $mockErrors->shouldReceive('flush');

        $mockStateTracker = Mockery::mock(FlagStateTracker::class);
        $mockStateTracker->shouldReceive('reset')->once();

        $middleware = new FlushTelemetry(
            $mockTelemetry,
            $mockConversions,
            $mockErrors,
            $mockStateTracker,
        );

        $middleware->terminate(Request::create('/test'), new Response());

        $this->assertTrue(true);
    }

    public function test_terminate_resets_request_context(): void
    {
        // Initialize RequestContext first
        RequestContext::initialize();
        $this->assertTrue(RequestContext::isInitialized());

        $mockTelemetry = Mockery::mock(TelemetryCollector::class);
        $mockTelemetry->shouldReceive('flush');

        $mockConversions = Mockery::mock(ConversionCollector::class);
        $mockConversions->shouldReceive('flush');

        $mockErrors = Mockery::mock(ErrorCollector::class);
        $mockErrors->shouldReceive('flush');

        $mockStateTracker = Mockery::mock(FlagStateTracker::class);
        $mockStateTracker->shouldReceive('reset');

        $middleware = new FlushTelemetry(
            $mockTelemetry,
            $mockConversions,
            $mockErrors,
            $mockStateTracker,
        );

        $middleware->terminate(Request::create('/test'), new Response());

        // RequestContext should be reset after terminate
        $this->assertFalse(RequestContext::isInitialized());
    }
}
