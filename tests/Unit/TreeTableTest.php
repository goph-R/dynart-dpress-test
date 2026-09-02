<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Dpress;
use PHPUnit\Framework\TestCase;

/**
 * The two tree screens, against the partial they both render through
 *
 * `dpress_admin:tree` is to a tree what `dpress_admin:list` is to a dynamic list: the screen
 * writes one array and no markup. The scaffolding was copied between the two screens before
 * 0.30.0, down to the categories table carrying `class="menu-items"`, and copied markup drifts.
 *
 * What can still go wrong is what always goes wrong with a config-driven table: **a column with no
 * field behind it is a row of blanks**, with no error and no warning. Both screens are checked
 * here for the same reason the attachments panel is, and the dashboard was not until it had been
 * broken for three releases.
 *
 * @covers \Dynart\Dpress\Controller\Admin\MenuAdminController
 * @covers \Dynart\Dpress\Controller\Admin\TaxonomyAdminController
 */
class TreeTableTest extends TestCase {

    /** screen => [the method building its rows, the method declaring its columns] */
    private const SCREENS = [
        'menu items' => ['src/Controller/Admin/MenuAdminController.php', 'flatten', 'items'],
        'categories' => ['src/Controller/Admin/TaxonomyAdminController.php', 'flattenCategories', 'categories'],
    ];

    private function source(string $file): string {
        $path = Dpress::path($file);
        $this->assertFileExists($path);
        return (string)file_get_contents($path);
    }

    /**
     * The keys of the row literal, read out of the method that builds it
     *
     * @return string[]
     */
    private function fieldsOf(string $file, string $builder): array {
        $source = $this->source($file);
        $start = strpos($source, 'function '.$builder.'(');
        $this->assertNotFalse($start, "$builder() is not there any more");
        $end = strpos($source, "\n    }", $start);
        preg_match_all("/'([a-z_]+)'\s*=>/", substr($source, $start, $end - $start), $matches);
        return $matches[1];
    }

    /**
     * The column names a screen declares, read out of its `'columns' => [...]` block
     *
     * @return string[]
     */
    private function columnsOf(string $file, string $action): array {
        $source = $this->source($file);
        $start = strpos($source, 'function '.$action.'(');
        $this->assertNotFalse($start);
        $at = strpos($source, "'columns'", $start);
        $this->assertNotFalse($at, "$action() declares no columns, so the tree renders none");
        $end = strpos($source, "'row_actions'", $at);
        preg_match_all("/'([a-z_]+)'\s*=>\s*\['label'/", substr($source, $at, $end - $at), $matches);
        return $matches[1];
    }

    public function testEveryTreeColumnHasAFieldBehindIt(): void {
        foreach (self::SCREENS as $screen => [$file, $builder, $action]) {
            $columns = $this->columnsOf($file, $action);
            $fields = $this->fieldsOf($file, $builder);
            $this->assertNotEmpty($columns, "$screen declares no columns");
            foreach ($columns as $column) {
                $this->assertContains(
                    $column, $fields,
                    "the $screen tree shows a '$column' column and its rows carry no such field"
                );
            }
        }
    }

    /**
     * The three the dragging reads. Without them a row is in the table and out of the tree: the
     * handle picks it up and there is nothing to say where it came from or what it belongs to.
     */
    public function testEveryTreeRowCarriesWhatTheDraggingReads(): void {
        foreach (self::SCREENS as $screen => [$file, $builder, $action]) {
            $fields = $this->fieldsOf($file, $builder);
            foreach (['id', 'parent_id', 'depth'] as $needed) {
                $this->assertContains($needed, $fields, "$screen rows carry no '$needed'");
            }
        }
    }

    /**
     * A row action names the row property holding its URL, and a name nothing carries renders no
     * button at all - quietly, because the partial skips an empty one rather than linking nowhere
     */
    public function testEveryRowActionPointsAtAFieldTheRowsCarry(): void {
        foreach (self::SCREENS as $screen => [$file, $builder, $action]) {
            $source = $this->source($file);
            $start = strpos($source, "'row_actions'");
            $fields = $this->fieldsOf($file, $builder);
            // `'link' => 'edit_url'` and `'post' => 'delete_url'`, wherever the actions were built
            preg_match_all("/'(?:link|post)'\s*=>\s*'([a-z_]+)'/", $source, $matches);
            $this->assertNotEmpty($matches[1], "$screen declares no row actions");
            foreach (array_unique($matches[1]) as $property) {
                $this->assertContains($property, $fields, "$screen has an action on '$property' and no such field");
            }
        }
    }

    /**
     * Both screens render through the one partial, and it is there
     */
    public function testBothScreensGoThroughTheSharedPartial(): void {
        $this->assertFileExists(Dpress::viewsPath().'/admin/tree.phtml');
        foreach (['admin/menu/items.phtml', 'admin/taxonomy/categories.phtml'] as $template) {
            $source = (string)file_get_contents(Dpress::viewsPath().'/'.$template);
            $this->assertStringContainsString('dpress_admin:tree', $source, "$template builds its own table");
            $this->assertStringNotContainsString('<table', $source, "$template still has a table in it");
        }
    }
}
