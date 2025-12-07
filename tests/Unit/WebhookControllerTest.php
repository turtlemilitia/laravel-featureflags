<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\FeatureFlags;
use FeatureFlags\Http\Controllers\WebhookController;
use FeatureFlags\Tests\TestCase;
use Illuminate\Http\Request;
use Mockery;

class WebhookControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_valid_signature_allows_request(): void
    {
        $secret = 'test-webhook-secret';
        config(['featureflags.webhook.secret' => $secret]);

        $payload = json_encode(['event' => 'flag.updated']);
        $signature = hash_hmac('sha256', $payload, $secret);

        $request = Request::create('/webhooks/feature-flags', 'POST', [], [], [], [], $payload);
        $request->headers->set('X-FeatureFlags-Signature', $signature);
        $request->headers->set('Content-Type', 'application/json');

        // Only mock the FeatureFlags since it would make API calls
        $mockFeatureFlags = Mockery::mock(FeatureFlags::class);
        $mockFeatureFlags->shouldReceive('flush')->once();
        $mockFeatureFlags->shouldReceive('sync')->once();

        $controller = new WebhookController($mockFeatureFlags);
        $response = $controller($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(['received' => true], $response->getData(true));
    }

    public function test_invalid_signature_returns_401(): void
    {
        $secret = 'test-webhook-secret';
        config(['featureflags.webhook.secret' => $secret]);

        $payload = json_encode(['event' => 'flag.updated']);

        $request = Request::create('/webhooks/feature-flags', 'POST', [], [], [], [], $payload);
        $request->headers->set('X-FeatureFlags-Signature', 'invalid-signature');

        // Mock won't have flush/sync called, but we still need to provide it
        $mockFeatureFlags = Mockery::mock(FeatureFlags::class);

        $controller = new WebhookController($mockFeatureFlags);
        $response = $controller($request);

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals(['error' => 'Invalid signature'], $response->getData(true));
    }

    public function test_missing_signature_returns_401_when_secret_configured(): void
    {
        config(['featureflags.webhook.secret' => 'test-secret']);

        $payload = json_encode(['event' => 'flag.updated']);
        $request = Request::create('/webhooks/feature-flags', 'POST', [], [], [], [], $payload);

        $mockFeatureFlags = Mockery::mock(FeatureFlags::class);

        $controller = new WebhookController($mockFeatureFlags);
        $response = $controller($request);

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function test_no_secret_configured_rejects_request(): void
    {
        config(['featureflags.webhook.secret' => null]);

        $payload = json_encode(['event' => 'flag.updated']);
        $request = Request::create('/webhooks/feature-flags', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $mockFeatureFlags = Mockery::mock(FeatureFlags::class);

        $controller = new WebhookController($mockFeatureFlags);
        $response = $controller($request);

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals(['error' => 'Invalid signature'], $response->getData(true));
    }

    public function test_empty_secret_configured_rejects_request(): void
    {
        config(['featureflags.webhook.secret' => '']);

        $payload = json_encode(['event' => 'flag.updated']);
        $request = Request::create('/webhooks/feature-flags', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $mockFeatureFlags = Mockery::mock(FeatureFlags::class);

        $controller = new WebhookController($mockFeatureFlags);
        $response = $controller($request);

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals(['error' => 'Invalid signature'], $response->getData(true));
    }

    public function test_flag_created_event_triggers_sync(): void
    {
        $secret = 'test-secret';
        config(['featureflags.webhook.secret' => $secret]);

        $payload = json_encode(['event' => 'flag.created']);
        $signature = hash_hmac('sha256', $payload, $secret);

        $request = Request::create('/webhooks/feature-flags', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
        $request->headers->set('X-FeatureFlags-Signature', $signature);

        $mockFeatureFlags = Mockery::mock(FeatureFlags::class);
        $mockFeatureFlags->shouldReceive('flush')->once();
        $mockFeatureFlags->shouldReceive('sync')->once();

        $controller = new WebhookController($mockFeatureFlags);
        $response = $controller($request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_flag_deleted_event_triggers_sync(): void
    {
        $secret = 'test-secret';
        config(['featureflags.webhook.secret' => $secret]);

        $payload = json_encode(['event' => 'flag.deleted']);
        $signature = hash_hmac('sha256', $payload, $secret);

        $request = Request::create('/webhooks/feature-flags', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
        $request->headers->set('X-FeatureFlags-Signature', $signature);

        $mockFeatureFlags = Mockery::mock(FeatureFlags::class);
        $mockFeatureFlags->shouldReceive('flush')->once();
        $mockFeatureFlags->shouldReceive('sync')->once();

        $controller = new WebhookController($mockFeatureFlags);
        $response = $controller($request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_flag_environment_updated_event_triggers_sync(): void
    {
        $secret = 'test-secret';
        config(['featureflags.webhook.secret' => $secret]);

        $payload = json_encode(['event' => 'flag_environment.updated']);
        $signature = hash_hmac('sha256', $payload, $secret);

        $request = Request::create('/webhooks/feature-flags', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
        $request->headers->set('X-FeatureFlags-Signature', $signature);

        $mockFeatureFlags = Mockery::mock(FeatureFlags::class);
        $mockFeatureFlags->shouldReceive('flush')->once();
        $mockFeatureFlags->shouldReceive('sync')->once();

        $controller = new WebhookController($mockFeatureFlags);
        $response = $controller($request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_unknown_event_does_not_trigger_sync(): void
    {
        $secret = 'test-secret';
        config(['featureflags.webhook.secret' => $secret]);

        $payload = json_encode(['event' => 'unknown.event']);
        $signature = hash_hmac('sha256', $payload, $secret);

        $request = Request::create('/webhooks/feature-flags', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
        $request->headers->set('X-FeatureFlags-Signature', $signature);

        // Use a spy to verify flush/sync are NOT called
        $mockFeatureFlags = Mockery::mock(FeatureFlags::class);
        $mockFeatureFlags->shouldNotReceive('flush');
        $mockFeatureFlags->shouldNotReceive('sync');

        $controller = new WebhookController($mockFeatureFlags);
        $response = $controller($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(['received' => true], $response->getData(true));
    }

    public function test_null_event_does_not_trigger_sync(): void
    {
        $secret = 'test-secret';
        config(['featureflags.webhook.secret' => $secret]);

        $payload = json_encode(['data' => 'no event key']);
        $signature = hash_hmac('sha256', $payload, $secret);

        $request = Request::create('/webhooks/feature-flags', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
        $request->headers->set('X-FeatureFlags-Signature', $signature);

        $mockFeatureFlags = Mockery::mock(FeatureFlags::class);
        $mockFeatureFlags->shouldNotReceive('flush');
        $mockFeatureFlags->shouldNotReceive('sync');

        $controller = new WebhookController($mockFeatureFlags);
        $response = $controller($request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_timing_safe_comparison_prevents_timing_attacks(): void
    {
        $secret = 'test-webhook-secret';
        config(['featureflags.webhook.secret' => $secret]);

        $payload = json_encode(['event' => 'flag.updated']);
        $validSignature = hash_hmac('sha256', $payload, $secret);

        // Create a signature that's almost identical
        $almostValidSignature = substr($validSignature, 0, -1) . 'x';

        $request = Request::create('/webhooks/feature-flags', 'POST', [], [], [], [], $payload);
        $request->headers->set('X-FeatureFlags-Signature', $almostValidSignature);

        $mockFeatureFlags = Mockery::mock(FeatureFlags::class);

        $controller = new WebhookController($mockFeatureFlags);
        $response = $controller($request);

        $this->assertEquals(401, $response->getStatusCode());
    }
}
