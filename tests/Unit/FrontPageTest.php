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

    // --- a page as the front page (0.97.0) ---

    private function content(string $setting): \Dynart\Dpress\Service\ContentService {
        $settings = new PlacesSettings();
        $settings->values[\Dynart\Dpress\Entity\Setting::FRONT_PAGE] = $setting;
        // `ancestors()` and `findBySlug()` go to the database; a root-level page has no ancestors
        // and one page is all this needs to find
        $service = new class extends \Dynart\Dpress\Service\ContentService {
            public array $pages = [];
            public function __construct() {}
            public function ancestors(\Dynart\Dpress\Entity\Content $content): array { return []; }
            public function findBySlug(string $slug, bool $publishedOnly = true): ?\Dynart\Dpress\Entity\Content {
                return $this->pages[$slug] ?? null;
            }
            public function give(object $settings): void { $this->settings = $settings; }
        };
        $service->give($settings);
        return $service;
    }

    private function page(string $slug, string $type = 'page', string $status = 'published'): \Dynart\Dpress\Entity\Content {
        $content = new \Dynart\Dpress\Entity\Content();
        $content->id = 3;
        $content->type = $type;
        $content->slug = $slug;
        $content->status = $status;
        return $content;
    }

    /** The rule moved to the service two controllers ask; the old name still answers */
    public function testTheOldNameAndTheNewAgree(): void {
        foreach (['', '/', '/docs', ' /about/ ', '//elsewhere.example', 'docs'] as $setting) {
            $this->assertSame(
                \Dynart\Dpress\Service\ContentService::frontPageAddress($setting),
                HomeController::frontPage($setting), $setting
            );
        }
    }

    public function testAPageAtTheAddressIsTheFrontPage(): void {
        $service = $this->content('/player');
        $service->pages['player'] = $this->page('player');
        $this->assertSame($service->pages['player'], $service->frontPage());
        $this->assertTrue($service->isFrontPage($service->pages['player']));
    }

    public function testNoSettingIsNoPage(): void {
        $service = $this->content('');
        $service->pages['player'] = $this->page('player');
        $this->assertNull($service->frontPage());
        $this->assertFalse($service->isFrontPage($service->pages['player']));
    }

    /** `/docs` is a plugin's screen: nothing to render, so the redirect it always was */
    public function testAnAddressNoPageLivesAtIsNotOne(): void {
        $this->assertNull($this->content('/docs')->frontPage());
    }

    /** A post is not rendered at `/` even when posts live at the root: the front of a site is a page */
    public function testAPostIsNotTheFrontPage(): void {
        $service = $this->content('/hello');
        $service->pages['hello'] = $this->page('hello', \Dynart\Dpress\Entity\Content::TYPE_POST);
        $this->assertNull($service->frontPage());
        $this->assertFalse($service->isFrontPage($service->pages['hello']));
    }

    /** Only where the page really lives - any other spelling is the redirect, and that one corrects it */
    public function testAnAddressThatIsNotThePagesOwnIsNotOne(): void {
        $service = $this->content('/old/player');
        $service->pages['player'] = $this->page('player');
        $this->assertNull($service->frontPage());
        $this->assertFalse($service->isFrontPage($service->pages['player']));
    }

    public function testAnotherPageIsNotTheFrontPage(): void {
        $service = $this->content('/player');
        $this->assertFalse($service->isFrontPage($this->page('about')));
    }

    /** An editor previewing a draft sees it where it is, not the public front page */
    public function testADraftIsNotSentToTheFrontPage(): void {
        $service = $this->content('/player');
        $this->assertFalse($service->isFrontPage($this->page('player', 'page', \Dynart\Dpress\Entity\Content::STATUS_DRAFT)));
    }

    /** What the page's own address takes along to `/` */
    public function testTheRedirectCarriesTheLinksQueryAndNotTheRewrites(): void {
        $carried = \Dynart\Dpress\Controller\PageController::carriedQuery('route=/player&track=area-67&t=30');
        $this->assertSame(['track' => 'area-67', 't' => '30'], $carried);
        $this->assertSame([], \Dynart\Dpress\Controller\PageController::carriedQuery('route=/player'));
        $this->assertSame(['a' => '1'], \Dynart\Dpress\Controller\PageController::carriedQuery('a=1&b[c]=2'));
    }
}
