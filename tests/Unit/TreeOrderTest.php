<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\TreeOrder;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Category;
use Dynart\Dpress\Test\StubDatabase;
use Dynart\Micro\Entities\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * An in-memory tree, so the ordering can be exercised without a database
 *
 * `TreeOrder` reads the children of a parent through one query and writes through the entity
 * manager, so those two are all that has to be stood in for.
 */
class FakeTree extends StubDatabase {

    /** @var Category[] by id */
    public array $nodes = [];

    public function add(int $id, ?int $parentId, int $position): Category {
        $node = new Category();
        $node->id = $id;
        $node->parent_id = $parentId;
        $node->position = $position;
        $this->nodes[$id] = $node;
        return $node;
    }

    public function fetchColumn(string $query, array $params = []): array {
        $this->queries[] = ['sql' => $query, 'params' => $params];
        $parentId = $params[':parentId'] ?? null;
        $children = array_filter($this->nodes, fn($n) => $n->parent_id === $parentId);
        usort($children, fn($a, $b) => [$a->position, $a->id] <=> [$b->position, $b->id]);
        return array_map(fn($n) => (string)$n->id, array_values($children));
    }

    /** @return array [id => position] in id order, for asserting on */
    public function order(?int $parentId): array {
        $children = array_filter($this->nodes, fn($n) => $n->parent_id === $parentId);
        usort($children, fn($a, $b) => $a->position <=> $b->position);
        return array_map(fn($n) => $n->id, array_values($children));
    }
}

/**
 * Moving a node around a `parent_id` + `position` tree
 *
 * A drag hands over "put this under that, third", and what has to come back out is `0, 1, 2, …`
 * on both the row of siblings the node left and the one it joined - with no gaps and no
 * duplicates. Positions that are nudged rather than renumbered drift into `0, 3, 3, 7`, where the
 * ties fall back on insertion order and nobody can see why the list looks like it does.
 *
 * The guard matters more than the arithmetic. A node dropped inside its own branch is still in
 * the table, with a parent chain that loops - so nothing walking down from the top ever reaches
 * it again, and it is gone from every screen while still being there.
 *
 * @covers \Dynart\Dpress\Content\TreeOrder
 */
class TreeOrderTest extends TestCase {

    private FakeTree $db;

    /**
     * A tree: 1 and 2 at the top, 3 and 4 under 1, 5 under 3
     */
    private function tree(): TreeOrder {
        $this->db = new FakeTree();
        $this->db->add(1, null, 0);
        $this->db->add(2, null, 1);
        $this->db->add(3, 1, 0);
        $this->db->add(4, 1, 1);
        $this->db->add(5, 3, 0);

        $em = $this->createMock(EntityManager::class);
        $em->method('safeTableName')->willReturn('`dp_category`');
        $em->method('findById')->willReturnCallback(fn($className, $id) => $this->db->nodes[(int)$id] ?? null);
        // `save()` returns void, and the fake holds the same instances the mutations were made
        // on, so there is genuinely nothing for it to do
        return new TreeOrder($em, $this->db);
    }

    // --- the guard ---

    public function testANodeCannotGoInsideItself(): void {
        $tree = $this->tree();
        $this->expectException(DpressException::class);
        $this->expectExceptionMessage('inside itself');
        $tree->move(Category::class, 1, 1, 0);
    }

    public function testANodeCannotGoInsideItsOwnChild(): void {
        $tree = $this->tree();
        $this->expectException(DpressException::class);
        $this->expectExceptionMessage('already contains');
        $tree->move(Category::class, 1, 3, 0);
    }

    /**
     * Two levels down, which is the one a check that only looked at the direct children would let
     * through - and it is the same disappearance
     */
    public function testNorInsideAGrandchild(): void {
        $tree = $this->tree();
        $this->expectException(DpressException::class);
        $tree->move(Category::class, 1, 5, 0);
    }

    public function testNothingMovedWhenTheMoveIsRefused(): void {
        $tree = $this->tree();
        try {
            $tree->move(Category::class, 1, 5, 0);
        } catch (DpressException $e) {
            // expected
        }
        $this->assertSame([1, 2], $this->db->order(null));
        $this->assertSame([3, 4], $this->db->order(1));
    }

    // --- the arithmetic ---

    public function testReorderingAmongSiblingsRenumbersThemAll(): void {
        $tree = $this->tree();
        $tree->move(Category::class, 4, 1, 0);   // 4 in front of 3
        $this->assertSame([4, 3], $this->db->order(1));
        $this->assertSame([0, 1], [$this->db->nodes[4]->position, $this->db->nodes[3]->position]);
    }

    /**
     * The row it left closes up, or the next drop into that parent computes an index against
     * positions that have a hole in them
     */
    public function testLeavingAParentClosesTheGap(): void {
        $tree = $this->tree();
        $tree->move(Category::class, 3, null, 0);
        $this->assertSame([3, 1, 2], $this->db->order(null));
        $this->assertSame([4], $this->db->order(1));
        $this->assertSame(0, $this->db->nodes[4]->position, 'the sibling left behind kept position 1');
    }

    public function testMovingIntoAParentPutsItAtTheAskedIndex(): void {
        $tree = $this->tree();
        $tree->move(Category::class, 2, 1, 1);
        $this->assertSame([3, 2, 4], $this->db->order(1));
    }

    /**
     * A drop past the end is a drop at the end; the browser and the table can disagree by one and
     * neither of them should be able to write a position nothing sorts by
     */
    public function testAPositionPastTheEndIsClamped(): void {
        $tree = $this->tree();
        $tree->move(Category::class, 2, 1, 99);
        $this->assertSame([3, 4, 2], $this->db->order(1));
        $this->assertSame(2, $this->db->nodes[2]->position);
    }

    public function testANegativePositionIsTheFront(): void {
        $tree = $this->tree();
        $tree->move(Category::class, 2, 1, -5);
        $this->assertSame([2, 3, 4], $this->db->order(1));
    }

    /**
     * The children come with it. `TreeOrder` never touches them - their `parent_id` still points
     * at the node that moved - which is what makes moving a branch one write rather than many.
     */
    public function testTheBranchTravelsWithTheNode(): void {
        $tree = $this->tree();
        $tree->move(Category::class, 3, 2, 0);
        $this->assertSame([3], $this->db->order(2));
        $this->assertSame([5], $this->db->order(3), 'the child stopped following its parent');
    }

    public function testMovingSomethingThatIsNotThere(): void {
        $tree = $this->tree();
        $this->expectException(DpressException::class);
        $tree->move(Category::class, 99, null, 0);
    }
}
