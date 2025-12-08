<?php

declare(strict_types=1);

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Context;
use FeatureFlags\ContextResolver;
use FeatureFlags\Contracts\HasFeatureFlagContext;
use FeatureFlags\Evaluation\ContextNormalizer;
use FeatureFlags\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

class ContextNormalizerTest extends TestCase
{
    private ContextResolver&MockInterface $contextResolver;
    private ContextNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contextResolver = Mockery::mock(ContextResolver::class);
        $this->normalizer = new ContextNormalizer($this->contextResolver);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_normalize_null_delegates_to_resolver(): void
    {
        $expectedContext = new Context('resolved-123', ['from' => 'resolver']);
        $this->contextResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn($expectedContext);

        $result = $this->normalizer->normalize(null);

        $this->assertSame($expectedContext, $result);
    }

    public function test_normalize_null_returns_null_when_resolver_returns_null(): void
    {
        $this->contextResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn(null);

        $result = $this->normalizer->normalize(null);

        $this->assertNull($result);
    }

    public function test_normalize_context_enriches_with_version_traits(): void
    {
        $original = new Context('user-123', ['plan' => 'pro']);

        $this->contextResolver
            ->shouldReceive('resolveVersionTraits')
            ->once()
            ->andReturn(['app_version' => '2.0.0', 'platform' => 'ios']);

        $result = $this->normalizer->normalize($original);

        $this->assertInstanceOf(Context::class, $result);
        $this->assertEquals('user-123', $result->id);
        $this->assertEquals('pro', $result->get('plan'));
        $this->assertEquals('2.0.0', $result->get('app_version'));
        $this->assertEquals('ios', $result->get('platform'));
    }

    public function test_normalize_context_without_version_traits_returns_same_context(): void
    {
        $original = new Context('user-123', ['plan' => 'pro']);

        $this->contextResolver
            ->shouldReceive('resolveVersionTraits')
            ->once()
            ->andReturn([]);

        $result = $this->normalizer->normalize($original);

        $this->assertSame($original, $result);
    }

    public function test_normalize_context_user_traits_override_version_traits(): void
    {
        $original = new Context('user-123', ['app_version' => '3.0.0']);

        $this->contextResolver
            ->shouldReceive('resolveVersionTraits')
            ->once()
            ->andReturn(['app_version' => '2.0.0', 'platform' => 'ios']);

        $result = $this->normalizer->normalize($original);

        // User's app_version should take precedence
        $this->assertEquals('3.0.0', $result->get('app_version'));
        $this->assertEquals('ios', $result->get('platform'));
    }

    public function test_normalize_has_feature_flag_context_delegates_to_resolver(): void
    {
        $model = new TestModelWithContext();
        $expectedContext = new Context('model-456', ['type' => 'test']);

        $this->contextResolver
            ->shouldReceive('fromInterface')
            ->once()
            ->with($model)
            ->andReturn($expectedContext);

        $result = $this->normalizer->normalize($model);

        $this->assertSame($expectedContext, $result);
    }

    public function test_normalize_array_with_id_creates_context(): void
    {
        $this->contextResolver
            ->shouldReceive('mergeVersionTraits')
            ->once()
            ->with(['plan' => 'enterprise', 'country' => 'US'])
            ->andReturn(['plan' => 'enterprise', 'country' => 'US', 'app_version' => '1.0.0']);

        $result = $this->normalizer->normalize([
            'id' => 'user-789',
            'plan' => 'enterprise',
            'country' => 'US',
        ]);

        $this->assertInstanceOf(Context::class, $result);
        $this->assertEquals('user-789', $result->id);
        $this->assertEquals('enterprise', $result->get('plan'));
        $this->assertEquals('US', $result->get('country'));
        $this->assertEquals('1.0.0', $result->get('app_version'));
    }

    public function test_normalize_array_with_explicit_traits_key(): void
    {
        $this->contextResolver
            ->shouldReceive('mergeVersionTraits')
            ->once()
            ->with(['role' => 'admin'])
            ->andReturn(['role' => 'admin']);

        $result = $this->normalizer->normalize([
            'id' => 'user-101',
            'traits' => ['role' => 'admin'],
        ]);

        $this->assertInstanceOf(Context::class, $result);
        $this->assertEquals('user-101', $result->id);
        $this->assertEquals('admin', $result->get('role'));
    }

    public function test_normalize_array_without_id_returns_null(): void
    {
        $result = $this->normalizer->normalize([
            'plan' => 'pro',
            'country' => 'UK',
        ]);

        $this->assertNull($result);
    }

    public function test_normalize_array_with_integer_id(): void
    {
        $this->contextResolver
            ->shouldReceive('mergeVersionTraits')
            ->once()
            ->andReturn(['plan' => 'basic']);

        $result = $this->normalizer->normalize([
            'id' => 12345,
            'plan' => 'basic',
        ]);

        $this->assertInstanceOf(Context::class, $result);
        $this->assertEquals(12345, $result->id);
    }

    public function test_normalize_array_extracts_traits_from_flat_structure(): void
    {
        $this->contextResolver
            ->shouldReceive('mergeVersionTraits')
            ->once()
            ->with(['email' => 'test@example.com', 'age' => 30])
            ->andReturn(['email' => 'test@example.com', 'age' => 30]);

        $result = $this->normalizer->normalize([
            'id' => 'flat-user',
            'email' => 'test@example.com',
            'age' => 30,
        ]);

        $this->assertEquals('test@example.com', $result->get('email'));
        $this->assertEquals(30, $result->get('age'));
    }
}

class TestModelWithContext implements HasFeatureFlagContext
{
    public function toFeatureFlagContext(): array
    {
        return [
            'id' => 'model-456',
            'type' => 'test',
        ];
    }
}
