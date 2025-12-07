<?php

namespace FeatureFlags\Tests;

use FeatureFlags\FeatureFlagsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            FeatureFlagsServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Feature' => \FeatureFlags\Facades\Feature::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('featureflags.api_url', 'https://test.featureflags.io');
        $app['config']->set('featureflags.api_key', 'test-api-key');
        $app['config']->set('featureflags.cache.enabled', false);
        $app['config']->set('featureflags.telemetry.enabled', false);
    }
}
