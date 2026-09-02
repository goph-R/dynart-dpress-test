<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Block\Blocks;
use Dynart\Dpress\Block\CategoryListBlock;
use Dynart\Dpress\Block\MarkdownBlock;
use Dynart\Dpress\Block\TagCloudBlock;
use Dynart\Dpress\Content\MarkdownRenderer;
use Dynart\Dpress\DpressServices;
use Dynart\Dpress\Entity\Block;
use Dynart\Dpress\Service\TaxonomyService;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Test\StubLogger;
use Dynart\Micro\ViewInterface;
use PHPUnit\Framework\TestCase;

/**
 * A block is a type plus its settings
 *
 * The alternative was a column per kind of block, which means a schema change - and a migration
 * nobody can write from outside the package - every time somebody registers a new one. So the
 * registry holds what a type *is* and the row holds what it was told, and the pair has to survive
 * a type being switched off, a setting that predates a field, and JSON somebody edited by hand.
 *
 * @covers \Dynart\Dpress\Block\Blocks
 * @covers \Dynart\Dpress\Entity\Block
 * @covers \Dynart\Dpress\Block\MarkdownBlock
 * @covers \Dynart\Dpress\Block\TagCloudBlock
 * @covers \Dynart\Dpress\Block\CategoryListBlock
 */
class BlockTest extends TestCase {

    /** The variables a block handed to its template, which is where its own logic ends up */
    private array $handed = [];

    private function view(): ViewInterface {
        $view = $this->createMock(ViewInterface::class);
        $view->method('fetch')->willReturnCallback(function (string $template, array $variables = []): string {
            $this->handed = $variables;
            return '<rendered '.$template.'>';
        });
        return $view;
    }

    private function block(string $type, array $settings = []): Block {
        $block = new Block();
        $block->type = $type;
        $block->setSettings($settings);
        return $block;
    }

    // --- the row ---

    public function testSettingsSurviveTheRoundTrip(): void {
        $block = $this->block('markdown', ['markdown' => 'A **thing**', 'html' => '<p>A</p>']);
        $this->assertSame('A **thing**', $block->setting('markdown'));
    }

    /**
     * A row written before a type had this field, or one somebody edited by hand: the answer is
     * the default, not a fatal in a template
     */
    public function testASettingThatWasNeverStored(): void {
        $this->assertSame(30, $this->block('tag_cloud')->setting('limit', 30));
    }

    public function testSettingsThatAreNotJsonAtAll(): void {
        $block = $this->block('markdown');
        $block->settings = 'not json {';
        $this->assertSame([], $block->settings());
    }

    // --- the registry ---

    private function blocks(): Blocks {
        $blocks = new Blocks(new StubLogger());
        $blocks->add('example', ['title' => 'Example', 'render' => fn() => 'x',
                                 'fields' => ['limit' => ['type' => 'text', 'label' => 'Limit']]]);
        return $blocks;
    }

    public function testATypeKnowsItsOwnFields(): void {
        $this->assertSame(['limit'], array_keys($this->blocks()->fields('example')));
    }

    public function testATypeNobodyRegisteredHasNoFieldsRatherThanBlowingUp(): void {
        $this->assertSame([], $this->blocks()->fields('gone'));
    }

    /**
     * The plugin that provided a type may simply be switched off this morning. A sidebar with one
     * thing missing still renders; an exception on a page view does not.
     */
    public function testAnUnknownTypeLeavesAComment(): void {
        $html = $this->blocks()->render($this->block('gone'));
        $this->assertStringContainsString('<!-- no block type called gone -->', $html);
    }

    public function testAnUnknownTypeIsLogged(): void {
        $logger = new StubLogger();
        $blocks = new Blocks($logger);
        $blocks->render($this->block('gone'));
        $this->assertNotEmpty($logger->lines, 'a block that did not render should say so somewhere');
    }

    /**
     * Most types store what they were given. Only one has anything to do at save time.
     */
    public function testSettingsPassThroughATypeWithNoPrepare(): void {
        $this->assertSame(['limit' => '5'], $this->blocks()->prepare('example', ['limit' => '5']));
    }

    // --- the markdown block ---

    /**
     * The content rule, one level down: what is stored is HTML, and a page view prints it
     */
    public function testMarkdownIsRenderedWhenItIsSavedRatherThanWhenItIsShown(): void {
        $prepared = (new MarkdownBlock(new MarkdownRenderer(new RecordingEvents())))
            ->prepare(['markdown' => 'A **bold** thing.']);
        $this->assertStringContainsString('<strong>bold</strong>', $prepared['html']);
        $this->assertSame('A **bold** thing.', $prepared['markdown'], 'the markdown is the truth and is kept');
    }

    public function testRenderingAMarkdownBlockParsesNothing(): void {
        $markdown = $this->getMockBuilder(MarkdownRenderer::class)->disableOriginalConstructor()->getMock();
        $markdown->expects($this->never())->method('render');
        $block = new MarkdownBlock($markdown);
        $this->assertSame('<p>cached</p>', $block->render($this->block('markdown'), ['html' => '<p>cached</p>']));
    }

    // --- the tag cloud ---

    /** @return array[] the tags the block handed to the template */
    private function tagCloud(array $tags): array {
        $taxonomy = $this->getMockBuilder(TaxonomyService::class)->disableOriginalConstructor()->getMock();
        $taxonomy->method('tagCloud')->willReturn($tags);
        (new TagCloudBlock($taxonomy, $this->view()))->render($this->block('tag_cloud'), ['limit' => '2']);
        return $this->handed['tags'] ?? [];
    }

    /**
     * Which tags are in the cloud is a question about the totals; the order they are read in is
     * alphabetical. Both, in that order.
     */
    public function testTheLimitTakesTheMostUsedAndThenSortsThemByName(): void {
        $tags = $this->tagCloud([
            ['id' => 1, 'name' => 'zebra', 'slug' => 'zebra', 'total' => 9],
            ['id' => 2, 'name' => 'alpha', 'slug' => 'alpha', 'total' => 5],
            ['id' => 3, 'name' => 'never', 'slug' => 'never', 'total' => 1],
        ]);
        $this->assertSame(['alpha', 'zebra'], array_column($tags, 'name'));
    }

    public function testTheMostUsedTagIsTheBiggestAndTheLeastIsTheSmallest(): void {
        $weights = array_column($this->tagCloud([
            ['id' => 1, 'name' => 'alpha', 'slug' => 'alpha', 'total' => 9],
            ['id' => 2, 'name' => 'beta', 'slug' => 'beta', 'total' => 1],
        ]), 'weight');
        $this->assertSame([TagCloudBlock::STEPS, 1], $weights);
    }

    /**
     * Every tag used three times is not a cloud with five sizes in it - the difference between 3
     * and 3 is not something to draw
     */
    public function testTagsUsedEquallyAreDrawnEqually(): void {
        $this->assertSame([3, 3], array_column($this->tagCloud([
            ['id' => 1, 'name' => 'alpha', 'slug' => 'alpha', 'total' => 3],
            ['id' => 2, 'name' => 'beta', 'slug' => 'beta', 'total' => 3],
        ]), 'weight'));
    }

    /**
     * A site with no tags yet renders nothing at all, so the layout leaves out the heading too
     */
    public function testNoTagsIsNoBlock(): void {
        $taxonomy = $this->getMockBuilder(TaxonomyService::class)->disableOriginalConstructor()->getMock();
        $taxonomy->method('tagCloud')->willReturn([]);
        $this->assertSame('', (new TagCloudBlock($taxonomy, $this->view()))
            ->render($this->block('tag_cloud'), []));
    }

    // --- the category list ---

    private function categories(array $rows): array {
        $taxonomy = $this->getMockBuilder(TaxonomyService::class)->disableOriginalConstructor()->getMock();
        $taxonomy->method('categories')->willReturn($rows);
        (new CategoryListBlock($taxonomy, $this->view()))->render($this->block('category_list'), []);
        return $this->handed['items'] ?? [];
    }

    public function testCategoriesComeOutNested(): void {
        $items = $this->categories([
            ['id' => 1, 'parent_id' => null, 'name' => 'News', 'slug' => 'news'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'Releases', 'slug' => 'releases'],
        ]);
        $this->assertCount(1, $items);
        $this->assertSame('Releases', $items[0]['children'][0]['name']);
    }

    /**
     * A row pointing at a parent that is not in the list would otherwise be in the table and on no
     * screen - the same disappearance a tree loop causes, and the same answer: keep it visible
     */
    public function testACategoryWhoseParentIsMissingStaysVisible(): void {
        $items = $this->categories([
            ['id' => 2, 'parent_id' => 99, 'name' => 'Orphan', 'slug' => 'orphan'],
        ]);
        $this->assertSame(['Orphan'], array_column($items, 'name'));
    }

    // --- what the CMS registers ---

    /**
     * Every core type goes in through the call a plugin uses, and every one of them can actually
     * be rendered: a `render` naming a method that is not there is an error nobody sees until
     * somebody puts that block on a page.
     */
    public function testEveryCoreBlockTypeCanBeRendered(): void {
        foreach (DpressServices::BLOCKS as $type => $definition) {
            $this->assertArrayHasKey('render', $definition, "$type has nothing to render with");
            [$className, $method] = $definition['render'];
            $this->assertTrue(method_exists($className, $method), "$className::$method() is missing");
        }
    }

    public function testEveryCoreBlockTypeHasATitleToShowInTheChooser(): void {
        foreach (DpressServices::BLOCKS as $type => $definition) {
            $this->assertNotSame('', (string)($definition['title'] ?? ''), "$type has no title");
        }
    }

    public function testTheSaveTimeHookExistsWhereATypeDeclaresOne(): void {
        foreach (DpressServices::BLOCKS as $type => $definition) {
            if (empty($definition['prepare'])) {
                continue;
            }
            [$className, $method] = $definition['prepare'];
            $this->assertTrue(method_exists($className, $method), "$className::$method() is missing");
        }
    }

    /**
     * A block's settings are form fields, and a field whose type nobody registered renders as
     * nothing at all - an editor with a missing box and no error anywhere
     */
    public function testEveryFieldACoreBlockDeclaresIsAFieldTypeThatExists(): void {
        $known = array_merge(
            ['text', 'textarea', 'select', 'checkbox', 'password', 'hidden', 'file'],
            array_keys(DpressServices::WIDGETS)
        );
        foreach (DpressServices::BLOCKS as $type => $definition) {
            foreach ((array)($definition['fields'] ?? []) as $name => $field) {
                $this->assertContains($field['type'], $known, "$type.$name is a '{$field['type']}', which nothing renders");
            }
        }
    }

    public function testRegisteringPutsEveryCoreTypeIntoTheRegistry(): void {
        $blocks = new Blocks(new StubLogger());
        DpressServices::registerBlocks($blocks);
        foreach (array_keys(DpressServices::BLOCKS) as $type) {
            $this->assertTrue($blocks->has($type), "$type was not registered");
        }
    }
}
