<?php

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class OgEndpointTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        require_once HANNIES_OG_PATH . 'includes/class-og-renderer.php';
        require_once HANNIES_OG_PATH . 'includes/class-og-endpoint.php';
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_registers_rest_route(): void {
        $registered = false;

        Functions\expect('register_rest_route')
            ->once()
            ->with('hannies/v1', '/og-image/(?P<id>\d+)', \Mockery::type('array'))
            ->andReturnUsing(function () use (&$registered) {
                $registered = true;
            });

        Hannies_OG_Endpoint::registerRoutes();
        $this->assertTrue($registered);
    }

    public function test_returns_404_for_missing_post(): void {
        $request = \Mockery::mock('WP_REST_Request');
        $request->shouldReceive('get_param')->with('id')->andReturn(999);

        Functions\expect('get_post')->once()->with(999)->andReturn(null);

        // Need WP_Error class
        if (!class_exists('WP_Error')) {
            $this->markTestSkipped('WP_Error not available in unit test context');
        }

        $result = Hannies_OG_Endpoint::handleRequest($request);
        $this->assertInstanceOf('WP_Error', $result);
    }
}
