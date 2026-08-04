<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\DpressException;
use Dynart\Dpress\Query\QueryFactory;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Micro\Entities\EntityManagerException;
use Dynart\Micro\Entities\Query;
use PHPUnit\Framework\TestCase;

class ExampleQueryBuilder {
    public function build(array $context): Query { return new Query('Content'); }
}

/**
 * @covers \Dynart\Dpress\Query\QueryFactory
 */
class QueryFactoryTest extends TestCase {

    private RecordingEvents $events;
    private QueryFactory $factory;

    protected function setUp(): void {
        $this->events = new RecordingEvents();
        $this->factory = new QueryFactory($this->events);
    }

    private function addContentList(): void {
        $this->factory->add('content_list', function(array $context) {
            $query = new Query('Content');
            $query->addCondition('`status` = :status', [':status' => 'published']);
            return $query;
        });
    }

    // --- Registry ---

    public function testHasIsFalseForAnUnregisteredName(): void {
        $this->assertFalse($this->factory->has('content_list'));
    }

    public function testHasIsTrueAfterAdd(): void {
        $this->addContentList();
        $this->assertTrue($this->factory->has('content_list'));
    }

    public function testNames(): void {
        $this->addContentList();
        $this->factory->add('tag_cloud', fn(array $c) => new Query('Tag'));
        $this->assertSame(['content_list', 'tag_cloud'], $this->factory->names());
    }

    public function testCreateThrowsForAnUnknownName(): void {
        $this->expectException(DpressException::class);
        $this->factory->create('nosuchquery');
    }

    /**
     * The builder is resolved through the DI container, so registering the query has to put its
     * class there - otherwise every caller needs a second Micro::add() and finds out at runtime.
     */
    public function testAddRegistersTheBuilderClassInTheContainer(): void {
        $this->assertFalse(\Dynart\Micro\Micro::hasInterface(ExampleQueryBuilder::class));
        $this->factory->add('example', [ExampleQueryBuilder::class, 'build']);
        $this->assertTrue(\Dynart\Micro\Micro::hasInterface(ExampleQueryBuilder::class));
    }

    public function testAddAcceptsAClosureWithoutTouchingTheContainer(): void {
        $this->factory->add('closure_query', fn(array $c) => new Query('Content'));
        $this->assertInstanceOf(Query::class, $this->factory->create('closure_query'));
    }

    public function testCreateThrowsWhenTheBuilderReturnsSomethingElse(): void {
        $this->factory->add('broken', fn(array $c) => 'not a query');
        $this->expectException(DpressException::class);
        $this->factory->create('broken');
    }

    // --- Building ---

    public function testCreateReturnsWhatTheBuilderBuilt(): void {
        $this->addContentList();
        $query = $this->factory->create('content_list');
        $this->assertInstanceOf(Query::class, $query);
        $this->assertSame(['`status` = :status'], $query->conditions());
    }

    public function testTheBuilderReceivesTheContext(): void {
        $received = null;
        $this->factory->add('with_context', function(array $context) use (&$received) {
            $received = $context;
            return new Query('Content');
        });
        $this->factory->create('with_context', ['page' => 2]);
        $this->assertSame(['page' => 2], $received);
    }

    // --- Events ---

    public function testEventName(): void {
        $this->assertSame('query.content_list:created', QueryFactory::eventName('content_list'));
    }

    public function testBothEventsAreEmitted(): void {
        $this->addContentList();
        $this->factory->create('content_list');
        $this->assertContains('query.content_list:created', $this->events->emitted);
        $this->assertContains(QueryFactory::EVENT_CREATED, $this->events->emitted);
    }

    public function testTheScopedEventCarriesTheQueryAndTheContext(): void {
        $this->addContentList();
        $query = $this->factory->create('content_list', ['page' => 3]);
        $args = $this->events->args['query.content_list:created'];
        $this->assertSame($query, $args[0]);
        $this->assertSame(['page' => 3], $args[1]);
    }

    public function testTheGenericEventCarriesTheNameFirst(): void {
        $this->addContentList();
        $query = $this->factory->create('content_list');
        $args = $this->events->args[QueryFactory::EVENT_CREATED];
        $this->assertSame('content_list', $args[0]);
        $this->assertSame($query, $args[1]);
    }

    /**
     * The point of the whole thing: a plugin narrows a query it did not write.
     */
    public function testASubscriberCanAddAConditionToTheQuery(): void {
        $this->addContentList();
        $this->events->subscribe('query.content_list:created', function(Query $query, array $context) {
            $name = $query->nextParamName('author');
            $query->addCondition("`author_id` = $name", [$name => 7]);
        });
        $query = $this->factory->create('content_list');
        $this->assertSame(['`status` = :status', '`author_id` = :author_0'], $query->conditions());
        $this->assertSame([':status' => 'published', ':author_0' => 7], $query->variables());
    }

    public function testAGenericSubscriberSeesEveryQuery(): void {
        $this->addContentList();
        $this->factory->add('tag_cloud', fn(array $c) => new Query('Tag'));
        $seen = [];
        $this->events->subscribe(QueryFactory::EVENT_CREATED, function(string $name, Query $q, array $c) use (&$seen) {
            $seen[] = $name;
        });
        $this->factory->create('content_list');
        $this->factory->create('tag_cloud');
        $this->assertSame(['content_list', 'tag_cloud'], $seen);
    }

    /**
     * Two plugins reaching for the obvious placeholder name must not silently share a value.
     * `nextParamName()` is how they avoid it; without it the second one throws rather than
     * corrupting the first one's condition.
     */
    public function testTwoSubscribersCollidingOnAParamNameThrows(): void {
        $this->addContentList();
        $this->events->subscribe('query.content_list:created', function(Query $query) {
            $query->addCondition('`a` = :id', [':id' => 1]);
        });
        $this->events->subscribe('query.content_list:created', function(Query $query) {
            $query->addCondition('`b` = :id', [':id' => 2]);
        });
        $this->expectException(EntityManagerException::class);
        $this->factory->create('content_list');
    }

    public function testTwoSubscribersUsingNextParamNameDoNotCollide(): void {
        $this->addContentList();
        $subscriber = function(Query $query) {
            $name = $query->nextParamName('id');
            $query->addCondition("`x` = $name", [$name => 1]);
        };
        $this->events->subscribe('query.content_list:created', $subscriber);
        $this->events->subscribe('query.content_list:created', $subscriber);
        $query = $this->factory->create('content_list');
        $this->assertSame([':status' => 'published', ':id_0' => 1, ':id_1' => 1], $query->variables());
    }

    /**
     * Conditions are appended and joined with AND, and there is no removeCondition(), so a
     * subscriber can narrow a query but cannot widen it - it cannot drop the published filter.
     */
    public function testASubscriberCannotRemoveTheCoreConditions(): void {
        $this->addContentList();
        $this->events->subscribe('query.content_list:created', function(Query $query) {
            $query->addCondition('1=1 or 1=1');
        });
        $query = $this->factory->create('content_list');
        $this->assertContains('`status` = :status', $query->conditions());
    }
}
