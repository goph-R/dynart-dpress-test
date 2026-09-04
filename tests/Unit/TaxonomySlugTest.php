<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\Slugger;
use Dynart\Dpress\Entity\Category;
use Dynart\Dpress\Entity\Tag;
use Dynart\Dpress\Service\TaxonomyService;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Test\StubConfig;
use Dynart\Dpress\Test\StubDatabase;
use Dynart\Micro\Entities\EntityManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Where a category's slug comes from when the editor left the field alone
 *
 * The form posts `slug` whether or not anybody typed in it, so an untouched field arrives as the
 * empty string - which is a slug nobody chose, not a slug that is empty. `createCategory()` read
 * it as `$data['slug'] ?? $name` and the `??` only ever fires on a *missing* key, so the name was
 * unreachable: every category made through the admin came out `item`, then `item-2`, `item-3`.
 * The fallback itself is right - a name of nothing but punctuation has to become something - it
 * was just standing in for a name that was there all along.
 *
 * `ContentService::resolveSlug()` and `createTag()` had both said `trim($given) !== ''` since they
 * had slugs at all, which is why posts and tags were never affected. So the test is really about
 * the three of them agreeing.
 *
 * @covers \Dynart\Dpress\Service\TaxonomyService
 */
class TaxonomySlugTest extends TestCase {

    private StubDatabase $db;

    /**
     * A `TaxonomyService` with only the four collaborators the create path touches
     *
     * `assertNoCategoryCycle()` returns at once with no parent, and neither create reaches the
     * query factory or the tree - so those stay unset rather than being stood in for.
     */
    private function taxonomy(): TaxonomyService {
        $this->db = new StubDatabase();
        $this->db->answers = [0]; // nothing is taken, so the first candidate is the slug
        $em = new EntityManager(new StubConfig(), $this->db, new RecordingEvents());
        foreach ([Category::class, Tag::class] as $className) {
            $em->registerEntity($className);
        }
        $reflection = new ReflectionClass(TaxonomyService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        foreach (['em' => $em, 'db' => $this->db, 'events' => new RecordingEvents(),
                  'slugger' => new Slugger()] as $property => $value) {
            $found = $reflection->getProperty($property);
            $found->setAccessible(true);
            $found->setValue($service, $value);
        }
        return $service;
    }

    /**
     * What the categories screen actually posts, and the bug as it was reported
     */
    private function created(string $name, string $slug): string {
        return $this->taxonomy()->createCategory($name, [
            'name' => $name, 'slug' => $slug, 'parent_id' => null, 'description' => '', 'position' => 0,
        ])->slug;
    }

    public function testAnEmptySlugFieldIsTheName(): void {
        $this->assertSame('development', $this->created('Development', ''));
    }

    public function testASlugFieldOfNothingButSpacesIsTheNameToo(): void {
        $this->assertSame('retro', $this->created('Retro', '   '));
    }

    public function testAFilledSlugFieldWins(): void {
        $this->assertSame('how-to', $this->created('Guides and walkthroughs', 'How To'));
    }

    /**
     * The one the fallback is for: a name that leaves nothing behind
     */
    public function testANameWithNothingSluggableStillFallsBackToItem(): void {
        $this->assertSame('item', $this->created('!!! ???', ''));
    }

    /**
     * The other two, which were right, so that they stay right together
     */
    public function testATagDoesTheSame(): void {
        $this->assertSame('retro-computing', $this->taxonomy()->createTag('Retro Computing', '')->slug);
        $this->assertSame('chosen', $this->taxonomy()->createTag('Retro Computing', 'Chosen')->slug);
    }

    /**
     * Editing is the neighbouring rule and the one the fix was copied from: an empty field there
     * means "leave the slug it has", because changing it would break every link to the category
     */
    public function testAnEmptySlugFieldLeavesAnExistingSlugAlone(): void {
        $category = new Category();
        $category->id = 5;
        $category->name = 'Retro';
        $category->slug = 'retro';
        $this->taxonomy()->updateCategory($category, ['name' => 'Retro Computing', 'slug' => '']);
        $this->assertSame('retro', $category->slug);
        $this->assertSame('Retro Computing', $category->name);
    }
}
