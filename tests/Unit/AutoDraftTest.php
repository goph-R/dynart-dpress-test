<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\Slugger;
use Dynart\Dpress\Controller\Admin\ContentAdminController;
use Dynart\Dpress\DpressCliApp;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Form\AdminForms;
use Dynart\Dpress\Form\DpressForm;
use Dynart\Dpress\Query\CoreQueries;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Test\StubDatabase;
use Dynart\Dpress\Test\StubTranslation;
use Dynart\Micro\Attribute\Route;
use Dynart\Micro\Request;
use Dynart\Micro\Session;
use Dynart\Micro\Entities\EntityManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A `ContentService` with the two collaborators `update()` actually reaches
 *
 * Rendering markdown and emitting events are not what is under test here, and both want more
 * scaffolding than the assertion is worth.
 */
class PromotableContent extends ContentService {

    public array $saved = [];

    public function __construct(EntityManager $em, StubDatabase $db, Slugger $slugger) {
        $this->em = $em;
        $this->db = $db;
        $this->slugger = $slugger;
        $this->events = new RecordingEvents();
    }

    public function renderInto(Content $content): void {}

    protected function emitBoth(Content $content, string $genericEvent, string $suffix): void {}

    public function rerenderReferrers(Content $content): void {}
}

/**
 * "New" writes a row, so there is no such thing as an unsaved post
 *
 * The editor could do nothing immediate without an id - attaching a file is an immediate write,
 * the same as every other row action - and the old answer was to ask for a save first and come
 * back. So opening the editor inserts an **auto-draft**: a row holding nothing, promoted to a
 * real draft by the first save.
 *
 * Two things have to hold for that to be safe rather than merely convenient. **Nothing ever
 * lists one**, because it is not content until somebody saves it. And **nothing it holds is ever
 * offered back to the author as if they had chosen it** - the placeholder slug above all, which
 * would otherwise be submitted with the form and become the post's URL.
 *
 * @covers \Dynart\Dpress\Entity\Content
 * @covers \Dynart\Dpress\Query\CoreQueries
 * @covers \Dynart\Dpress\Service\ContentService
 * @covers \Dynart\Dpress\Form\AdminForms
 */
class AutoDraftTest extends TestCase {

    // --- the status nobody can choose ---

    /**
     * `STATUSES` is what the status select offers and what `assertStatus()` accepts, so keeping
     * this out of it is what stops the value arriving from a form or from `content:create`. It is
     * set in exactly one place and left in exactly one other.
     */
    public function testTheAutoDraftStatusIsNotOneAnybodyCanSet(): void {
        $this->assertNotContains(Content::STATUS_AUTO_DRAFT, Content::STATUSES);
        $this->assertSame(['draft', 'published'], Content::STATUSES);
    }

    public function testAContentKnowsWhetherItHasEverBeenSaved(): void {
        $content = new Content();
        $content->status = Content::STATUS_AUTO_DRAFT;
        $this->assertTrue($content->isAutoDraft());
        $content->status = Content::STATUS_DRAFT;
        $this->assertFalse($content->isAutoDraft());
        $this->assertFalse((new Content())->isAutoDraft());
    }

    // --- nothing lists one ---

    /**
     * The filter is in `applyContentFilters()` rather than in each caller, because "list some
     * content" is asked in a dozen places and every one of them means the same thing by it
     */
    public function testNoContentListingIncludesAnAutoDraft(): void {
        $sql = join(' ', $this->queries()->contentList([])->conditions());
        $this->assertStringContainsString('`status` <> :notAutoDraft', $sql);
    }

    /**
     * Including the one that fills the "Parent page" select, which is the same query - a page
     * nobody has written yet is not somewhere to put another one
     */
    public function testTheFilterSurvivesTheOtherFilters(): void {
        $query = $this->queries()->contentList(['type' => 'page', 'status' => 'draft', 'search' => 'x']);
        $this->assertStringContainsString('`status` <> :notAutoDraft', join(' ', $query->conditions()));
        $this->assertSame(Content::STATUS_AUTO_DRAFT, $query->variables()[':notAutoDraft']);
    }

    public function testTheAutoDraftLookupIsByAuthorTypeAndStatus(): void {
        $query = $this->queries()->autoDraft(['type' => 'page', 'author_id' => 3]);
        $variables = $query->variables();
        $this->assertSame(Content::STATUS_AUTO_DRAFT, $variables[':status']);
        $this->assertSame('page', $variables[':type']);
        $this->assertSame(3, $variables[':authorId']);
    }

    private function queries(): CoreQueries {
        $em = $this->createMock(EntityManager::class);
        $em->method('safeTableName')->willReturn('`dp_content`');
        return new CoreQueries($em);
    }

    // --- the first save ---

    /**
     * The row stops being scaffolding, and it happens in `update()` rather than in
     * `applyStatus()` because nothing about it is the author's to choose
     */
    public function testTheFirstSavePromotesItToADraft(): void {
        $content = $this->autoDraft();
        $this->service()->update($content, ['title' => 'Hello there', 'markdown' => 'text']);
        $this->assertSame(Content::STATUS_DRAFT, $content->status);
    }

    /**
     * The bug this guards: `update()` leaves the slug alone when the field is empty, which is
     * right on every other save and wrong on this one - the slug it has is `auto-draft-3f9c...`,
     * which nobody chose and which would have become the post's URL.
     */
    public function testTheFirstSaveMakesTheSlugFromTheTitle(): void {
        $content = $this->autoDraft();
        $this->service()->update($content, ['title' => 'Hello there', 'markdown' => 'text']);
        $this->assertSame('hello-there', $content->slug);
    }

    public function testASlugTypedByHandStillWins(): void {
        $content = $this->autoDraft();
        $this->service()->update($content, ['title' => 'Hello there', 'slug' => 'Chosen One']);
        $this->assertSame('chosen-one', $content->slug);
    }

    /**
     * And the ordinary case is untouched: on a post that has been saved before, an empty slug
     * field means "keep the one it has"
     */
    public function testAnEmptySlugOnARealPostChangesNothing(): void {
        $content = $this->autoDraft();
        $content->status = Content::STATUS_DRAFT;
        $content->slug = 'already-named';
        $this->service()->update($content, ['title' => 'A new title']);
        $this->assertSame('already-named', $content->slug);
    }

    private function autoDraft(): Content {
        $content = new Content();
        $content->id = 7;
        $content->status = Content::STATUS_AUTO_DRAFT;
        $content->slug = 'auto-draft-3f9c1a2b4d5e6f70';
        return $content;
    }

    private function service(): PromotableContent {
        $em = $this->createMock(EntityManager::class);
        $em->method('safeTableName')->willReturn('`dp_content`');
        return new PromotableContent($em, new StubDatabase(), new Slugger());
    }

    // --- what the editor offers back ---

    /**
     * An auto-draft fills the form the way a post that does not exist would. Above all the slug:
     * offered back, it would be posted as if it had been meant.
     */
    public function testTheFormIsEmptyForAnAutoDraft(): void {
        $draft = $this->autoDraft();
        $form = $this->contentForm($draft);
        $this->assertNotSame($draft->slug, $form->value('slug'), 'the placeholder slug was offered back');
        $this->assertEmpty($form->value('slug'));
        $this->assertEmpty($form->value('title'));
        $this->assertSame(Content::STATUS_DRAFT, $form->value('status'), 'the select has no auto-draft option');
    }

    public function testAPostThatHasBeenSavedFillsTheFormAsBefore(): void {
        $content = $this->autoDraft();
        $content->status = Content::STATUS_DRAFT;
        $content->slug = 'already-named';
        $content->title = 'Already written';
        $this->assertSame('already-named', $this->contentForm($content)->value('slug'));
        $this->assertSame('Already written', $this->contentForm($content)->value('title'));
    }

    private function contentForm(Content $content): DpressForm {
        $forms = new \Dynart\Dpress\Form\FormFactory(
            new Request(), new Session(), new RecordingEvents(), new StubTranslation()
        );
        AdminForms::register($forms);
        return $forms->create(AdminForms::CONTENT, ['content' => $content, 'can_publish' => true]);
    }

    // --- the route that writes ---

    /**
     * "New" inserts a row, so it is a POST like every other write in the admin. A GET that
     * inserts can be followed by a prefetcher or a crawler.
     */
    public function testNewIsAPostAndOnlyAPost(): void {
        $methods = [];
        foreach ((new ReflectionMethod(ContentAdminController::class, 'create'))->getAttributes(Route::class) as $attribute) {
            $route = $attribute->newInstance();
            $methods[$route->path][] = $route->method;
        }
        $this->assertSame(['/admin/content/?/new' => ['POST']], $methods);
    }

    public function testThereIsAWayToThrowAwayTheOnesNobodyCameBackTo(): void {
        $this->assertArrayHasKey('content:prune', DpressCliApp::COMMANDS);
        $this->assertTrue(method_exists(ContentService::class, 'pruneAutoDrafts'));
    }
}
