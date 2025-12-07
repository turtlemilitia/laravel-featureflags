<?php

declare(strict_types=1);

namespace FeatureFlags\Tests\Feature;

use FeatureFlags\Cache\FlagCache;

class WebhookIntegrationTest extends FeatureTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('featureflags.webhook.enabled', true);
        $app['config']->set('featureflags.webhook.secret', 'test-webhook-secret');
        $app['config']->set('featureflags.webhook.path', '/webhooks/feature-flags');
    }

    public function test_webhook_route_is_registered(): void
    {
        $response = $this->postJson('/webhooks/feature-flags', [], [
            'X-FeatureFlags-Signature' => 'invalid',
        ]);

        // Should not be 404 - route exists
        $this->assertNotEquals(404, $response->status());
    }

    public function test_webhook_rejects_missing_signature(): void
    {
        $response = $this->postJson('/webhooks/feature-flags', [
            'event' => 'flag.updated',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Invalid signature']);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $payload = ['event' => 'flag.updated', 'flag_key' => 'test-flag'];

        $response = $this->postJson('/webhooks/feature-flags', $payload, [
            'X-FeatureFlags-Signature' => 'invalid-signature',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Invalid signature']);
    }

    public function test_webhook_accepts_valid_signature(): void
    {
        // Seed initial flags
        $this->seedFlags([
            $this->simpleFlag('test-flag', true),
        ]);

        // Mock API response for the sync triggered by webhook
        $this->mockApiResponse([
            $this->simpleFlag('test-flag', true),
            $this->simpleFlag('new-flag', true),
        ]);

        $payload = ['event' => 'flag.updated', 'flag_key' => 'test-flag'];
        $signature = hash_hmac('sha256', json_encode($payload) ?: '', 'test-webhook-secret');

        $response = $this->postJson('/webhooks/feature-flags', $payload, [
            'X-FeatureFlags-Signature' => $signature,
        ]);

        $response->assertOk();
        $response->assertJson(['received' => true]);
    }

    public function test_webhook_invalidates_cache_on_flag_updated(): void
    {
        // Seed initial flags
        $this->seedFlags([
            $this->simpleFlag('my-flag', false, 'old-value'),
        ]);

        // Mock API response with updated flag
        $this->mockApiResponse([
            $this->simpleFlag('my-flag', true, 'new-value'),
        ]);

        $payload = ['event' => 'flag.updated', 'flag_key' => 'my-flag'];
        $signature = hash_hmac('sha256', json_encode($payload) ?: '', 'test-webhook-secret');

        $response = $this->postJson('/webhooks/feature-flags', $payload, [
            'X-FeatureFlags-Signature' => $signature,
        ]);

        $response->assertOk();

        // Verify cache was updated with new flag value
        /** @var FlagCache $cache */
        $cache = $this->app->make(FlagCache::class);
        $flag = $cache->get('my-flag');

        $this->assertNotNull($flag);
        $this->assertTrue($flag['enabled']);
        $this->assertEquals('new-value', $flag['default_value']);
    }

    public function test_webhook_handles_flag_created_event(): void
    {
        $this->mockApiResponse([
            $this->simpleFlag('brand-new-flag', true),
        ]);

        $payload = ['event' => 'flag.created', 'flag_key' => 'brand-new-flag'];
        $signature = hash_hmac('sha256', json_encode($payload) ?: '', 'test-webhook-secret');

        $response = $this->postJson('/webhooks/feature-flags', $payload, [
            'X-FeatureFlags-Signature' => $signature,
        ]);

        $response->assertOk();
    }

    public function test_webhook_handles_flag_deleted_event(): void
    {
        $this->seedFlags([
            $this->simpleFlag('to-delete', true),
        ]);

        $this->mockApiResponse([]);

        $payload = ['event' => 'flag.deleted', 'flag_key' => 'to-delete'];
        $signature = hash_hmac('sha256', json_encode($payload) ?: '', 'test-webhook-secret');

        $response = $this->postJson('/webhooks/feature-flags', $payload, [
            'X-FeatureFlags-Signature' => $signature,
        ]);

        $response->assertOk();
    }

    public function test_webhook_handles_environment_updated_event(): void
    {
        $this->mockApiResponse([
            $this->simpleFlag('env-flag', true),
        ]);

        $payload = ['event' => 'flag_environment.updated', 'environment_key' => 'production'];
        $signature = hash_hmac('sha256', json_encode($payload) ?: '', 'test-webhook-secret');

        $response = $this->postJson('/webhooks/feature-flags', $payload, [
            'X-FeatureFlags-Signature' => $signature,
        ]);

        $response->assertOk();
    }

    public function test_webhook_ignores_unknown_events(): void
    {
        $payload = ['event' => 'unknown.event', 'data' => 'test'];
        $signature = hash_hmac('sha256', json_encode($payload) ?: '', 'test-webhook-secret');

        $response = $this->postJson('/webhooks/feature-flags', $payload, [
            'X-FeatureFlags-Signature' => $signature,
        ]);

        $response->assertOk();
        $response->assertJson(['received' => true]);
    }

    public function test_webhook_without_secret_configured_rejects_requests(): void
    {
        $this->app['config']->set('featureflags.webhook.secret', null);

        $response = $this->postJson('/webhooks/feature-flags', [
            'event' => 'flag.updated',
        ]);

        $response->assertStatus(401);
    }
}
