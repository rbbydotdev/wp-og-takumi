<?php

use PHPUnit\Framework\TestCase;
use Brain\Monkey;

class TemplateParserTest extends TestCase {

    private WP_OG_Takumi_Template_Engine $engine;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Stub font settings — return empty to use defaults
        Monkey\Functions\stubs([
            'get_option' => '',
            'get_theme_mod' => function (string $key, $default = '') {
                return $default;
            },
        ]);

        $this->engine = new WP_OG_Takumi_Template_Engine();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_empty_input_returns_empty_array(): void {
        $tree = $this->engine->toNodeTree('');
        $this->assertEquals([], $tree);
    }

    public function test_single_text_element(): void {
        $html = '<h1 tw="text-6xl font-bold text-white">Hello World</h1>';
        $tree = $this->engine->toNodeTree($html);

        $this->assertEquals('text', $tree['type']);
        $this->assertEquals('Hello World', $tree['content']);
        $this->assertStringContainsString('text-6xl font-bold text-white', $tree['tw']);
        $this->assertStringContainsString("font-['Playfair_Display']", $tree['tw']);
    }

    public function test_container_with_children(): void {
        $html = '<div tw="flex flex-col p-16"><span tw="text-lg">One</span><span tw="text-2xl">Two</span></div>';
        $tree = $this->engine->toNodeTree($html);

        $this->assertEquals('container', $tree['type']);
        $this->assertEquals('flex flex-col p-16', $tree['tw']);
        $this->assertCount(2, $tree['children']);
        $this->assertEquals('text', $tree['children'][0]['type']);
        $this->assertEquals('One', $tree['children'][0]['content']);
    }

    public function test_nested_containers(): void {
        $html = '<div tw="w-[1200px] h-[630px] flex"><div tw="flex flex-col"><h1 tw="text-6xl">Title</h1></div></div>';
        $tree = $this->engine->toNodeTree($html);

        $this->assertEquals('container', $tree['type']);
        $this->assertCount(1, $tree['children']);
        $this->assertEquals('container', $tree['children'][0]['type']);
        $this->assertCount(1, $tree['children'][0]['children']);
        $this->assertEquals('text', $tree['children'][0]['children'][0]['type']);
        $this->assertEquals('Title', $tree['children'][0]['children'][0]['content']);
    }

    public function test_style_attribute_preserved(): void {
        $html = '<div tw="w-[1200px] h-[630px] flex" style="background: linear-gradient(135deg, #C4653A, #1B6B6D)"><div tw="p-16"><span tw="text-lg">X</span></div></div>';
        $tree = $this->engine->toNodeTree($html);

        $this->assertEquals('background: linear-gradient(135deg, #C4653A, #1B6B6D)', $tree['style']);
    }

    public function test_tw_attribute_preserved(): void {
        $html = '<div tw="w-[1200px] h-[630px] flex items-center justify-center bg-teal-800"><span tw="text-6xl font-bold text-white">Title</span></div>';
        $tree = $this->engine->toNodeTree($html);

        $this->assertStringContainsString('items-center', $tree['tw']);
        $this->assertStringContainsString('justify-center', $tree['tw']);
    }

    public function test_image_element(): void {
        $html = '<div tw="flex"><img src="/path/to/photo.jpg" tw="w-[200px] h-[150px]" /></div>';
        $tree = $this->engine->toNodeTree($html);

        $this->assertEquals('container', $tree['type']);
        $img = $tree['children'][0];
        $this->assertEquals('image', $img['type']);
        $this->assertEquals('/path/to/photo.jpg', $img['src']);
        $this->assertEquals('w-[200px] h-[150px]', $img['tw']);
    }

    public function test_whitespace_text_nodes_ignored(): void {
        $html = '<div tw="flex">
            <span tw="text-lg">Text</span>
        </div>';
        $tree = $this->engine->toNodeTree($html);

        // Should have children, whitespace-only text nodes filtered out
        $this->assertEquals('container', $tree['type']);
        $nonEmpty = array_filter($tree['children'] ?? [], fn($c) => !empty($c));
        $this->assertCount(1, $nonEmpty);
    }

    public function test_full_default_template(): void {
        $template = file_get_contents(WP_OG_TAKUMI_PATH . 'templates/default.html');
        $vars = [
            'post_type_label' => 'Post',
            'title' => 'Amazing Journey',
            'excerpt' => 'An incredible adventure awaits.',
            'site_name' => 'Your Site Name',
            'date' => 'March 19, 2026',
        ];
        $html = $this->engine->substitute($template, $vars);
        $tree = $this->engine->toNodeTree($html);

        $this->assertEquals('container', $tree['type']);
        $this->assertStringContainsString('1200px', $tree['tw']);
        $this->assertStringContainsString('linear-gradient', $tree['style']);

        // Verify the template content is in the tree somewhere
        $json = json_encode($tree);
        $this->assertStringContainsString('Amazing Journey', $json);
        $this->assertStringContainsString('Your Site Name', $json);
    }

    public function test_full_tour_template(): void {
        $template = file_get_contents(WP_OG_TAKUMI_PATH . 'templates/tour.html');
        $vars = [
            'title' => 'Bangkok Explorer',
            'excerpt' => 'Discover the best of Bangkok.',
            'price' => '$199',
            'duration' => '3 days',
            'location' => 'Bangkok',
            'site_name' => 'Your Site Name',
        ];
        $html = $this->engine->substitute($template, $vars);
        $tree = $this->engine->toNodeTree($html);

        $json = json_encode($tree);
        $this->assertStringContainsString('Bangkok Explorer', $json);
        $this->assertStringContainsString('$199', $json);
        $this->assertStringContainsString('3 days', $json);
        $this->assertStringContainsString('Bangkok', $json);
    }

    public function test_produces_valid_json(): void {
        $html = '<div tw="w-[1200px] h-[630px] flex" style="background: linear-gradient(135deg, #C4653A, #1B6B6D)"><div tw="flex flex-col p-16 text-center"><h1 tw="text-6xl font-bold text-white">Test</h1></div></div>';
        $tree = $this->engine->toNodeTree($html);

        $json = json_encode($tree, JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($json);

        // Verify it round-trips
        $decoded = json_decode($json, true);
        $this->assertEquals($tree, $decoded);
    }

    public function test_no_tw_or_style_keys_when_absent(): void {
        $html = '<div><span>Plain text</span></div>';
        $tree = $this->engine->toNodeTree($html);

        $this->assertEquals('container', $tree['type']);
        $this->assertArrayNotHasKey('tw', $tree);
        $this->assertArrayNotHasKey('style', $tree);
    }
}
