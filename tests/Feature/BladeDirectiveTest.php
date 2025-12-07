<?php

declare(strict_types=1);

namespace FeatureFlags\Tests\Feature;

use FeatureFlags\Context;
use Illuminate\Support\Facades\Blade;

class BladeDirectiveTest extends FeatureTestCase
{
    private string $viewPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewPath = __DIR__ . '/views';
        if (!is_dir($this->viewPath)) {
            mkdir($this->viewPath, 0755, true);
        }
        $this->app['view']->addNamespace('test', $this->viewPath);
    }

    protected function tearDown(): void
    {
        // Cleanup view files
        foreach (glob($this->viewPath . '/*.blade.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->viewPath);

        parent::tearDown();
    }

    /**
     * Render a Blade template using actual view files.
     * Blade directives require newlines/whitespace to be recognized.
     *
     * @param array<string, mixed> $data
     */
    private function renderBlade(string $template, array $data = []): string
    {
        $viewName = 'test-' . md5($template . serialize($data));
        $filename = $viewName . '.blade.php';
        file_put_contents($this->viewPath . '/' . $filename, $template);

        return view('test::' . $viewName, $data)->render();
    }

    public function test_feature_directive_renders_content_when_flag_enabled(): void
    {
        $this->seedFlags([
            $this->simpleFlag('show-banner', true, true),
        ]);

        $rendered = $this->renderBlade(<<<'BLADE'
@feature('show-banner')
Banner Content
@endfeature
BLADE);

        $this->assertEquals('Banner Content', trim($rendered));
    }

    public function test_feature_directive_hides_content_when_flag_disabled(): void
    {
        $this->seedFlags([
            $this->simpleFlag('show-banner', false, false),
        ]);

        $rendered = $this->renderBlade(<<<'BLADE'
@feature('show-banner')
Banner Content
@endfeature
BLADE);

        $this->assertEquals('', trim($rendered));
    }

    public function test_feature_directive_renders_else_block_when_disabled(): void
    {
        $this->seedFlags([
            $this->simpleFlag('new-ui', false, false),
        ]);

        $rendered = $this->renderBlade(<<<'BLADE'
@feature('new-ui')
New UI
@else
Old UI
@endfeature
BLADE);

        $this->assertEquals('Old UI', trim($rendered));
    }

    public function test_feature_directive_with_context_array(): void
    {
        $this->seedFlags([
            $this->flagWithRules('pro-feature', [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'plan', 'operator' => 'equals', 'value' => 'pro'],
                    ],
                    'value' => true,
                ],
            ]),
        ]);

        // Pro user should see the feature
        $rendered = $this->renderBlade(<<<'BLADE'
@feature('pro-feature', ['id' => 'user-1', 'plan' => 'pro'])
Pro Content
@else
Free Content
@endfeature
BLADE);
        $this->assertEquals('Pro Content', trim($rendered));

        // Free user should not
        $rendered = $this->renderBlade(<<<'BLADE'
@feature('pro-feature', ['id' => 'user-2', 'plan' => 'free'])
Pro Content
@else
Free Content
@endfeature
BLADE);
        $this->assertEquals('Free Content', trim($rendered));
    }

    public function test_feature_directive_with_context_object(): void
    {
        $this->seedFlags([
            $this->flagWithRules('enterprise-feature', [
                [
                    'priority' => 1,
                    'conditions' => [
                        ['trait' => 'tier', 'operator' => 'equals', 'value' => 'enterprise'],
                    ],
                    'value' => true,
                ],
            ]),
        ]);

        $enterpriseContext = new Context('enterprise-user', ['tier' => 'enterprise']);
        $basicContext = new Context('basic-user', ['tier' => 'basic']);

        $rendered = $this->renderBlade(<<<'BLADE'
@feature('enterprise-feature', $enterpriseContext)
Enterprise
@else
Basic
@endfeature
BLADE, ['enterpriseContext' => $enterpriseContext]);
        $this->assertEquals('Enterprise', trim($rendered));

        $rendered = $this->renderBlade(<<<'BLADE'
@feature('enterprise-feature', $basicContext)
Enterprise
@else
Basic
@endfeature
BLADE, ['basicContext' => $basicContext]);
        $this->assertEquals('Basic', trim($rendered));
    }

    public function test_feature_directive_with_nonexistent_flag_returns_false(): void
    {
        $this->seedFlags([]);

        $rendered = $this->renderBlade(<<<'BLADE'
@feature('nonexistent-flag')
Shown
@else
Hidden
@endfeature
BLADE);

        $this->assertEquals('Hidden', trim($rendered));
    }

    public function test_feature_directive_with_string_value_flag(): void
    {
        $this->seedFlags([
            $this->simpleFlag('variant-flag', true, 'control'),
        ]);

        // String values are truthy
        $rendered = $this->renderBlade(<<<'BLADE'
@feature('variant-flag')
Has Value
@else
No Value
@endfeature
BLADE);

        $this->assertEquals('Has Value', trim($rendered));
    }

    public function test_feature_directive_with_false_default_shows_else(): void
    {
        $this->seedFlags([
            $this->simpleFlag('disabled-flag', true, false),
        ]);

        $rendered = $this->renderBlade(<<<'BLADE'
@feature('disabled-flag')
Enabled
@else
Disabled
@endfeature
BLADE);

        $this->assertEquals('Disabled', trim($rendered));
    }

    public function test_nested_feature_directives(): void
    {
        $this->seedFlags([
            $this->simpleFlag('outer-feature', true, true),
            $this->simpleFlag('inner-feature', true, true),
        ]);

        $rendered = $this->renderBlade(<<<'BLADE'
@feature('outer-feature')
Outer
@feature('inner-feature')
Inner
@endfeature
@endfeature
BLADE);

        $this->assertStringContainsString('Outer', trim($rendered));
        $this->assertStringContainsString('Inner', trim($rendered));
    }

    public function test_feature_directive_is_registered(): void
    {
        $directives = Blade::getCustomDirectives();

        $this->assertArrayHasKey('feature', $directives);
        $this->assertArrayHasKey('endfeature', $directives);
    }
}
