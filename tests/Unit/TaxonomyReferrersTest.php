<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\Slugger;
use Dynart\Dpress\Entity\Category;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Entity\Tag;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Service\TaxonomyService;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Test\StubConfig;
use Dynart\Dpress\Test\SavingEntities;
use Dynart\Dpress\Test\StubDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A `ContentService` whose only real part is the referrer path
 *
 * The lookup is the SQL it writes, which the stub database records; finding and rendering are
 * stood in for, because what is under test is *which* documents get rendered again.
 */
class ReferrerContent extends ContentService {
    public array $rendered = [];
    public function __construct(StubDatabase $db, SavingEntities $em) {
        $this->db = $db;
        $this->em = $em;
    }
    public function findById(int $id): ?Content {
        $content = new Content();
        $content->id = $id;
        return $content;
    }
    public function renderInto(Content $content): void { $this->rendered[] = $content->id; }
}

/**
 * Renaming a category or a tag re-renders what links to it
 *
 * `category#3` is resolved when the markdown is rendered, so the URL sits in somebody else's
 * `body_html` from the last time they were saved. A post's rename already chased its referrers;
 * a category's and a tag's did not, and the link in the post went on pointing at the old slug
 * until something else happened to save it. Rare, but found by a visitor rather than by whoever
 * did the renaming.
 *
 * @covers \Dynart\Dpress\Service\TaxonomyService
 * @covers \Dynart\Dpress\Service\ContentService
 */
class TaxonomyReferrersTest extends TestCase {

    private RecordingEvents $events;

    /**
     * A `TaxonomyService` with only what the update path touches, as `TaxonomySlugTest` builds it
     */
    private function taxonomy(): TaxonomyService {
        $db = new StubDatabase();
        $db->answers = [0]; // no slug is taken
        $this->events = new RecordingEvents();
        $em = new SavingEntities(new StubConfig(), $db, $this->events);
        $reflection = new ReflectionClass(TaxonomyService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        foreach (['em' => $em, 'db' => $db, 'events' => $this->events, 'slugger' => new Slugger()] as $property => $value) {
            $found = $reflection->getProperty($property);
            $found->setAccessible(true);
            $found->setValue($service, $value);
        }
        return $service;
    }

    private function category(): Category {
        $category = new Category();
        $category->id = 3;
        $category->name = 'Retro';
        $category->slug = 'retro';
        $category->parent_id = null;
        return $category;
    }

    private function tag(): Tag {
        $tag = new Tag();
        $tag->id = 7;
        $tag->name = 'PHP';
        $tag->slug = 'php';
        return $tag;
    }

    // --- when it is announced ---

    public function testANewCategorySlugIsAnnounced(): void {
        $category = $this->category();
        $this->taxonomy()->updateCategory($category, ['slug' => 'retro-computing']);
        $this->assertSame(1, $this->events->countOf(TaxonomyService::EVENT_CATEGORY_SLUG_CHANGED));
        $this->assertSame([$category], $this->events->args[TaxonomyService::EVENT_CATEGORY_SLUG_CHANGED]);
    }

    /**
     * The name is not part of the address, and the editor posts the slug field whether or not it
     * was touched - so a save that leaves the slug as it was re-renders nothing
     */
    public function testACategorySavedWithTheSameSlugIsNot(): void {
        $this->taxonomy()->updateCategory($this->category(), ['name' => 'Retro Computing', 'slug' => '']);
        $this->taxonomy()->updateCategory($this->category(), ['slug' => 'retro']);
        $this->assertSame(0, $this->events->countOf(TaxonomyService::EVENT_CATEGORY_SLUG_CHANGED));
    }

    public function testANewTagSlugIsAnnounced(): void {
        $tag = $this->tag();
        $this->taxonomy()->updateTag($tag, ['slug' => 'php-8']);
        $this->assertSame(1, $this->events->countOf(TaxonomyService::EVENT_TAG_SLUG_CHANGED));
        $this->assertSame([$tag], $this->events->args[TaxonomyService::EVENT_TAG_SLUG_CHANGED]);
    }

    public function testATagSavedWithTheSameSlugIsNot(): void {
        $this->taxonomy()->updateTag($this->tag(), ['name' => 'PHP 8', 'slug' => '']);
        $this->assertSame(0, $this->events->countOf(TaxonomyService::EVENT_TAG_SLUG_CHANGED));
    }

    // --- what gets rendered again ---

    private function content(array $found): array {
        $db = new StubDatabase();
        $db->column = $found;
        $em = new SavingEntities(new StubConfig(), $db, new RecordingEvents());
        return [new ReferrerContent($db, $em), $db, $em];
    }

    /**
     * Only `category#3` is looked for - a category's id says nothing about the post with the same
     * number, so asking for `post#3` as well would re-render documents for no reason
     */
    public function testACategoryRenameRendersWhatMentionsTheCategory(): void {
        [$content, $db, $em] = $this->content(['12', '15']);
        $content->rerenderCategoryReferrers($this->category());
        $this->assertSame([12, 15], $content->rendered);
        $this->assertCount(2, $em->saved);
        $query = $db->matching('like')[0];
        $this->assertSame(['%category#3%'], array_values($query['params']));
    }

    public function testATagRenameRendersWhatMentionsTheTag(): void {
        [$content, $db] = $this->content(['4']);
        $content->rerenderTagReferrers($this->tag());
        $this->assertSame([4], $content->rendered);
        $this->assertSame(['%tag#7%'], array_values($db->matching('like')[0]['params']));
    }

    /**
     * The content lookup is unchanged by all this: all three prefixes, because they are one lookup
     */
    public function testAPostRenameStillLooksForAllThreeContentPrefixes(): void {
        [$content, $db] = $this->content([]);
        $post = new Content();
        $post->id = 42;
        $post->type = Content::TYPE_POST;
        $content->rerenderReferrers($post);
        $this->assertSame(['%content#42%', '%post#42%', '%page#42%'], array_values($db->matching('like')[0]['params']));
    }
}
