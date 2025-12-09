<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Context\DeviceIdentifier;
use FeatureFlags\Tests\TestCase;
use Illuminate\Support\Str;

class DeviceIdentifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DeviceIdentifier::reset();
    }

    protected function tearDown(): void
    {
        DeviceIdentifier::reset();
        parent::tearDown();
    }

    public function test_generates_uuid_in_console(): void
    {
        $deviceId = DeviceIdentifier::get();

        $this->assertTrue(Str::isUuid($deviceId));
    }

    public function test_returns_same_value_on_subsequent_calls(): void
    {
        $first = DeviceIdentifier::get();
        $second = DeviceIdentifier::get();

        $this->assertSame($first, $second);
    }

    public function test_custom_device_id_takes_priority(): void
    {
        $customId = 'custom-device-123';

        DeviceIdentifier::setDeviceId($customId);

        $this->assertSame($customId, DeviceIdentifier::get());
    }

    public function test_custom_device_id_persists_after_multiple_calls(): void
    {
        $customId = 'custom-device-456';

        DeviceIdentifier::setDeviceId($customId);
        DeviceIdentifier::get();
        DeviceIdentifier::get();

        $this->assertSame($customId, DeviceIdentifier::get());
    }

    public function test_reset_clears_all_state(): void
    {
        DeviceIdentifier::setDeviceId('custom-id');
        $before = DeviceIdentifier::get();

        DeviceIdentifier::reset();
        $after = DeviceIdentifier::get();

        $this->assertSame('custom-id', $before);
        $this->assertNotSame($before, $after);
        $this->assertTrue(Str::isUuid($after));
    }

    public function test_set_device_id_overrides_any_cached_value(): void
    {
        // Get an auto-generated ID first
        $autoId = DeviceIdentifier::get();

        // Then set a custom one
        $customId = 'override-device-id';
        DeviceIdentifier::setDeviceId($customId);

        // Custom ID should take priority
        $this->assertSame($customId, DeviceIdentifier::get());
        $this->assertNotSame($autoId, DeviceIdentifier::get());
    }

    public function test_device_id_is_valid_uuid_format(): void
    {
        $deviceId = DeviceIdentifier::get();

        // Should be a valid UUID v4
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $deviceId,
        );
    }

    public function test_different_instances_after_reset_generate_different_ids(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            DeviceIdentifier::reset();
            $ids[] = DeviceIdentifier::get();
        }

        // All IDs should be unique
        $this->assertCount(5, array_unique($ids));
    }

    public function test_has_consent_returns_false_by_default(): void
    {
        $this->assertFalse(DeviceIdentifier::hasConsent());
    }

    public function test_grant_consent_sets_consent_state(): void
    {
        $this->assertFalse(DeviceIdentifier::hasConsent());

        DeviceIdentifier::grantConsent();

        $this->assertTrue(DeviceIdentifier::hasConsent());
    }

    public function test_revoke_consent_clears_consent_state(): void
    {
        DeviceIdentifier::grantConsent();
        $this->assertTrue(DeviceIdentifier::hasConsent());

        DeviceIdentifier::revokeConsent();

        $this->assertFalse(DeviceIdentifier::hasConsent());
    }

    public function test_reset_clears_consent_state(): void
    {
        DeviceIdentifier::grantConsent();
        $this->assertTrue(DeviceIdentifier::hasConsent());

        DeviceIdentifier::reset();

        $this->assertFalse(DeviceIdentifier::hasConsent());
    }

    public function test_consent_state_persists_across_multiple_checks(): void
    {
        DeviceIdentifier::grantConsent();

        $this->assertTrue(DeviceIdentifier::hasConsent());
        $this->assertTrue(DeviceIdentifier::hasConsent());
        $this->assertTrue(DeviceIdentifier::hasConsent());
    }
}
