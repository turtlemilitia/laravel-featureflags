<?php

declare(strict_types=1);

namespace FeatureFlags\Integrations;

use FeatureFlags\Context\RequestContext;
use FeatureFlags\Facades\Feature;
use FeatureFlags\Telemetry\ErrorCollector;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Throwable;

class ErrorTrackingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /** @var bool $errorTrackingEnabled */
        $errorTrackingEnabled = config('featureflags.error_tracking.enabled', true);
        if (!$errorTrackingEnabled) {
            return;
        }

        $this->registerExceptionReportingCallback();
    }

    protected function registerExceptionReportingCallback(): void
    {
        try {
            /** @var ExceptionHandler $handler */
            $handler = $this->app->make(ExceptionHandler::class);

            $handler->reportable(function (Throwable $e): void {
                $this->trackErrorWithFlags($e);
            });
        } catch (\Throwable $e) {
            Log::debug('Feature Flags automatic error tracking unavailable.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function trackErrorWithFlags(Throwable $exception): void
    {
        try {
            if ($this->shouldSkipException($exception)) {
                return;
            }

            $evaluatedFlags = Feature::getEvaluatedFlags();
            if (empty($evaluatedFlags)) {
                return;
            }

            $this->addFlagContextToException($evaluatedFlags);

            /** @var bool $telemetryEnabled */
            $telemetryEnabled = config('featureflags.telemetry.enabled', false);
            if ($telemetryEnabled) {
                $this->sendToInternalTelemetry($exception, $evaluatedFlags);
            }
        } catch (\Throwable $e) {
            Log::debug('Feature Flags automatic error tracking skipped due to internal error.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string, bool|int|float|string|array<string, mixed>|null> $evaluatedFlags */
    protected function addFlagContextToException(array $evaluatedFlags): void
    {
        if (class_exists(Context::class)) {
            Context::add([
                'feature_flags' => $evaluatedFlags,
                'feature_flags_count' => count($evaluatedFlags),
                'feature_flags_request_id' => RequestContext::getRequestId(),
            ]);
        }
    }

    /** @param array<string, bool|int|float|string|array<string, mixed>|null> $evaluatedFlags */
    protected function sendToInternalTelemetry(Throwable $exception, array $evaluatedFlags): void
    {
        /** @var ErrorCollector $errors */
        $errors = $this->app->make(ErrorCollector::class);

        foreach ($evaluatedFlags as $flagKey => $value) {
            $errors->trackAutomatic(
                $flagKey,
                $value,
                $exception,
                [
                    'auto_tracked' => true,
                    'all_evaluated_flags' => $evaluatedFlags,
                    'request_id' => RequestContext::getRequestId(),
                ],
            );
        }
    }

    protected function shouldSkipException(Throwable $exception): bool
    {
        /** @var array<int, class-string<Throwable>> $skipClasses */
        $skipClasses = config('featureflags.error_tracking.skip_exceptions', [
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
            \Symfony\Component\HttpKernel\Exception\HttpException::class,
            \Illuminate\Validation\ValidationException::class,
            \Illuminate\Auth\AuthenticationException::class,
            \Illuminate\Auth\Access\AuthorizationException::class,
            \Illuminate\Session\TokenMismatchException::class,
        ]);

        foreach ($skipClasses as $skipClass) {
            if ($exception instanceof $skipClass) {
                return true;
            }
        }

        return false;
    }
}
