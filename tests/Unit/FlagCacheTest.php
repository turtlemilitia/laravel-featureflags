<?php

namespace FeatureFlags\Tests\Unit;

use FeatureFlags\Cache\FlagCache;
use FeatureFlags\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

class FlagCacheTest extends TestCase
{
    private FlagCache $flagCache;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Create FlagCache with the cache repository
        $this->flagCache = new FlagCache(
            Cache::store(),
            'test_featureflags',
            300,
        );
    }

    public function test_put_stores_flags_in_cache(): void
    {
        $flags = [
            ['key' => 'flag-a', 'enabled' => true],
            ['key' => 'flag-b', 'enabled' => false],
        ];

        $this->flagCache->put($flags, 300);

        $this->assertTrue($this->flagCache->has());
    }

    public function test_get_returns_flag_by_key(): void
    {
        $flags = [
            ['key' => 'flag-a', 'enabled' => true, 'default_value' => true],
            ['key' => 'flag-b', 'enabled' => false, 'default_value' => false],
        ];

        $this->flagCache->put($flags, 300);

        $flagA = $this->flagCache->get('flag-a');
        $this->assertNotNull($flagA);
        $this->assertEquals('flag-a', $flagA['key']);
        $this->assertTrue($flagA['enabled']);

        $flagB = $this->flagCache->get('flag-b');
        $this->assertNotNull($flagB);
        $this->assertEquals('flag-b', $flagB['key']);
        $this->assertFalse($flagB['enabled']);
    }

    public function test_get_returns_null_for_unknown_flag(): void
    {
        $flags = [
            ['key' => 'flag-a', 'enabled' => true],
        ];

        $this->flagCache->put($flags, 300);

        $this->assertNull($this->flagCache->get('unknown-flag'));
    }

    public function test_all_returns_all_flags(): void
    {
        $flags = [
            ['key' => 'flag-a', 'enabled' => true],
            ['key' => 'flag-b', 'enabled' => false],
            ['key' => 'flag-c', 'enabled' => true],
        ];

        $this->flagCache->put($flags, 300);

        $all = $this->flagCache->all();
        $this->assertCount(3, $all);
    }

    public function test_flush_clears_cache(): void
    {
        $flags = [['key' => 'flag-a', 'enabled' => true]];
        $this->flagCache->put($flags, 300);

        $this->assertTrue($this->flagCache->has());

        $this->flagCache->flush();

        $this->assertFalse($this->flagCache->has());
    }

    public function test_put_segments_stores_segments(): void
    {
        $segments = [
            ['key' => 'beta-testers', 'name' => 'Beta Testers', 'rules' => []],
            ['key' => 'vip-users', 'name' => 'VIP Users', 'rules' => []],
        ];

        $this->flagCache->putSegments($segments, 300);

        $segment = $this->flagCache->getSegment('beta-testers');
        $this->assertNotNull($segment);
        $this->assertEquals('beta-testers', $segment['key']);
    }

    public function test_get_segment_returns_null_for_unknown_segment(): void
    {
        $segments = [
            ['key' => 'beta-testers', 'name' => 'Beta Testers', 'rules' => []],
        ];

        $this->flagCache->putSegments($segments, 300);

        $this->assertNull($this->flagCache->getSegment('unknown-segment'));
    }

    public function test_indexed_lookup_is_efficient(): void
    {
        // Create many flags
        $flags = [];
        for ($i = 0; $i < 1000; $i++) {
            $flags[] = ['key' => "flag-{$i}", 'enabled' => true];
        }

        $this->flagCache->put($flags, 300);

        // Access should be O(1), not O(n)
        $start = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $this->flagCache->get('flag-999'); // Get the last flag 100 times
        }
        $elapsed = microtime(true) - $start;

        // Should complete in reasonable time (< 100ms for 100 lookups)
        $this->assertLessThan(0.1, $elapsed);
    }

    public function test_segment_indexed_lookup_is_efficient(): void
    {
        // Create many segments
        $segments = [];
        for ($i = 0; $i < 1000; $i++) {
            $segments[] = ['key' => "segment-{$i}", 'name' => "Segment {$i}", 'rules' => []];
        }

        $this->flagCache->putSegments($segments, 300);

        // Access should be O(1), not O(n)
        $start = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $this->flagCache->getSegment('segment-999'); // Get the last segment 100 times
        }
        $elapsed = microtime(true) - $start;

        // Should complete in reasonable time (< 100ms for 100 lookups)
        $this->assertLessThan(0.1, $elapsed);
    }

    public function test_index_is_rebuilt_after_cache_refresh(): void
    {
        // Initial flags
        $this->flagCache->put([['key' => 'flag-a', 'enabled' => true]], 300);
        $this->assertNotNull($this->flagCache->get('flag-a'));
        $this->assertNull($this->flagCache->get('flag-b'));

        // Update flags
        $this->flagCache->put([['key' => 'flag-b', 'enabled' => true]], 300);

        // New flag should be accessible, old flag should not
        $this->assertNotNull($this->flagCache->get('flag-b'));
        $this->assertNull($this->flagCache->get('flag-a'));
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }
}
