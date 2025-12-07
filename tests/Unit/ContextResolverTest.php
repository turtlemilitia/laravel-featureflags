<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Context;
use FeatureFlags\ContextResolver;
use FeatureFlags\Contracts\HasFeatureFlagContext;
use FeatureFlags\Tests\TestCase;
use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Support\Facades\Auth;
use Mockery;

class ContextResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_null_when_auto_resolve_disabled(): void
    {
        config(['featureflags.context.auto_resolve' => false]);

        $resolver = new ContextResolver();

        $this->assertNull($resolver->resolve());
    }

    public function test_returns_null_when_no_authenticated_user(): void
    {
        config(['featureflags.context.auto_resolve' => true]);

        Auth::shouldReceive('user')->once()->andReturn(null);

        $resolver = new ContextResolver();

        $this->assertNull($resolver->resolve());
    }

    public function test_returns_null_when_user_does_not_implement_interface(): void
    {
        config(['featureflags.context.auto_resolve' => true]);

        $user = new TestUserWithoutInterface();
        $user->id = 123;

        Auth::shouldReceive('user')->once()->andReturn($user);

        $resolver = new ContextResolver();

        $this->assertNull($resolver->resolve());
    }

    public function test_resolves_context_from_interface(): void
    {
        config(['featureflags.context.auto_resolve' => true]);

        $user = new TestUserWithInterface();
        $user->id = 123;
        $user->email = 'test@example.com';

        Auth::shouldReceive('user')->once()->andReturn($user);

        $resolver = new ContextResolver();
        $context = $resolver->resolve();

        $this->assertInstanceOf(Context::class, $context);
        $this->assertEquals(123, $context->id);
        $this->assertEquals('interface@example.com', $context->get('email'));
        $this->assertEquals('pro', $context->get('plan'));
    }

    public function test_from_interface_works_with_any_model(): void
    {
        $team = new TestTeam();
        $team->id = 456;
        $team->name = 'Acme Corp';

        $resolver = new ContextResolver();
        $context = $resolver->fromInterface($team);

        $this->assertInstanceOf(Context::class, $context);
        $this->assertEquals(456, $context->id);
        $this->assertEquals('Acme Corp', $context->get('name'));
        $this->assertEquals('enterprise', $context->get('plan'));
    }

    public function test_from_interface_uses_auth_identifier_when_no_id_in_context(): void
    {
        $user = new TestUserWithInterfaceNoId();
        $user->id = 999;

        $resolver = new ContextResolver();
        $context = $resolver->fromInterface($user);

        $this->assertInstanceOf(Context::class, $context);
        $this->assertEquals(999, $context->id);
        $this->assertEquals('test@example.com', $context->get('email'));
    }

    public function test_from_interface_uses_spl_object_id_when_no_id_or_auth_identifier(): void
    {
        $model = new TestModelWithoutId();

        $resolver = new ContextResolver();
        $context = $resolver->fromInterface($model);

        $this->assertInstanceOf(Context::class, $context);
        $this->assertIsInt($context->id);
        $this->assertEquals('test', $context->get('name'));
    }
}

/**
 * Test user class without HasFeatureFlagContext.
 */
class TestUserWithoutInterface extends BaseUser
{
    public int $id;

    public function getAuthIdentifier(): int
    {
        return $this->id;
    }
}

/**
 * Test user class that implements HasFeatureFlagContext.
 */
class TestUserWithInterface extends BaseUser implements HasFeatureFlagContext
{
    public int $id;
    public string $email;

    public function getAuthIdentifier(): int
    {
        return $this->id;
    }

    public function toFeatureFlagContext(): array
    {
        return [
            'id' => $this->id,
            'email' => 'interface@example.com',
            'plan' => 'pro',
        ];
    }
}

/**
 * Test user class that implements HasFeatureFlagContext but doesn't return id.
 */
class TestUserWithInterfaceNoId extends BaseUser implements HasFeatureFlagContext
{
    public int $id;

    public function getAuthIdentifier(): int
    {
        return $this->id;
    }

    public function toFeatureFlagContext(): array
    {
        return [
            'email' => 'test@example.com',
        ];
    }
}

/**
 * Test non-User model that implements HasFeatureFlagContext.
 */
class TestTeam implements HasFeatureFlagContext
{
    public int $id;
    public string $name;

    public function toFeatureFlagContext(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'plan' => 'enterprise',
        ];
    }
}

/**
 * Test model without id in context and no getAuthIdentifier.
 */
class TestModelWithoutId implements HasFeatureFlagContext
{
    public function toFeatureFlagContext(): array
    {
        return [
            'name' => 'test',
        ];
    }
}
