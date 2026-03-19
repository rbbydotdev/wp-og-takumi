<?php

use PHPUnit\Framework\TestCase;
use Brain\Monkey;

class VariableSubstitutionTest extends TestCase {

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

    public function test_substitutes_single_variable(): void {
        $result = $this->engine->substitute(
            '<h1>{{title}}</h1>',
            ['title' => 'My Post']
        );

        $this->assertEquals('<h1>My Post</h1>', $result);
    }

    public function test_substitutes_multiple_variables(): void {
        $result = $this->engine->substitute(
            '<span>{{title}} by {{author}}</span>',
            ['title' => 'My Post', 'author' => 'Jane']
        );

        $this->assertEquals('<span>My Post by Jane</span>', $result);
    }

    public function test_missing_variable_becomes_empty(): void {
        $result = $this->engine->substitute(
            '<span>{{title}} - {{missing}}</span>',
            ['title' => 'My Post']
        );

        $this->assertEquals('<span>My Post - </span>', $result);
    }

    public function test_special_chars_passed_through(): void {
        // Substitution no longer HTML-escapes — Takumi handles raw text
        $result = $this->engine->substitute(
            '<h1>{{title}}</h1>',
            ['title' => 'Tom & Jerry']
        );

        $this->assertStringContainsString('Tom & Jerry', $result);
    }

    public function test_quotes_passed_through(): void {
        $result = $this->engine->substitute(
            '<span>{{title}}</span>',
            ['title' => 'She said "hello"']
        );

        $this->assertStringContainsString('She said "hello"', $result);
    }

    public function test_no_variables_returns_template_unchanged(): void {
        $template = '<div tw="flex">Static content</div>';
        $result = $this->engine->substitute($template, []);

        $this->assertEquals($template, $result);
    }

    public function test_double_braces_not_in_variable_format_unchanged(): void {
        $template = '<span>{{ not_a_var }}</span>';
        $result = $this->engine->substitute($template, ['not_a_var' => 'test']);

        $this->assertEquals($template, $result);
    }

    public function test_empty_string_variable(): void {
        $result = $this->engine->substitute(
            '<span>{{title}}</span>',
            ['title' => '']
        );

        $this->assertEquals('<span></span>', $result);
    }
}
