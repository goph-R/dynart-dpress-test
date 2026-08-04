<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Query\CoreQueries;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Entities\Query;
use PHPUnit\Framework\TestCase;

/**
 * Exposes the ordering and paging helper the list endpoints go through
 */
class ListOptionQueries extends CoreQueries {
    public function apply(Query $query, array $context, array $default = []): void {
        $this->applyListOptions($query, $context, $default);
    }
}

/**
 * What a dynamic list is allowed to change about a query
 *
 * @covers \Dynart\Dpress\Query\CoreQueries
 */
class CoreQueriesTest extends TestCase {

    private ListOptionQueries $queries;

    protected function setUp(): void {
        $this->queries = new ListOptionQueries($this->createMock(EntityManager::class));
    }

    private function query(array $context, array $default = []): Query {
        $query = new Query(\Dynart\Dpress\Entity\Content::class);
        $this->queries->apply($query, $context, $default);
        return $query;
    }

    public function testWithoutARequestedOrderTheBuildersOwnDefaultStands(): void {
        $query = $this->query([], ['published_at' => 'desc', 'created_at' => 'desc']);
        $this->assertSame([['published_at', 'desc'], ['created_at', 'desc']], $query->orderBy());
    }

    public function testARequestedOrderReplacesTheDefault(): void {
        $query = $this->query(['order_by' => 'title', 'order_dir' => 'asc'], ['published_at' => 'desc']);
        $this->assertSame([['title', 'asc']], $query->orderBy());
    }

    public function testAnythingOtherThanDescIsAscending(): void {
        $query = $this->query(['order_by' => 'title', 'order_dir' => 'sideways']);
        $this->assertSame([['title', 'asc']], $query->orderBy());
    }

    /**
     * The name is put into the SQL, so it is checked here as well as by `ListRequest` - this is
     * the point where it stops being data and starts being a query
     */
    public function testAnOrderNameThatIsNotAPlainColumnIsIgnored(): void {
        $query = $this->query(['order_by' => 'title; drop table dp_content'], ['created_at' => 'desc']);
        $this->assertSame([['created_at', 'desc']], $query->orderBy());
    }

    public function testAnUppercaseOrCommaSeparatedOrderNameIsIgnored(): void {
        $this->assertSame([], $this->query(['order_by' => 'TITLE'])->orderBy());
        $this->assertSame([], $this->query(['order_by' => 'title, slug'])->orderBy());
        $this->assertSame([], $this->query(['order_by' => '(select 1)'])->orderBy());
    }

    public function testThePageIsApplied(): void {
        $query = $this->query(['offset' => 50, 'max' => 25]);
        $this->assertSame(50, $query->offset());
        $this->assertSame(25, $query->max());
    }

    /**
     * A caller that asked for no page gets no limit, so the CLI and the services keep working the
     * way they did before the admin needed pages
     */
    public function testWithoutAPageSizeThereIsNoLimit(): void {
        $untouched = new Query(\Dynart\Dpress\Entity\Content::class);
        $this->assertSame($untouched->max(), $this->query(['offset' => 50])->max());
    }
}
