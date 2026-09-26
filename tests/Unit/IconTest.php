<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Dpress;
use PHPUnit\Framework\TestCase;

/**
 * The admin's icons, as a template asks for them
 *
 * @covers \Dynart\Dpress\Dpress
 */
class IconTest extends TestCase {

    /**
     * The buttons draw these, so each has to be a drawing of its own and not the fallback
     */
    public function testTheButtonIconsAreThere(): void {
        $fallback = Dpress::icon('section');
        foreach (['back', 'plus', 'upload', 'save', 'eye', 'scroll', 'restore', 'delete'] as $name) {
            $icon = Dpress::icon($name);
            $this->assertStringStartsWith('<svg', $icon, $name);
            $this->assertNotSame($fallback, $icon, "$name fell back to the generic mark");
        }
    }

    /**
     * An icon the admin does not have is the generic mark, and only the mark: the fallback file
     * carries a PHP comment saying what it is for, which must not reach the page
     */
    public function testAMissingIconIsTheFallbackAndNothingElse(): void {
        $icon = Dpress::icon('no-such-icon');
        $this->assertSame(Dpress::icon('section'), $icon);
        $this->assertStringStartsWith('<svg', $icon);
        $this->assertStringNotContainsString('<?php', $icon);
    }

    /**
     * The name is part of a path, so anything that is not a plain name is the fallback too
     */
    public function testANameThatIsAPathIsNotFollowed(): void {
        $this->assertSame(Dpress::icon('section'), Dpress::icon('../src/Dpress'));
    }
}
