<?php

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class OgMetaOutputTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        require_once HANNIES_OG_PATH . 'includes/class-og-meta.php';
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_outputs_meta_tags_on_singular(): void {
        Functions\expect('is_singular')->once()->andReturn(true);
        Functions\expect('get_queried_object_id')->once()->andReturn(42);
        Functions\expect('rest_url')
            ->once()
            ->with('hannies/v1/og-image/42')
            ->andReturn('http://localhost:8080/wp-json/hannies/v1/og-image/42');
        Functions\expect('esc_url')->andReturnFirstArg();
        Functions\expect('esc_attr')->andReturnFirstArg();

        ob_start();
        Hannies_OG_Meta::outputMetaTags();
        $output = ob_get_clean();

        $this->assertStringContainsString('og:image', $output);
        $this->assertStringContainsString('og:image:width', $output);
        $this->assertStringContainsString('og:image:height', $output);
        $this->assertStringContainsString('twitter:card', $output);
        $this->assertStringContainsString('twitter:image', $output);
        $this->assertStringContainsString('1200', $output);
        $this->assertStringContainsString('630', $output);
    }

    public function test_no_output_on_non_singular(): void {
        Functions\expect('is_singular')->once()->andReturn(false);

        ob_start();
        Hannies_OG_Meta::outputMetaTags();
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    public function test_no_output_when_no_post_id(): void {
        Functions\expect('is_singular')->once()->andReturn(true);
        Functions\expect('get_queried_object_id')->once()->andReturn(0);

        ob_start();
        Hannies_OG_Meta::outputMetaTags();
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }
}
