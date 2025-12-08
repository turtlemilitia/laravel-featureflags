<?php

declare(strict_types=1);

namespace FeatureFlags\Tests;

use FeatureFlags\Cache\FlagCache;
use FeatureFlags\Client\ApiClient;
use FeatureFlags\ContextResolver;
use FeatureFlags\Evaluation\ContextNormalizer;
use FeatureFlags\Evaluation\FlagEvaluator;
use FeatureFlags\Evaluation\OperatorEvaluator;
use FeatureFlags\FeatureFlags;
use FeatureFlags\FlagService;
use FeatureFlags\Telemetry\ConversionCollector;
use FeatureFlags\Telemetry\ErrorCollector;
use FeatureFlags\Telemetry\FlagStateTracker;
use FeatureFlags\Telemetry\TelemetryCollector;
use Mockery;

trait CreatesFeatureFlags
{
    protected function createFeatureFlagsInstance(
        ?ApiClient $apiClient = null,
        ?FlagCache $cache = null,
        ?ContextResolver $contextResolver = null,
        ?TelemetryCollector $telemetry = null,
        ?ConversionCollector $conversions = null,
        ?ErrorCollector $errors = null,
        ?FlagStateTracker $stateTracker = null,
        bool $cacheEnabled = true,
    ): FeatureFlags {
        $cache ??= Mockery::mock(FlagCache::class);
        $apiClient ??= Mockery::mock(ApiClient::class);
        $contextResolver ??= new ContextResolver();
        $telemetry ??= $this->createMockTelemetry();
        $conversions ??= $this->createMockConversions();
        $errors ??= $this->createMockErrors();
        $stateTracker ??= new FlagStateTracker();

        $operatorEvaluator = new OperatorEvaluator();
        $contextNormalizer = new ContextNormalizer($contextResolver);
        $flagEvaluator = new FlagEvaluator($cache, $operatorEvaluator);

        $flagService = new FlagService(
            $cache,
            $apiClient,
            $flagEvaluator,
            $contextNormalizer,
            $telemetry,
            $stateTracker,
            $cacheEnabled,
        );

        return new FeatureFlags(
            $flagService,
            $conversions,
            $errors,
        );
    }

    protected function createMockTelemetry(): TelemetryCollector
    {
        $mock = Mockery::mock(TelemetryCollector::class);
        $mock->shouldReceive('record')->andReturnNull();
        $mock->shouldReceive('flush')->andReturnNull();
        return $mock;
    }

    protected function createMockConversions(): ConversionCollector
    {
        $mock = Mockery::mock(ConversionCollector::class);
        $mock->shouldReceive('track')->andReturnNull();
        $mock->shouldReceive('flush')->andReturnNull();
        return $mock;
    }

    protected function createMockErrors(): ErrorCollector
    {
        $mock = Mockery::mock(ErrorCollector::class);
        $mock->shouldReceive('track')->andReturnNull();
        $mock->shouldReceive('trackAutomatic')->andReturnNull();
        $mock->shouldReceive('flush')->andReturnNull();
        return $mock;
    }
}
