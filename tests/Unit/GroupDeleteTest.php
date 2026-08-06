<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Controller\Admin\AbstractAdminController;
use Dynart\Dpress\Controller\Admin\DashboardController;
use Dynart\Dpress\DpressException;
use Dynart\Micro\Request;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Deleting a selection, and saying what happened to it
 *
 * A row action is one thing and it either worked or it did not. A group action is many, and some
 * of them will be refused - your own account, the last administrator, a role the system needs.
 * The rule that matters: **one refusal must not abandon the rest**, and the page has to say so.
 * Silently deleting four of five and reporting "Deleted." is the version of this that loses
 * somebody's afternoon.
 *
 * @covers \Dynart\Dpress\Controller\Admin\AbstractAdminController
 */
class GroupDeleteTest extends TestCase {

    protected function setUp(): void {
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void {
        $_REQUEST = [];
    }

    private function controller(array $ids): AbstractAdminController {
        $_REQUEST = ['ids' => $ids];
        $controller = (new ReflectionClass(DashboardController::class))->newInstanceWithoutConstructor();
        $request = new ReflectionClass(AbstractAdminController::class);
        while (!$request->hasProperty('request')) {
            $request = $request->getParentClass();
        }
        $property = $request->getProperty('request');
        $property->setAccessible(true);
        $property->setValue($controller, new Request());
        return $controller;
    }

    private function deleting(array $ids, callable $each): string {
        $method = new ReflectionMethod(AbstractAdminController::class, 'deleteSelected');
        $method->setAccessible(true);
        return $method->invoke($this->controller($ids), $each);
    }

    // --- what it counts ---

    public function testItDeletesEveryIdItWasGiven(): void {
        $seen = [];
        $notice = $this->deleting(['1', '2', '3'], function (int $id) use (&$seen) {
            $seen[] = $id;
            return true;
        });
        $this->assertSame([1, 2, 3], $seen);
        $this->assertSame('3 deleted.', $notice);
    }

    public function testOneReadsAsOne(): void {
        $this->assertSame('1 deleted.', $this->deleting(['4'], fn(int $id) => true));
    }

    /**
     * A row somebody else deleted between the page loading and the button being pressed is the
     * outcome that was wanted. Counting it would report more deletions than happened.
     */
    public function testSomethingAlreadyGoneIsNotCounted(): void {
        $this->assertSame('2 deleted.', $this->deleting(['1', '2', '3'], fn(int $id) => $id !== 2));
    }

    public function testAnEmptySelectionSaysSoRatherThanNothing(): void {
        $this->assertSame('Nothing was selected.', $this->deleting([], fn(int $id) => true));
    }

    /**
     * Selecting five rows that somebody else already deleted is not selecting nothing
     *
     * These were one sentence at first, inferred from the count, and it read as though the button
     * had not registered the selection - which is how somebody presses it a second time.
     */
    public function testSelectingRowsThatAreAllGoneIsNotAnEmptySelection(): void {
        $this->assertSame('0 deleted.', $this->deleting(['1', '2'], fn(int $id) => false));
    }

    // --- what it does with a refusal ---

    /**
     * The rest still go. This is the whole reason each one is tried on its own.
     */
    public function testARefusalDoesNotStopTheOthers(): void {
        $seen = [];
        $notice = $this->deleting(['1', '2', '3'], function (int $id) use (&$seen) {
            $seen[] = $id;
            return $id === 2 ? 'Your own account was left alone.' : true;
        });
        $this->assertSame([1, 2, 3], $seen, 'it gave up at the first refusal');
        $this->assertSame('2 deleted. Your own account was left alone.', $notice);
    }

    /**
     * A service saying no by throwing is the same answer as saying no by returning - `delete()`
     * on the last administrator and on a role the system needs both throw
     */
    public function testAThrownRefusalIsReportedLikeAnyOther(): void {
        $notice = $this->deleting(['1', '2'], function (int $id) {
            if ($id === 2) {
                throw new DpressException("The role 'admin' can not be removed.");
            }
            return true;
        });
        $this->assertSame("1 deleted. The role 'admin' can not be removed.", $notice);
    }

    /**
     * Five rows refused for one reason is one reason, not five sentences
     */
    public function testTheSameReasonIsSaidOnce(): void {
        $notice = $this->deleting(['1', '2', '3'], fn(int $id) => 'That one is protected.');
        $this->assertSame('0 deleted. That one is protected.', $notice);
    }

    public function testDifferentReasonsAreAllReported(): void {
        $notice = $this->deleting(['1', '2'], fn(int $id) => $id === 1 ? 'First reason.' : 'Second reason.');
        $this->assertSame('0 deleted. First reason. Second reason.', $notice);
    }

    // --- what arrives from the request ---

    /**
     * The ids come out of a POST, so they are strings, and anything that is not a number is not
     * an id. `actionIds()` is what every group endpoint trusts.
     */
    public function testOnlyRealIdsSurviveTheRequest(): void {
        $seen = [];
        $this->deleting(['7', 'drop table', '0', '', '12'], function (int $id) use (&$seen) {
            $seen[] = $id;
            return true;
        });
        $this->assertSame([7, 12], $seen);
    }
}
