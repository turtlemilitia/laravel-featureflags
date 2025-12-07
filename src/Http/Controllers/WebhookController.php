<?php

declare(strict_types=1);

namespace FeatureFlags\Http\Controllers;

use FeatureFlags\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController
{
    public function __construct(
        private readonly FeatureFlags $featureFlags,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->verifySignature($request)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        /** @var string|null $event */
        $event = $request->input('event');

        // Handle flag update events by flushing cache and re-syncing
        if ($event !== null && in_array($event, ['flag.created', 'flag.updated', 'flag.deleted', 'flag_environment.updated'], true)) {
            $this->featureFlags->flush();
            $this->featureFlags->sync();
        }

        return response()->json(['received' => true]);
    }

    private function verifySignature(Request $request): bool
    {
        /** @var string|null $secret */
        $secret = config('featureflags.webhook.secret');

        if ($secret === null || $secret === '') {
            return false;
        }

        /** @var string|null $signature */
        $signature = $request->header('X-FeatureFlags-Signature');

        if ($signature === null || $signature === '') {
            return false;
        }

        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }
}
