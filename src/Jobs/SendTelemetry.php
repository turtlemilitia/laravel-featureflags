<?php

declare(strict_types=1);

namespace FeatureFlags\Jobs;

use FeatureFlags\Client\ApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendTelemetry implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    /**
     * @param 'telemetry'|'conversions'|'errors' $type
     * @param array<int, array<string, mixed>> $events
     */
    public function __construct(
        public readonly string $type,
        public readonly array $events,
    ) {}

    public function handle(ApiClient $apiClient): void
    {
        if (empty($this->events)) {
            return;
        }

        try {
            match ($this->type) {
                'telemetry' => $apiClient->sendTelemetry($this->events),
                'conversions' => $apiClient->sendConversions($this->events),
                'errors' => $apiClient->sendErrors($this->events),
            };
        } catch (\Throwable $e) {
            Log::warning("Failed to send feature flag {$this->type}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['featureflags', "featureflags:{$this->type}"];
    }
}
