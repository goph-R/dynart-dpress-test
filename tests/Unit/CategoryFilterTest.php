<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Controller\Admin\ContentAdminController;
use Dynart\Dpress\Entity\Content;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Entities\Query;
use PHPUnit\Framework\TestCase;

/**
 * The Posts list's category filter (0.88.0): one category, a post in it whatever else it is in
 *
 * `FilterQueries` is `FeaturedPostsTest`'s, which exposes the content filters.
 *
 * @covers \Dynart\Dpress\Query\CoreQueries
 */
final class CategoryFilterTest extends TestCase {

    private function filtered(array $context): Query {
        $query = new Query(Content::class);
        (new FilterQueries($this->createMock(EntityManager::class)))->filters($query, $context);
        return $query;
    }

    public function testACategoryIsAnExistsOnTheJoinTable(): void {
        $query = $this->filtered(['category_id' => '7']);
        $sql = join(' ', $query->conditions());
        // `exists` and not a join: a post in three categories is still one row of the list
        $this->assertStringContainsString('exists (select 1 from', $sql);
        $this->assertStringContainsString('`fc`.`category_id` = :filterCategoryId', $sql);
        $this->assertSame(7, $query->variables()[':filterCategoryId'] ?? null);
    }

    public function testNoCategoryIsNoCondition(): void {
        foreach ([[], ['category_id' => ''], ['category_id' => '0']] as $context) {
            $this->assertStringNotContainsString('exists', join(' ', $this->filtered($context)->conditions()));
        }
    }

    /** the list screen and its endpoint read the same filters, or a filtered first page and the next disagree */
    public function testTheScreenAndTheEndpointReadTheCategory(): void {
        $this->assertContains('category_id', ContentAdminController::LIST_FILTERS);
    }
}
