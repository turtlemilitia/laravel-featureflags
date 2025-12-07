<?php

declare(strict_types=1);

namespace FeatureFlags\Tests\Feature;

use FeatureFlags\Cache\FlagCache;

class ConsoleCommandsTest extends FeatureTestCase
{
    public function test_sync_command_fetches_flags(): void
    {
        $this->mockApiResponse([
            $this->simpleFlag('flag-1', true),
            $this->simpleFlag('flag-2', false),
        ]);

        $this->artisan('featureflags:sync')
            ->expectsOutput('Syncing feature flags...')
            ->expectsOutput('Synced 2 flag(s).')
            ->assertExitCode(0);
    }

    public function test_sync_command_verbose_shows_flags(): void
    {
        $this->mockApiResponse([
            $this->simpleFlag('enabled-flag', true),
            $this->simpleFlag('disabled-flag', false),
        ]);

        $this->artisan('featureflags:sync', ['--verbose' => true])
            ->expectsOutput('Syncing feature flags...')
            ->expectsOutput('Synced 2 flag(s).')
            ->expectsOutputToContain('enabled-flag')
            ->expectsOutputToContain('disabled-flag')
            ->assertExitCode(0);
    }

    public function test_warm_command_pre_warms_cache(): void
    {
        $this->mockApiResponse([
            $this->simpleFlag('warm-flag-1', true),
            $this->simpleFlag('warm-flag-2', true),
            $this->simpleFlag('warm-flag-3', false),
        ]);

        $this->artisan('featureflags:warm')
            ->expectsOutput('Warming feature flags cache...')
            ->expectsOutputToContain('Cached 3 flag(s)')
            ->assertExitCode(0);

        // Verify flags are actually cached
        /** @var FlagCache $cache */
        $cache = $this->app->make(FlagCache::class);

        $this->assertNotNull($cache->get('warm-flag-1'));
        $this->assertNotNull($cache->get('warm-flag-2'));
        $this->assertNotNull($cache->get('warm-flag-3'));
    }

    public function test_warm_command_verbose_shows_flag_list(): void
    {
        $this->mockApiResponse([
            $this->simpleFlag('verbose-flag', true),
        ]);

        $this->artisan('featureflags:warm', ['--verbose' => true])
            ->expectsOutput('Warming feature flags cache...')
            ->expectsOutputToContain('verbose-flag')
            ->assertExitCode(0);
    }

    public function test_warm_command_skips_in_local_mode(): void
    {
        $this->app['config']->set('featureflags.local.enabled', true);

        $this->artisan('featureflags:warm')
            ->expectsOutput('Local mode enabled - cache warming not needed.')
            ->assertExitCode(0);
    }

    public function test_dump_command_outputs_php_format(): void
    {
        // Dump command calls sync(), so we need to mock the API response
        $this->mockApiResponse([
            $this->simpleFlag('dump-flag-1', true, true),
            $this->simpleFlag('dump-flag-2', true, 'string-value'),
        ]);

        $exitCode = \Artisan::call('featureflags:dump', ['--format' => 'php']);
        $output = \Artisan::output();

        $this->assertEquals(0, $exitCode, "Command failed with output: " . $output);
        $this->assertStringContainsString("'dump-flag-1' => true", $output, "Output was: " . $output);
        $this->assertStringContainsString("'dump-flag-2' => 'string-value'", $output, "Output was: " . $output);
    }

    public function test_dump_command_outputs_json_format(): void
    {
        $this->mockApiResponse([
            $this->simpleFlag('json-flag', true, true),
        ]);

        $this->artisan('featureflags:dump', ['--format' => 'json'])
            ->expectsOutputToContain('"json-flag"')
            ->assertExitCode(0);
    }

    public function test_dump_command_outputs_yaml_format(): void
    {
        $this->mockApiResponse([
            $this->simpleFlag('yaml-flag', true, true),
        ]);

        $this->artisan('featureflags:dump', ['--format' => 'yaml'])
            ->expectsOutputToContain('yaml-flag:')
            ->assertExitCode(0);
    }

    public function test_dump_command_writes_to_file(): void
    {
        $this->mockApiResponse([
            $this->simpleFlag('file-flag', true, true),
        ]);

        $outputFile = sys_get_temp_dir() . '/featureflags-dump-test.php';

        $this->artisan('featureflags:dump', [
            '--format' => 'php',
            '--output' => $outputFile,
        ])->assertExitCode(0);

        $this->assertFileExists($outputFile);
        $content = file_get_contents($outputFile);
        $this->assertIsString($content);
        $this->assertStringContainsString('file-flag', $content);

        unlink($outputFile);
    }

    public function test_dump_command_handles_empty_flags(): void
    {
        $this->mockApiResponse([]);

        $this->artisan('featureflags:dump', ['--format' => 'php'])
            ->expectsOutput('No flags found.')
            ->assertExitCode(0);
    }

    public function test_dump_command_handles_flag_with_rollout(): void
    {
        $this->mockApiResponse([
            [
                'key' => 'rollout-flag',
                'enabled' => true,
                'default_value' => true,
                'rollout_percentage' => 50,
                'rules' => [],
                'segments' => [],
            ],
        ]);

        $exitCode = \Artisan::call('featureflags:dump', ['--format' => 'php']);
        $output = \Artisan::output();

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString("'rollout-flag'", $output, "Output was: " . $output);
        $this->assertStringContainsString("'rollout' => 50", $output, "Output was: " . $output);
    }
}
