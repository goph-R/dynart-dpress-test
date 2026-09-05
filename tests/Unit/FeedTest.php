<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Micro\ConfigInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Dpress\Content\Dates;
use Dynart\Dpress\Content\Feed;
use Dynart\Dpress\Controller\FeedController;
use Dynart\Dpress\DpressServices;
use Dynart\Dpress\DpressWebApp;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Theme\PageAssets;
use PHPUnit\Framework\TestCase;

/**
 * What a reader gets from `/feed`
 *
 * The blog this was written for moved off WordPress, which had served `/feed/` since the day it
 * existed. A CMS that answers 404 there loses no argument with anybody - it loses the readers who
 * subscribed once and never visit the site again, and it does it silently, because nobody writes
 * in to say their reader went quiet.
 *
 * @covers \Dynart\Dpress\Content\Feed
 * @covers \Dynart\Dpress\Content\Dates
 */
class FeedTest extends TestCase {

    /**
     * A feed over rows that never touched a database
     *
     * `ContentService` has a dozen collaborators it never touches on this path, and building them
     * would be building the application - the same bargain `SpyContent` makes elsewhere.
     *
     * @param array[] $rows listing rows, as `content_list` answers
     */
    private function feed(array $rows, array $settings = []): Feed {
        $content = new class($rows) extends ContentService {
            public int $calls = 0;
            public array $context = [];
            public function __construct(public array $rows) {}
            public function findAll(array $context = []): array {
                $this->calls++;
                $this->context = $context;
                return $this->rows;
            }
            public function postPath(string $slug): string {
                return '/'.$slug;
            }
        };
        $values = new PlacesSettings();
        $values->values = $settings + [
            Setting::SITE_NAME => 'gopherlab',
            Setting::SITE_DESCRIPTION => 'Develop It Yourself',
        ];
        $config = $this->createMock(ConfigInterface::class);
        $config->method('get')->willReturnCallback(fn($name, $default = null) => $default);
        $router = $this->createMock(RouterInterface::class);
        $router->method('url')->willReturnCallback(
            fn(?string $route = null) => 'https://example.com'.(string)$route
        );
        return new Feed($content, $values, $config, $router, new Dates($values));
    }

    private function row(array $overrides = []): array {
        return $overrides + [
            'id' => 1,
            'title' => 'A post',
            'slug' => 'a-post',
            'lead_html' => '<p>The lead.</p>',
            'body_html' => '<p>The body.</p>',
            'published_at' => '2026-01-10 18:13:38',
        ];
    }

    private function parse(Feed $feed): \SimpleXMLElement {
        $xml = simplexml_load_string($feed->xml());
        $this->assertNotFalse($xml, 'the feed is not well-formed XML');
        return $xml;
    }

    private function double(Feed $feed): object {
        $property = new \ReflectionProperty(Feed::class, 'content');
        $property->setAccessible(true);
        return $property->getValue($feed);
    }

    // --- the document ---

    public function testItIsWellFormedRss(): void {
        $xml = $this->parse($this->feed([$this->row()]));
        $this->assertSame('2.0', (string)$xml['version']);
        $this->assertSame('gopherlab', (string)$xml->channel->title);
        $this->assertSame('Develop It Yourself', (string)$xml->channel->description);
        $this->assertCount(1, $xml->channel->item);
    }

    /**
     * A reader that has been redirected can re-find the feed, and a browser can offer it -
     * neither works without the self link
     */
    public function testItNamesItsOwnAddress(): void {
        $xml = $this->parse($this->feed([$this->row()]));
        $atom = $xml->channel->children('http://www.w3.org/2005/Atom');
        $this->assertSame('https://example.com/feed', (string)$atom->link->attributes()['href']);
    }

    /**
     * Both bodies, so a reader shows either without the site having decided for it
     */
    public function testAnItemCarriesTheLeadAndTheWholePost(): void {
        $item = $this->parse($this->feed([$this->row()]))->channel->item[0];
        $this->assertStringContainsString('The lead.', (string)$item->description);
        $encoded = $item->children('http://purl.org/rss/1.0/modules/content/');
        $this->assertStringContainsString('The body.', (string)$encoded->encoded);
    }

    public function testTheGuidIsThePermalink(): void {
        $item = $this->parse($this->feed([$this->row()]))->channel->item[0];
        $this->assertSame('https://example.com/a-post', (string)$item->link);
        $this->assertSame('https://example.com/a-post', (string)$item->guid);
        $this->assertSame('true', (string)$item->guid->attributes()['isPermaLink']);
    }

    // --- the two ways a hand-written feed stops being XML ---

    /**
     * A post about XML contains the CDATA terminator, and a CDATA section ends at the first one
     *
     * Not hypothetical on a blog that writes about file formats. Unsplit, the feed stops being
     * XML halfway through the post and every reader drops the whole document, not just that item.
     */
    public function testAPostContainingTheCdataTerminatorStaysValid(): void {
        $xml = $this->parse($this->feed([$this->row([
            'body_html' => '<p>It ends with ]]> and then carries on.</p>',
        ])]));
        $encoded = $xml->channel->item[0]->children('http://purl.org/rss/1.0/modules/content/');
        $this->assertStringContainsString(']]>', (string)$encoded->encoded, 'the bytes must survive');
        $this->assertStringContainsString('carries on', (string)$encoded->encoded);
    }

    public function testMarkupInATitleIsEscaped(): void {
        $xml = $this->parse($this->feed([$this->row(['title' => 'DOS & Windows <3'])]));
        $this->assertSame('DOS & Windows <3', (string)$xml->channel->item[0]->title);
    }

    // --- what goes in it ---

    public function testItAsksForPublishedPostsOnly(): void {
        $feed = $this->feed([$this->row()]);
        $feed->items();
        $context = $this->double($feed)->context;
        $this->assertSame(Content::TYPE_POST, $context['type']);
        $this->assertTrue($context['published_only'], 'a draft must not reach a reader');
        $this->assertSame(20, $context['max']);
    }

    public function testTheCountIsASettingAndDefaultsToTwenty(): void {
        $this->assertSame(20, $this->feed([])->itemCount());
        $this->assertSame(5, $this->feed([], [Setting::FEED_ITEMS => 5])->itemCount());
    }

    /**
     * The number is typed into a settings screen, and every fetch by every reader forever pays
     * for whatever was typed
     */
    public function testTheCountIsClamped(): void {
        $this->assertSame(Feed::MAX_ITEMS, $this->feed([], [Setting::FEED_ITEMS => 5000])->itemCount());
        $this->assertSame(1, $this->feed([], [Setting::FEED_ITEMS => 0])->itemCount());
        $this->assertSame(1, $this->feed([], [Setting::FEED_ITEMS => -3])->itemCount());
    }

    /**
     * The controller wants the rows to work out `Last-Modified`, and `xml()` wants the same rows
     */
    public function testTheRowsAreFetchedOnce(): void {
        $feed = $this->feed([$this->row()]);
        $feed->items();
        $feed->xml();
        $feed->items();
        $this->assertSame(1, $this->double($feed)->calls, 'the feed query should run once a request');
    }

    // --- a site with nothing on it ---

    /**
     * A new site has no posts, and an invalid feed is worse than an empty one
     */
    public function testAnEmptySiteStillProducesAValidFeed(): void {
        $xml = $this->parse($this->feed([]));
        $this->assertCount(0, $xml->channel->item);
        $this->assertSame('', (string)$xml->channel->lastBuildDate);
    }

    public function testLastBuildDateIsTheNewestPost(): void {
        $xml = $this->parse($this->feed([
            $this->row(['published_at' => '2026-01-10 18:13:38']),
            $this->row(['slug' => 'older', 'published_at' => '2025-05-01 09:00:00']),
        ]));
        $this->assertSame('Sat, 10 Jan 2026 18:13:38 +0000', (string)$xml->channel->lastBuildDate);
    }

    // --- the date format, which is its own trap ---

    /**
     * `pubDate` carries its own offset and the reader converts it for whoever is reading.
     * Applying the site's timezone as well prints a Budapest wall clock with `+0000` after it -
     * a date quietly wrong by however far the site is from UTC, in everybody else's reader.
     */
    public function testRssDatesAreUtcWhateverTheSitesTimezoneIs(): void {
        $settings = new PlacesSettings();
        $settings->values[Setting::TIMEZONE] = 'Europe/Budapest';
        $dates = new Dates($settings);
        $this->assertSame('Sat, 10 Jan 2026 18:13:38 +0000', $dates->rss('2026-01-10 18:13:38'));
        $this->assertSame(
            '2026-01-10 19:13:38',
            $dates->format('2026-01-10 18:13:38', 'Y-m-d H:i:s'),
            'the printed date is still the site timezone, which is the difference being tested'
        );
    }

    public function testAPostWithNoDateGetsNoPubDate(): void {
        $dates = new Dates(new PlacesSettings());
        $this->assertSame('', $dates->rss(null));
        $this->assertSame('', $dates->rss('0000-00-00 00:00:00'));
    }

    // --- how a reader finds it ---

    /**
     * Registered with no needle, so it is in the head of every page of every theme. A theme that
     * has to remember this is a theme that forgets it.
     */
    public function testTheHeadLinkIsRegisteredForEveryPage(): void {
        $assets = new PageAssets();
        DpressServices::registerPageAssets($assets);
        $this->assertContains('feed', $assets->names());
    }

    /**
     * A fragment render is not a page, and must not build the feed service to find that out
     *
     * `</head>` reads like a trick and is not: it is exactly the question being asked, and it is
     * the same string `withPageAssets()` looks for before it injects anything.
     */
    public function testAFragmentGetsNothing(): void {
        $assets = new PageAssets();
        DpressServices::registerPageAssets($assets);
        $this->assertSame('', $assets->tags('<p>a fragment, rendered on purpose</p>'));
    }

    public function testTheHeadLinkPointsAtTheFeed(): void {
        $link = $this->feed([])->headLink();
        $this->assertStringContainsString('rel="alternate"', $link);
        $this->assertStringContainsString('type="application/rss+xml"', $link);
        $this->assertStringContainsString('href="https://example.com/feed"', $link);
    }

    public function testTheControllerIsRegisteredOrItHasNoRoutes(): void {
        $this->assertContains(
            FeedController::class,
            DpressWebApp::CONTROLLERS,
            'a controller missing from CONTROLLERS is a route the attribute processor never sees'
        );
    }

    public function testTheCountIsEditableOnTheSettingsScreen(): void {
        $this->assertSame('int', DpressServices::SETTING_FIELDS[Setting::FEED_ITEMS] ?? null);
    }
}
