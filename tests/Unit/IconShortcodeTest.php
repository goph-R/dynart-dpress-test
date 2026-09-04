<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\Shortcode\IconShortcode;
use PHPUnit\Framework\TestCase;

/**
 * An icon in the middle of a sentence
 *
 * Raw HTML cannot be one: the renderer strips it, so `<i class="fa-solid fa-star">` in a post is a
 * gap where somebody wanted a star. That strip is what stops a compromised account putting a
 * script on every page, so the way in is a shortcode - a lookup table rather than a hole.
 *
 * **The name is validated, not escaped**, and the difference is the point. Escaping would stop the
 * value breaking out of the quotes; it would not stop a post naming a class somebody else's
 * stylesheet defines. A class attribute is not a place to put whatever arrived.
 *
 * @covers \Dynart\Dpress\Content\Shortcode\IconShortcode
 */
class IconShortcodeTest extends TestCase {

    private IconShortcode $icon;

    protected function setUp(): void {
        $this->icon = new IconShortcode();
    }

    public function testAnIconIsAnElementWithFontAwesomeClasses(): void {
        $this->assertSame(
            '<i class="fa-solid fa-star" aria-hidden="true"></i>',
            $this->icon->render(['star'])
        );
    }

    public function testTheStyleCanBeChosenEitherWay(): void {
        $expected = '<i class="fa-brands fa-rust" aria-hidden="true"></i>';
        $this->assertSame($expected, $this->icon->render(['rust', 'brands']));
        $this->assertSame($expected, $this->icon->render(['rust', 'style' => 'brands']));
    }

    /**
     * `fa-github` is how the name is written everywhere an author would have read it, and
     * `fa-fa-github` is a silent nothing rather than an error
     */
    public function testALeadingFaIsDropped(): void {
        $this->assertStringContainsString('fa-brands fa-github', $this->icon->render(['fa-github', 'brands']));
    }

    // --- what it says, and to whom ---

    /**
     * An icon beside a word that already says the same thing is decoration, and a screen reader
     * announcing "star star" is worse than one that says it once
     */
    public function testAnIconWithNoLabelIsSilent(): void {
        $this->assertStringContainsString('aria-hidden="true"', $this->icon->render(['star']));
        $this->assertStringNotContainsString('role=', $this->icon->render(['star']));
    }

    /**
     * A label is the author saying this one carries the meaning on its own - and then it has to be
     * a thing rather than a picture
     */
    public function testALabelMakesItAThing(): void {
        $html = $this->icon->render(['star', 'label' => 'Favourite']);
        $this->assertStringContainsString('role="img"', $html);
        $this->assertStringContainsString('aria-label="Favourite"', $html);
        $this->assertStringNotContainsString('aria-hidden', $html);
    }

    public function testALabelIsEscaped(): void {
        $html = $this->icon->render(['star', 'label' => 'A "quote" & <tag>']);
        $this->assertStringNotContainsString('<tag>', $html);
        $this->assertStringContainsString('&amp;', $html);
        $this->assertSame(1, substr_count($html, '></i>'), 'the attribute must not have been broken out of');
    }

    // --- and what it refuses ---

    /**
     * The one that matters: a post must not be able to name a class
     */
    public function testANameThatIsNotAnIconNameIsRefused(): void {
        foreach (['star" onmouseover="x', 'star extra', 'star!', '../star', 'st ar',
                  'star-', '-star', 'fa-'] as $name) {
            $html = $this->icon->render([$name]);
            $this->assertStringStartsWith('<!-- icon:', $html, $name);
            $this->assertStringNotContainsString('<i ', $html, $name);
        }
    }

    /**
     * Case is not a mistake worth an error - somebody who wrote `STAR` meant a star
     */
    public function testTheNameAndTheStyleAreCaseInsensitive(): void {
        $this->assertStringContainsString('fa-brands fa-github',
            $this->icon->render(['GitHub', 'style' => 'Brands']));
    }

    public function testAStyleThatIsNotAStyleIsRefused(): void {
        $html = $this->icon->render(['star', 'style' => 'wobbly']);
        $this->assertStringStartsWith('<!-- icon:', $html);
        $this->assertStringNotContainsString('fa-wobbly', $html);
    }

    /**
     * A comment rather than nothing, the same as the video shortcode: the page still renders, and
     * whoever looks at the source finds out why
     */
    public function testWhatItCannotDoIsSaidInTheSource(): void {
        $this->assertStringContainsString('needs a name', $this->icon->render([]));
    }

    /**
     * Every style Font Awesome has, so a Pro site is not held to the Free families
     */
    public function testEveryListedStyleWorks(): void {
        foreach (IconShortcode::STYLES as $style) {
            $this->assertStringContainsString(
                'fa-'.$style.' fa-star', $this->icon->render(['star', 'style' => $style]), $style
            );
        }
    }
}
