<?php

declare(strict_types=1);

namespace FeatureFlags;

use FeatureFlags\Cache\FlagCache;
use FeatureFlags\Client\ApiClient;
use FeatureFlags\Evaluation\OperatorEvaluator;
use FeatureFlags\Telemetry\ConversionCollector;
use FeatureFlags\Telemetry\ErrorCollector;
use FeatureFlags\Telemetry\FlagStateTracker;
use FeatureFlags\Telemetry\TelemetryCollector;

final readonly class FeatureFlagsConfig
{
    public function __construct(
        public ApiClient $apiClient,
        public FlagCache $cache,
        public ContextResolver $contextResolver,
        public TelemetryCollector $telemetry,
        public ConversionCollector $conversions,
        public ErrorCollector $errors,
        public FlagStateTracker $stateTracker,
        public OperatorEvaluator $operatorEvaluator,
        public bool $cacheEnabled = true,
    ) {}
}
