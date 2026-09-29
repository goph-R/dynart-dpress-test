<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Micro\Attribute\Route;
use Dynart\Dpress\Controller\Admin\MediaAdminController;
use Dynart\Dpress\Dpress;
use PHPUnit\Framework\TestCase;

/**
 * The markdown field's *Preview media* (0.85.0): the endpoint a `media#<id>` is asked about, and
 * the two addresses the admin gives the script
 *
 * Which reference the caret is in is `Dpress.mediaTargetAt()`, tested in `assets/admin.test.js`.
 */
final class MediaPreviewTest extends TestCase {

    public function testTheLibraryAnswersAPreviewByItsId(): void {
        $method = new \ReflectionMethod(MediaAdminController::class, 'preview');
        $routes = array_map(
            fn(\ReflectionAttribute $a) => $a->newInstance()->method.' '.$a->newInstance()->path,
            $method->getAttributes(Route::class)
        );
        $this->assertSame(['GET /admin/media/preview'], $routes);
        // the id is a query parameter, so the script can add it to whatever address it was given
        $this->assertSame(0, $method->getNumberOfParameters());
    }

    public function testThePreviewAsksForThePermissionToSeeTheLibrary(): void {
        $source = file_get_contents(Dpress::path('src/Controller/Admin/MediaAdminController.php'));
        preg_match('/public function preview\(\): array \{(.*?)\n    \}/s', $source, $body);
        $this->assertStringContainsString('requirePermission(Permissions::MEDIA_VIEW)', $body[1] ?? '');
    }

    public function testTheLayoutTellsTheScriptWhereToAsk(): void {
        $layout = file_get_contents(Dpress::path('views/admin/layout.phtml'));
        $this->assertStringContainsString("data-media-preview=\"<?= esc_attr(route_url('/admin/media/preview')) ?>\"", $layout);
        $this->assertStringContainsString("data-site-url=\"<?= esc_attr(route_url('/')) ?>\"", $layout);
    }
}
