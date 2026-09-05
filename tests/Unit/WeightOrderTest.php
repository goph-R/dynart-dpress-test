<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Controller\Admin\ContentAdminController;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Form\AdminForms;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Form\Validator\IntegerValidator;
use Dynart\Dpress\Query\CoreQueries;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Test\StubTranslation;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Request;
use Dynart\Micro\Session;
use PHPUnit\Framework\TestCase;

/**
 * A weight is a tiebreaker on top of the date, and every listing agrees about it
 *
 * The agreement is the thing worth testing. A weight that ordered the front page and not a
 * category would be a post that floats on one screen and sits still on the next, and nobody would
 * think to look at the query builder they did not change.
 *
 * @covers \Dynart\Dpress\Query\CoreQueries
 * @covers \Dynart\Dpress\Form\Validator\IntegerValidator
 */
class WeightOrderTest extends TestCase {

    private CoreQueries $queries;

    protected function setUp(): void {
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->queries = new CoreQueries($this->createMock(EntityManager::class));
    }

    protected function tearDown(): void {
        $_REQUEST = [];
    }

    /**
     * `weight desc` first, and the date still deciding underneath it
     *
     * Which is what makes it safe on every listing at once: until somebody sets one, every post
     * is 0 and the order is the one the site already had.
     */
    public function testEveryListingOfPostsOrdersByWeightBeforeTheDate(): void {
        foreach (['contentList', 'contentByTag', 'contentByCategory'] as $builder) {
            $order = $this->queries->$builder([])->orderBy();
            $this->assertSame(['weight', 'desc'], $order[0] ?? null, "$builder does not order by weight");
            $this->assertSame(['published_at', 'desc'], $order[1] ?? null, "$builder lost the date");
        }
    }

    /**
     * The children of a page are a list somebody arranges too - "In this section" and the tree in
     * the admin - so a weight orders those, with the alphabet underneath instead of a date
     */
    public function testThePagesUnderAPageOrderByWeightThenByName(): void {
        $order = $this->queries->contentChildren(['parent_id' => 1])->orderBy();
        $this->assertSame([['weight', 'desc'], ['title', 'asc']], $order);
    }

    /**
     * The admin sorts by clicking a column, and that has to win over the default - otherwise
     * "order by title" would be "order by weight, then title" and the click would look broken
     */
    public function testAColumnSortStillReplacesTheDefaultOrder(): void {
        $order = $this->queries->contentList(['order_by' => 'title', 'order_dir' => 'asc'])->orderBy();
        $this->assertSame([['title', 'asc']], $order);
    }

    public function testANewPostWeighsNothing(): void {
        $this->assertSame(0, (new Content())->weight);
    }

    // --- the editor ---

    private function factory(): FormFactory {
        $factory = new FormFactory(new Request(), new Session(), new RecordingEvents(), new StubTranslation());
        AdminForms::register($factory);
        return $factory;
    }

    public function testTheEditorOffersAWeightAndDoesNotInsistOnOne(): void {
        $form = $this->factory()->create(AdminForms::CONTENT, ['is_page' => false]);
        $this->assertArrayHasKey('weight', $form->fields());
        $this->assertFalse($form->required('weight'));
    }

    /**
     * And the list can be sorted by it, which is how "what have I ordered by hand" is asked
     * without a screen of its own
     */
    public function testTheContentListCanBeSortedByWeight(): void {
        $this->assertContains('weight', ContentAdminController::SORTABLE);
    }

    // --- the validator ---

    /**
     * `(int)` never fails, so without this `1o` is 1 and `x` is 0, and the screen reports that it
     * saved either way
     */
    public function testAWeightHasToBeAWholeNumber(): void {
        $validator = new IntegerValidator();
        foreach (['0', '5', '-5', '12345', ' 7 ', ''] as $value) {
            $this->assertTrue($validator->validate($value), "'$value' should be accepted");
        }
        foreach (['1o', 'x', '1.5', '5 posts', '--1', '1-'] as $value) {
            $this->assertFalse($validator->validate($value), "'$value' should be refused");
        }
    }
}
