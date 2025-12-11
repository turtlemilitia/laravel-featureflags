<?php

declare(strict_types=1);

namespace FeatureFlags\Telemetry;

use FeatureFlags\Client\ApiClient;
use FeatureFlags\Context;
use FeatureFlags\Context\DeviceIdentifier;
use FeatureFlags\Context\RequestContext;

class ConversionCollector extends AbstractCollector
{
    public function __construct(
        ApiClient $apiClient,
        private readonly ?FlagStateTracker $stateTracker = null,
    ) {
        parent::__construct($apiClient);
    }

    /**
     * @param string $eventName The conversion event name (e.g., 'purchase', 'signup')
     * @param Context|null $context The user context
     * @param array<string, mixed> $properties Additional event properties
     * @param string|null $flagKey Optional: explicitly attribute to a specific flag
     * @param bool|int|float|string|array<string, mixed>|null $flagValue Optional: the variant value
     */
    public function track(
        string $eventName,
        ?Context $context = null,
        array $properties = [],
        ?string $flagKey = null,
        bool|int|float|string|array|null $flagValue = null,
    ): void {
        if (!$this->enabled) {
            return;
        }

        $event = [
            'event_name' => $eventName,
            'context_id' => $context?->id,
            'device_id' => ($context !== null ? $context->deviceId : null) ?? DeviceIdentifier::get(),
            'session_id' => RequestContext::getSessionId(),
            'request_id' => RequestContext::getRequestId(),
            'timestamp' => now()->toIso8601String(),
        ];

        if (!empty($properties)) {
            $event['properties'] = $properties;
        }

        if ($flagKey !== null) {
            $event['flag_key'] = $flagKey;
            $event['flag_value'] = $flagValue;
        }

        $evaluatedFlags = $this->stateTracker?->getEvaluatedFlags() ?? [];
        if (!empty($evaluatedFlags)) {
            $event['evaluated_flags'] = $evaluatedFlags;
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
