<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Client\ApiClient;
use FeatureFlags\Context;
use FeatureFlags\Exceptions\ApiException;
use FeatureFlags\Telemetry\TelemetryCollector;
use FeatureFlags\Tests\TestCase;
use Mockery;

class TelemetryCollectorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_records_evaluation_when_enabled(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.batch_size' => 100]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $collector = new TelemetryCollector($mockApiClient);

        $context = new Context('user-123', ['plan' => 'pro']);
        $collector->record('my-flag', true, $context, 1);

        $this->assertEquals(1, $collector->pendingCount());
    }

    public function test_does_not_record_when_disabled(): void
    {
        config(['featureflags.telemetry.enabled' => false]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $collector = new TelemetryCollector($mockApiClient);

        $context = new Context('user-123', ['plan' => 'pro']);
        $collector->record('my-flag', true, $context, 1);

        $this->assertEquals(0, $collector->pendingCount());
    }

    public function test_auto_flushes_at_batch_size(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.batch_size' => 3]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendTelemetry')->once();

        $collector = new TelemetryCollector($mockApiClient);

        $context = new Context('user-123');
        $collector->record('flag-1', true, $context);
        $collector->record('flag-2', false, $context);
        $collector->record('flag-3', 'value', $context);

        // After auto-flush, pending count should be 0
        $this->assertEquals(0, $collector->pendingCount());
    }

    public function test_flush_sends_pending_events(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.batch_size' => 100]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendTelemetry')->once();

        $collector = new TelemetryCollector($mockApiClient);

        $context = new Context('user-123');
        $collector->record('flag-1', true, $context);
        $collector->record('flag-2', false, $context);

        $this->assertEquals(2, $collector->pendingCount());

        $collector->flush();

        $this->assertEquals(0, $collector->pendingCount());
    }

    public function test_flush_does_nothing_when_empty(): void
    {
        config(['featureflags.telemetry.enabled' => true]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldNotReceive('sendTelemetry');

        $collector = new TelemetryCollector($mockApiClient);
        $collector->flush();

        $this->assertEquals(0, $collector->pendingCount());
    }

    public function test_is_enabled_returns_correct_state(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        $mockApiClient = Mockery::mock(ApiClient::class);
        $enabledCollector = new TelemetryCollector($mockApiClient);
        $this->assertTrue($enabledCollector->isEnabled());

        config(['featureflags.telemetry.enabled' => false]);
        $disabledCollector = new TelemetryCollector($mockApiClient);
        $this->assertFalse($disabledCollector->isEnabled());
    }

    public function test_records_evaluation_with_null_context(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.batch_size' => 100]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $collector = new TelemetryCollector($mockApiClient);
        $collector->record('my-flag', true, null);

        $this->assertEquals(1, $collector->pendingCount());
    }

    public function test_retry_on_failure_requeues_events(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.batch_size' => 100]);
        config(['featureflags.telemetry.retry_on_failure' => true]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendTelemetry')
            ->once()
            ->andThrow(new ApiException('Send failed'));

        $collector = new TelemetryCollector($mockApiClient);

        $context = new Context('user-123');
        $collector->record('flag-1', true, $context);
        $collector->record('flag-2', false, $context);

        $collector->flush();

        // Events should be re-queued after failure
        $this->assertEquals(2, $collector->pendingCount());
    }

    public function test_no_retry_on_failure_clears_events(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.batch_size' => 100]);
        config(['featureflags.telemetry.retry_on_failure' => false]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendTelemetry')
            ->once()
            ->andThrow(new ApiException('Send failed'));

        $collector = new TelemetryCollector($mockApiClient);

        $context = new Context('user-123');
        $collector->record('flag-1', true, $context);

        $collector->flush();

        // Events should NOT be re-queued
        $this->assertEquals(0, $collector->pendingCount());
    }

    public function test_records_match_reason_and_rule_index(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.batch_size' => 100]);

        $capturedEvents = null;
        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendTelemetry')
            ->once()
            ->andReturnUsing(function ($events) use (&$capturedEvents) {
                $capturedEvents = $events;
            });

        $collector = new TelemetryCollector($mockApiClient);

        $context = new Context('user-123', ['plan' => 'pro']);
        $collector->record('my-flag', true, $context, 2, 'rule');

        $collector->flush();

        $this->assertNotNull($capturedEvents);
        $this->assertCount(1, $capturedEvents);
        $this->assertEquals('rule', $capturedEvents[0]['match_reason']);
        $this->assertEquals(2, $capturedEvents[0]['matched_rule_index']);
    }

    public function test_records_duration_when_provided(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.batch_size' => 100]);

        $capturedEvents = null;
        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendTelemetry')
            ->once()
            ->andReturnUsing(function ($events) use (&$capturedEvents) {
                $capturedEvents = $events;
            });

        $collector = new TelemetryCollector($mockApiClient);

        $context = new Context('user-123');
        $collector->record('my-flag', true, $context, null, 'default', 5);

        $collector->flush();

        $this->assertNotNull($capturedEvents);
        $this->assertCount(1, $capturedEvents);
        $this->assertEquals(5, $capturedEvents[0]['duration_ms']);
    }

    public function test_does_not_include_match_metadata_when_null(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.batch_size' => 100]);

        $capturedEvents = null;
        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendTelemetry')
            ->once()
            ->andReturnUsing(function ($events) use (&$capturedEvents) {
                $capturedEvents = $events;
            });

        $collector = new TelemetryCollector($mockApiClient);

        $context = new Context('user-123');
        $collector->record('my-flag', true, $context);

        $collector->flush();

        $this->assertNotNull($capturedEvents);
        $this->assertCount(1, $capturedEvents);
        $this->assertArrayNotHasKey('match_reason', $capturedEvents[0]);
        $this->assertArrayNotHasKey('matched_rule_index', $capturedEvents[0]);
        $this->assertArrayNotHasKey('duration_ms', $capturedEvents[0]);
    }

    public function test_includes_reason_but_not_index_when_index_is_null(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.batch_size' => 100]);

        $capturedEvents = null;
        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendTelemetry')
            ->once()
            ->andReturnUsing(function ($events) use (&$capturedEvents) {
                $capturedEvents = $events;
            });

        $collector = new TelemetryCollector($mockApiClient);

        $context = new Context('user-123');
        $collector->record('my-flag', true, $context, null, 'default');

        $collector->flush();

        $this->assertNotNull($capturedEvents);
        $this->assertEquals('default', $capturedEvents[0]['match_reason']);
        $this->assertArrayNotHasKey('matched_rule_index', $capturedEvents[0]);
    }

    public function test_records_event_with_all_fields(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.batch_size' => 100]);

        $capturedEvents = null;
        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendTelemetry')
            ->once()
            ->andReturnUsing(function ($events) use (&$capturedEvents) {
                $capturedEvents = $events;
            });

        $collector = new TelemetryCollector($mockApiClient);

        $context = new Context('user-123', ['plan' => 'pro']);
        $collector->record('my-flag', 'variant-b', $context, 1, 'rule', 3);

        $collector->flush();

        $this->assertNotNull($capturedEvents);
        $this->assertCount(1, $capturedEvents);
        $event = $capturedEvents[0];

        $this->assertEquals('my-flag', $event['flag_key']);
        $this->assertEquals('variant-b', $event['value']);
        $this->assertEquals('user-123', $event['context_id']);
        $this->assertEquals('rule', $event['match_reason']);
        $this->assertEquals(1, $event['matched_rule_index']);
        $this->assertEquals(3, $event['duration_ms']);
        $this->assertArrayHasKey('timestamp', $event);
    }
}
