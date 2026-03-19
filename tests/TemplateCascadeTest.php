<?php

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class TemplateCascadeTest extends TestCase {

    private Hannies_OG_Template_Engine $engine;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->engine = new Hannies_OG_Template_Engine();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_per_post_override_takes_priority(): void {
        $custom = '<div tw="flex">Custom OG</div>';

        Functions\expect('get_post_meta')
            ->once()
            ->with(42, '_og_template', true)
            ->andReturn($custom);

        $result = $this->engine->resolveTemplate(42);
        $this->assertEquals($custom, $result);
    }

    public function test_per_post_type_fallback(): void {
        $typeTemplate = '<div tw="flex">Tour Template</div>';

        Functions\expect('get_post_meta')
            ->once()
            ->with(42, '_og_template', true)
            ->andReturn('');

        Functions\expect('get_post_type')
            ->once()
            ->with(42)
            ->andReturn('tour');

        Functions\expect('get_option')
            ->once()
            ->with('hannies_og_template_tour', '')
            ->andReturn($typeTemplate);

        $result = $this->engine->resolveTemplate(42);
        $this->assertEquals($typeTemplate, $result);
    }

    public function test_global_default_fallback(): void {
        $globalTemplate = '<div tw="flex">Global Default</div>';

        Functions\expect('get_post_meta')
            ->once()
            ->with(42, '_og_template', true)
            ->andReturn('');

        Functions\expect('get_post_type')
            ->once()
            ->with(42)
            ->andReturn('post');

        Functions\expect('get_option')
            ->once()
            ->with('hannies_og_template_post', '')
            ->andReturn('');

        Functions\expect('get_option')
            ->once()
            ->with('hannies_og_default_template', '')
            ->andReturn($globalTemplate);

        $result = $this->engine->resolveTemplate(42);
        $this->assertEquals($globalTemplate, $result);
    }

    public function test_file_template_fallback_for_post_type(): void {
        Functions\expect('get_post_meta')
            ->once()
            ->with(42, '_og_template', true)
            ->andReturn('');

        Functions\expect('get_post_type')
            ->once()
            ->with(42)
            ->andReturn('tour');

        Functions\expect('get_option')
            ->once()
            ->with('hannies_og_template_tour', '')
            ->andReturn('');

        Functions\expect('get_option')
            ->once()
            ->with('hannies_og_default_template', '')
            ->andReturn('');

        $result = $this->engine->resolveTemplate(42);

        // Should load from templates/tour.html
        $expected = file_get_contents(HANNIES_OG_PATH . 'templates/tour.html');
        $this->assertEquals($expected, $result);
    }

    public function test_default_file_fallback(): void {
        Functions\expect('get_post_meta')
            ->once()
            ->with(42, '_og_template', true)
            ->andReturn('');

        Functions\expect('get_post_type')
            ->once()
            ->with(42)
            ->andReturn('nonexistent_type');

        Functions\expect('get_option')
            ->once()
            ->with('hannies_og_template_nonexistent_type', '')
            ->andReturn('');

        Functions\expect('get_option')
            ->once()
            ->with('hannies_og_default_template', '')
            ->andReturn('');

        $result = $this->engine->resolveTemplate(42);

        // Should fall back to templates/default.html
        $expected = file_get_contents(HANNIES_OG_PATH . 'templates/default.html');
        $this->assertEquals($expected, $result);
    }
}
