<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\Autolinks;
use Dynart\Dpress\Content\MarkdownRenderer;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Test\RecordingEvents;
use PHPUnit\Framework\TestCase;

/**
 * A bare URL in prose, turned into a link
 *
 * Somebody writing `https://example.com` in a sentence meant a link, and making them write it
 * twice is a tax on the common case - so it is on unless a site turns it off.
 *
 * **The rule that matters is the one about code.** A post explaining how to write something is
 * full of URLs that are examples rather than destinations, and a fenced block or a code span
 * rewritten into markup is a document saying something its author did not write. It holds because
 * of *where* this is plugged in - an inline parser runs where inline markup runs, and inside code
 * nothing does - which is exactly why it is worth a test: nothing in the class would fail if that
 * stopped being true.
 *
 * @covers \Dynart\Dpress\Content\Autolinks
 * @covers \Dynart\Dpress\Content\HttpAutolinkParser
 */
class AutolinkTest extends TestCase {

    private function renderer(?bool $on = null): MarkdownRenderer {
        $settings = new PlacesSettings();
        if ($on !== null) {
            $settings->values[Setting::AUTOLINK] = $on ? '1' : '0';
        }
        $events = new RecordingEvents();
        $autolinks = new Autolinks($settings);
        $events->subscribe(MarkdownRenderer::EVENT_ENVIRONMENT, [$autolinks, 'onEnvironment']);
        return new MarkdownRenderer($events);
    }

    // --- prose ---

    public function testABareUrlBecomesALink(): void {
        $html = $this->renderer()->render('See https://example.com for more.');
        $this->assertStringContainsString('<a href="https://example.com">https://example.com</a>', $html);
    }

    public function testHttpAsWellAsHttps(): void {
        $html = $this->renderer()->render('Old school: http://example.com/x');
        $this->assertStringContainsString('href="http://example.com/x"', $html);
    }

    /**
     * A URL at the end of a sentence is the normal case, and the full stop is not part of it
     */
    public function testTrailingPunctuationIsLeftOutOfTheLink(): void {
        $html = $this->renderer()->render('Read https://example.com/page.');
        $this->assertStringContainsString('href="https://example.com/page"', $html);
        $this->assertStringContainsString('</a>.', $html);
    }

    /**
     * A URL somebody already wrote as a link must not be linked twice
     */
    public function testAUrlThatIsAlreadyALinkIsLeftAlone(): void {
        $html = $this->renderer()->render('[the site](https://example.com)');
        $this->assertSame(1, substr_count($html, '<a '), $html);
        $this->assertStringContainsString('>the site</a>', $html);
    }

    // --- and code, which is the whole point ---

    public function testAUrlInAFencedBlockStaysText(): void {
        $html = $this->renderer()->render("```\ncurl https://example.com\n```");
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('curl https://example.com', $html);
    }

    public function testAUrlInACodeSpanStaysText(): void {
        $html = $this->renderer()->render('Run `curl https://example.com` first.');
        $this->assertStringNotContainsString('<a ', $html);
    }

    public function testAUrlInAnIndentedBlockStaysText(): void {
        $html = $this->renderer()->render("Try this:\n\n    curl https://example.com\n");
        $this->assertStringNotContainsString('<a ', $html);
    }

    // --- what it deliberately does not do ---

    /**
     * CommonMark's own autolinker does three things rather than one. An email address written in a
     * post as a fact about somebody must not quietly become a `mailto:` - that is a decision the
     * author did not make, and it was not what was asked for.
     */
    public function testAnEmailAddressIsNotTouched(): void {
        $html = $this->renderer()->render('Write to nobody@example.com about it.');
        $this->assertStringNotContainsString('mailto:', $html);
    }

    public function testABareWwwHostIsNotTouched(): void {
        $html = $this->renderer()->render('See www.example.com for more.');
        $this->assertStringNotContainsString('<a ', $html);
    }

    // --- the setting ---

    /**
     * On for a site that has never said, because writing a URL in a sentence already meant a link
     */
    public function testItIsOnByDefault(): void {
        $this->assertTrue((new Autolinks(new PlacesSettings()))->enabled());
        $this->assertStringContainsString('<a ', $this->renderer()->render('https://example.com'));
    }

    public function testTurningItOffLeavesTheTextAlone(): void {
        $html = $this->renderer(false)->render('See https://example.com for more.');
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('https://example.com', $html);
    }

    /**
     * Off must not take the links somebody wrote by hand with it
     */
    public function testAWrittenLinkSurvivesTheSettingBeingOff(): void {
        $html = $this->renderer(false)->render('[the site](https://example.com)');
        $this->assertStringContainsString('<a href="https://example.com">the site</a>', $html);
    }
}
