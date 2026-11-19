<?php

declare(strict_types=1);

namespace Freespoke\Wordpress\Tests;

use Brain\Monkey\Functions;
use Freespoke\Wordpress\EditorNotices;
use Freespoke\Wordpress\PostMeta;

class EditorNoticesTest extends TestCase
{
    private PostMeta|\Mockery\MockInterface $postMeta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->postMeta = \Mockery::mock(PostMeta::class);
    }

    public function testRegisterRoutes(): void
    {
        $notices = new EditorNotices($this->postMeta, '/var/www/plugin/', 'https://example.com/wp-content/plugins/freespoke/');

        Functions\expect('register_rest_route')
            ->once()
            ->with('freespoke/v1', '/publisher-latest-error', \Mockery::type('array'));

        $notices->registerRoutes();
    }

    public function testEnqueueScriptWhenFileExists(): void
    {
        // Use a real temp directory with the expected file path
        $tmpDir = sys_get_temp_dir() . '/freespoke-test-' . uniqid() . '/';
        mkdir($tmpDir . 'assets/js', 0777, true);
        file_put_contents($tmpDir . 'assets/js/publisher-notices.js', '// test');

        $notices = new EditorNotices($this->postMeta, $tmpDir, 'https://example.com/wp-content/plugins/freespoke/');

        Functions\expect('wp_enqueue_script')
            ->once()
            ->with(
                'freespoke-publisher-editor-notices',
                'https://example.com/wp-content/plugins/freespoke/assets/js/publisher-notices.js',
                \Mockery::type('array'),
                \Mockery::type('int'),
                true,
            );
        Functions\expect('wp_add_inline_script')->once();
        Functions\expect('wp_json_encode')->andReturnUsing(fn($v) => json_encode($v));

        $notices->enqueueScript();

        // Cleanup
        unlink($tmpDir . 'assets/js/publisher-notices.js');
        rmdir($tmpDir . 'assets/js');
        rmdir($tmpDir . 'assets');
        rmdir($tmpDir);
    }

    public function testEnqueueScriptSkipsWhenFileMissing(): void
    {
        $notices = new EditorNotices($this->postMeta, '/nonexistent/path/', 'https://example.com/');

        Functions\expect('wp_enqueue_script')->never();

        $notices->enqueueScript();
    }

    public function testHandleRequestReturnsOkWhenNoError(): void
    {
        $notices = new EditorNotices($this->postMeta, '/var/www/plugin/', 'https://example.com/');

        $request = new \WP_REST_Request(['id' => 42]);

        $this->postMeta->expects('getError')
            ->once()
            ->with(42)
            ->andReturn(null);

        Functions\expect('rest_ensure_response')
            ->once()
            ->with(\Mockery::on(fn($data) => $data['code'] === 'OK'))
            ->andReturnUsing(fn($data) => new \WP_REST_Response($data));

        $notices->handleRequest($request);
    }

    public function testHandleRequestReturnsErrorWhenPresent(): void
    {
        $notices = new EditorNotices($this->postMeta, '/var/www/plugin/', 'https://example.com/');

        $request = new \WP_REST_Request(['id' => 42]);

        $this->postMeta->expects('getError')
            ->once()
            ->with(42)
            ->andReturn(['code' => 'api_error', 'message' => 'Something failed']);

        Functions\expect('rest_ensure_response')
            ->once()
            ->with(\Mockery::on(fn($data) => $data['code'] === 'api_error' && $data['message'] === 'Something failed'))
            ->andReturnUsing(fn($data) => new \WP_REST_Response($data));

        $notices->handleRequest($request);
    }

    public function testHandleRequestReturnsMissingIdForZero(): void
    {
        $notices = new EditorNotices($this->postMeta, '/var/www/plugin/', 'https://example.com/');

        $request = new \WP_REST_Request(['id' => 0]);

        Functions\expect('rest_ensure_response')
            ->once()
            ->with(\Mockery::on(fn($data) => $data['code'] === 'missing_id'))
            ->andReturnUsing(fn($data) => new \WP_REST_Response($data));

        $notices->handleRequest($request);
    }
}
