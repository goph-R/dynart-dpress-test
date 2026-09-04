<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\Shortcode\BreakShortcode;
use PHPUnit\Framework\TestCase;

/**
 * `{{ br }}` - the one piece of markup markdown cannot express where it is needed
 *
 * Markdown has two line breaks already, and both need the line to **end**: two trailing spaces,
 * and a trailing backslash. A table cell cannot end a line - the whole row is one - so a cell is
 * inline content with no way to break it. GFM's answer is `<br>` in the cell, and raw HTML is
 * stripped here so that a compromised account cannot put a script on every page.
 *
 * @covers \Dynart\Dpress\Content\Shortcode\BreakShortcode
 */
class BreakShortcodeTest extends TestCase {

    /**
     * `<br />` and not `<br>`, because that is the form CommonMark itself emits for a hard break -
     * a document should not end up with two spellings of one thing
     */
    public function testItIsTheBreakCommonMarkWrites(): void {
        $this->assertSame('<br />', (new BreakShortcode())->render([]));
    }

    /**
     * A line break has nothing to configure, so anything handed to it is ignored rather than
     * refused - there is no way to write it wrong
     */
    public function testArgumentsAreIgnored(): void {
        $this->assertSame('<br />', (new BreakShortcode())->render(['nonsense', 'x' => 1]));
    }
}
