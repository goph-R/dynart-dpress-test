<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Block\KofiBlock;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A Ko-fi button, from three boxes somebody typed into
 *
 * Two of the three end up somewhere a typo is more than a typo: the page name goes into an `href`
 * and the colour into a `style`. Both are **validated rather than escaped**, and the difference is
 * the same one the icon shortcode makes - escaping stops a value breaking out of its quotes, and
 * what has to be impossible is a settings box naming a declaration or an address of its own.
 *
 * @covers \Dynart\Dpress\Block\KofiBlock
 */
class KofiBlockTest extends TestCase {

    private KofiBlock $block;

    protected function setUp(): void {
        $this->block = (new ReflectionClass(KofiBlock::class))->newInstanceWithoutConstructor();
    }

    // --- the page name ---

    public function testThePlainNameIsTheName(): void {
        $this->assertSame('supportkofi', $this->block->page('supportkofi'));
        $this->assertSame('goph-R_1', $this->block->page('  goph-R_1  '));
    }

    /**
     * The field asks for the bit after the slash and somebody will paste the whole address anyway -
     * it is the thing in front of them, and both mean the same page
     */
    public function testTheWholeAddressIsAccepted(): void {
        foreach (['https://ko-fi.com/gopherlab', 'http://www.ko-fi.com/gopherlab',
                  'ko-fi.com/gopherlab/', 'kofi.com/gopherlab'] as $typed) {
            $this->assertSame('gopherlab', $this->block->page($typed), $typed);
        }
    }

    /**
     * This ends up in an `href`, so anything that is not a page name is not a page
     */
    public function testAnythingThatIsNotANameIsNothing(): void {
        foreach (['', '   ', 'not a name!', 'javascript:alert(1)', '../elsewhere',
                  'https://example.com/gopherlab', 'a"onmouseover="x'] as $typed) {
            $this->assertSame('', $this->block->page($typed), $typed);
        }
    }

    // --- the colour ---

    public function testAHexColourInEitherSpelling(): void {
        $this->assertSame('#f1fa8c', $this->block->color('#F1FA8C'));
        $this->assertSame('#29abe0', $this->block->color('29abe0'));
    }

    /**
     * Three digits are the same colour written shorter, and the template should only ever see one
     * shape
     */
    public function testThreeDigitsAreExpanded(): void {
        $this->assertSame('#ff0000', $this->block->color('f00'));
    }

    /**
     * A `style` attribute is not a place to put whatever arrived. Ko-fi's own blue is what an
     * unreadable value means, so a block still looks like Ko-fi rather than looking broken.
     */
    public function testAnythingThatIsNotAColourFallsBack(): void {
        foreach (['', 'blue', '#GGGGGG', 'red; background-image: url(x)', '#1234567'] as $typed) {
            $this->assertSame(KofiBlock::DEFAULT_COLOR, $this->block->color($typed), $typed);
        }
    }

    // --- and what can be read on it ---

    /**
     * A pale brand colour would otherwise get white text on it, which is a button nobody can read
     * and nothing tells the site owner that is what happened
     */
    public function testTheTextTurnsBlackOnALightButton(): void {
        foreach (['#ffffff', '#f1fa8c', '#8be9fd'] as $light) {
            $this->assertTrue($this->block->isLight($light), $light);
        }
        foreach (['#000000', '#29abe0', '#6272a4', '#ff5555'] as $dark) {
            $this->assertFalse($this->block->isLight($dark), $dark);
        }
    }
}
