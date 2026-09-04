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

    private function controller(): ContentAdminController {
        $reflection = new ReflectionClass(ContentAdminController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        foreach (['slugger' => new Slugger(), 'content' => new PreviewRenderer(),
                  'taxonomy' => new PreviewTaxonomy()] as $name => $value) {
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
     * POST only, like every other action posted from a form: the boxes are the request body, and
     * there is nothing to see at this address without them
     */
    public function testThePreviewIsPostOnly(): void {
        $methods = [];
        foreach ((new ReflectionMethod(ContentAdminController::class, 'preview'))
                     ->getAttributes(Route::class) as $attribute) {
            $route = $attribute->newInstance();
            $this->assertStringContainsString('/preview/', $route->path);
            $methods[] = $route->method;
        }
        $this->assertSame(['POST'], $methods);
    }

    private function previewOf(array $values): Content {
        return $this->call('previewOf', [$this->stored(), $values]);
    }
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
