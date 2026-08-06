<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\MarkdownRenderer;
use Dynart\Dpress\Test\RecordingEvents;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Dynart\Dpress\Content\MarkdownRenderer
 */
class MarkdownRendererTest extends TestCase {

    private RecordingEvents $events;
    private MarkdownRenderer $renderer;

    protected function setUp(): void {
        $this->events = new RecordingEvents();
        $this->renderer = new MarkdownRenderer($this->events);
    }

    // --- The lead / body split ---

    public function testSplitsOnTheFirstSeparatorLine(): void {
        $parts = $this->renderer->split("The lead.\n\n---\n\nThe body.");
        $this->assertSame('The lead.', $parts['lead']);
        $this->assertSame('The body.', $parts['body']);
    }

    public function testEverythingIsLeadWithoutASeparator(): void {
        $parts = $this->renderer->split("Just a short note.");
        $this->assertSame('Just a short note.', $parts['lead']);
        $this->assertSame('', $parts['body']);
    }

    /**
     * A `---` on the very first line is opening YAML front matter, not a lead break. Treating it
     * as one would give every such document an empty lead.
     */
    public function testASeparatorOnTheFirstLineIsNotALeadBreak(): void {
        $parts = $this->renderer->split("---\ntitle: x\n---\n\nThe body.");
        $this->assertNotSame('', $parts['lead']);
        $this->assertStringContainsString('title: x', $parts['lead']);
    }

    public function testOnlyTheFirstSeparatorSplits(): void {
        $parts = $this->renderer->split("Lead.\n\n---\n\nBody one.\n\n---\n\nBody two.");
        $this->assertSame('Lead.', $parts['lead']);
        $this->assertStringContainsString('Body one.', $parts['body']);
        $this->assertStringContainsString('---', $parts['body'], 'the later separator stays in the body');
    }

    /**
     * `---` underlining text is a setext heading, and `- - -` with spaces is a horizontal rule.
     * Only a line that is exactly the separator counts.
     */
    public function testALineThatMerelyContainsDashesDoesNotSplit(): void {
        $this->assertSame('', $this->renderer->split("Lead.\n\n----\n\nStill lead.")['body']);
        $this->assertSame('', $this->renderer->split("Lead.\n\n- - -\n\nStill lead.")['body']);
        $this->assertSame('', $this->renderer->split("Lead.\n\n--\n\nStill lead.")['body']);
    }

    public function testTrailingWhitespaceOnTheSeparatorIsTolerated(): void {
        $parts = $this->renderer->split("Lead.\n---   \nBody.");
        $this->assertSame('Body.', $parts['body']);
    }

    public function testWindowsLineEndings(): void {
        $parts = $this->renderer->split("Lead.\r\n\r\n---\r\n\r\nBody.");
        $this->assertSame('Lead.', $parts['lead']);
        $this->assertSame('Body.', $parts['body']);
    }

    public function testHasLeadSeparator(): void {
        $this->assertTrue($this->renderer->hasLeadSeparator("a\n---\nb"));
        $this->assertFalse($this->renderer->hasLeadSeparator("just a note"));
    }

    // --- Rendering ---

    public function testRendersMarkdown(): void {
        $this->assertStringContainsString('<strong>bold</strong>', $this->renderer->render('**bold**'));
    }

    public function testRendersNothingForAnEmptyString(): void {
        $this->assertSame('', $this->renderer->render(''));
    }

    public function testRenderSplitRendersBothParts(): void {
        $rendered = $this->renderer->renderSplit("The **lead**.\n\n---\n\nThe *body*.");
        $this->assertStringContainsString('<strong>lead</strong>', $rendered['lead']);
        $this->assertStringContainsString('<em>body</em>', $rendered['body']);
    }

    /**
     * The markdown is written by editors, but a compromised account should not be able to put a
     * script on every page of the site.
     */
    public function testRawHtmlIsStripped(): void {
        $html = $this->renderer->render('Hello <script>alert(1)</script> there');
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testJavascriptLinksAreNotAllowed(): void {
        $html = $this->renderer->render('[click](javascript:alert(1))');
        $this->assertStringNotContainsString('javascript:', $html);
    }

    // --- Events ---

    public function testRenderingEmitsItsEvents(): void {
        $this->renderer->render('hello');
        $this->assertContains(MarkdownRenderer::EVENT_BEFORE_RENDER, $this->events->emitted);
        $this->assertContains(MarkdownRenderer::EVENT_RENDERED, $this->events->emitted);
    }

    public function testNoEventsForAnEmptyString(): void {
        $this->renderer->render('');
        $this->assertSame([], $this->events->emitted);
    }

    /**
     * The seam the CMS reaches the renderer through
     *
     * This class knows nothing about media or posts, and resolving `media#12` needs both. So the
     * environment is handed out before the converter is sealed, and a subscriber adds what it
     * needs. Emitted lazily, on the first render - which on a page view never happens, because
     * the HTML was written at save time.
     */
    public function testTheEnvironmentIsOfferedBeforeTheConverterIsBuilt(): void {
        $seen = null;
        $this->events->subscribe(MarkdownRenderer::EVENT_ENVIRONMENT, function ($environment) use (&$seen) {
            $seen = $environment;
        });
        $this->assertSame([], $this->events->emitted, 'the converter was built before anything asked for one');
        $this->renderer->render('hello');
        $this->assertInstanceOf(EnvironmentBuilderInterface::class, $seen);
    }

    /**
     * Built once and kept, so a subscriber cannot be handed a second environment that the
     * converter in use knows nothing about
     */
    public function testTheEnvironmentIsOfferedOnlyOnce(): void {
        $this->renderer->render('one');
        $this->renderer->render('two');
        $this->assertSame(
            1, count(array_filter($this->events->emitted, fn($e) => $e === MarkdownRenderer::EVENT_ENVIRONMENT))
        );
    }
}
