<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Controller\Admin\ContentAdminController;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Entity\Media;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Form\AdminForms;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Service\MediaService;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Test\StubTranslation;
use Dynart\Micro\Attribute\Route;
use Dynart\Micro\Request;
use Dynart\Micro\Session;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * A `ContentService` whose `update()` records what it was handed instead of saving it
 */
class RestoringContent extends ContentService {
    public ?array $updated = null;
    public function __construct(MediaService $media) { $this->media = $media; }
    public function update(Content $content, array $data): Content { $this->updated = $data; return $content; }
}

class KnownMedia extends MediaService {
    public array $known = [];
    public function __construct() {}
    public function findById(int $id): ?Media { return in_array($id, $this->known, true) ? new Media() : null; }
}

/**
 * Restoring a revision, and previewing one first
 *
 * What is asserted is mostly what a restore does *not* take: a revision is what somebody wrote,
 * and bringing that back must not move the post, unpublish it or put it back on the front page.
 *
 * @covers \Dynart\Dpress\Service\ContentService
 * @covers \Dynart\Dpress\Controller\Admin\ContentAdminController
 */
class RevisionRestoreTest extends TestCase {

    private function service(array $knownMedia = []): RestoringContent {
        $media = new KnownMedia();
        $media->known = $knownMedia;
        return new RestoringContent($media);
    }

    private function revision(array $overrides = []): array {
        return $overrides + [
            'id' => 7, 'rev_id' => 40, 'rev_type' => 'mod',
            'title' => 'Tuesday', 'markdown' => 'What it said on Tuesday.',
            'featured_media_id' => '3', 'css' => 'a { color: red }',
            'slug' => 'old-slug', 'status' => 'draft', 'published_at' => null,
            'parent_id' => '2', 'weight' => '9', 'author_id' => '5',
        ];
    }

    private function post(): Content {
        $content = new Content();
        $content->id = 7;
        $content->status = Content::STATUS_PUBLISHED;
        return $content;
    }

    public function testTheWritingComesBack(): void {
        $service = $this->service([3]);
        $service->restoreRevision($this->post(), $this->revision());
        $this->assertSame('Tuesday', $service->updated['title']);
        $this->assertSame('What it said on Tuesday.', $service->updated['markdown']);
        $this->assertSame(3, $service->updated['featured_media_id']);
        $this->assertSame('a { color: red }', $service->updated['css']);
    }

    /**
     * Where it lives and whether anybody can see it are not part of what it said
     */
    public function testNothingThatMovesOrHidesItComesBack(): void {
        $service = $this->service([3]);
        $service->restoreRevision($this->post(), $this->revision());
        foreach (['slug', 'status', 'published_at', 'parent_id', 'weight', 'author_id'] as $field) {
            $this->assertArrayNotHasKey($field, $service->updated, $field);
        }
    }

    /**
     * A picture purged since is no picture, not a foreign key the save trips over
     */
    public function testAFeaturedImageThatIsGoneIsNoImage(): void {
        $service = $this->service([]);
        $service->restoreRevision($this->post(), $this->revision());
        $this->assertNull($service->updated['featured_media_id']);
    }

    public function testNothingIsRestoredIntoSomethingInTheTrash(): void {
        $this->expectException(DpressException::class);
        $post = $this->post();
        $post->status = Content::STATUS_TRASH;
        $this->service()->restoreRevision($post, $this->revision());
    }

    public function testTheHistoryHasAPreviewAndARestore(): void {
        $routes = [];
        foreach ((new ReflectionClass(ContentAdminController::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Route::class) as $attribute) {
                $route = $attribute->newInstance();
                $routes[] = $route->method.' '.$route->path;
            }
        }
        $this->assertContains('GET /admin/content/?/history/?/preview/?', $routes);
        $this->assertContains('POST /admin/content/?/history/?/restore/?', $routes);
    }

    // --- the editor's scroll setting ---

    public function testTheEditorKeepsTheWheelOnlyWhenAskedTo(): void {
        $factory = new FormFactory(new Request(), new Session(), new RecordingEvents(), new StubTranslation());
        AdminForms::register($factory);
        $fields = $factory->create(AdminForms::ADMIN_SETTINGS, [])->fields();
        $this->assertSame('checkbox', $fields[Setting::ADMIN_EDITOR_CONTAIN_SCROLL]['type'] ?? null);
        // the stylesheet only contains the wheel under the body class the setting turns on
        $css = file_get_contents(dirname(__DIR__, 3).'/dynart-dpress/assets/admin.css');
        $this->assertStringContainsString('body.editor-contain-scroll textarea.code-editor { overscroll-behavior: contain; }', $css);
        // and the field's own rule does not do it unconditionally any more
        $this->assertDoesNotMatchRegularExpression('/^textarea\.(markdown|code)-editor \{[^}]*overscroll-behavior/m', $css);
    }
}
