<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Context;
use FeatureFlags\Tests\TestCase;

class ContextTest extends TestCase
{
    public function test_context_stores_id_and_traits(): void
    {
        $context = new Context('user-123', [
            'plan' => 'pro',
            'country' => 'US',
        ]);

        $this->assertEquals('user-123', $context->id);
        $this->assertEquals('pro', $context->get('plan'));
        $this->assertEquals('US', $context->get('country'));
    }

    public function test_get_returns_null_for_missing_trait(): void
    {
        $context = new Context('user-123', ['plan' => 'pro']);

        $this->assertNull($context->get('missing-trait'));
    }

    public function test_context_can_be_created_with_make(): void
    {
        $context = Context::make('user-456', ['role' => 'admin']);

        $this->assertEquals('user-456', $context->id);
        $this->assertEquals('admin', $context->get('role'));
    }

    public function test_traits_are_readonly(): void
    {
        $context = new Context('user-123', [
            'plan' => 'pro',
        ]);

        $this->assertEquals(['plan' => 'pro'], $context->traits);
    }

    public function test_context_supports_nested_traits(): void
    {
        $context = new Context('user-123', [
            'subscription' => [
                'plan' => 'enterprise',
                'status' => 'active',
            ],
        ]);

        // Direct access returns the array
        $this->assertIsArray($context->get('subscription'));
        $this->assertEquals('enterprise', $context->get('subscription')['plan']);
    }

    public function test_get_supports_dot_notation(): void
    {
        $context = new Context('user-123', [
            'subscription' => [
                'plan' => [
                    'name' => 'enterprise',
                    'price' => 99,
                ],
                'status' => 'active',
            ],
        ]);

        $this->assertEquals('enterprise', $context->get('subscription.plan.name'));
        $this->assertEquals(99, $context->get('subscription.plan.price'));
        $this->assertEquals('active', $context->get('subscription.status'));
    }

    public function test_get_dot_notation_returns_default_for_missing(): void
    {
        $context = new Context('user-123', [
            'subscription' => [
                'plan' => 'pro',
            ],
        ]);

        $this->assertNull($context->get('subscription.missing'));
        $this->assertEquals('default', $context->get('subscription.missing', 'default'));
        $this->assertNull($context->get('missing.nested.path'));
    }

    public function test_has_supports_dot_notation(): void
    {
        $context = new Context('user-123', [
            'subscription' => [
                'plan' => [
                    'name' => 'enterprise',
                ],
            ],
        ]);

        $this->assertTrue($context->has('subscription'));
        $this->assertTrue($context->has('subscription.plan'));
        $this->assertTrue($context->has('subscription.plan.name'));
        $this->assertFalse($context->has('subscription.plan.missing'));
        $this->assertFalse($context->has('missing.nested'));
    }

    public function test_has_returns_true_for_existing_trait(): void
    {
        $context = new Context('user-123', [
            'plan' => 'pro',
            'country' => 'US',
        ]);

        $this->assertTrue($context->has('plan'));
        $this->assertTrue($context->has('country'));
    }

    public function test_has_returns_false_for_missing_trait(): void
    {
        $context = new Context('user-123', ['plan' => 'pro']);

        $this->assertFalse($context->has('country'));
        $this->assertFalse($context->has('missing'));
    }

    public function test_to_array_returns_complete_structure(): void
    {
        $context = new Context('user-456', [
            'plan' => 'enterprise',
            'country' => 'UK',
            'role' => 'admin',
        ]);

        $array = $context->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('traits', $array);
        $this->assertEquals('user-456', $array['id']);
        $this->assertEquals('enterprise', $array['traits']['plan']);
        $this->assertEquals('UK', $array['traits']['country']);
        $this->assertEquals('admin', $array['traits']['role']);
    }

    public function test_get_returns_default_when_trait_missing(): void
    {
        $context = new Context('user-123', ['plan' => 'pro']);

        $this->assertEquals('fallback', $context->get('missing', 'fallback'));
        $this->assertEquals(42, $context->get('other', 42));
    }
}
