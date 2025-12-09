<?php

declare(strict_types=1);

namespace FeatureFlags\Telemetry;

use FeatureFlags\Context;
use FeatureFlags\Context\DeviceIdentifier;
use FeatureFlags\Context\RequestContext;

class TelemetryCollector extends AbstractCollector
{
    /** @param bool|int|float|string|array<string, mixed>|null $value */
    public function record(
        string $flagKey,
        bool|int|float|string|array|null $value,
        ?Context $context,
        ?int $matchedRuleIndex = null,
        ?string $matchReason = null,
        ?int $durationMs = null,
    ): void {
        if (!$this->enabled) {
            return;
        }

        if (!$this->shouldSample()) {
            return;
        }

        $event = [
            'flag_key' => $flagKey,
            'value' => $value,
            'context_id' => $context?->id,
            'device_id' => ($context !== null ? $context->deviceId : null) ?? DeviceIdentifier::get(),
            'session_id' => RequestContext::getSessionId(),
            'request_id' => RequestContext::getRequestId(),
            'timestamp' => now()->toIso8601String(),
        ];

        if ($matchReason !== null) {
            $event['match_reason'] = $matchReason;
            if ($matchedRuleIndex !== null) {
                $event['matched_rule_index'] = $matchedRuleIndex;
            }
        }

        if ($durationMs !== null) {
            $event['duration_ms'] = $durationMs;
        }

        $this->events[] = $event;

        if ($this->shouldAutoFlush()) {
            $this->flush();
        }
    }

    protected function send(array $events): void
    {
        $this->apiClient->sendTelemetry($events);
    }

    protected function getFailureMessage(): string
    {
        return 'Failed to send feature flag telemetry';
    }

    protected function getTelemetryType(): string
    {
        return 'evaluations';
    }
}
