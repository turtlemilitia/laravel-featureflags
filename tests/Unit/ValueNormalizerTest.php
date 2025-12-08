<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Evaluation\ValueNormalizer;
use FeatureFlags\Tests\TestCase;

class ValueNormalizerTest extends TestCase
{
    public function test_normalizes_null(): void
    {
        $this->assertNull(ValueNormalizer::normalize(null));
    }

    public function test_normalizes_bool(): void
    {
        $this->assertTrue(ValueNormalizer::normalize(true));
        $this->assertFalse(ValueNormalizer::normalize(false));
    }

    public function test_normalizes_int(): void
    {
        $this->assertSame(42, ValueNormalizer::normalize(42));
        $this->assertSame(0, ValueNormalizer::normalize(0));
        $this->assertSame(-1, ValueNormalizer::normalize(-1));
    }

    public function test_normalizes_float(): void
    {
        $this->assertSame(3.14, ValueNormalizer::normalize(3.14));
        $this->assertSame(0.0, ValueNormalizer::normalize(0.0));
    }

    public function test_normalizes_string(): void
    {
        $this->assertSame('hello', ValueNormalizer::normalize('hello'));
        $this->assertSame('', ValueNormalizer::normalize(''));
    }

    public function test_normalizes_array(): void
    {
        $array = ['key' => 'value', 'nested' => ['a' => 1]];
        $this->assertSame($array, ValueNormalizer::normalize($array));
    }

    public function test_returns_null_for_object(): void
    {
        $this->assertNull(ValueNormalizer::normalize(new \stdClass()));
    }

    public function test_returns_null_for_resource(): void
    {
        $resource = fopen('php://memory', 'r');
        $this->assertNull(ValueNormalizer::normalize($resource));
        fclose($resource);
    }

    public function test_returns_null_for_callable(): void
    {
        $this->assertNull(ValueNormalizer::normalize(fn() => true));
    }
}
