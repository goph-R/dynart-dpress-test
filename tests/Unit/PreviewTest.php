<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\Slugger;
use Dynart\Dpress\Controller\Admin\ContentAdminController;
use Dynart\Dpress\Entity\Content;
use Dynart\Micro\Attribute\Route;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Looking at what is in the boxes, without any of it being saved
 *
 * The editor could always open a *published* post, and nothing else. A draft was invisible even
 * though the front end has always served one to anybody who may edit posts - only the button was
 * hidden - and text that had not been saved at all could not be looked at by any means.
 *
 * The rule that makes this more than a convenience: **it writes nothing**. Saving first and then
 * looking would be simpler and is wrong for the case that matters, a published post, where it
 * would put the unsaved edits live. So the preview builds a `Content` that lives for one request
 * and never reaches the entity manager, which is what these tests are about.
 *
 * @covers \Dynart\Dpress\Controller\Admin\ContentAdminController
 */
class PreviewTest extends TestCase {

    private PreviewSession $session;

    protected function setUp(): void {
        $this->session = new PreviewSession();
    }

    private function controller(): ContentAdminController {
        $reflection = new ReflectionClass(ContentAdminController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        foreach (['slugger' => new Slugger(), 'content' => new PreviewRenderer(),
                  'taxonomy' => new PreviewTaxonomy(),
                  'session' => $this->session] as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($controller, $value);
        }
        return $controller;
    }

    private function call(string $method, array $args) {
        $reflected = new ReflectionMethod(ContentAdminController::class, $method);
        $reflected->setAccessible(true);
        return $reflected->invokeArgs($this->controller(), $args);
    }

    private function stored(): Content {
        $content = new Content();
        $content->id = 12;
        $content->type = Content::TYPE_POST;
        $content->status = Content::STATUS_PUBLISHED;
        $content->title = 'As it is stored';
        $content->markdown = 'The stored words.';
        $content->lead_html = '<p>The stored words.</p>';
        $content->slug = 'as-it-is-stored';
        $content->featured_media_id = 3;
        return $content;
    }

    // --- what the preview is made of ---

    /**
     * The stored row is the base, so the id, the author and the media still resolve - and the
     * boxes are laid over it
     */
    public function testThePostedBoxesWinOverWhatIsStored(): void {
        $preview = $this->previewOf(['title' => '  Typed just now  ', 'markdown' => 'Typed body.']);
        $this->assertSame('Typed just now', $preview->title);
        $this->assertSame('Typed body.', $preview->markdown);
        $this->assertSame(12, $preview->id, 'the id has to stay the stored one');
        // rendered again from what was typed: showing the stored HTML beside a typed title
        // would be a preview of two different versions at once
        $this->assertSame('<p>Typed body.</p>', $preview->lead_html);
    }

    /**
     * **The whole point.** A published post that is being previewed must be exactly as published
     * afterwards - a preview that edits the row is not a preview.
     */
    public function testTheStoredRowIsNotTouched(): void {
        $stored = $this->stored();
        $this->call('previewOf', [$stored, ['title' => 'Something else', 'markdown' => 'Other words.']]);
        $this->assertSame('As it is stored', $stored->title);
        $this->assertSame('The stored words.', $stored->markdown);
        $this->assertSame('<p>The stored words.</p>', $stored->lead_html);
    }

    /**
     * A field the form did not send is a field nobody changed, not a field somebody emptied - the
     * page editor has no tags box, and previewing from it must not blank the featured image
     */
    public function testAFieldThatWasNotPostedKeepsWhatIsStored(): void {
        $preview = $this->previewOf(['title' => 'Only the title moved']);
        $this->assertSame('The stored words.', $preview->markdown);
        $this->assertSame(3, $preview->featured_media_id);
    }

    /**
     * Moving a page changes its ancestors, and nothing here has checked the new parent for a
     * cycle - `ancestors()` walking a loop does not come back, and a preview is not the place to
     * find that out
     */
    public function testTheParentIsDeliberatelyLeftAlone(): void {
        $stored = $this->stored();
        $stored->parent_id = 4;
        $preview = $this->call('previewOf', [$stored, ['title' => 'x', 'parent_id' => '99']]);
        $this->assertSame(4, $preview->parent_id);
    }

    // --- the taxonomy, which is unsaved too ---

    /**
     * Ticking a category and pressing Preview shows that category, not the ones on the stored row
     */
    public function testTheCategoriesAreTheOnesTickedRightNow(): void {
        $controller = $this->controller();
        $method = new ReflectionMethod(ContentAdminController::class, 'previewCategories');
        $method->setAccessible(true);

        $chosen = $method->invokeArgs($controller, [['categories' => ['4', '6']]]);
        $this->assertSame(['Development', 'AI'], array_column($chosen, 'name'));
        $this->assertSame([], $method->invokeArgs($controller, [[]]));
    }

    /**
     * A tag that has never been used has no row and so no slug. It gets one made the way
     * `findOrCreateTag()` would, so the chip reads right - and its link is a 404 until the post is
     * saved and the tag really made, which is the truth about a tag that is not there.
     */
    public function testATagNobodyHasUsedYetIsShownWithASlugOfItsOwn(): void {
        $controller = $this->controller();
        $method = new ReflectionMethod(ContentAdminController::class, 'previewTags');
        $method->setAccessible(true);

        $tags = $method->invokeArgs($controller, [['tags' => 'dpress,  A Brand New Tag ,']]);
        $this->assertSame([
            ['id' => 1, 'name' => 'dpress', 'slug' => 'dpress'],
            ['name' => 'A Brand New Tag', 'slug' => 'a-brand-new-tag'],
        ], $tags);
    }

    // --- and how it is reached ---

    /**
     * Post, redirect, get - and it is the paging that made it worth doing
     *
     * The boxes can only arrive by POST. Everything after that is a GET of a real address,
     * which is what lets a body written in `---` parts page through on **links** the way it
     * will once it is saved: a theme renders the pager it always renders and knows nothing
     * about previews. Refreshing the tab stops re-posting into the bargain.
     */
    public function testTheBoxesArriveByPostAndEveryPageAfterThatIsAGet(): void {
        $this->assertSame(['POST'], $this->routeMethods('preview'));
        $this->assertSame(['GET'], $this->routeMethods('previewPage'));
    }

    private function routeMethods(string $method): array {
        $methods = [];
        foreach ((new ReflectionMethod(ContentAdminController::class, $method))
                     ->getAttributes(Route::class) as $attribute) {
            $route = $attribute->newInstance();
            $this->assertStringContainsString('/preview/', $route->path);
            $methods[] = $route->method;
        }
        return $methods;
    }

    // --- the boxes, kept where the next few GETs can read them ---

    /**
     * The session and **not the database**: the post itself is still never written, which is
     * the whole point. This is one tab's copy of what somebody typed.
     */
    public function testWhatWasHandedOverComesBack(): void {
        $controller = $this->controller();
        $token = $this->keep($controller, 12, ['title' => 'Typed']);
        $this->assertNotSame('', $token);
        $this->assertSame(['title' => 'Typed'], $this->keptPreview($controller, 12, $token));
    }

    /**
     * A token is of one post. One that has fallen off the end must not quietly render whatever
     * else the address happens to name.
     */
    public function testATokenIsNoGoodForAnotherPost(): void {
        $controller = $this->controller();
        $token = $this->keep($controller, 12, ['title' => 'Typed']);
        $this->assertNull($this->keptPreview($controller, 13, $token));
        $this->assertNull($this->keptPreview($controller, 12, 'not a token'));
        $this->assertNull($this->keptPreview($controller, 12, ''));
    }

    /**
     * Two tabs have to work, and a long session must not become the place a hundred drafts
     * pile up - so a few are kept and the oldest goes
     */
    public function testAFewArePreviewedAtOnceAndTheOldestFallsOff(): void {
        $controller = $this->controller();
        $tokens = [];
        foreach (range(1, ContentAdminController::PREVIEW_KEEP + 1) as $n) {
            $tokens[$n] = $this->keep($controller, $n, ['title' => 'Post '.$n]);
        }
        $this->assertNull($this->keptPreview($controller, 1, $tokens[1]), 'the oldest should be gone');
        foreach (range(2, ContentAdminController::PREVIEW_KEEP + 1) as $n) {
            $this->assertSame(['title' => 'Post '.$n], $this->keptPreview($controller, $n, $tokens[$n]), (string)$n);
        }
    }

    /**
     * Two previews are never the same address, so one tab cannot show what another one typed
     */
    public function testEveryPreviewGetsATokenOfItsOwn(): void {
        $controller = $this->controller();
        $this->assertNotSame($this->keep($controller, 12, []), $this->keep($controller, 12, []));
    }

    private function keep(ContentAdminController $controller, int $id, array $values): string {
        $method = new ReflectionMethod(ContentAdminController::class, 'keepPreview');
        $method->setAccessible(true);
        return $method->invokeArgs($controller, [$id, $values]);
    }

    private function keptPreview(ContentAdminController $controller, int $id, string $token): ?array {
        $method = new ReflectionMethod(ContentAdminController::class, 'storedPreview');
        $method->setAccessible(true);
        return $method->invokeArgs($controller, [$id, $token]);
    }

    private function previewOf(array $values): Content {
        return $this->call('previewOf', [$this->stored(), $values]);
    }
}

/**
 * A session that is a box, which is all the preview asks of one
 */
class PreviewSession implements \Dynart\Micro\SessionInterface {

    public array $values = [];

    public function destroy(): void { $this->values = []; }
    public function id(): string { return 'test'; }
    public function get(string $name, mixed $default = null): mixed {
        return $this->values[$name] ?? $default;
    }
    public function set(string $name, mixed $value): void { $this->values[$name] = $value; }
}

/**
 * Only the rendering, which is all `previewOf()` asks the service for
 */
class PreviewRenderer extends \Dynart\Dpress\Service\ContentService {

    public function __construct() {}

    public function renderInto(Content $content): void {
        $content->lead_html = '<p>'.$content->markdown.'</p>';
        $content->body_html = '';
    }
}

/**
 * The two lists the preview reads, and nothing else
 */
class PreviewTaxonomy extends \Dynart\Dpress\Service\TaxonomyService {

    public function __construct() {}

    public function categories(array $context = []): array {
        return [
            ['id' => 4, 'name' => 'Development'],
            ['id' => 5, 'name' => 'Retro'],
            ['id' => 6, 'name' => 'AI'],
        ];
    }

    public function tags(array $context = []): array {
        return [['id' => 1, 'name' => 'dpress', 'slug' => 'dpress']];
    }
}
