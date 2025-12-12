<?php

declare(strict_types=1);

namespace FeatureFlags\Tests\Feature;

use FeatureFlags\Events\FlagEvaluated;
use FeatureFlags\Events\FlagSyncCompleted;
use FeatureFlags\Events\TelemetryFlushed;
use FeatureFlags\FeatureFlags;
use FeatureFlags\Telemetry\TelemetryCollector;
use Illuminate\Support\Facades\Event;

class EventsIntegrationTest extends FeatureTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('featureflags.events.enabled', true);
        $app['config']->set('featureflags.events.dispatch.flag_evaluated', true);
        $app['config']->set('featureflags.events.dispatch.flag_sync_completed', true);
        $app['config']->set('featureflags.events.dispatch.telemetry_flushed', true);
    }

    public function test_flag_evaluated_event_dispatched(): void
    {
        Event::fake([FlagEvaluated::class]);

        $this->seedFlags([
            $this->simpleFlag('event-flag', true, true),
        ]);

        /** @var FeatureFlags $ff */
        $ff = $this->app->make(FeatureFlags::class);
        $ff->active('event-flag');

        Event::assertDispatched(FlagEvaluated::class, function (FlagEvaluated $event) {
            return $event->flagKey === 'event-flag'
                && $event->value === true
                && $event->matchReason === 'default';
        });
    }

    public function test_flag_evaluated_event_contains_correct_data(): void
    {
        Event::fake([FlagEvaluated::class]);

        $this->seedFlags([
            $this->flagWithRules('rule-flag', [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro'],
                    ],
                    'value' => 'pro-value',
                ],
            ], 'default-value'),
        ]);

        /** @var FeatureFlags $ff */
        $ff = $this->app->make(FeatureFlags::class);
        $ff->value('rule-flag', ['id' => 'user-1', 'plan' => 'pro']);

        Event::assertDispatched(FlagEvaluated::class, function (FlagEvaluated $event) {
            return $event->flagKey === 'rule-flag'
                && $event->value === 'pro-value'
                && $event->matchReason === 'rule'
                && $event->matchedRuleIndex === 0
                && $event->contextId === 'user-1'
                && $event->durationMs >= 0;
        });
    }

    public function test_flag_sync_completed_event_dispatched(): void
    {
        Event::fake([FlagSyncCompleted::class]);

        $this->mockApiResponse([
            $this->simpleFlag('sync-flag-1', true),
            $this->simpleFlag('sync-flag-2', true),
        ], [
            ['key' => 'segment-1', 'rules' => []],
        ]);

        /** @var FeatureFlags $ff */
        $ff = $this->app->make(FeatureFlags::class);
        $ff->sync();

        Event::assertDispatched(FlagSyncCompleted::class, function (FlagSyncCompleted $event) {
            return $event->flagCount === 2
                && $event->segmentCount === 1
                && $event->source === 'api'
                && $event->durationMs >= 0;
        });
    }

    public function test_telemetry_flushed_event_dispatched(): void
    {
        Event::fake([TelemetryFlushed::class]);

        $this->app['config']->set('featureflags.telemetry.enabled', true);

        // Recreate collector with telemetry enabled
        $this->app->forgetInstance(TelemetryCollector::class);

        /** @var TelemetryCollector $telemetry */
        $telemetry = $this->app->make(TelemetryCollector::class);

        // Record and flush
        $telemetry->record('flush-flag', true, null, null, 'default', 1);
        $telemetry->flush();

        Event::assertDispatched(TelemetryFlushed::class, function (TelemetryFlushed $event) {
            return $event->type === 'telemetry'
                && $event->eventCount === 1
                && $event->durationMs >= 0;
        });
    }

    public function test_events_not_dispatched_when_disabled(): void
    {
        $this->app['config']->set('featureflags.events.enabled', false);

        Event::fake([FlagEvaluated::class, FlagSyncCompleted::class]);

        $this->seedFlags([
            $this->simpleFlag('no-event-flag', true),
        ]);

        /** @var FeatureFlags $ff */
        $ff = $this->app->make(FeatureFlags::class);
        $ff->active('no-event-flag');

        Event::assertNotDispatched(FlagEvaluated::class);
    }

    public function test_individual_event_types_can_be_disabled(): void
    {
        $this->app['config']->set('featureflags.events.dispatch.flag_evaluated', false);
        $this->app['config']->set('featureflags.events.dispatch.flag_sync_completed', true);

        Event::fake([FlagEvaluated::class, FlagSyncCompleted::class]);

        $this->mockApiResponse([
            $this->simpleFlag('partial-flag', true),
        ]);

        /** @var FeatureFlags $ff */
        $ff = $this->app->make(FeatureFlags::class);
        $ff->sync();
        $ff->active('partial-flag');

        Event::assertNotDispatched(FlagEvaluated::class);
        Event::assertDispatched(FlagSyncCompleted::class);
    }

    public function test_flag_not_found_event_has_correct_match_reason(): void
    {
        Event::fake([FlagEvaluated::class]);

        $this->seedFlags([]);

        /** @var FeatureFlags $ff */
        $ff = $this->app->make(FeatureFlags::class);
        $ff->active('nonexistent-flag');

        Event::assertDispatched(FlagEvaluated::class, function (FlagEvaluated $event) {
            return $event->flagKey === 'nonexistent-flag'
                && $event->value === false
                && $event->matchReason === 'not_found';
        });
    }

    public function test_disabled_flag_event_has_correct_match_reason(): void
    {
        Event::fake([FlagEvaluated::class]);

        $this->seedFlags([
            $this->simpleFlag('disabled-flag', false, 'disabled-value'),
        ]);

        /** @var FeatureFlags $ff */
        $ff = $this->app->make(FeatureFlags::class);
        $ff->value('disabled-flag');

        Event::assertDispatched(FlagEvaluated::class, function (FlagEvaluated $event) {
            return $event->matchReason === 'disabled';
        });
    }

    public function test_rollout_flag_event_has_correct_match_reason(): void
    {
        Event::fake([FlagEvaluated::class]);

        $this->seedFlags([
            [
                'key' => 'rollout-event-flag',
                'enabled' => true,
                'default_value' => true,
                'rollout_percentage' => 100, // Always in rollout
                'rules' => [],
            ],
        ]);

        /** @var FeatureFlags $ff */
        $ff = $this->app->make(FeatureFlags::class);
        $ff->active('rollout-event-flag', ['id' => 'user-123']);

        Event::assertDispatched(FlagEvaluated::class, function (FlagEvaluated $event) {
            return $event->matchReason === 'rollout';
        });
    }

    public function test_event_listener_receives_accurate_timing(): void
    {
        $capturedDuration = null;

        Event::listen(FlagEvaluated::class, function (FlagEvaluated $event) use (&$capturedDuration) {
            $capturedDuration = $event->durationMs;
        });

        $this->seedFlags([
            $this->simpleFlag('timing-flag', true),
        ]);

        /** @var FeatureFlags $ff */
        $ff = $this->app->make(FeatureFlags::class);
        $ff->active('timing-flag');

        $this->assertNotNull($capturedDuration);
        $this->assertIsFloat($capturedDuration);
        $this->assertGreaterThanOrEqual(0, $capturedDuration);
        $this->assertLessThan(1000, $capturedDuration); // Should be well under 1 second
    }
}
