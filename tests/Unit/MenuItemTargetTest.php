<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Controller\Admin\MenuAdminController;
use Dynart\Dpress\Entity\MenuItem;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Two fields that could disagree about where a menu item points
 *
 * The editor asks the kind in one select ("Points at") and the thing in another, and the second
 * carries its own kind in the value - `12` is a post or page, `c12` a category, `t12` a tag. So
 * the two can contradict each other, and nothing said when they did.
 *
 * It produced the shape somebody actually hits: **leave "Points at" on its default and fill in
 * Address**, which is the obvious way to add an external link, and what got saved was a post link
 * with no post - reported afterwards as *"its target is gone"* when it had never been there. And a
 * quieter one: a tag chosen under the category kind had its `t` stripped by `ltrim($v, 'ct')` and
 * the item pointed at the category with that id, at a URL nobody had chosen.
 *
 * @covers \Dynart\Dpress\Controller\Admin\MenuAdminController
 */
class MenuItemTargetTest extends TestCase {

    private function call(string $method, array $args): mixed {
        $controller = (new ReflectionClass(MenuAdminController::class))->newInstanceWithoutConstructor();
        $reflected = new ReflectionMethod(MenuAdminController::class, $method);
        $reflected->setAccessible(true);
        return $reflected->invokeArgs($controller, $args);
    }

    private function targetId(string $type, string $value): ?int {
        return $this->call('targetId', [$type, $value]);
    }

    private function problem(string $type, ?int $targetId, string $url): ?string {
        return $this->call('itemProblem', [[
            'target_type' => $type, 'target_id' => $targetId, 'url' => $url,
        ]]);
    }

    // --- the value has to be of the kind that was chosen ---

    public function testEachKindReadsItsOwnValue(): void {
        $this->assertSame(12, $this->targetId(MenuItem::TARGET_CONTENT, '12'));
        $this->assertSame(12, $this->targetId(MenuItem::TARGET_CATEGORY, 'c12'));
        $this->assertSame(12, $this->targetId(MenuItem::TARGET_TAG, 't12'));
    }

    /**
     * The quiet one: `ltrim('t12', 'ct')` is `12`, so a tag chosen under the category kind used to
     * become category 12 - an item pointing somewhere nobody picked, with nothing said
     */
    public function testAValueOfTheWrongKindIsNoTargetAtAll(): void {
        $this->assertNull($this->targetId(MenuItem::TARGET_CATEGORY, 't12'));
        $this->assertNull($this->targetId(MenuItem::TARGET_TAG, 'c12'));
        $this->assertNull($this->targetId(MenuItem::TARGET_CONTENT, 'c12'));
        $this->assertNull($this->targetId(MenuItem::TARGET_CONTENT, 't12'));
    }

    /**
     * `ltrim` also ate a leading `c` or `t` from anything - these are the kinds that point at
     * nothing in the library, and a stale value left in the select must not become an id
     */
    public function testTheKindsThatPointAtNothingKeepNoTarget(): void {
        $this->assertNull($this->targetId(MenuItem::TARGET_HOME, '12'));
        $this->assertNull($this->targetId(MenuItem::TARGET_URL, 'c12'));
    }

    public function testNothingChosenIsNoTarget(): void {
        $this->assertNull($this->targetId(MenuItem::TARGET_CONTENT, ''));
        $this->assertNull($this->targetId(MenuItem::TARGET_CATEGORY, 'c'));
        $this->assertNull($this->targetId(MenuItem::TARGET_CONTENT, 'nonsense'));
    }

    // --- and an item that cannot render is refused rather than saved ---

    /**
     * The reported bug, as a test
     */
    public function testAnAddressWithTheDefaultKindIsRefused(): void {
        $problem = $this->problem(MenuItem::TARGET_CONTENT, null, 'https://example.com');
        $this->assertNotNull($problem);
        $this->assertStringContainsString('Address', $problem);
    }

    public function testTheExternalKindNeedsAnAddress(): void {
        $this->assertNotNull($this->problem(MenuItem::TARGET_URL, null, ''));
        $this->assertNotNull($this->problem(MenuItem::TARGET_URL, null, '   '));
        $this->assertNull($this->problem(MenuItem::TARGET_URL, null, 'https://example.com'));
    }

    public function testTheLibraryKindsNeedSomethingChosen(): void {
        foreach ([MenuItem::TARGET_CONTENT, MenuItem::TARGET_CATEGORY, MenuItem::TARGET_TAG] as $type) {
            $this->assertNotNull($this->problem($type, null, ''), $type);
            $this->assertNull($this->problem($type, 12, ''), $type);
        }
    }

    /**
     * The front page is the one kind that needs nothing filled in at all
     */
    public function testTheFrontPageNeedsNothing(): void {
        $this->assertNull($this->problem(MenuItem::TARGET_HOME, null, ''));
    }

    // --- and the reason shown on the items screen is the true one ---

    private function why(string $type, ?int $targetId): string {
        return $this->call('whyNotRendered', [['target_type' => $type, 'target_id' => $targetId]]);
    }

    /**
     * "Its target is gone" sent you looking through the bin for a post that never existed
     */
    public function testNeverSetAndDeletedReadDifferently(): void {
        $this->assertSame('nothing is chosen for it to point at', $this->why(MenuItem::TARGET_CONTENT, null));
        $this->assertSame('its target is gone', $this->why(MenuItem::TARGET_CONTENT, 42));
        $this->assertSame('it has no address', $this->why(MenuItem::TARGET_URL, null));
    }
}
