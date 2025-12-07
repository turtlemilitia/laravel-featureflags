<?php

namespace FeatureFlags\Tests\Unit;

use Exception;
use FeatureFlags\Client\ApiClient;
use FeatureFlags\Exceptions\ApiException;
use FeatureFlags\Telemetry\ErrorCollector;
use FeatureFlags\Telemetry\FlagStateTracker;
use FeatureFlags\Tests\TestCase;
use Mockery;

class ErrorCollectorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_tracks_error_when_enabled(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.error_batch_size' => 100]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $collector = new ErrorCollector($mockApiClient);

        $exception = new Exception('Test error');
        $collector->trackAutomatic('my-flag', true, $exception);

        $this->assertEquals(1, $collector->pendingCount());
    }

    public function test_does_not_track_when_disabled(): void
    {
        config(['featureflags.telemetry.enabled' => false]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $collector = new ErrorCollector($mockApiClient);

        $exception = new Exception('Test error');
        $collector->trackAutomatic('my-flag', true, $exception);

        $this->assertEquals(0, $collector->pendingCount());
    }

    public function test_auto_flushes_at_batch_size(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.error_batch_size' => 2]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendErrors')->once();

        $collector = new ErrorCollector($mockApiClient);

        $exception = new Exception('Test error');
        $collector->trackAutomatic('flag-1', true, $exception);
        $collector->trackAutomatic('flag-2', false, $exception);

        $this->assertEquals(0, $collector->pendingCount());
    }

    public function test_flush_sends_pending_errors(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.error_batch_size' => 100]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendErrors')->once();

        $collector = new ErrorCollector($mockApiClient);

        $exception = new Exception('Test error message');
        $collector->trackAutomatic('my-flag', true, $exception);

        $this->assertEquals(1, $collector->pendingCount());

        $collector->flush();

        $this->assertEquals(0, $collector->pendingCount());
    }

    public function test_flush_does_nothing_when_empty(): void
    {
        config(['featureflags.telemetry.enabled' => true]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldNotReceive('sendErrors');

        $collector = new ErrorCollector($mockApiClient);
        $collector->flush();

        $this->assertEquals(0, $collector->pendingCount());
    }

    public function test_is_enabled_returns_correct_state(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        $mockApiClient = Mockery::mock(ApiClient::class);
        $enabledCollector = new ErrorCollector($mockApiClient);
        $this->assertTrue($enabledCollector->isEnabled());

        config(['featureflags.telemetry.enabled' => false]);
        $disabledCollector = new ErrorCollector($mockApiClient);
        $this->assertFalse($disabledCollector->isEnabled());
    }

    public function test_retry_on_failure_requeues_errors(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.error_batch_size' => 100]);
        config(['featureflags.telemetry.retry_on_failure' => true]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendErrors')
            ->once()
            ->andThrow(new ApiException('Send failed'));

        $collector = new ErrorCollector($mockApiClient);

        $exception = new Exception('Test error');
        $collector->trackAutomatic('my-flag', true, $exception);

        $collector->flush();

        // Error should be re-queued
        $this->assertEquals(1, $collector->pendingCount());
    }

    public function test_no_retry_on_failure_clears_errors(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.error_batch_size' => 100]);
        config(['featureflags.telemetry.retry_on_failure' => false]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendErrors')
            ->once()
            ->andThrow(new ApiException('Send failed'));

        $collector = new ErrorCollector($mockApiClient);

        $exception = new Exception('Test error');
        $collector->trackAutomatic('my-flag', true, $exception);

        $collector->flush();

        // Error should NOT be re-queued
        $this->assertEquals(0, $collector->pendingCount());
    }

    public function test_track_uses_state_tracker_for_value(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.error_batch_size' => 100]);

        // Set up state tracker with a value
        $stateTracker = new FlagStateTracker();
        $stateTracker->record('tracked-flag', 'tracked-value');
        $this->app->instance(FlagStateTracker::class, $stateTracker);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendErrors')->once();

        $collector = new ErrorCollector($mockApiClient);

        $exception = new Exception('Test error');
        $collector->track('tracked-flag', $exception);

        $this->assertEquals(1, $collector->pendingCount());
        $collector->flush();
    }

    public function test_tracks_error_with_metadata(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.error_batch_size' => 100]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $collector = new ErrorCollector($mockApiClient);

        $exception = new Exception('Test error');
        $collector->trackAutomatic('my-flag', true, $exception, ['custom_key' => 'custom_value']);

        $this->assertEquals(1, $collector->pendingCount());
    }

    public function test_track_returns_null_value_when_flag_not_in_state_tracker(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.error_batch_size' => 100]);

        // Empty state tracker - flag was never evaluated
        $stateTracker = new FlagStateTracker();
        $this->app->instance(FlagStateTracker::class, $stateTracker);

        $capturedErrors = null;
        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendErrors')
            ->once()
            ->andReturnUsing(function ($errors) use (&$capturedErrors) {
                $capturedErrors = $errors;
            });

        $collector = new ErrorCollector($mockApiClient);

        $exception = new Exception('Test error');
        $collector->track('unknown-flag', $exception);
        $collector->flush();

        $this->assertNotNull($capturedErrors);
        $this->assertCount(1, $capturedErrors);
        $this->assertEquals('unknown-flag', $capturedErrors[0]['flag_key']);
        $this->assertNull($capturedErrors[0]['flag_value']);
    }

    public function test_error_event_contains_correct_structure(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.error_batch_size' => 100]);

        $capturedErrors = null;
        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendErrors')
            ->once()
            ->andReturnUsing(function ($errors) use (&$capturedErrors) {
                $capturedErrors = $errors;
            });

        $collector = new ErrorCollector($mockApiClient);

        $exception = new Exception('Test error message');
        $collector->trackAutomatic('test-flag', 'variant-a', $exception, ['request_path' => '/checkout']);

        $collector->flush();

        $this->assertNotNull($capturedErrors);
        $this->assertCount(1, $capturedErrors);

        $error = $capturedErrors[0];
        $this->assertEquals('test-flag', $error['flag_key']);
        $this->assertEquals('variant-a', $error['flag_value']);
        $this->assertEquals(Exception::class, $error['error_type']);
        $this->assertEquals('Test error message', $error['error_message']);
        $this->assertArrayHasKey('stack_trace', $error);
        $this->assertArrayHasKey('metadata', $error);
        $this->assertEquals('/checkout', $error['metadata']['request_path']);
        $this->assertArrayHasKey('occurred_at', $error);
    }

    public function test_track_manual_does_not_record_when_disabled(): void
    {
        config(['featureflags.telemetry.enabled' => false]);

        $mockApiClient = Mockery::mock(ApiClient::class);
        $collector = new ErrorCollector($mockApiClient);

        $exception = new Exception('Test error');
        $collector->track('my-flag', $exception);

        $this->assertEquals(0, $collector->pendingCount());
    }

    public function test_records_context_id_from_authenticated_user(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.error_batch_size' => 100]);

        // Create a mock user
        $mockUser = Mockery::mock(\Illuminate\Contracts\Auth\Authenticatable::class);
        $mockUser->shouldReceive('getAuthIdentifier')->andReturn(123);
        $mockUser->shouldReceive('getAuthIdentifierName')->andReturn('id');
        $mockUser->shouldReceive('getAuthPassword')->andReturn('password');
        $mockUser->shouldReceive('getRememberToken')->andReturn(null);
        $mockUser->shouldReceive('getRememberTokenName')->andReturn('remember_token');

        // Set the authenticated user
        $this->actingAs($mockUser);

        $capturedErrors = null;
        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendErrors')
            ->once()
            ->andReturnUsing(function ($errors) use (&$capturedErrors) {
                $capturedErrors = $errors;
            });

        $collector = new ErrorCollector($mockApiClient);

        $exception = new Exception('Test error');
        $collector->trackAutomatic('test-flag', true, $exception);
        $collector->flush();

        $this->assertNotNull($capturedErrors);
        $this->assertEquals('123', $capturedErrors[0]['context_id']);
    }

    public function test_context_id_is_null_when_user_not_authenticated(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.error_batch_size' => 100]);

        // Make sure no user is authenticated
        \Illuminate\Support\Facades\Auth::logout();

        $capturedErrors = null;
        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendErrors')
            ->once()
            ->andReturnUsing(function ($errors) use (&$capturedErrors) {
                $capturedErrors = $errors;
            });

        $collector = new ErrorCollector($mockApiClient);

        $exception = new Exception('Test error');
        $collector->trackAutomatic('test-flag', true, $exception);
        $collector->flush();

        $this->assertNotNull($capturedErrors);
        $this->assertNull($capturedErrors[0]['context_id']);
    }

    public function test_context_id_handles_string_identifier(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.error_batch_size' => 100]);

        $mockUser = Mockery::mock(\Illuminate\Contracts\Auth\Authenticatable::class);
        $mockUser->shouldReceive('getAuthIdentifier')->andReturn('user-uuid-123');
        $mockUser->shouldReceive('getAuthIdentifierName')->andReturn('id');
        $mockUser->shouldReceive('getAuthPassword')->andReturn('password');
        $mockUser->shouldReceive('getRememberToken')->andReturn(null);
        $mockUser->shouldReceive('getRememberTokenName')->andReturn('remember_token');

        $this->actingAs($mockUser);

        $capturedErrors = null;
        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendErrors')
            ->once()
            ->andReturnUsing(function ($errors) use (&$capturedErrors) {
                $capturedErrors = $errors;
            });

        $collector = new ErrorCollector($mockApiClient);

        $exception = new Exception('Test error');
        $collector->trackAutomatic('test-flag', true, $exception);
        $collector->flush();

        $this->assertNotNull($capturedErrors);
        $this->assertEquals('user-uuid-123', $capturedErrors[0]['context_id']);
    }

    public function test_get_tracked_flag_value_handles_exception(): void
    {
        config(['featureflags.telemetry.enabled' => true]);
        config(['featureflags.telemetry.error_batch_size' => 100]);

        // Make FlagStateTracker throw when resolved
        $this->app->bind(FlagStateTracker::class, function () {
            throw new \RuntimeException('State tracker unavailable');
        });

        $capturedErrors = null;
        $mockApiClient = Mockery::mock(ApiClient::class);
        $mockApiClient->shouldReceive('sendErrors')
            ->once()
            ->andReturnUsing(function ($errors) use (&$capturedErrors) {
                $capturedErrors = $errors;
            });

        $collector = new ErrorCollector($mockApiClient);

        $exception = new Exception('Test error');
        $collector->track('test-flag', $exception);
        $collector->flush();

        $this->assertNotNull($capturedErrors);
        // When state tracker throws, flag_value should be null
        $this->assertNull($capturedErrors[0]['flag_value']);
    }
}
