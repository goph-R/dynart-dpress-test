<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\ShortcodeArguments;
use Dynart\Dpress\Content\ShortcodeRenderer;
use Dynart\Dpress\Content\Shortcodes;
use Dynart\Dpress\DpressServices;
use Dynart\Dpress\Test\StubLogger;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use PHPUnit\Framework\TestCase;

/**
 * `{{ name(args) }}` - what the parser claims, and what it deliberately does not
 *
 * The shortcode is parsed at **save** and run at **view**. What goes into `body_html` is a marker
 * carrying the call; `Shortcodes::expand()` swaps markers for output on the way to the page. That
 * split is the whole design: the markdown is still rendered once, and a gallery whose contents
 * change does not need every post that embeds it re-rendered.
 *
 * The single most important test in here is that a shortcode inside a code span is left alone.
 * A regular expression over the markdown - which is how WordPress does this - cannot tell the
 * difference, and a CMS whose own documentation cannot be written in it has a bad idea in it.
 *
 * @covers \Dynart\Dpress\Content\Shortcodes
 * @covers \Dynart\Dpress\Content\ShortcodeParser
 * @covers \Dynart\Dpress\Content\ShortcodeArguments
 * @covers \Dynart\Dpress\Content\ShortcodeRenderer
 */
class ShortcodeTest extends TestCase {

    private Shortcodes $shortcodes;
    private StubLogger $logger;

    protected function setUp(): void {
        $this->logger = new StubLogger();
        $this->shortcodes = new Shortcodes($this->logger);
        $this->shortcodes->add('video', fn(array $a) => '<video data-src="'.($a[0] ?? '').'"></video>', Shortcodes::BLOCK);
        $this->shortcodes->add('icon', fn(array $a) => '<i>'.($a['name'] ?? '?').'</i>', Shortcodes::INLINE);
    }

    /** The markdown, rendered exactly as the CMS renders it */
    private function render(string $markdown): string {
        $environment = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension());
        (new ShortcodeRenderer($this->shortcodes))->onEnvironment($environment);
        return trim((string)(new MarkdownConverter($environment))->convert($markdown));
    }

    /** The markdown, rendered and then expanded - what a visitor sees */
    private function page(string $markdown): string {
        return $this->shortcodes->expand($this->render($markdown));
    }

    // --- what the parser must not touch ---

    /**
     * The reason this is an inline parser and not a regular expression
     */
    public function testAShortcodeInACodeSpanIsLeftAlone(): void {
        $html = $this->page("Write `{{ video('media#13') }}` to embed one.");
        $this->assertStringContainsString("<code>{{ video('media#13') }}</code>", $html);
        $this->assertStringNotContainsString('<video', $html);
    }

    public function testAShortcodeInAFencedBlockIsLeftAlone(): void {
        $html = $this->page("```\n{{ video('media#13') }}\n```");
        $this->assertStringContainsString("{{ video('media#13') }}", $html);
        $this->assertStringNotContainsString('<video', $html);
    }

    /**
     * `{` is ASCII punctuation, so CommonMark's own backslash escapes already cover this - there
     * is nothing in the parser for it, and this is here to notice if that ever stops being true
     */
    public function testBackslashEscapingWorksWithoutHelp(): void {
        $html = $this->page('Literal \\{\\{ video() }} here.');
        $this->assertStringContainsString('{{ video() }}', $html);
        $this->assertStringNotContainsString('<video', $html);
    }

    /**
     * A document is written before the plugin providing a shortcode is installed as often as
     * after. Leaving the text is what lets that document be saved and then start working.
     */
    public function testAnUnknownNameStaysAsTheAuthorTypedIt(): void {
        $html = $this->page('{{ nosuchthing() }}');
        $this->assertStringContainsString('{{ nosuchthing() }}', $html);
        $this->assertStringNotContainsString('dpress-sc', $html);
    }

    public function testMalformedArgumentsStayAsText(): void {
        // a bare word is not a value in this grammar, and guessing where one ends is where a
        // small grammar stops being small
        $this->assertStringContainsString('{{ icon(name=large) }}', $this->page('{{ icon(name=large) }}'));
    }

    // --- block and inline ---

    /**
     * A marker inside a `<p>` expands to a `<video>` inside a `<p>`, which is untidy - and to a
     * `<figure>` inside a `<p>`, which is invalid and gets rearranged by the browser
     */
    public function testABlockShortcodeAloneInAParagraphLeavesTheParagraph(): void {
        $html = $this->page("Before.\n\n{{ video('media#13') }}\n\nAfter.");
        $this->assertStringContainsString('</p>', $html);
        $this->assertStringNotContainsString('<p><video', $html);
        $this->assertStringContainsString('<video data-src="media#13"></video>', $html);
    }

    /**
     * A block shortcode among words is an author asking for something that cannot be done, and
     * tearing their paragraph in half is a worse answer than rendering it where they put it
     */
    public function testABlockShortcodeAmongWordsStaysWhereItIs(): void {
        $html = $this->page("Some words {{ video('media#13') }} and more.");
        $this->assertStringContainsString('<p>Some words <video', $html);
    }

    public function testAnInlineShortcodeStaysInline(): void {
        $this->assertStringContainsString('<p>An <i>star</i> here.</p>', $this->page("An {{ icon(name='star') }} here."));
    }

    // --- the marker, which is the point of the design ---

    /**
     * The handler does not run at save. What is stored is the call, so the output can change
     * without the post being touched - which is the reason shortcodes are not baked.
     */
    public function testWhatIsStoredIsTheCallRatherThanTheOutput(): void {
        $html = $this->render("{{ video('media#13') }}");
        $this->assertStringContainsString('<!--dpress-sc ', $html);
        $this->assertStringNotContainsString('<video', $html);
        $this->assertStringContainsString('<video data-src="media#13"></video>', $this->shortcodes->expand($html));
    }

    /**
     * The whole performance story: a page with nothing in it must not pay for a regular expression
     */
    public function testExpandingHtmlWithNoMarkerReturnsItUntouched(): void {
        $html = '<p>Just a page.</p>';
        $this->assertSame($html, $this->shortcodes->expand($html));
        $this->assertSame('', $this->shortcodes->expand(null));
    }

    /**
     * `-->` in an argument would end the comment early and spill the rest of the post onto the
     * page, which is why the payload is encoded rather than written out
     */
    public function testAnArgumentCannotBreakOutOfItsMarker(): void {
        $marker = $this->shortcodes->marker('video', ['--> <script>alert(1)</script>']);
        $this->assertSame(1, preg_match_all(Shortcodes::MARKER_PATTERN, $marker));
        $this->assertStringNotContainsString('<script', $marker);
    }

    /**
     * A plugin switched off this morning leaves posts that mention its shortcode. They are pages
     * with one thing missing, not broken content - the same answer `FormWidgets` gives.
     */
    public function testAShortcodeThatIsGoneByViewTimeLeavesACommentAndALine(): void {
        $marker = $this->shortcodes->marker('was_a_plugin', []);
        $this->assertStringContainsString('<!-- no shortcode called was_a_plugin -->', $this->shortcodes->expand($marker));
        $this->assertNotEmpty($this->logger->lines, 'nothing was logged, so nobody finds out');
    }

    // --- the argument grammar ---

    public function testPositionalAndNamedArgumentsLandWhereAHandlerLooks(): void {
        $this->assertSame(['media#13'], ShortcodeArguments::parse("('media#13')"));
        $this->assertSame(['limit' => 6], ShortcodeArguments::parse('(limit=6)'));
        $this->assertSame([0 => 'a', 'size' => 'l'], ShortcodeArguments::parse("('a', size='l')"));
    }

    public function testTheLiteralsItKnows(): void {
        $this->assertSame([true, false, null], ShortcodeArguments::parse('(true, false, null)'));
        $this->assertSame([3, -2, 1.5], ShortcodeArguments::parse('(3, -2, 1.5)'));
        $this->assertSame(['it, works'], ShortcodeArguments::parse("('it, works')"), 'a comma inside quotes split the arguments');
        $this->assertSame(["it's"], ShortcodeArguments::parse("('it\\'s')"));
    }

    public function testNoArgumentsAtAll(): void {
        $this->assertSame([], ShortcodeArguments::parse(''));
        $this->assertSame([], ShortcodeArguments::parse('()'));
    }

    public function testWhatItRefuses(): void {
        $this->assertNull(ShortcodeArguments::parse('(bare)'), 'an unquoted word was accepted');
        $this->assertNull(ShortcodeArguments::parse("('a',,'b')"), 'a missing argument was accepted');
        $this->assertNull(ShortcodeArguments::parse("'a'"), 'text with no brackets was accepted');
    }

    // --- the core registration ---

    /**
     * Registered through exactly the call a plugin uses, so the mechanism is one the core eats
     */
    public function testTheCmsRegistersItsOwnThroughThePublicCall(): void {
        $shortcodes = new Shortcodes(new StubLogger());
        DpressServices::registerShortcodes($shortcodes);
        $this->assertTrue($shortcodes->has('video'));
        $this->assertSame(Shortcodes::BLOCK, $shortcodes->kind('video'), 'a video inside a paragraph is not what anybody meant');
    }
}
