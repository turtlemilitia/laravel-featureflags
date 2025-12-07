<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Contracts\FeatureFlagsInterface;
use FeatureFlags\FeatureFlags;
use FeatureFlags\Tests\TestCase;
use Mockery;

class DumpCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['featureflags.local.enabled' => false]);
    }

    public function test_fails_when_in_local_mode(): void
    {
        config(['featureflags.local.enabled' => true]);

        $this->artisan('featureflags:dump')
            ->expectsOutput('Cannot dump flags while in local mode. Disable FEATUREFLAGS_LOCAL_MODE first.')
            ->assertFailed();
    }

    public function test_handles_empty_flags(): void
    {
        $this->mockFeatureFlagsForDump([]);

        $this->artisan('featureflags:dump')
            ->expectsOutput('No flags found.')
            ->assertSuccessful();
    }

    public function test_dumps_flags_in_php_format(): void
    {
        $this->mockFeatureFlagsForDump([
            ['key' => 'feature-a', 'enabled' => true, 'default_value' => true],
            ['key' => 'feature-b', 'enabled' => false, 'default_value' => false],
        ]);

        $this->artisan('featureflags:dump')
            ->expectsOutput('Fetching flags from API...')
            ->assertSuccessful();
    }

    public function test_dumps_flags_in_json_format(): void
    {
        $this->mockFeatureFlagsForDump([
            ['key' => 'feature-a', 'enabled' => true, 'default_value' => true],
        ]);

        $this->artisan('featureflags:dump', ['--format' => 'json'])
            ->assertSuccessful();
    }

    public function test_dumps_flags_in_yaml_format(): void
    {
        $this->mockFeatureFlagsForDump([
            ['key' => 'feature-a', 'enabled' => true, 'default_value' => true],
        ]);

        $this->artisan('featureflags:dump', ['--format' => 'yaml'])
            ->assertSuccessful();
    }

    public function test_includes_rollout_percentage(): void
    {
        $this->mockFeatureFlagsForDump([
            ['key' => 'beta-feature', 'enabled' => true, 'default_value' => true, 'rollout_percentage' => 50],
        ]);

        $this->artisan('featureflags:dump')
            ->assertSuccessful();
    }

    public function test_outputs_to_file(): void
    {
        $outputFile = sys_get_temp_dir() . '/featureflags-test-output.php';

        $this->mockFeatureFlagsForDump([
            ['key' => 'feature-a', 'enabled' => true, 'default_value' => true],
        ]);

        $this->artisan('featureflags:dump', ['--output' => $outputFile])
            ->assertSuccessful();

        $this->assertFileExists($outputFile);
        $contents = file_get_contents($outputFile);
        $this->assertStringContainsString("'feature-a' => true", $contents);

        unlink($outputFile);
    }

    public function test_handles_string_values(): void
    {
        $this->mockFeatureFlagsForDump([
            ['key' => 'welcome-msg', 'enabled' => true, 'default_value' => 'Hello, World!'],
        ]);

        $this->artisan('featureflags:dump')
            ->assertSuccessful();
    }

    public function test_handles_numeric_values(): void
    {
        $this->mockFeatureFlagsForDump([
            ['key' => 'max-items', 'enabled' => true, 'default_value' => 100],
        ]);

        $this->artisan('featureflags:dump')
            ->assertSuccessful();
    }

    /**
     * Helper to mock FeatureFlags for dump command tests.
     *
     * @param array<int, array<string, mixed>> $flags
     */
    private function mockFeatureFlagsForDump(array $flags): void
    {
        $mockFeatureFlags = Mockery::mock(FeatureFlagsInterface::class);
        $mockFeatureFlags->shouldReceive('isLocalMode')->andReturn(false);
        $mockFeatureFlags->shouldReceive('sync')->andReturnNull();
        $mockFeatureFlags->shouldReceive('all')->andReturn($flags);

        $this->app->instance(FeatureFlags::class, $mockFeatureFlags);
    }

    public function test_skips_flags_with_empty_key(): void
    {
        $this->mockFeatureFlagsForDump([
            ['key' => '', 'enabled' => true, 'default_value' => true],
            ['key' => 'valid-flag', 'enabled' => true, 'default_value' => true],
        ]);

        $this->artisan('featureflags:dump')
            ->assertSuccessful();
    }

    public function test_handles_rollout_at_100_percent(): void
    {
        $this->mockFeatureFlagsForDump([
            ['key' => 'full-rollout', 'enabled' => true, 'default_value' => true, 'rollout_percentage' => 100],
        ]);

        $this->artisan('featureflags:dump')
            ->assertSuccessful();
    }

    public function test_yaml_output_with_rollout(): void
    {
        $this->mockFeatureFlagsForDump([
            ['key' => 'beta-feature', 'enabled' => true, 'default_value' => 'variant-a', 'rollout_percentage' => 50],
        ]);

        $this->artisan('featureflags:dump', ['--format' => 'yaml'])
            ->assertSuccessful();
    }

    public function test_yaml_output_with_boolean_false(): void
    {
        $this->mockFeatureFlagsForDump([
            ['key' => 'disabled-feature', 'enabled' => false, 'default_value' => false],
        ]);

        $this->artisan('featureflags:dump', ['--format' => 'yaml'])
            ->assertSuccessful();
    }

    public function test_yaml_output_with_null_value(): void
    {
        $this->mockFeatureFlagsForDump([
            ['key' => 'null-feature', 'enabled' => true, 'default_value' => null],
        ]);

        $this->artisan('featureflags:dump', ['--format' => 'yaml'])
            ->assertSuccessful();
    }

    public function test_php_output_with_null_value(): void
    {
        $this->mockFeatureFlagsForDump([
            ['key' => 'null-feature', 'enabled' => true, 'default_value' => null],
        ]);

        $this->artisan('featureflags:dump', ['--format' => 'php'])
            ->assertSuccessful();
    }

    public function test_json_output_to_file(): void
    {
        $outputFile = sys_get_temp_dir() . '/featureflags-test-json.json';

        $this->mockFeatureFlagsForDump([
            ['key' => 'feature-a', 'enabled' => true, 'default_value' => true],
        ]);

        $this->artisan('featureflags:dump', ['--format' => 'json', '--output' => $outputFile])
            ->assertSuccessful();

        $this->assertFileExists($outputFile);
        $contents = file_get_contents($outputFile);
        $decoded = json_decode($contents, true);
        $this->assertArrayHasKey('feature-a', $decoded);

        unlink($outputFile);
    }

    protected function tearDown(): void
    {
        config(['featureflags.local.enabled' => false]);
        Mockery::close();
        parent::tearDown();
    }
}
