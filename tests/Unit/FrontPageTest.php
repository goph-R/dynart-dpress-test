<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Controller\HomeController;
use PHPUnit\Framework\TestCase;

/**
 * Where the Front page setting may send the front page
 *
 * The setting is a redirect target, so what matters is what it may *not* be: another site.
 *
 * @covers \Dynart\Dpress\Controller\HomeController::frontPage
 */
class FrontPageTest extends TestCase {

    public function testEmptyIsTheLatestPosts(): void {
        $this->assertSame('', HomeController::frontPage(''));
        $this->assertSame('', HomeController::frontPage('   '));
    }

    public function testAnAddressOnThisSite(): void {
        $this->assertSame('/docs', HomeController::frontPage('/docs'));
        $this->assertSame('/docs/dos-game-engine', HomeController::frontPage(' /docs/dos-game-engine/ '));
    }

    /** The front page sent to itself would be a loop */
    public function testTheFrontPageItselfIsTheLatestPosts(): void {
        $this->assertSame('', HomeController::frontPage('/'));
    }

    /**
     * Each of these would send a visitor off the site from its own front page
     */
    public function testNothingThatCouldLeaveTheSite(): void {
        foreach (['https://elsewhere.example', '//elsewhere.example', '/\\elsewhere.example',
                  'docs', 'javascript:alert(1)', "/docs\nLocation: x", '/do cs'] as $setting) {
            $this->assertSame('', HomeController::frontPage($setting), $setting);
        }
    }
}
