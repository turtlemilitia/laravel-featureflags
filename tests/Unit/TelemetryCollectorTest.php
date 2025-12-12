<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Client\ApiClient;
use FeatureFlags\Context;
use FeatureFlags\Context\DeviceIdentifier;
use FeatureFlags\Exceptions\ApiException;
use FeatureFlags\Jobs\SendTelemetry;
use FeatureFlags\Telemetry\TelemetryCollector;
use FeatureFlags\Tests\TestCase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Mockery;

class TelemetryCollectorTest extends TestCase
{
    protected function tearDown(): void
    {
        DeviceIdentifier::reset();
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

    public function test_hold_mode_prevents_auto_flush(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.batch_size' => 2]);
        config(['featureflags.telemetry.hold_until_consent' => true]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldNotReceive('sendTelemetry');

        $collector = new TelemetryCollector($mockApiClient);

        $context = new Context('user-123');
        $collector->record('flag-1', true, $context);
        $collector->record('flag-2', false, $context);
        $collector->record('flag-3', 'value', $context);

        // Events should be held, not flushed
        $this->assertEquals(3, $collector->pendingCount());
        $this->assertTrue($collector->isHolding());
    }

    public function test_flush_after_consent_sends_held_events(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.batch_size' => 100]);
        config(['featureflags.telemetry.hold_until_consent' => true]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendTelemetry')->once();

        $collector = new TelemetryCollector($mockApiClient);

        $context = new Context('user-123');
        $collector->record('flag-1', true, $context);
        $collector->record('flag-2', false, $context);

        $this->assertEquals(2, $collector->pendingCount());
        $this->assertTrue($collector->isHolding());

        // Grant consent via DeviceIdentifier, then flush
        DeviceIdentifier::grantConsent();
        $collector->flush();

        $this->assertEquals(0, $collector->pendingCount());
        $this->assertFalse($collector->isHolding());
    }

    public function test_discard_held_clears_events_without_sending(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.batch_size' => 100]);
        config(['featureflags.telemetry.hold_until_consent' => true]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldNotReceive('sendTelemetry');

        $collector = new TelemetryCollector($mockApiClient);

        $context = new Context('user-123');
        $collector->record('flag-1', true, $context);
        $collector->record('flag-2', false, $context);

        $this->assertEquals(2, $collector->pendingCount());

        $collector->discardHeld();

        $this->assertEquals(0, $collector->pendingCount());
    }

    public function test_is_not_holding_when_hold_mode_disabled(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.hold_until_consent' => false]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $collector = new TelemetryCollector($mockApiClient);

        $this->assertFalse($collector->isHolding());
    }

    public function test_is_not_holding_after_consent_granted(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.hold_until_consent' => true]);

        $mockApiClient = Mockery::mock(ApiClient::class);

        $collector = new TelemetryCollector($mockApiClient);

        $this->assertTrue($collector->isHolding());

        DeviceIdentifier::grantConsent();

        $this->assertFalse($collector->isHolding());
    }

    public function test_auto_flushes_after_consent_granted(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.batch_size' => 2]);
        config(['featureflags.telemetry.hold_until_consent' => true]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        // Only called once from auto-flush at batch_size
        $mockApiClient->shouldReceive('sendTelemetry')->once();

        $collector = new TelemetryCollector($mockApiClient);

        // Grant consent (via DeviceIdentifier, not collector - no flush of empty events)
        DeviceIdentifier::grantConsent();

        $context = new Context('user-123');
        $collector->record('flag-1', true, $context);
        $collector->record('flag-2', false, $context);

        // Should auto-flush since we have consent
        $this->assertEquals(0, $collector->pendingCount());
    }

    public function test_async_mode_dispatches_job_instead_of_calling_api(): void
    {
        Bus::fake([SendTelemetry::class]);

        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.async' => true]);
        config(['featureflags.telemetry.batch_size' => 100]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        // In async mode, API should NOT be called directly on the collector
        $mockApiClient->shouldNotReceive('sendTelemetry');

        $collector = new TelemetryCollector($mockApiClient);

        $context = new Context('user-123');
        $collector->record('flag-1', true, $context);
        $collector->record('flag-2', false, $context);

        $this->assertEquals(2, $collector->pendingCount());

        // Flush should dispatch to queue, not call API
        $collector->flush();

        // Events should be cleared after dispatch
        $this->assertEquals(0, $collector->pendingCount());

        // Assert job was dispatched with correct type and events
        Bus::assertDispatched(SendTelemetry::class, function (SendTelemetry $job) {
            return $job->type === 'telemetry' && count($job->events) === 2;
        });
    }

    public function test_async_mode_uses_custom_queue(): void
    {
        Bus::fake([SendTelemetry::class]);

        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.async' => true]);
        config(['featureflags.telemetry.queue' => 'telemetry-queue']);
        config(['featureflags.telemetry.batch_size' => 100]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $collector = new TelemetryCollector($mockApiClient);

        $context = new Context('user-123');
        $collector->record('flag-1', true, $context);
        $collector->flush();

        Bus::assertDispatched(SendTelemetry::class, function (SendTelemetry $job) {
            return $job->queue === 'telemetry-queue';
        });
    }

    public function test_sync_mode_calls_api_directly(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.async' => false]);
        config(['featureflags.telemetry.batch_size' => 100]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        // In sync mode, API should be called directly
        $mockApiClient->shouldReceive('sendTelemetry')->once();

        $collector = new TelemetryCollector($mockApiClient);

        $context = new Context('user-123');
        $collector->record('flag-1', true, $context);

        $collector->flush();

        $this->assertEquals(0, $collector->pendingCount());
    }
}
