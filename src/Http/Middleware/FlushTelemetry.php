<?php

declare(strict_types=1);

namespace FeatureFlags\Http\Middleware;

use Closure;
use FeatureFlags\Context\RequestContext;
use FeatureFlags\Telemetry\ConversionCollector;
use FeatureFlags\Telemetry\ErrorCollector;
use FeatureFlags\Telemetry\FlagStateTracker;
use FeatureFlags\Telemetry\TelemetryCollector;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FlushTelemetry
{
    public function __construct(
        private readonly TelemetryCollector $telemetryCollector,
        private readonly ConversionCollector $conversionCollector,
        private readonly ErrorCollector $errorCollector,
        private readonly FlagStateTracker $stateTracker,
    ) {}

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        RequestContext::initialize();

        /** @var Response $response */
        $response = $next($request);
        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->telemetryCollector->flush();
        $this->conversionCollector->flush();
        $this->errorCollector->flush();
        $this->stateTracker->reset();
        RequestContext::reset();
    }
}
