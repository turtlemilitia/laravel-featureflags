<?php

declare(strict_types=1);

namespace FeatureFlags\Telemetry;

use FeatureFlags\Context\RequestContext;
use Illuminate\Support\Facades\Log;
use Throwable;

class ErrorCollector extends AbstractCollector
{
    /** @param array<string, mixed> $metadata */
    public function track(string $flagKey, Throwable $exception, array $metadata = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $flagValue = $this->getTrackedFlagValue($flagKey);
        $this->recordError($flagKey, $flagValue, $exception, $metadata);
    }

    /**
     * @param bool|int|float|string|array<string, mixed>|null $flagValue
     * @param array<string, mixed> $metadata
     */
    public function trackAutomatic(string $flagKey, bool|int|float|string|array|null $flagValue, Throwable $exception, array $metadata = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->recordError($flagKey, $flagValue, $exception, $metadata);
    }

    protected function send(array $events): void
    {
        $this->apiClient->sendErrors($events);
    }

    protected function getFailureMessage(): string
    {
        return 'Failed to send feature flag error telemetry';
    }

    protected function getBatchSize(): int
    {
        $size = config('featureflags.telemetry.error_batch_size', 10);

        return is_int($size) ? $size : 10;
    }

    /**
     * @param bool|int|float|string|array<string, mixed>|null $flagValue
     * @param array<string, mixed> $metadata
     */
    private function recordError(string $flagKey, bool|int|float|string|array|null $flagValue, Throwable $exception, array $metadata): void
    {
        $this->events[] = [
            'flag_key' => $flagKey,
            'flag_value' => $flagValue,
            'error_type' => $exception::class,
            'error_message' => $exception->getMessage(),
            'stack_trace' => $exception->getTraceAsString(),
            'metadata' => $metadata,
            'context_id' => $this->getCurrentContextId(),
            'session_id' => RequestContext::getSessionId(),
            'request_id' => RequestContext::getRequestId(),
            'occurred_at' => now()->toIso8601String(),
        ];

        if ($this->shouldAutoFlush()) {
            $this->flush();
        }
    }

    /** @return bool|int|float|string|array<string, mixed>|null */
    private function getTrackedFlagValue(string $flagKey): bool|int|float|string|array|null
    {
        try {
            return app(FlagStateTracker::class)->getValue($flagKey);
        } catch (\Throwable $e) {
            Log::debug('Feature flags: Could not retrieve tracked flag value', [
                'flag_key' => $flagKey,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function getCurrentContextId(): ?string
    {
        try {
            $user = auth()->user();
            $id = $user?->getAuthIdentifier();

            return is_string($id) || is_int($id) ? (string) $id : null;
        } catch (\Throwable $e) {
            Log::debug('Feature flags: Could not retrieve current context ID', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    protected function getTelemetryType(): string
    {
        return 'errors';
    }
}
