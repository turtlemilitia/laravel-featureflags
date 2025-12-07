<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Client\ApiClient;
use FeatureFlags\Context;
use FeatureFlags\Exceptions\ApiException;
use FeatureFlags\Telemetry\ConversionCollector;
use FeatureFlags\Tests\TestCase;
use Mockery;

class ConversionCollectorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_tracks_conversion_when_enabled(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.batch_size' => 100]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $collector = new ConversionCollector($mockApiClient);

        $context = new Context('user-123', ['plan' => 'pro']);
        $collector->track('purchase', $context);

        $this->assertEquals(1, $collector->pendingCount());
    }

    public function test_does_not_track_when_disabled(): void
    {
        config(['featureflags.telemetry.enabled' => false]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $collector = new ConversionCollector($mockApiClient);

        $context = new Context('user-123', ['plan' => 'pro']);
        $collector->track('purchase', $context);

        $this->assertEquals(0, $collector->pendingCount());
    }

    public function test_auto_flushes_at_batch_size(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.batch_size' => 3]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendConversions')->once();

        $collector = new ConversionCollector($mockApiClient);

        $context = new Context('user-123');
        $collector->track('event-1', $context);
        $collector->track('event-2', $context);
        $collector->track('event-3', $context);

        $this->assertEquals(0, $collector->pendingCount());
    }

    public function test_flush_sends_pending_conversions(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.batch_size' => 100]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendConversions')->once();

        $collector = new ConversionCollector($mockApiClient);

        $context = new Context('user-123');
        $collector->track('purchase', $context);
        $collector->track('signup', $context);

        $this->assertEquals(2, $collector->pendingCount());

        $collector->flush();

        $this->assertEquals(0, $collector->pendingCount());
    }

    public function test_flush_does_nothing_when_empty(): void
    {
        config(['featureflags.telemetry.enabled' => true]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldNotReceive('sendConversions');

        $collector = new ConversionCollector($mockApiClient);
        $collector->flush();

        $this->assertEquals(0, $collector->pendingCount());
    }

    public function test_is_enabled_returns_correct_state(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        $mockApiClient = Mockery::mock(ApiClient::class);
        $enabledCollector = new ConversionCollector($mockApiClient);
        $this->assertTrue($enabledCollector->isEnabled());

        config(['featureflags.telemetry.enabled' => false]);
        $disabledCollector = new ConversionCollector($mockApiClient);
        $this->assertFalse($disabledCollector->isEnabled());
    }

    public function test_includes_properties_in_event(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.batch_size' => 100]);

        $capturedEvents = null;
        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendConversions')
            ->once()
            ->andReturnUsing(function ($events) use (&$capturedEvents) {
                $capturedEvents = $events;
            });

        $collector = new ConversionCollector($mockApiClient);

        $context = new Context('user-123');
        $properties = ['revenue' => 99.99, 'currency' => 'USD'];
        $collector->track('purchase', $context, $properties);

        $collector->flush();

        $this->assertNotNull($capturedEvents);
        $this->assertCount(1, $capturedEvents);
        $this->assertArrayHasKey('properties', $capturedEvents[0]);
        $this->assertEquals(99.99, $capturedEvents[0]['properties']['revenue']);
        $this->assertEquals('USD', $capturedEvents[0]['properties']['currency']);
    }

    public function test_retry_on_failure_requeues_events(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.batch_size' => 100]);
        config(['featureflags.telemetry.retry_on_failure' => true]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendConversions')
            ->once()
            ->andThrow(new ApiException('Send failed'));

        $collector = new ConversionCollector($mockApiClient);

        $context = new Context('user-123');
        $collector->track('purchase', $context);
        $collector->track('signup', $context);

        $collector->flush();

        $this->assertEquals(2, $collector->pendingCount());
    }

    public function test_no_retry_on_failure_clears_events(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.batch_size' => 100]);
        config(['featureflags.telemetry.retry_on_failure' => false]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendConversions')
            ->once()
            ->andThrow(new ApiException('Send failed'));

        $collector = new ConversionCollector($mockApiClient);

        $context = new Context('user-123');
        $collector->track('purchase', $context);

        $collector->flush();

        $this->assertEquals(0, $collector->pendingCount());
    }
}
