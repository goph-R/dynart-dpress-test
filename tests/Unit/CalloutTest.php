<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\Callouts;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use PHPUnit\Framework\TestCase;

/**
 * `> [!WARNING]` turns a blockquote into a panel
 *
 * GitHub's syntax, chosen because it is **valid CommonMark either way**: anywhere without this -
 * a README, an editor preview, a document exported from here - it is still a blockquote, still
 * readable, with a visible marker where the styling would have been. A convention that only works
 * inside one CMS is a convention that breaks the moment a document leaves it.
 *
 * And the content is markdown, because CommonMark parsed it before any of this ran. A shortcode
 * could not do that: `{{ warning('…') }}` takes a string, and a panel holds prose.
 *
 * @covers \Dynart\Dpress\Content\Callouts
 */
class CalloutTest extends TestCase {

    private function render(string $markdown): string {
        $environment = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension());
        (new Callouts())->onEnvironment($environment);
        return trim((string)(new MarkdownConverter($environment))->convert($markdown));
    }

    /**
     * Every blockquote gets a class, marker or not - a stylesheet that has to say
     * `blockquote:not(.callout-info):not(.callout-warning)…` to reach a plain one is a stylesheet
     * nobody wants to add the next kind to
     */
    public function testAPlainBlockquoteIsAPanelToo(): void {
        $this->assertStringContainsString(
            '<blockquote class="callout callout-quote">', $this->render('> Just a quote.')
        );
    }

    public function testTheFiveGitHubMarkersAndTheTwoPeopleGuess(): void {
        foreach (['NOTE' => 'info', 'TIP' => 'info', 'IMPORTANT' => 'info', 'INFO' => 'info',
                  'WARNING' => 'warning', 'CAUTION' => 'danger', 'DANGER' => 'danger'] as $marker => $kind) {
            $this->assertStringContainsString(
                'callout-'.$kind, $this->render("> [!$marker]\n> Text."),
                "[!$marker] should look like a $kind"
            );
        }
    }

    public function testTheMarkerItselfIsNotShown(): void {
        $html = $this->render("> [!WARNING]\n> Do not do this.");
        $this->assertStringNotContainsString('[!WARNING]', $html);
        $this->assertStringContainsString('<p>Do not do this.</p>', $html);
    }

    /**
     * `> [!WARNING]\n> text` parses as one paragraph - marker, newline, text - and the newline
     * left behind is a blank first line inside the panel
     */
    public function testThePanelDoesNotOpenWithAnEmptyLine(): void {
        $html = $this->render("> [!NOTE]\n> First line.");
        $this->assertStringContainsString('<p>First line.</p>', $html);
        $this->assertStringNotContainsString("<p>\n", $html);
    }

    /**
     * Written on one line there is no newline to remove, only a prefix to strip
     */
    public function testAMarkerAndItsTextOnOneLine(): void {
        $html = $this->render('> [!TIP] All on one line.');
        $this->assertStringContainsString('callout-info', $html);
        $this->assertStringContainsString('<p>All on one line.</p>', $html);
    }

    /**
     * The content is markdown and always was - this is the reason for using a blockquote rather
     * than inventing a syntax or reaching for a shortcode
     */
    public function testWhatIsInsideIsStillMarkdown(): void {
        $html = $this->render("> [!NOTE]\n> **Bold**, a [link](https://example.com) and `code`.\n>\n> A second paragraph.");
        $this->assertStringContainsString('<strong>Bold</strong>', $html);
        $this->assertStringContainsString('<a href="https://example.com">link</a>', $html);
        $this->assertStringContainsString('<code>code</code>', $html);
        $this->assertSame(2, substr_count($html, '<p>'), 'a panel holds as many paragraphs as it was given');
    }

    /**
     * A marker nobody registered is somebody's own text, not a broken panel
     */
    public function testAnUnknownMarkerIsLeftExactlyAsWritten(): void {
        $html = $this->render("> [!NOPE]\n> Text.");
        $this->assertStringContainsString('callout-quote', $html);
        $this->assertStringContainsString('[!NOPE]', $html);
    }

    /**
     * The marker is only a marker at the very start. `> See [!WARNING] below` is a sentence.
     */
    public function testAMarkerInTheMiddleOfASentenceIsJustText(): void {
        $html = $this->render('> See [!WARNING] in the docs.');
        $this->assertStringContainsString('callout-quote', $html);
        $this->assertStringContainsString('[!WARNING]', $html);
    }

    public function testTheMarkerIsNotCaseSensitive(): void {
        $this->assertStringContainsString('callout-warning', $this->render("> [!warning]\n> Text."));
    }

    /**
     * A panel inside a panel is somebody quoting one, and each keeps its own kind
     */
    public function testNestedQuotesEachGetTheirOwn(): void {
        $html = $this->render("> [!NOTE]\n> Outer.\n>\n> > Inner quote.");
        $this->assertStringContainsString('callout-info', $html);
        $this->assertStringContainsString('callout-quote', $html);
    }
}
