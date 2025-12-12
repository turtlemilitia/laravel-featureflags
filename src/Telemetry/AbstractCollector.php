<?php

declare(strict_types=1);

namespace FeatureFlags\Telemetry;

use FeatureFlags\Client\ApiClient;
use FeatureFlags\Config\ConfigHelper;
use FeatureFlags\Context\DeviceIdentifier;
use FeatureFlags\Events\TelemetryFlushed;
use FeatureFlags\Jobs\SendTelemetry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

abstract class AbstractCollector
{
    private const RATE_LIMIT_KEY = 'featureflags:telemetry_rate_limit';

    /** @var array<int, array<string, mixed>> */
    protected array $events = [];

    protected bool $enabled;

    protected bool $holdMode = false;

    protected bool $asyncMode = false;

    protected ?string $queue = null;

    public function __construct(
        protected readonly ApiClient $apiClient,
    ) {
        $this->enabled = ConfigHelper::bool('featureflags.telemetry.enabled', false);
        $this->holdMode = ConfigHelper::bool('featureflags.telemetry.hold_until_consent', false);
        $this->asyncMode = ConfigHelper::bool('featureflags.telemetry.async', false);
        $this->queue = ConfigHelper::stringOrNull('featureflags.telemetry.queue');
    }

    public function isHolding(): bool
    {
        return $this->holdMode && !DeviceIdentifier::hasConsent();
    }

    public function discardHeld(): void
    {
        $this->events = [];
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

        if ($this->asyncMode) {
            $this->dispatchAsync($events);
            $this->recordFlush();
            $this->dispatchTelemetryFlushedEvent($eventCount, true, 0, null);
            return;
        }

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

            if (ConfigHelper::bool('featureflags.telemetry.retry_on_failure', false)) {
                $this->events = array_merge($events, $this->events);
            }
        }

        $durationMs = (hrtime(true) - $startTime) / 1_000_000;

        $this->dispatchTelemetryFlushedEvent($eventCount, $success, $durationMs, $error);
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    private function dispatchAsync(array $events): void
    {
        $job = new SendTelemetry($this->getTelemetryType(), $events);

        if ($this->queue !== null) {
            $job->onQueue($this->queue);
        }

        dispatch($job);
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
        if ($this->isHolding()) {
            return false;
        }

        return count($this->events) >= $this->getBatchSize();
    }

    protected function getBatchSize(): int
    {
        return ConfigHelper::int('featureflags.telemetry.batch_size', 100);
    }

    protected function shouldSample(): bool
    {
        $rate = ConfigHelper::float('featureflags.telemetry.sample_rate', 1.0);

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
        if (!ConfigHelper::bool('featureflags.telemetry.rate_limit.enabled', false)) {
            return true;
        }

        $maxFlushes = ConfigHelper::int('featureflags.telemetry.rate_limit.max_flushes_per_minute', 60);

        /** @var int|null $cached */
        $cached = Cache::get(self::RATE_LIMIT_KEY);
        $currentCount = $cached ?? 0;

        return $currentCount < $maxFlushes;
    }

    private function recordFlush(): void
    {
        if (!ConfigHelper::bool('featureflags.telemetry.rate_limit.enabled', false)) {
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
        if (!ConfigHelper::bool('featureflags.events.enabled', false)) {
            return;
        }

        if (!ConfigHelper::bool('featureflags.events.dispatch.telemetry_flushed', true)) {
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

    /**
     * @return 'telemetry'|'conversions'|'errors'
     */
    abstract protected function getTelemetryType(): string;

    /**
     * @param array<int, array<string, mixed>> $events
     */
    abstract protected function send(array $events): void;

    abstract protected function getFailureMessage(): string;
}
