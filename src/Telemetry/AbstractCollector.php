<?php

declare(strict_types=1);

namespace FeatureFlags\Telemetry;

use FeatureFlags\Client\ApiClient;
use FeatureFlags\Events\TelemetryFlushed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

abstract class AbstractCollector
{
    private const RATE_LIMIT_KEY = 'featureflags:telemetry_rate_limit';

    /** @var array<int, array<string, mixed>> */
    protected array $events = [];

    protected bool $enabled;

    public function __construct(
        protected readonly ApiClient $apiClient,
    ) {
        $this->enabled = (bool) config('featureflags.telemetry.enabled', false);
    }

    public function flush(): void
    {
        if (empty($this->events)) {
            return;
        }

        if (!$this->canFlush()) {
            return;
        }

        $events = $this->events;
        $eventCount = count($events);
        $this->events = [];

        $startTime = hrtime(true);
        $success = false;
        $error = null;

        try {
            $this->send($events);
            $success = true;
            $this->recordFlush();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            Log::warning($this->getFailureMessage() . ': ' . $error);

            if ((bool) config('featureflags.telemetry.retry_on_failure', false)) {
                $this->events = array_merge($events, $this->events);
            }
        }

        $durationMs = (hrtime(true) - $startTime) / 1_000_000;

        $this->dispatchTelemetryFlushedEvent($eventCount, $success, $durationMs, $error);
    }

    public function pendingCount(): int
    {
        return count($this->events);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    protected function shouldAutoFlush(): bool
    {
        return count($this->events) >= $this->getBatchSize();
    }

    protected function getBatchSize(): int
    {
        $size = config('featureflags.telemetry.batch_size', 100);

        return is_int($size) ? $size : 100;
    }

    protected function shouldSample(): bool
    {
        $sampleRate = config('featureflags.telemetry.sample_rate', 1.0);

        if (!is_numeric($sampleRate)) {
            return true;
        }

        $rate = (float) $sampleRate;

        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) < $rate;
    }

    private function canFlush(): bool
    {
        if (!config('featureflags.telemetry.rate_limit.enabled', false)) {
            return true;
        }

        $maxFlushesConfig = config('featureflags.telemetry.rate_limit.max_flushes_per_minute', 60);
        $maxFlushes = is_int($maxFlushesConfig) ? $maxFlushesConfig : 60;

        /** @var int|null $cached */
        $cached = Cache::get(self::RATE_LIMIT_KEY);
        $currentCount = $cached ?? 0;

        return $currentCount < $maxFlushes;
    }

    private function recordFlush(): void
    {
        if (!config('featureflags.telemetry.rate_limit.enabled', false)) {
            return;
        }

        $key = self::RATE_LIMIT_KEY;

        if (!Cache::has($key)) {
            Cache::put($key, 1, 60);
        } else {
            Cache::increment($key);
        }
    }

    private function dispatchTelemetryFlushedEvent(int $eventCount, bool $success, float $durationMs, ?string $error): void
    {
        if (!config('featureflags.events.enabled', false)) {
            return;
        }

        if (!config('featureflags.events.dispatch.telemetry_flushed', true)) {
            return;
        }

        TelemetryFlushed::dispatch(
            $this->getTelemetryType(),
            $eventCount,
            $success,
            $durationMs,
            $error,
        );
    }

    abstract protected function getTelemetryType(): string;

    /**
     * @param array<int, array<string, mixed>> $events
     */
    abstract protected function send(array $events): void;

    abstract protected function getFailureMessage(): string;
}
