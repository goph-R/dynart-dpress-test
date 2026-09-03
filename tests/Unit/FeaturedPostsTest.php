<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Controller\AbstractController;
use Dynart\Dpress\Controller\Admin\DashboardController;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Query\CoreQueries;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Entities\Query;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Exposes the content filters, which are protected because nothing outside builds a query
 */
class FilterQueries extends CoreQueries {
    public function filters(Query $query, array $context): void {
        $this->applyContentFilters($query, $context);
    }
}

/**
 * Posts pinned to the top of the front page, and the row of the list they leave
 *
 * A tag rather than a column: an author already knows how to tag a post, un-featuring is removing
 * a tag, and there is no new screen and no migration behind it. What the mechanism has to get
 * right is the other half - **a featured post is in one place on the front page, not two**, since
 * pinned at the top *and* repeated four rows down reads as a bug rather than as emphasis.
 *
 * @covers \Dynart\Dpress\Query\CoreQueries
 * @covers \Dynart\Dpress\Controller\AbstractController
 */
class FeaturedPostsTest extends TestCase {

    private FilterQueries $queries;

    protected function setUp(): void {
        $this->queries = new FilterQueries($this->createMock(EntityManager::class));
    }

    private function filtered(array $context): Query {
        $query = new Query(Content::class);
        $this->queries->filters($query, $context);
        return $query;
    }

    // --- leaving the featured posts out of the list ---

    public function testTheExcludedIdsBecomeConditions(): void {
        $query = $this->filtered(['exclude_ids' => [7, 9]]);
        $sql = join(' ', $query->conditions());
        $this->assertStringContainsString('`id` <>', $sql);
        $this->assertSame([7, 9], array_values(array_intersect($query->variables(), [7, 9])));
    }

    /**
     * The bug this shape avoids: `nextParamName()` only sees a name once it is **bound**, so
     * asking for several before adding any condition hands back the same name every time - and
     * the second id would overwrite the first, silently excluding one post instead of two.
     */
    public function testEachExcludedIdGetsAPlaceholderOfItsOwn(): void {
        $query = $this->filtered(['exclude_ids' => [1, 2, 3]]);
        $bound = array_filter($query->variables(), fn($v) => in_array($v, [1, 2, 3], true));
        $this->assertCount(3, $bound, 'the ids shared a placeholder');
        $this->assertSame([1, 2, 3], array_values($bound));
    }

    public function testTheSamePostTwiceIsExcludedOnce(): void {
        $query = $this->filtered(['exclude_ids' => [4, 4, 4]]);
        $this->assertSame(1, substr_count(join(' ', $query->conditions()), '`id` <>'));
    }

    /**
     * A site that features nothing pays for no condition at all
     */
    public function testNoExclusionsAddNothing(): void {
        foreach ([[], null] as $value) {
            $query = $this->filtered(['exclude_ids' => $value]);
            $this->assertStringNotContainsString('`id` <>', join(' ', $query->conditions()));
        }
    }

    /**
     * Ids arrive from a listing row, so they are whatever the database answered with - strings
     * from a driver that does not type them, most often
     */
    public function testIdsAreIntegersWhateverArrives(): void {
        $query = $this->filtered(['exclude_ids' => ['7', 7, '007']]);
        $bound = array_values(array_filter($query->variables(), fn($v) => $v === 7));
        $this->assertSame([7], $bound, 'the same id in two spellings was excluded twice');
    }

    // --- the picture on a card ---

    /**
     * The real mapping, not a copy of it: `thumbnails()` is a fetch plus this, and the fetch is
     * the line a live page exercises
     */
    private function thumbnails(array $rows, array $media): array {
        $controller = (new ReflectionClass(DashboardController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AbstractController::class, 'mapThumbnails');
        $method->setAccessible(true);
        return $method->invoke($controller, $rows, $media);
    }

    /**
     * Keyed by **content** id, because that is what a template has in its hand inside the loop
     */
    public function testThePictureIsKeyedByThePostRatherThanByTheMedia(): void {
        $found = $this->thumbnails(
            [['id' => 10, 'featured_media_id' => 3], ['id' => 11, 'featured_media_id' => 3]],
            [3 => 'the picture']
        );
        $this->assertSame([10 => 'the picture', 11 => 'the picture'], $found);
    }

    /**
     * A post with no picture, and a post whose picture has been deleted, are the same thing to a
     * template: nothing under that key, so `isset()` is the whole of the check it has to write
     */
    public function testAPostWithNoPictureIsSimplyAbsent(): void {
        $found = $this->thumbnails(
            [['id' => 10, 'featured_media_id' => null], ['id' => 11, 'featured_media_id' => 99]],
            [3 => 'the picture']
        );
        $this->assertSame([], $found);
    }
}
