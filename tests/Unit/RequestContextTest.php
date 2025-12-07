<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Context\RequestContext;
use FeatureFlags\Tests\TestCase;

class RequestContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::reset();
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        parent::tearDown();
    }

    public function test_initialize_generates_ulid_request_id(): void
    {
        RequestContext::initialize();

        $requestId = RequestContext::getRequestId();

        $this->assertNotNull($requestId);
        // ULID format: 26 characters, alphanumeric (Crockford's Base32)
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/i', $requestId);
    }

    public function test_initialize_regenerates_each_call(): void
    {
        RequestContext::initialize();
        $firstRequestId = RequestContext::getRequestId();

        RequestContext::initialize();
        $secondRequestId = RequestContext::getRequestId();

        $this->assertNotEquals($firstRequestId, $secondRequestId);
    }

    public function test_get_session_id_returns_null_before_init(): void
    {
        $this->assertNull(RequestContext::getSessionId());
    }

    public function test_get_request_id_returns_null_before_init(): void
    {
        $this->assertNull(RequestContext::getRequestId());
    }

    public function test_set_session_id_overrides_value(): void
    {
        RequestContext::initialize();

        RequestContext::setSessionId('custom-session-123');

        $this->assertEquals('custom-session-123', RequestContext::getSessionId());
    }

    public function test_set_request_id_overrides_value(): void
    {
        RequestContext::initialize();

        RequestContext::setRequestId('custom-request-456');

        $this->assertEquals('custom-request-456', RequestContext::getRequestId());
    }

    public function test_reset_clears_all_context(): void
    {
        RequestContext::initialize();
        $this->assertNotNull(RequestContext::getRequestId());
        $this->assertNotNull(RequestContext::getSessionId());
        $this->assertTrue(RequestContext::isInitialized());

        RequestContext::reset();

        $this->assertNull(RequestContext::getRequestId());
        $this->assertNull(RequestContext::getSessionId());
        $this->assertFalse(RequestContext::isInitialized());
    }

    public function test_is_initialized_returns_correct_state(): void
    {
        $this->assertFalse(RequestContext::isInitialized());

        RequestContext::initialize();

        $this->assertTrue(RequestContext::isInitialized());

        RequestContext::reset();

        $this->assertFalse(RequestContext::isInitialized());
    }

    public function test_to_array_returns_both_ids(): void
    {
        RequestContext::initialize();

        $data = RequestContext::toArray();

        $this->assertArrayHasKey('session_id', $data);
        $this->assertArrayHasKey('request_id', $data);
        $this->assertNotNull($data['session_id']);
        $this->assertNotNull($data['request_id']);
    }
}
