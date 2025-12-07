<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Context;
use FeatureFlags\Context\RequestContext;
use FeatureFlags\Telemetry\FlagStateTracker;
use FeatureFlags\Tests\TestCase;

class FlagStateTrackerTest extends TestCase
{
    private FlagStateTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tracker = new FlagStateTracker();
        RequestContext::reset();
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        parent::tearDown();
    }

    public function test_records_flag_evaluation(): void
    {
        $context = new Context('user-123', ['plan' => 'pro']);
        $this->tracker->record('my-flag', true, $context);

        $this->assertEquals(1, $this->tracker->count());
        $this->assertTrue($this->tracker->wasEvaluated('my-flag'));
    }

    public function test_get_evaluated_flags_returns_values_only(): void
    {
        $context = new Context('user-123');
        $this->tracker->record('flag-1', true, $context);
        $this->tracker->record('flag-2', 'variant-a', $context);
        $this->tracker->record('flag-3', 42, $context);

        $flags = $this->tracker->getEvaluatedFlags();

        $this->assertCount(3, $flags);
        $this->assertTrue($flags['flag-1']);
        $this->assertEquals('variant-a', $flags['flag-2']);
        $this->assertEquals(42, $flags['flag-3']);
    }

    public function test_get_evaluated_flags_with_metadata(): void
    {
        $context = new Context('user-456');
        $this->tracker->record('my-flag', 'test-value', $context);

        $flagsWithMeta = $this->tracker->getEvaluatedFlagsWithMetadata();

        $this->assertArrayHasKey('my-flag', $flagsWithMeta);
        $this->assertEquals('test-value', $flagsWithMeta['my-flag']['value']);
        $this->assertEquals('user-456', $flagsWithMeta['my-flag']['context_id']);
        $this->assertArrayHasKey('timestamp', $flagsWithMeta['my-flag']);
    }

    public function test_was_evaluated_returns_true_for_recorded_flag(): void
    {
        $this->tracker->record('known-flag', true, null);

        $this->assertTrue($this->tracker->wasEvaluated('known-flag'));
    }

    public function test_was_evaluated_returns_false_for_unknown_flag(): void
    {
        $this->assertFalse($this->tracker->wasEvaluated('unknown-flag'));
    }

    public function test_get_value_returns_recorded_value(): void
    {
        $this->tracker->record('string-flag', 'variant-b', null);
        $this->tracker->record('bool-flag', false, null);

        $this->assertEquals('variant-b', $this->tracker->getValue('string-flag'));
        $this->assertFalse($this->tracker->getValue('bool-flag'));
    }

    public function test_get_value_returns_null_for_unknown_flag(): void
    {
        $this->assertNull($this->tracker->getValue('unknown-flag'));
    }

    public function test_count_returns_number_of_flags(): void
    {
        $this->assertEquals(0, $this->tracker->count());

        $this->tracker->record('flag-1', true, null);
        $this->assertEquals(1, $this->tracker->count());

        $this->tracker->record('flag-2', false, null);
        $this->assertEquals(2, $this->tracker->count());

        // Recording same flag again doesn't increase count
        $this->tracker->record('flag-1', true, null);
        $this->assertEquals(2, $this->tracker->count());
    }

    public function test_reset_clears_all_tracked_flags(): void
    {
        $this->tracker->record('flag-1', true, null);
        $this->tracker->record('flag-2', false, null);

        $this->assertEquals(2, $this->tracker->count());

        $this->tracker->reset();

        $this->assertEquals(0, $this->tracker->count());
        $this->assertFalse($this->tracker->wasEvaluated('flag-1'));
        $this->assertFalse($this->tracker->wasEvaluated('flag-2'));
    }

    public function test_to_error_context_formats_correctly(): void
    {
        RequestContext::initialize();

        $this->tracker->record('feature-a', true, null);
        $this->tracker->record('feature-b', 'control', null);

        $errorContext = $this->tracker->toErrorContext();

        $this->assertArrayHasKey('flags', $errorContext);
        $this->assertArrayHasKey('count', $errorContext);
        $this->assertArrayHasKey('request_id', $errorContext);

        $this->assertEquals(2, $errorContext['count']);
        $this->assertTrue($errorContext['flags']['feature-a']);
        $this->assertEquals('control', $errorContext['flags']['feature-b']);
        $this->assertNotNull($errorContext['request_id']);
    }
}
