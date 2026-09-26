<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\LinkTargets;
use Dynart\Dpress\Controller\Admin\ContentAdminController;
use Dynart\Dpress\Controller\Admin\MediaAdminController;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Query\CoreQueries;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Test\SavingEntities;
use Dynart\Dpress\Test\StubConfig;
use Dynart\Dpress\Test\StubDatabase;
use Dynart\Micro\Attribute\Route;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Request;
use Dynart\Micro\Router;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * A `ContentService` with the trash's own path real and everything around it recorded
 */
class TrashingContent extends ContentService {
    /** @var Content[] the rows `findById()` answers with */
    public array $rows = [];
    public array $rerendered = [];
    public function __construct(StubDatabase $db, SavingEntities $em, RecordingEvents $events) {
        $this->db = $db;
        $this->em = $em;
        $this->events = $events;
    }
    public function findById(int $id): ?Content { return $this->rows[$id] ?? null; }
    public function rerenderReferrers(Content $content): void { $this->rerendered[] = $content->id; }
}

/**
 * The trash, for content and for media
 *
 * Content goes there as a status and media as a date, and the two answer the same questions:
 * does anything a visitor sees still show it, does anything in the admin still list it, and
 * does it come back as it was.
 *
 * @covers \Dynart\Dpress\Service\ContentService
 * @covers \Dynart\Dpress\Query\CoreQueries
 * @covers \Dynart\Dpress\Content\LinkTargets
 * @covers \Dynart\Dpress\Controller\Admin\ContentAdminController
 * @covers \Dynart\Dpress\Controller\Admin\MediaAdminController
 */
class TrashTest extends TestCase {

    private StubDatabase $db;
    private SavingEntities $em;
    private RecordingEvents $events;

    private function service(): TrashingContent {
        $this->db = new StubDatabase();
        $this->events = new RecordingEvents();
        $this->em = new SavingEntities(new StubConfig(), $this->db, $this->events);
        $this->em->registerEntity(Content::class);
        return new TrashingContent($this->db, $this->em, $this->events);
    }

    private function post(int $id, string $status = Content::STATUS_PUBLISHED): Content {
        $content = new Content();
        $content->id = $id;
        $content->type = Content::TYPE_POST;
        $content->status = $status;
        $content->published_at = $status === Content::STATUS_PUBLISHED ? '2026-01-10 18:13:38' : null;
        return $content;
    }

    private function queries(): CoreQueries {
        $em = $this->createMock(EntityManager::class);
        $em->method('safeTableName')->willReturn('`dp_content`');
        return new CoreQueries($em);
    }

    // --- the status ---

    /**
     * Set by `trash()` and taken away by `restore()`, and offered by no select
     */
    public function testTheTrashIsAStatusNobodyCanChoose(): void {
        $this->assertNotContains(Content::STATUS_TRASH, Content::STATUSES);
    }

    // --- what lists it ---

    public function testNoListOfContentShowsTheTrash(): void {
        $query = $this->queries()->contentList(['type' => Content::TYPE_POST]);
        $this->assertContains('`status` <> :notTrash', $query->conditions());
        $this->assertSame(Content::STATUS_TRASH, $query->variables()[':notTrash'] ?? null);
    }

    public function testTheTrashListsOnlyTheTrash(): void {
        $query = $this->queries()->contentList(['type' => Content::TYPE_POST, 'trashed' => true]);
        $this->assertContains('`status` = :trash', $query->conditions());
        $this->assertNotContains('`status` <> :notTrash', $query->conditions());
    }

    /**
     * A draft answers to its slug for somebody who may see drafts, and a page lists its children -
     * neither of them the trash
     */
    public function testNeitherASlugNorASectionReachesIntoTheTrash(): void {
        $this->assertContains('`status` <> :notTrash',
            $this->queries()->contentBySlug(['slug' => 'x', 'published_only' => false])->conditions());
        $this->assertContains('`status` <> :notTrash',
            $this->queries()->contentChildren(['parent_id' => 1])->conditions());
    }

    public function testTheMediaTrashIsOnlyTheDeletedFiles(): void {
        $this->assertContains('`deleted_at` is not null', $this->queries()->mediaList(['trashed' => true])->conditions());
        $this->assertContains('`deleted_at` is null', $this->queries()->mediaList([])->conditions());
    }

    // --- in and out ---

    public function testTrashingTakesItOffTheSiteAndOutOfTheLinks(): void {
        $service = $this->service();
        $post = $this->post(7);
        $service->trash($post);
        $this->assertTrue($post->isTrashed());
        $this->assertSame([$post], $this->em->saved, 'saved through the entity manager, so the history says so');
        $this->assertSame([7], $service->rerendered, 'the links to it fall back to their words');
        $this->assertContains(ContentService::EVENT_TRASHED, $this->events->emitted);
        $this->assertContains('post:trashed', $this->events->emitted);
        $this->assertNotContains(ContentService::EVENT_DELETED, $this->events->emitted);
    }

    /**
     * Its children go to its parent - all of them, the trashed ones too, asked of the table
     */
    public function testTrashingAPageHandsItsChildrenOn(): void {
        $service = $this->service();
        $page = $this->post(5);
        $page->type = Content::TYPE_PAGE;
        $page->parent_id = 2;
        $child = $this->post(9);
        $child->type = Content::TYPE_PAGE;
        $child->parent_id = 5;
        $service->rows = [9 => $child];
        $this->db->column = ['9'];
        $service->trash($page);
        $this->assertSame(2, $child->parent_id);
        $this->assertCount(1, $this->db->matching('where `parent_id` = :id'));
    }

    /**
     * The status it had, read off the history - so a published post comes back published
     */
    public function testRestoringBringsBackTheStatusItHad(): void {
        $service = $this->service();
        $post = $this->post(7, Content::STATUS_TRASH);
        $post->published_at = '2026-01-10 18:13:38';
        $this->db->column = [Content::STATUS_PUBLISHED];
        $service->restore($post);
        $this->assertTrue($post->isPublished());
        $this->assertSame('2026-01-10 18:13:38', $post->published_at, 'under its original date');
        $this->assertSame([7], $service->rerendered, 'and the links to it come back');
        $this->assertContains(ContentService::EVENT_RESTORED, $this->events->emitted);
        $history = $this->db->matching('`status` in (:draft, :published)')[0];
        $this->assertStringContainsString('_aud', $history['sql']);
    }

    /**
     * Nothing in the history to go on is a draft: nothing appears on the site nobody chose
     */
    public function testWithNoHistoryItComesBackAsADraft(): void {
        $service = $this->service();
        $post = $this->post(7, Content::STATUS_TRASH);
        $this->db->column = [];
        $service->restore($post);
        $this->assertSame(Content::STATUS_DRAFT, $post->status);
    }

    public function testNothingIsPublishedStraightOutOfTheTrash(): void {
        $this->expectException(DpressException::class);
        $this->service()->publish($this->post(7, Content::STATUS_TRASH));
    }

    // --- links ---

    public function testALinkToSomethingInTheTrashIsItsWords(): void {
        $content = new SpyContent();
        $content->answer = $this->post(7, Content::STATUS_TRASH);
        $content->answer->slug = 'gone';
        $targets = new LinkTargets($content, new SpyTaxonomy(), new SpyMedia(), new SpyMediaView(),
            new Router(new StubConfig(['app.base_url' => 'https://example.com', Router::CONFIG_USE_REWRITE => true]), new Request()));
        $this->assertNull($targets->resolve('post', 7));
        $content->answer->status = Content::STATUS_DRAFT;
        $this->assertSame('https://example.com/post/gone', $targets->resolve('post', 7), 'a draft still resolves');
    }

    // --- the screens ---

    private function routes(string $className): array {
        $routes = [];
        foreach ((new ReflectionClass($className))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Route::class) as $attribute) {
                $route = $attribute->newInstance();
                $routes[] = $route->method.' '.$route->path;
            }
        }
        return $routes;
    }

    public function testEachTrashIsAScreenWithItsOwnActions(): void {
        $content = $this->routes(ContentAdminController::class);
        foreach (['GET /admin/content/?/trash', 'GET /admin/content/?/trash/list', 'POST /admin/content/?/restore/?',
                  'POST /admin/content/?/destroy/?', 'POST /admin/content/?/empty-trash'] as $route) {
            $this->assertContains($route, $content);
        }
        $media = $this->routes(MediaAdminController::class);
        foreach (['GET /admin/media/trash', 'GET /admin/media/trash/list', 'POST /admin/media/restore/?',
                  'POST /admin/media/purge/?', 'POST /admin/media/empty-trash'] as $route) {
            $this->assertContains($route, $media);
        }
    }
}
