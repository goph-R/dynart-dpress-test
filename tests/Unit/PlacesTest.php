<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Block\Blocks;
use Dynart\Dpress\Entity\Block;
use Dynart\Dpress\Service\BlockService;
use Dynart\Dpress\Service\MenuService;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Theme\Places;
use Dynart\Micro\ViewInterface;
use PHPUnit\Framework\TestCase;

/**
 * What a layout gets when it asks a place what is in it
 *
 * The whole contract is in the empty answers. A layout writes
 *
 *     $sidebar = $places->render('sidebar');
 *     if ($sidebar !== '') { … open the column … }
 *
 * so `''` has to mean *there is nothing here* all the way down: no blocks, blocks that all came
 * out empty, a place nobody has used. An `<aside>` containing nothing is a hole in the design at
 * every width, and the layout can only avoid it if this is honest.
 *
 * @covers \Dynart\Dpress\Theme\Places
 */
class PlacesTest extends TestCase {

    private array $fetched = [];

    private function places(array $blocks, string $renders = '<p>x</p>', array $menuItems = []): Places {
        $menus = $this->getMockBuilder(MenuService::class)->disableOriginalConstructor()->getMock();
        $menus->method('tree')->willReturn($menuItems);

        $service = $this->getMockBuilder(BlockService::class)->disableOriginalConstructor()->getMock();
        $service->method('inPlace')->willReturnCallback(fn(string $place) => $blocks[$place] ?? []);

        $types = $this->getMockBuilder(Blocks::class)->disableOriginalConstructor()->getMock();
        $types->method('render')->willReturn($renders);

        $view = $this->createMock(ViewInterface::class);
        $view->method('fetch')->willReturnCallback(function (string $template, array $variables = []): string {
            $this->fetched[] = $variables;
            return '<'.$template.'>';
        });
        return new Places($menus, $service, $types, $view, new RecordingEvents());
    }

    private function block(string $type, string $title = ''): Block {
        $block = new Block();
        $block->type = $type;
        $block->title = $title;
        return $block;
    }

    public function testAPlaceNobodyHasUsedIsEmpty(): void {
        $this->assertSame('', $this->places([])->render('sidebar'));
    }

    public function testAPlaceWithSomethingInItRendersTheWrapper(): void {
        $places = $this->places(['sidebar' => [$this->block('tag_cloud', 'Tags')]]);
        $this->assertSame('<dpress:blocks>', $places->blocks('sidebar'));
        $this->assertSame([['type' => 'tag_cloud', 'title' => 'Tags', 'html' => '<p>x</p>']],
                          $this->fetched[0]['blocks']);
    }

    /**
     * A tag cloud on a site with no tags returns nothing, and nothing is what it should leave
     * behind - not a heading over a blank space
     */
    public function testABlockThatDrewNothingIsLeftOut(): void {
        $places = $this->places(['sidebar' => [$this->block('tag_cloud')]], '   ');
        $this->assertSame('', $places->blocks('sidebar'));
    }

    /**
     * Both halves of a place, in the order a layout wants them: the menu assigned here, then what
     * was put here. One idea, two editors.
     */
    public function testAPlaceIsTheMenuAndTheBlocksTogether(): void {
        $places = $this->places(['footer' => [$this->block('markdown')]], '<p>x</p>',
                                [['label' => 'Home', 'url' => '/', 'external' => false, 'children' => []]]);
        $this->assertSame('<dpress:menu><dpress:blocks>', $places->render('footer'));
    }

    public function testAPlaceWithAMenuAndNoBlocks(): void {
        $places = $this->places([], '<p>x</p>',
                                [['label' => 'Home', 'url' => '/', 'external' => false, 'children' => []]]);
        $this->assertSame('<dpress:menu>', $places->render('main'));
    }

    /**
     * A layout that asks twice - a wide variant and a narrow one - is one read, not two
     */
    public function testAskingTwiceReadsOnce(): void {
        $places = $this->places(['sidebar' => [$this->block('markdown')]]);
        $places->blocks('sidebar');
        $places->blocks('sidebar');
        $this->assertCount(1, $this->fetched);
    }

    /**
     * Every place answers for itself: one being empty says nothing about the next
     */
    public function testTwoPlacesAreTwoAnswers(): void {
        $places = $this->places(['sidebar' => [$this->block('markdown')]]);
        $this->assertSame('', $places->blocks('footer'));
        $this->assertNotSame('', $places->blocks('sidebar'));
    }
}
