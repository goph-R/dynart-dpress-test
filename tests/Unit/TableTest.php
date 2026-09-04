<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\MarkdownRenderer;
use Dynart\Dpress\Test\RecordingEvents;
use PHPUnit\Framework\TestCase;

/**
 * Tables, which CommonMark does not have and a blog needs
 *
 * The one syntax a post reaches for that plain CommonMark has no answer to. It is added beside the
 * core extension rather than as a subscriber, because a table is **syntax and not a policy**: it
 * has nothing to configure, nothing to ask a service, and it appears only where somebody wrote one.
 * The subscribers are the things that had a decision to make - what a URL points at, whether prose
 * gets linked, what a callout looks like. A table has none, and so it needs no setting either:
 * nobody writes a row of pipes by accident.
 *
 * @covers \Dynart\Dpress\Content\MarkdownRenderer
 */
class TableTest extends TestCase {

    private MarkdownRenderer $renderer;

    protected function setUp(): void {
        $this->renderer = new MarkdownRenderer(new RecordingEvents());
    }

    public function testATableIsATable(): void {
        $html = $this->renderer->render("| Thing | Does |\n|---|---|\n| DOSBox-X | DOS |\n");
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<th>Thing</th>', $html);
        $this->assertStringContainsString('<td>DOSBox-X</td>', $html);
        $this->assertStringContainsString('</table>', $html);
    }

    /**
     * `|---:|` is the author saying which way a column reads, and it survives as an attribute the
     * stylesheets answer - both of them, because a theme that sets `text-align` on every cell and
     * ignores this throws the instruction away silently
     */
    public function testTheColumnAlignmentSurvives(): void {
        $html = $this->renderer->render("| a | b | c |\n|:--|:-:|--:|\n| 1 | 2 | 3 |\n");
        $this->assertStringContainsString('align="center"', $html);
        $this->assertStringContainsString('align="right"', $html);
    }

    /**
     * Inline markup inside a cell is still inline markup - a table full of `**bold**` would be a
     * strange thing to have to work around
     */
    public function testACellIsStillMarkdown(): void {
        $html = $this->renderer->render("| a |\n|---|\n| **bold** and `code` |\n");
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<code>code</code>', $html);
    }

    /**
     * A post about writing markdown shows a table without becoming one
     */
    public function testATableInsideAFenceStaysText(): void {
        $html = $this->renderer->render("```\n| a | b |\n|---|---|\n| 1 | 2 |\n```");
        $this->assertStringNotContainsString('<table>', $html);
        $this->assertStringContainsString('| a | b |', $html);
    }

    /**
     * Pipes in a sentence are pipes. A table needs the row of dashes under the header, and without
     * it this is a paragraph - which is what every post written before tables existed relies on.
     */
    public function testALineOfPipesIsNotATable(): void {
        $html = $this->renderer->render('Run `ls | grep x | wc -l` to count them.');
        $this->assertStringNotContainsString('<table>', $html);
    }

    /**
     * A table is split into pages by the same `---` everything else is, so a separator immediately
     * under a header row could have been read as one. It is not: the split happens before the
     * markdown is parsed, on a line that is **only** `---`, and a table's is `|---|`.
     */
    public function testAPageBreakDoesNotCutATableInHalf(): void {
        $parts = $this->renderer->renderSplit("Lead.\n\n---\n\n| a | b |\n|---|---|\n| 1 | 2 |\n");
        $this->assertStringContainsString('<table>', $parts['body']);
        $this->assertStringNotContainsString(MarkdownRenderer::PAGE_MARKER, $parts['body']);
    }
}
