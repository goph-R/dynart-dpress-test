<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\ContentPages;
use Dynart\Dpress\Content\MarkdownRenderer;
use Dynart\Dpress\Test\RecordingEvents;
use PHPUnit\Framework\TestCase;

/**
 * A body written in pages, from the separator that was already there
 *
 * The first `---` ends the lead and every one after it ends a page, so a long post gets
 * *Previous* and *Next* without a new syntax, a new column or a new field on the editor.
 *
 * Two rules carry the whole feature. **A separator inside fenced code is not a separator** - a
 * post about YAML front matter is the obvious case, and splitting there would tear a document in
 * half at exactly the place its author was writing about. And **each page is rendered on its
 * own**, never one render cut up afterwards, because cutting HTML in half is how a `<ul>` loses
 * its closing tag.
 *
 * @covers \Dynart\Dpress\Content\ContentPages
 * @covers \Dynart\Dpress\Content\MarkdownRenderer
 */
class ContentPagesTest extends TestCase {

    private MarkdownRenderer $renderer;

    protected function setUp(): void {
        $this->renderer = new MarkdownRenderer(new RecordingEvents());
    }

    // --- what still has to be true ---

    /**
     * The rule that was there before this: the first separator, and not on line 0
     */
    public function testTheFirstSeparatorStillEndsTheLead(): void {
        $parts = $this->renderer->split("The lead.\n\n---\n\nThe body.");
        $this->assertSame('The lead.', $parts['lead']);
        $this->assertSame('The body.', $parts['body']);
    }

    /**
     * A separator on line 0 is opening front matter and ends nothing, so a document that is only
     * front matter has no body at all. (The *closing* fence does end the lead, which is how this
     * has always behaved.)
     */
    public function testASeparatorOnLineZeroIsStillFrontMatter(): void {
        $parts = $this->renderer->split("---\ntitle: x\n");
        $this->assertSame('', $parts['body']);
        $this->assertStringContainsString('---', $parts['lead']);
    }

    public function testAPostWithOneSeparatorIsStillOnePage(): void {
        $html = $this->renderer->renderSplit("The lead.\n\n---\n\nThe body.")['body'];
        $this->assertSame(1, ContentPages::count($html));
        $this->assertStringNotContainsString(MarkdownRenderer::PAGE_MARKER, $html);
    }

    // --- the pages ---

    public function testTheSecondSeparatorStartsASecondPage(): void {
        $this->assertSame(['One.', 'Two.'], $this->renderer->pages("One.\n\n---\n\nTwo."));
    }

    public function testAsManyPagesAsThereAreSeparators(): void {
        $this->assertCount(4, $this->renderer->pages("a\n---\nb\n---\nc\n---\nd"));
    }

    /**
     * `---` right after `---` is somebody's typo, and a blank page is never what it meant
     */
    public function testTwoSeparatorsTogetherDoNotMakeAnEmptyPage(): void {
        $this->assertSame(['One.', 'Two.'], $this->renderer->pages("One.\n\n---\n\n---\n\nTwo."));
    }

    public function testABodyWithNoSeparatorIsOnePage(): void {
        $this->assertSame(['Just this.'], $this->renderer->pages('Just this.'));
    }

    // --- the fence, which is the rule that keeps documents intact ---

    /**
     * The case this exists for: a post explaining YAML front matter
     */
    public function testASeparatorInsideAFenceIsNotAPageBreak(): void {
        $body = "Before.\n\n```yaml\n---\nname: x\n---\n```\n\nAfter.";
        $this->assertCount(1, $this->renderer->pages($body));
    }

    public function testNorDoesAFenceHideARealBreakAfterIt(): void {
        $body = "```\n---\n```\n\n---\n\nSecond page.";
        $pages = $this->renderer->pages($body);
        $this->assertCount(2, $pages);
        $this->assertSame('Second page.', $pages[1]);
    }

    public function testTildeFencesCountToo(): void {
        $this->assertCount(1, $this->renderer->pages("~~~\n---\n~~~"));
    }

    /**
     * A fence closes on its own character, so a stray `~~~` inside a backtick block is content
     */
    public function testAFenceIsClosedByItsOwnCharacter(): void {
        $this->assertCount(1, $this->renderer->pages("```\n~~~\n---\n```"));
    }

    /**
     * The lead/body split reads the same lines, so it had the same hole - a document whose *lead*
     * ended inside a code block was cut there
     */
    public function testTheLeadSplitSkipsFencedCodeAsWell(): void {
        $parts = $this->renderer->split("Lead with code:\n\n```\n---\n```\n\n---\n\nBody.");
        $this->assertSame('Body.', $parts['body']);
        $this->assertStringContainsString('```', $parts['lead']);
    }

    // --- what gets stored, and what comes back out ---

    public function testTheStoredBodyCarriesAMarkerBetweenPages(): void {
        $html = $this->renderer->renderSplit("Lead.\n\n---\n\nOne.\n\n---\n\nTwo.")['body'];
        $this->assertSame(2, ContentPages::count($html));
        $this->assertStringContainsString('<p>One.</p>', ContentPages::page($html, 1));
        $this->assertStringContainsString('<p>Two.</p>', ContentPages::page($html, 2));
    }

    /**
     * Rendered per page rather than cut up afterwards: a list that spans the break would otherwise
     * lose its closing tag on one side and gain an orphan on the other
     */
    public function testEachPageIsWholeHtml(): void {
        $html = $this->renderer->renderSplit("Lead.\n\n---\n\n- a\n- b\n\n---\n\n- c")['body'];
        foreach (ContentPages::split($html) as $page) {
            $this->assertSame(
                substr_count($page, '<ul>'), substr_count($page, '</ul>'),
                'a page came out with an unclosed list in it'
            );
        }
    }

    /**
     * The guard that keeps this free for everybody who never uses it
     */
    public function testABodyWithNoMarkerIsOnePageAndIsNotSearchedTwice(): void {
        $this->assertSame(['<p>Whole thing.</p>'], ContentPages::split('<p>Whole thing.</p>'));
    }

    public function testAnEmptyBodyIsStillOnePage(): void {
        $this->assertSame([''], ContentPages::split(null));
        $this->assertSame(1, ContentPages::count(''));
    }

    /**
     * Out of range is `null` and not the first page, because a controller has to be able to tell
     * "page seven of a three page post" from "page one" - one of those is a 404
     */
    public function testAPageThatIsNotThere(): void {
        $html = $this->renderer->renderSplit("Lead.\n\n---\n\nOne.\n\n---\n\nTwo.")['body'];
        $this->assertNull(ContentPages::page($html, 3));
        $this->assertNull(ContentPages::page($html, 0));
        $this->assertNotNull(ContentPages::page($html, 2));
    }
    // --- and which page a line of the document is on ---

    /**
     * What Preview asks so the tab opens on the part somebody was writing rather than at the
     * top of a seven page article. The answer has to come from the same two rules the pages
     * themselves come from, or it opens confidently on the wrong one.
     */
    private function document(): string {
        // lines:  0 lead   1        2 ---   3        4 one   5        6 ---   7        8 two
        return "The lead.\n\n---\n\nPart one.\n\n---\n\nPart two.";
    }

    public function testTheLeadIsPageOne(): void {
        foreach ([0, 1, 2] as $line) {
            $this->assertSame(1, $this->renderer->pageOfLine($this->document(), $line), (string)$line);
        }
    }

    /**
     * The separator that ends the lead is *not* a page break - the body starts after it, and
     * its first part is still page one
     */
    public function testTheFirstPartOfTheBodyIsAlsoPageOne(): void {
        $this->assertSame(1, $this->renderer->pageOfLine($this->document(), 4));
    }

    public function testALineAfterAPageBreakIsTheNextPage(): void {
        $this->assertSame(2, $this->renderer->pageOfLine($this->document(), 8));
    }

    /**
     * A separator line belongs to the page it ends, which is where the cursor reads as being
     * when somebody has just typed one
     */
    public function testAPageBreakBelongsToThePageAbove(): void {
        $this->assertSame(1, $this->renderer->pageOfLine($this->document(), 6));
    }

    /**
     * The answer can never be a page the pager does not offer, so it is worth checking against
     * the count rather than only against itself
     */
    public function testItNeverAnswersPastTheLastPage(): void {
        $markdown = $this->document();
        $pages = ContentPages::count($this->renderer->renderSplit($markdown)['body']);
        $this->assertSame(2, $pages);
        foreach (range(0, 40) as $line) {
            $page = $this->renderer->pageOfLine($markdown, $line);
            $this->assertGreaterThanOrEqual(1, $page, (string)$line);
            $this->assertLessThanOrEqual($pages, $page, (string)$line);
        }
    }

    /**
     * A document with no separator at all is one page whatever line the cursor is on - and so is
     * one whose only separator ends the lead
     */
    public function testADocumentThatWasNeverBrokenUpIsAlwaysPageOne(): void {
        $this->assertSame(1, $this->renderer->pageOfLine("Just a note.\n\nAnd more.", 2));
        $this->assertSame(1, $this->renderer->pageOfLine("Lead.\n\n---\n\nBody.", 4));
    }

    /**
     * The rule that carries the whole feature applies here too: a `---` inside fenced code is
     * not a page break, so a cursor below it must not be told it is on a page that does not exist
     */
    public function testASeparatorInsideCodeIsNotAPageBreakHereEither(): void {
        $markdown = "Lead.\n\n---\n\nOne.\n\n```\n---\n```\n\nStill one.";
        $this->assertSame(1, ContentPages::count($this->renderer->renderSplit($markdown)['body']));
        $this->assertSame(1, $this->renderer->pageOfLine($markdown, 10));
    }

    /**
     * `---` right after `---` is a typo rather than an empty page, and `pages()` drops it - so
     * the numbering here has to drop it too or every page after the typo is out by one
     */
    public function testAnEmptyPageIsSkippedTheWayItIsWhenRendering(): void {
        $markdown = "Lead.\n\n---\n\nOne.\n\n---\n---\n\nTwo.";
        $this->assertSame(2, ContentPages::count($this->renderer->renderSplit($markdown)['body']));
        $this->assertSame(2, $this->renderer->pageOfLine($markdown, 9));
    }

}
