<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Controller\Admin\AssetController;
use Dynart\Dpress\DpressServices;
use Dynart\Dpress\Plugin\AbstractPlugin;
use Dynart\Dpress\Theme\PageAssets;
use Dynart\Dpress\Theme\ThemeAssets;
use PHPUnit\Framework\TestCase;

/**
 * What a page puts in its own head
 *
 * The registry that lets a plugin reach a visitor. Everything here is about the one property that
 * makes it safe to have at all: **a page that does not need a file does not load it**, so a site
 * with three plugins enabled and none of them on this page pays nothing for them.
 *
 * @covers \Dynart\Dpress\Theme\PageAssets
 */
class PageAssetsTest extends TestCase {

    private PageAssets $assets;

    protected function setUp(): void {
        $this->assets = new PageAssets();
    }

    public function testAStylesheetIsALinkWithItsUrlEscaped(): void {
        $this->assets->addStyle('one', 'https://example.com/a.css?v=1&x=2');
        $this->assertSame(
            '<link rel="stylesheet" href="https://example.com/a.css?v=1&amp;x=2">',
            $this->assets->tags('<html></html>')
        );
    }

    /**
     * Nothing on a dpress page waits for a script, and a blocking one in the head is the most
     * reliable way to make a fast site feel slow
     */
    public function testAScriptIsDeferredUnlessSaidOtherwise(): void {
        $this->assets->addScript('one', '/a.js');
        $this->assertStringContainsString(' defer>', $this->assets->tags(''));

        $this->assets->addScript('one', '/a.js', '', false);
        $this->assertStringNotContainsString('defer', $this->assets->tags(''));
    }

    // --- the needle, which is the whole point ---

    public function testAFileWithANeedleIsLeftOutOfAPageThatDoesNotMatchIt(): void {
        $this->assets->addStyle('icons', '/icons.css', 'class="fa-');
        $this->assertSame('', $this->assets->tags('<p>a page with no icons on it</p>'));
        $this->assertStringContainsString(
            '/icons.css', $this->assets->tags('<p><i class="fa-solid"></i></p>')
        );
    }

    public function testAnEmptyNeedleIsEveryPage(): void {
        $this->assets->addStyle('site', '/site.css');
        $this->assertStringContainsString('/site.css', $this->assets->tags(''));
    }

    /**
     * A callable is the escape hatch for markup that has to be worked out, and it must not be
     * *called* on a page its needle rules out - that is what keeps a registration free
     */
    public function testACallableIsNotRunWhenItsNeedleDoesNotMatch(): void {
        $ran = false;
        $this->assets->add('late', function () use (&$ran) {
            $ran = true;
            return '<link rel="stylesheet" href="/late.css">';
        }, 'needle');

        $this->assets->tags('<p>nothing here</p>');
        $this->assertFalse($ran, 'the callable ran for a page that does not want it');

        $this->assertStringContainsString('/late.css', $this->assets->tags('<p>needle</p>'));
        $this->assertTrue($ran);
    }

    public function testACallableIsHandedThePageAndItsAnswerIsUsed(): void {
        $this->assets->add('seen', fn(string $html) => '<!-- '.strlen($html).' -->');
        $this->assertSame('<!-- 5 -->', $this->assets->tags('12345'));
    }

    /**
     * A callable answering with nothing is a thing that decided it was not wanted after all -
     * which is what the highlighter does when highlighting is switched off
     */
    public function testACallableThatAnswersWithNothingAddsNothing(): void {
        $this->assets->add('quiet', fn() => '');
        $this->assets->addStyle('loud', '/loud.css');
        $this->assertSame('<link rel="stylesheet" href="/loud.css">', $this->assets->tags(''));
    }

    // --- the registry ---

    public function testRegisteringTheSameNameTwiceReplacesRatherThanRepeats(): void {
        $this->assets->addStyle('one', '/first.css');
        $this->assets->addStyle('one', '/second.css');
        $this->assertSame(['one'], $this->assets->names());
        $this->assertStringNotContainsString('/first.css', $this->assets->tags(''));
    }

    public function testTheOrderIsTheOrderTheyWereRegisteredIn(): void {
        $this->assets->addStyle('a', '/a.css');
        $this->assets->addStyle('b', '/b.css');
        $tags = $this->assets->tags('');
        $this->assertLessThan(strpos($tags, '/b.css'), strpos($tags, '/a.css'));
    }

    public function testOneCanBeTakenOffAgain(): void {
        $this->assets->addStyle('a', '/a.css');
        $this->assertTrue($this->assets->has('a'));
        $this->assets->remove('a');
        $this->assertFalse($this->assets->has('a'));
        $this->assertSame('', $this->assets->tags(''));
    }

    // --- core's own entry ---

    /**
     * The highlighter is registered the way a plugin registers its own, which is the test that
     * the mechanism is one mechanism
     */
    public function testTheHighlighterIsJustAnotherEntry(): void {
        DpressServices::registerPageAssets($this->assets);
        $this->assertTrue($this->assets->has('code'));
        // and it is not built for a page with no code block on it
        $this->assertSame('', $this->assets->tags('<p>prose</p>'));
    }

    // --- what a plugin may put in front of a browser ---

    /**
     * The same list a theme is held to, because it is the same question - and a plugin shipping an
     * icon set cannot do without fonts, which a theme has always been able to serve
     */
    public function testAPluginMayServeWhateverAThemeMay(): void {
        $this->assertSame(ThemeAssets::TYPES, AssetController::PLUGIN_TYPES);
        foreach (['woff2', 'woff', 'css', 'js', 'svg', 'png'] as $extension) {
            $this->assertArrayHasKey($extension, AssetController::PLUGIN_TYPES);
        }
    }

    /**
     * Not under `/admin`: a stylesheet a visitor loads should not have that word in its address,
     * which invites a firewall rule that takes the site's icons out with the admin
     */
    public function testAPluginsAssetsAreOnTheFrontEnd(): void {
        $this->assertStringStartsWith('/assets/', AssetController::PLUGIN_ROUTE);
        $this->assertStringNotContainsString('admin', AssetController::PLUGIN_ROUTE);
    }

    // --- the contract ---

    public function testAPluginContributesNothingByDefault(): void {
        $plugin = new class extends AbstractPlugin {};
        $this->assertSame([], $plugin->blocks());
        $this->assertSame([], $plugin->pageAssets());
    }
}
