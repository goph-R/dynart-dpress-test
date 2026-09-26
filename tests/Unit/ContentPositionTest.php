<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Controller\Admin\ContentAdminController;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Test\SavingEntities;
use Dynart\Dpress\Test\StubConfig;
use Dynart\Dpress\Test\StubDatabase;
use Dynart\Micro\Attribute\Route;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * A `ContentService` with only what `move()` touches
 *
 * The group comes from the stub database's `rows`, in the order a visitor would see them, and
 * the writes are what the test reads back: plain updates in the database's log, revisions in
 * the entity manager's `saved`.
 */
class MovingContent extends ContentService {
    public function __construct(StubDatabase $db, SavingEntities $em, RecordingEvents $events) {
        $this->db = $db;
        $this->em = $em;
        $this->events = $events;
    }

    public function nextPositionOf(Content $content): int {
        return $this->nextPosition($content);
    }
}

/**
 * Up and down, beside the weight
 *
 * The weight pins a post to the top or the bottom; the position is the arrangement inside one
 * weight, and it is what the admin list's two buttons change. What is asserted below is mostly
 * the *cost* of a move - what gets written, and which of it gets a revision - because the order
 * it produces is only right if it is also cheap to produce.
 *
 * @covers \Dynart\Dpress\Service\ContentService
 * @covers \Dynart\Dpress\Controller\Admin\ContentAdminController
 */
class ContentPositionTest extends TestCase {

    private StubDatabase $db;
    private SavingEntities $em;
    private RecordingEvents $events;

    /**
     * @param array $group [id => position], in the order a visitor sees them
     */
    private function service(array $group): MovingContent {
        $this->db = new StubDatabase();
        foreach ($group as $id => $position) {
            $this->db->rows[] = ['id' => (string)$id, 'position' => (string)$position];
        }
        $this->events = new RecordingEvents();
        $this->em = new SavingEntities(new StubConfig(), $this->db, $this->events);
        $this->em->registerEntity(Content::class);
        return new MovingContent($this->db, $this->em, $this->events);
    }

    private function post(int $id, int $position = 0): Content {
        $content = new Content();
        $content->id = $id;
        $content->type = Content::TYPE_POST;
        $content->position = $position;
        return $content;
    }

    /**
     * @return array<int, int> the plain updates, as [id => position]
     */
    private function renumbered(): array {
        $found = [];
        foreach ($this->db->matching('set `position`') as $query) {
            $found[$query['params'][':id']] = $query['params'][':position'];
        }
        return $found;
    }

    // --- the order ---

    public function testANewPostHasNoPosition(): void {
        $this->assertSame(0, (new Content())->position);
    }

    /**
     * The first press in a group that was never arranged: every row is 0, so there is no number
     * to swap, and the group becomes 1, 2, 3 in its new order
     */
    public function testAMoveInAGroupOfZerosNumbersTheGroup(): void {
        $moved = $this->post(12);
        $this->assertTrue($this->service([10 => 0, 11 => 0, 12 => 0])->move($moved, ContentService::MOVE_UP));
        $this->assertSame(2, $moved->position);
        $this->assertSame([10 => 1, 11 => 3], $this->renumbered());
    }

    /**
     * **Only the one that was moved gets a revision.** The others did not change - their
     * neighbour did - and three hundred of them would otherwise each gain a row of history
     */
    public function testOnlyTheMovedPostIsSavedThroughTheEntityManager(): void {
        $moved = $this->post(12);
        $this->service([10 => 0, 11 => 0, 12 => 0])->move($moved, ContentService::MOVE_UP);
        $this->assertSame([$moved], $this->em->saved);
    }

    /**
     * Renumbered, but only what changed is written: in an arranged group a step touches two rows
     */
    public function testAnArrangedGroupWritesOnlyTheTwoThatSwapped(): void {
        $moved = $this->post(11, 2);
        $this->service([10 => 1, 11 => 2, 12 => 3])->move($moved, ContentService::MOVE_UP);
        $this->assertSame(1, $moved->position);
        $this->assertSame([10 => 2], $this->renumbered());
    }

    public function testDownIsOneStepTheOtherWay(): void {
        $moved = $this->post(10, 1);
        $this->service([10 => 1, 11 => 2, 12 => 3])->move($moved, ContentService::MOVE_DOWN);
        $this->assertSame(2, $moved->position);
        $this->assertSame([11 => 1], $this->renumbered());
    }

    /**
     * The top of a weight is as far as up goes - past it is the weight's to decide - and a press
     * there writes nothing at all
     */
    public function testTheEndsOfTheGroupAreAsFarAsItGoes(): void {
        $this->assertFalse($this->service([10 => 1, 11 => 2])->move($this->post(10, 1), ContentService::MOVE_UP));
        $this->assertSame([], $this->renumbered());
        $this->assertSame([], $this->em->saved);
        $this->assertFalse($this->service([10 => 1, 11 => 2])->move($this->post(11, 2), ContentService::MOVE_DOWN));
        $this->assertSame([], $this->em->saved);
    }

    /**
     * An arrangement, not an edit: `updated` would wake whatever re-publishes a changed post
     */
    public function testAMoveIsItsOwnEvent(): void {
        $this->service([10 => 0, 11 => 0])->move($this->post(11), ContentService::MOVE_UP);
        $this->assertContains(ContentService::EVENT_MOVED, $this->events->emitted);
        $this->assertContains('post:moved', $this->events->emitted);
        $this->assertNotContains(ContentService::EVENT_UPDATED, $this->events->emitted);
    }

    public function testADirectionIsUpOrDown(): void {
        $this->expectException(DpressException::class);
        $this->service([10 => 0])->move($this->post(10), 'sideways');
    }

    // --- the group ---

    /**
     * A post moves among the posts of its weight, in the order the site lists them: the date
     * under the position, drafts included so one keeps its place when it is published - and the
     * trash left out, since nothing in it is anywhere in that order
     */
    public function testAPostsGroupIsItsTypeAndWeightInTheSitesOrder(): void {
        $post = $this->post(10);
        $post->weight = 5;
        $this->service([10 => 0])->move($post, ContentService::MOVE_UP);
        $query = $this->db->matching('select `id`, `position`')[0];
        $this->assertStringContainsString('`weight` = :weight', $query['sql']);
        $this->assertStringContainsString('`status` not in (:autoDraft, :trash)', $query['sql']);
        $this->assertSame(Content::STATUS_TRASH, $query['params'][':trash']);
        $this->assertStringNotContainsString('parent_id', $query['sql']);
        $this->assertStringContainsString('order by `position` asc, `published_at` desc', $query['sql']);
        $this->assertSame(5, $query['params'][':weight']);
    }

    /**
     * A page moves among its siblings, in the order "In this section" shows them
     */
    public function testAPagesGroupIsItsSiblings(): void {
        $page = $this->post(10);
        $page->type = Content::TYPE_PAGE;
        $page->parent_id = 4;
        $this->service([10 => 0])->move($page, ContentService::MOVE_UP);
        $query = $this->db->matching('select `id`, `position`')[0];
        $this->assertStringContainsString('`parent_id` <=> :parentId', $query['sql']);
        $this->assertStringContainsString('order by `position` asc, `title` asc', $query['sql']);
        $this->assertSame(4, $query['params'][':parentId']);
    }

    /**
     * A new page goes after its siblings - null-safe, because the top level is the pages whose
     * parent is null, and `= null` matches nothing
     */
    public function testANewPageGoesAfterItsSiblings(): void {
        $service = $this->service([]);
        $this->db->answers = [4];
        $page = $this->post(0);
        $page->type = Content::TYPE_PAGE;
        $this->assertSame(4, $service->nextPositionOf($page));
        $query = $this->db->matching('max(`position`)')[0];
        $this->assertStringContainsString('`parent_id` <=> :parentId', $query['sql']);
        $this->assertNull($query['params'][':parentId']);
    }

    // --- the admin ---

    public function testTheListHasAMoveEndpoint(): void {
        $paths = [];
        foreach ((new ReflectionClass(ContentAdminController::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Route::class) as $attribute) {
                $route = $attribute->newInstance();
                $paths[] = $route->method.' '.$route->path;
            }
        }
        $this->assertContains('POST /admin/content/?/move', $paths);
    }
}
