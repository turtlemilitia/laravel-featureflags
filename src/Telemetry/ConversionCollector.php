<?php

declare(strict_types=1);

namespace FeatureFlags\Telemetry;

use FeatureFlags\Context;
use FeatureFlags\Context\RequestContext;

class ConversionCollector extends AbstractCollector
{
    /** @param array<string, mixed> $properties */
    public function track(
        string $eventName,
        ?Context $context = null,
        array $properties = [],
    ): void {
        if (!$this->enabled) {
            return;
        }

        $event = [
            'event_name' => $eventName,
            'context_id' => $context?->id,
            'session_id' => RequestContext::getSessionId(),
            'request_id' => RequestContext::getRequestId(),
            'timestamp' => now()->toIso8601String(),
        ];

        if (!empty($properties)) {
            $event['properties'] = $properties;
        }

        $this->events[] = $event;

        if ($this->shouldAutoFlush()) {
            $this->flush();
        }
    }

    protected function send(array $events): void
    {
        $this->apiClient->sendConversions($events);
    }

    protected function getFailureMessage(): string
    {
        return 'Failed to send conversion events';
    }

    protected function getTelemetryType(): string
    {
        return 'conversions';
    }
}
