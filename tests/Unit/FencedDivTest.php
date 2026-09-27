<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\Callouts;
use Dynart\Dpress\Content\FencedDivs;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use PHPUnit\Framework\TestCase;

/**
 * Boxes: `::: {#id .class}` ... `:::`, and what is refused before a save
 *
 * @covers \Dynart\Dpress\Content\FencedDivs
 * @covers \Dynart\Dpress\Content\FencedDiv\FencedDivParser
 * @covers \Dynart\Dpress\Content\FencedDiv\FencedDivStartParser
 * @covers \Dynart\Dpress\Content\FencedDiv\FencedDivRenderer
 */
class FencedDivTest extends TestCase {

    private ?MarkdownConverter $converter = null;

    private function render(string $markdown): string {
        if ($this->converter === null) {
            $environment = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false]);
            $environment->addExtension(new CommonMarkCoreExtension());
            (new Callouts())->onEnvironment($environment);
            (new FencedDivs())->onEnvironment($environment);
            $this->converter = new MarkdownConverter($environment);
        }
        return trim((string)$this->converter->convert($markdown));
    }

    // --- rendering ---

    public function testTheReportedCaseTwoPicturesInABox(): void {
        $this->assertSame(
            "<div class=\"center\">\n<p><img src=\"a.gif\" alt=\"a\" /> <img src=\"b.gif\" alt=\"b\" /></p>\n</div>",
            $this->render("::: {.center}\n![a](a.gif) ![b](b.gif)\n:::")
        );
    }

    public function testAnIdAndClasses(): void {
        $this->assertStringStartsWith('<div id="g" class="a b">', $this->render("::: {#g .a .b}\ntext\n:::"));
    }

    public function testOneBareClass(): void {
        $this->assertStringStartsWith('<div class="center">', $this->render("::: center\ntext\n:::"));
    }

    public function testMoreColonsAndTrailingColonsMeanNothingMore(): void {
        $this->assertStringStartsWith('<div class="a">', $this->render(":::::: {.a} :::\ntext\n::::::"));
    }

    public function testAClosingFenceClosesTheInnermostBox(): void {
        $html = $this->render(":::: {.outer}\n::: {.inner}\ninner\n:::\nstill outer\n::::\nafter");
        $this->assertSame(
            "<div class=\"outer\">\n<div class=\"inner\">\n<p>inner</p>\n</div>\n<p>still outer</p>\n</div>\n<p>after</p>",
            $html
        );
    }

    public function testAColonFenceInACodeSampleIsCode(): void {
        $html = $this->render("::: {.box}\n```\n:::\n```\nafter code\n:::\noutside");
        $this->assertStringContainsString("<pre><code>:::\n</code></pre>", $html);
        $this->assertStringContainsString("<p>after code</p>\n</div>\n<p>outside</p>", $html);
    }

    public function testABoxHoldsBlocks(): void {
        $html = $this->render("::: {.box}\n- one\n- two\n\n> [!NOTE]\n> hi\n:::");
        $this->assertStringContainsString("<div class=\"box\">\n<ul>", $html);
        $this->assertStringContainsString('callout-info', $html);
    }

    public function testABareFenceOpensNothing(): void {
        $this->assertSame("<p>text</p>\n<p>:::</p>", $this->render("text\n\n:::"));
    }

    /** Forgiving, for content saved without the check: what is left open ends with the part */
    public function testAnUnclosedBoxIsClosedAtTheEnd(): void {
        $this->assertSame("<div class=\"x\">\n<p>never closed</p>\n</div>", $this->render("::: {.x}\nnever closed"));
    }

    /** Nothing but a name reaches the attribute - the rest is dropped */
    public function testOnlyNamesBecomeAttributes(): void {
        $html = $this->render("::: {.ok #id onclick=x .bad\"quote}\ntext\n:::");
        $this->assertStringStartsWith('<div id="id" class="ok">', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    public function testNothingIsLeftOpenForTheNextDocument(): void {
        $this->render("::: {.x}\nnever closed");
        $this->assertSame('<p>plain</p>', $this->render('plain'));
    }

    // --- what a save refuses ---

    public function testBalancedBoxesHaveNoProblems(): void {
        $this->assertSame([], FencedDivs::problems(":::: {.a}\n::: {.b}\nx\n:::\n::::"));
    }

    public function testAStrayClosingFence(): void {
        $this->assertSame(['Line 3 closes a box (:::) that was never opened.'], FencedDivs::problems("text\n\n:::"));
    }

    public function testAnUnclosedBox(): void {
        $this->assertSame(
            ['The box opened on line 2 is never closed - end it with a line of :::.'],
            FencedDivs::problems("intro\n::: {.x}\ntext")
        );
    }

    public function testABoxCannotCrossAPageBreak(): void {
        $markdown = "::: {.x}\nlead\n---\nbody\n:::";
        $problems = FencedDivs::problems($markdown, [2]);
        $this->assertSame('The box opened on line 1 is still open at the page break on line 3 - close it with ::: before the ---.', $problems[0]);
        $this->assertSame('Line 5 closes a box (:::) that was never opened.', $problems[1]);
    }

    public function testFencesInCodeAreNotBoxes(): void {
        $this->assertSame([], FencedDivs::problems("```\n:::\n::: {.x}\n```\n~~~~\n:::\n~~~~"));
    }

    public function testAnAttributeThatIsNotANameOrASecondId(): void {
        $problems = FencedDivs::problems("::: {#a #b onclick=x}\nx\n:::");
        $this->assertCount(2, $problems);
        $this->assertStringContainsString("'#b'", $problems[0]);
        $this->assertStringContainsString("'onclick=x'", $problems[1]);
    }

    public function testAnIdUsedTwice(): void {
        $this->assertSame(
            ['Line 4: the id #g is already used on line 1.'],
            FencedDivs::problems("::: {#g}\nx\n:::\n::: {#g}\ny\n:::")
        );
    }
}
