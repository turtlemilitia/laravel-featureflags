<?php

declare(strict_types=1);

namespace FeatureFlags\Integrations;

use FeatureFlags\Facades\Feature;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

class ExceptionContext
{
    /**
     * Add current feature flags to Laravel's Context (Laravel 11+).
     *
     * Call this at any point to add the current flag state to context.
     * The context will be automatically included in error reports by
     * Sentry, Bugsnag, Flare, and other error trackers.
     *
     * Note: ErrorTrackingServiceProvider calls this automatically
     * when exceptions occur. Only use this if you need manual control.
     */
    public static function addFlagsToContext(): void
    {
        if (!class_exists(Context::class)) {
            return;
        }

        try {
            /** @var array<string, mixed> $flags */
            $flags = Feature::getEvaluatedFlags();

            if (!empty($flags)) {
                Context::add('feature_flags', $flags);
                Context::add('feature_flags_count', count($flags));
            }
        } catch (\Throwable $e) {
            Log::debug('Feature Flags failed to add flags to exception context.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get feature flags formatted for exception context.
     *
     * Use this in custom exception classes that implement context():
     *
     *     class PaymentFailedException extends Exception
     *     {
     *         public function context(): array
     *         {
     *             return array_merge(
     *                 ['payment_id' => $this->paymentId],
     *                 ExceptionContext::getFlags()
     *             );
     *         }
     *     }
     *
     * @return array{flags?: array<string, mixed>, count?: int, request_id?: string|null}
     */
    public static function getFlags(): array
    {
        try {
            /** @var array{flags: array<string, mixed>, count: int, request_id: string|null} $context */
            $context = Feature::getErrorContext();
            return $context;
        } catch (\Throwable $e) {
            Log::debug('Feature Flags failed to retrieve error context.', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
