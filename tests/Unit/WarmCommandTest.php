<?php

declare(strict_types=1);

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Contracts\FeatureFlagsInterface;
use FeatureFlags\Exceptions\FlagSyncException;
use FeatureFlags\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

class WarmCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_skips_warming_in_local_mode(): void
    {
        $mock = $this->mockFeatureFlags();
        $mock->shouldReceive('isLocalMode')->once()->andReturn(true);
        $mock->shouldNotReceive('flush');
        $mock->shouldNotReceive('sync');

        $this->artisan('featureflags:warm')
            ->expectsOutput('Local mode enabled - cache warming not needed.')
            ->assertSuccessful();
    }

    public function test_warms_cache_successfully(): void
    {
        $mock = $this->mockFeatureFlags();
        $mock->shouldReceive('isLocalMode')->once()->andReturn(false);
        $mock->shouldReceive('flush')->once();
        $mock->shouldReceive('sync')->once();
        $mock->shouldReceive('all')->once()->andReturn([
            ['key' => 'flag-1', 'enabled' => true],
            ['key' => 'flag-2', 'enabled' => false],
        ]);

        $this->artisan('featureflags:warm')
            ->expectsOutputToContain('Warming feature flags cache...')
            ->expectsOutputToContain('Cached 2 flag(s)')
            ->assertSuccessful();
    }

    public function test_displays_flags_in_verbose_mode(): void
    {
        $mock = $this->mockFeatureFlags();
        $mock->shouldReceive('isLocalMode')->once()->andReturn(false);
        $mock->shouldReceive('flush')->once();
        $mock->shouldReceive('sync')->once();
        $mock->shouldReceive('all')->once()->andReturn([
            ['key' => 'new-feature', 'enabled' => true],
            ['key' => 'old-feature', 'enabled' => false],
        ]);

        $this->artisan('featureflags:warm', ['-v' => true])
            ->expectsOutputToContain('new-feature')
            ->expectsOutputToContain('old-feature')
            ->assertSuccessful();
    }

    public function test_retries_on_sync_failure(): void
    {
        $mock = $this->mockFeatureFlags();
        $mock->shouldReceive('isLocalMode')->andReturn(false);
        $mock->shouldReceive('flush')->times(2);
        $mock->shouldReceive('sync')
            ->once()
            ->andThrow(new FlagSyncException('Connection failed'));
        $mock->shouldReceive('sync')
            ->once()
            ->andReturn(null);
        $mock->shouldReceive('all')->once()->andReturn([
            ['key' => 'flag-1', 'enabled' => true],
        ]);

        // --retry-delay=0 skips the sleep for fast tests
        $this->artisan('featureflags:warm', ['--retry' => 2, '--retry-delay' => 0])
            ->expectsOutputToContain('Attempt 1/2 failed')
            ->expectsOutputToContain('Cached 1 flag(s)')
            ->assertSuccessful();
    }

    public function test_fails_after_max_retries(): void
    {
        $mock = $this->mockFeatureFlags();
        $mock->shouldReceive('isLocalMode')->andReturn(false);
        $mock->shouldReceive('flush')->times(2);
        $mock->shouldReceive('sync')
            ->times(2)
            ->andThrow(new FlagSyncException('Connection failed'));

        $this->artisan('featureflags:warm', ['--retry' => 2, '--retry-delay' => 0])
            ->expectsOutputToContain('Attempt 1/2 failed')
            ->expectsOutputToContain('Attempt 2/2 failed')
            ->expectsOutputToContain('Failed to warm cache after 2 attempt(s)')
            ->assertFailed();
    }

    public function test_fails_immediately_on_unexpected_error(): void
    {
        $mock = $this->mockFeatureFlags();
        $mock->shouldReceive('isLocalMode')->andReturn(false);
        $mock->shouldReceive('flush')->once();
        $mock->shouldReceive('sync')
            ->once()
            ->andThrow(new \RuntimeException('Unexpected error'));

        $this->artisan('featureflags:warm', ['--retry' => 3])
            ->expectsOutputToContain('Unexpected error')
            ->assertFailed();
    }

    public function test_uses_default_retry_options(): void
    {
        $mock = $this->mockFeatureFlags();
        $mock->shouldReceive('isLocalMode')->andReturn(false);
        $mock->shouldReceive('flush')->once();
        $mock->shouldReceive('sync')->once();
        $mock->shouldReceive('all')->once()->andReturn([]);

        $this->artisan('featureflags:warm')
            ->expectsOutputToContain('Cached 0 flag(s)')
            ->assertSuccessful();
    }

    public function test_handles_minimum_retry_values(): void
    {
        $mock = $this->mockFeatureFlags();
        $mock->shouldReceive('isLocalMode')->andReturn(false);
        $mock->shouldReceive('flush')->once();
        $mock->shouldReceive('sync')
            ->once()
            ->andThrow(new FlagSyncException('Failed'));

        // --retry=0 should be clamped to minimum of 1
        $this->artisan('featureflags:warm', ['--retry' => 0, '--retry-delay' => 0])
            ->expectsOutputToContain('Failed to warm cache after 1 attempt(s)')
            ->assertFailed();
    }

    public function test_handles_flags_without_key_or_enabled(): void
    {
        $mock = $this->mockFeatureFlags();
        $mock->shouldReceive('isLocalMode')->andReturn(false);
        $mock->shouldReceive('flush')->once();
        $mock->shouldReceive('sync')->once();
        $mock->shouldReceive('all')->once()->andReturn([
            [], // Missing key and enabled
            ['key' => 'valid-flag'], // Missing enabled
        ]);

        $this->artisan('featureflags:warm', ['-v' => true])
            ->expectsOutputToContain('Cached 2 flag(s)')
            ->assertSuccessful();
    }

    private function mockFeatureFlags(): FeatureFlagsInterface&MockInterface
    {
        $mock = Mockery::mock(FeatureFlagsInterface::class);
        $this->app->instance(FeatureFlagsInterface::class, $mock);

        return $mock;
    }
}
