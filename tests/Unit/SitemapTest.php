<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Micro\RouterInterface;
use Dynart\Dpress\Content\Dates;
use Dynart\Dpress\Content\Sitemap;
use Dynart\Dpress\Controller\SitemapController;
use Dynart\Dpress\DpressWebApp;
use Dynart\Dpress\Entity\Category;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Entity\Tag;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Service\TaxonomyService;
use PHPUnit\Framework\TestCase;

/**
 * Every canonical URL on the site, for a crawler
 *
 * Worth having at all because a sitemap earns its keep exactly when a site's addresses change,
 * which is the day it moves off something else - and a crawler left to find them by following
 * links takes weeks over a small blog.
 *
 * @covers \Dynart\Dpress\Content\Sitemap
 */
class SitemapTest extends TestCase {

    /**
     * @param array[] $content rows as `content_list` answers, both types together
     * @param array[] $categories rows as `category_list` answers
     * @param array[] $tags rows as `tag_cloud` answers - already only the used ones
     */
    private function sitemap(array $content, array $categories = [], array $tags = []): Sitemap {
        $service = new class($content) extends ContentService {
            public array $contexts = [];
            public function __construct(public array $content) {}
            public function findAll(array $context = []): array {
                $this->contexts[] = $context;
                $rows = array_filter(
                    $this->content,
                    fn(array $r) => ($r['type'] ?? Content::TYPE_POST) === $context['type']
                );
                if ($context['published_only'] ?? true) {
                    $rows = array_filter(
                        $rows,
                        fn(array $r) => ($r['status'] ?? Content::STATUS_PUBLISHED) === Content::STATUS_PUBLISHED
                    );
                }
                return array_values($rows);
            }
            public function postPath(string $slug): string { return '/'.$slug; }
        };
        $taxonomy = new class($categories, $tags) extends TaxonomyService {
            public function __construct(public array $cats, public array $tagRows) {}
            public function categories(array $context = []): array { return $this->cats; }
            public function tagCloud(): array { return $this->tagRows; }
        };
        $router = $this->createMock(RouterInterface::class);
        $router->method('url')->willReturnCallback(
            fn(?string $route = null) => 'https://example.com'.(string)$route
        );
        return new Sitemap($service, $taxonomy, $router, new Dates(new PlacesSettings()));
    }

    private function post(array $overrides = []): array {
        return $overrides + [
            'id' => 1,
            'type' => Content::TYPE_POST,
            'status' => Content::STATUS_PUBLISHED,
            'slug' => 'a-post',
            'parent_id' => null,
            'published_at' => '2026-01-10 18:13:38',
            'updated_at' => '2026-02-01 09:00:00',
        ];
    }

    private function page(array $overrides = []): array {
        return $this->post($overrides + ['type' => Content::TYPE_PAGE, 'slug' => 'a-page']);
    }

    /** @return string[] every <loc> in the document */
    private function locs(Sitemap $sitemap): array {
        $xml = simplexml_load_string($sitemap->xml());
        $this->assertNotFalse($xml, 'the sitemap is not well-formed XML');
        $out = [];
        foreach ($xml->url as $url) {
            $out[] = (string)$url->loc;
        }
        return $out;
    }

    // --- the document ---

    public function testItIsWellFormedAndInTheSitemapNamespace(): void {
        $xml = simplexml_load_string($this->sitemap([$this->post()])->xml());
        $this->assertNotFalse($xml);
        $this->assertSame(
            'http://www.sitemaps.org/schemas/sitemap/0.9',
            $xml->getNamespaces()[''] ?? null,
            'a urlset in the wrong namespace is rejected whole'
        );
    }

    /**
     * Google has ignored both for years, and they are the two fields that make a hand-written
     * sitemap look authoritative while saying nothing
     */
    public function testItClaimsNoChangefreqAndNoPriority(): void {
        $xml = $this->sitemap([$this->post()])->xml();
        $this->assertStringNotContainsString('changefreq', $xml);
        $this->assertStringNotContainsString('priority', $xml);
    }

    public function testTheHomePageIsInIt(): void {
        $this->assertContains('https://example.com/', $this->locs($this->sitemap([$this->post()])));
    }

    // --- what goes in ---

    public function testAPublishedPostIsInItAndADraftIsNot(): void {
        $locs = $this->locs($this->sitemap([
            $this->post(['id' => 1, 'slug' => 'published']),
            $this->post(['id' => 2, 'slug' => 'a-draft', 'status' => Content::STATUS_DRAFT]),
        ]));
        $this->assertContains('https://example.com/published', $locs);
        $this->assertNotContains('https://example.com/a-draft', $locs, 'a draft must not be advertised');
    }

    public function testAPageLivesUnderItsAncestors(): void {
        $locs = $this->locs($this->sitemap([
            $this->page(['id' => 10, 'slug' => 'docs', 'parent_id' => null]),
            $this->page(['id' => 11, 'slug' => 'install', 'parent_id' => 10]),
        ]));
        $this->assertContains('https://example.com/docs/install', $locs);
    }

    /**
     * The reason every page is fetched and only the published ones listed
     *
     * `ContentService::path()` walks the ancestor chain without asking about status, so a draft
     * parent still contributes its slug to a published child's address. Leave the drafts out of
     * the lookup and the sitemap advertises `/install`, which 404s.
     */
    public function testADraftAncestorStillShapesAPublishedChildsUrl(): void {
        $locs = $this->locs($this->sitemap([
            $this->page(['id' => 10, 'slug' => 'docs', 'status' => Content::STATUS_DRAFT]),
            $this->page(['id' => 11, 'slug' => 'install', 'parent_id' => 10]),
        ]));
        $this->assertContains('https://example.com/docs/install', $locs);
        $this->assertNotContains('https://example.com/docs', $locs, 'the draft parent itself stays out');
    }

    /**
     * A corrupt tree must not hang a request, which is the same bargain `ancestors()` makes
     */
    public function testACycleInThePageTreeTerminates(): void {
        $sitemap = $this->sitemap([
            $this->page(['id' => 10, 'slug' => 'a', 'parent_id' => 11]),
            $this->page(['id' => 11, 'slug' => 'b', 'parent_id' => 10]),
        ]);
        $locs = $this->locs($sitemap);
        $this->assertCount(3, $locs, 'home and the two pages, each once');
    }

    /**
     * Categories are made by hand and few, so an empty one is a section somebody meant to have
     */
    public function testEveryCategoryIsInItEvenAnEmptyOne(): void {
        $locs = $this->locs($this->sitemap([], [['slug' => 'development'], ['slug' => 'empty']]));
        $this->assertContains('https://example.com/category/development', $locs);
        $this->assertContains('https://example.com/category/empty', $locs);
    }

    /**
     * A tag is a side effect of writing a post, so an unused one is a leftover rather than a
     * section - and `tagCloud()` already joins on published content, which is why this needs no
     * filtering of its own
     */
    public function testTagsComeFromTheCloudSoUnusedOnesStayOut(): void {
        $locs = $this->locs($this->sitemap([], [], [['slug' => 'retro', 'total' => 3]]));
        $this->assertContains('https://example.com/tag/retro', $locs);
    }

    // --- lastmod ---

    /**
     * A post edited last week is one a crawler should look at again; a `lastmod` frozen at
     * publication is telling it not to bother
     */
    public function testLastmodPrefersTheEditOverThePublication(): void {
        $xml = simplexml_load_string($this->sitemap([$this->post([
            'published_at' => '2026-01-10 18:13:38',
            'updated_at' => '2026-02-01 09:00:00',
        ])])->xml());
        $found = [];
        foreach ($xml->url as $url) {
            $found[(string)$url->loc] = (string)$url->lastmod;
        }
        $this->assertStringStartsWith('2026-02-01', $found['https://example.com/a-post']);
    }

    public function testAPostNeverEditedFallsBackToItsPublicationDate(): void {
        $xml = simplexml_load_string($this->sitemap([$this->post(['updated_at' => null])])->xml());
        $found = [];
        foreach ($xml->url as $url) {
            $found[(string)$url->loc] = (string)$url->lastmod;
        }
        $this->assertStringStartsWith('2026-01-10', $found['https://example.com/a-post']);
    }

    /**
     * The front page changes whenever anything on it does
     */
    public function testTheHomeLastmodIsTheNewestOfEverything(): void {
        $xml = simplexml_load_string($this->sitemap([
            $this->post(['id' => 1, 'slug' => 'old', 'updated_at' => '2025-05-01 09:00:00']),
            $this->post(['id' => 2, 'slug' => 'new', 'updated_at' => '2026-02-01 09:00:00']),
        ])->xml());
        $found = [];
        foreach ($xml->url as $url) {
            $found[(string)$url->loc] = (string)$url->lastmod;
        }
        $this->assertStringStartsWith('2026-02-01', $found['https://example.com/']);
    }

    /**
     * A category archive has no single moment it changed, and a `lastmod` that is really
     * `time()` is a lie a crawler learns to ignore
     */
    public function testACategoryCarriesNoLastmod(): void {
        $xml = simplexml_load_string($this->sitemap([], [['slug' => 'development']])->xml());
        foreach ($xml->url as $url) {
            if ((string)$url->loc === 'https://example.com/category/development') {
                $this->assertFalse(isset($url->lastmod));
                return;
            }
        }
        $this->fail('the category is not in the sitemap');
    }

    // --- the limit ---

    /**
     * Past this a site needs a sitemap *index*, which is a different shape and is not built here.
     * The cap is applied so growing into the problem truncates rather than exhausts a worker.
     */
    public function testItAsksTheDatabaseForNoMoreThanItCanCarry(): void {
        $sitemap = $this->sitemap([$this->post()]);
        $sitemap->urls();
        $property = new \ReflectionProperty(Sitemap::class, 'content');
        $property->setAccessible(true);
        foreach ($property->getValue($sitemap)->contexts as $context) {
            $this->assertSame(Sitemap::MAX_URLS, $context['max']);
        }
    }

    // --- robots.txt ---

    /**
     * The only automatic way a crawler finds the sitemap without somebody submitting it by hand
     */
    public function testRobotsTxtNamesTheSitemap(): void {
        $robots = $this->sitemap([])->robotsTxt();
        $this->assertStringContainsString('Sitemap: https://example.com/sitemap.xml', $robots);
        $this->assertStringContainsString('User-agent: *', $robots);
    }

    /**
     * A `Disallow: /admin` is a public file naming the door. It stops nothing that was going to
     * try it, and the screens behind it already answer 401.
     */
    public function testRobotsTxtDoesNotAdvertiseTheAdmin(): void {
        $this->assertStringNotContainsString('admin', $this->sitemap([])->robotsTxt());
    }

    // --- wiring ---

    public function testTheControllerIsRegisteredOrItHasNoRoutes(): void {
        $this->assertContains(SitemapController::class, DpressWebApp::CONTROLLERS);
    }

    /**
     * The slug-only helpers exist so a listing row does not have to build a URL by hand; they
     * are worth nothing if they disagree with the ones that take an entity
     */
    public function testTheSlugPathsAgreeWithTheEntityOnes(): void {
        $taxonomy = new class extends TaxonomyService {
            public function __construct() {}
        };
        $category = new Category();
        $category->slug = 'development';
        $tag = new Tag();
        $tag->slug = 'retro';
        $this->assertSame($taxonomy->categoryPath($category), $taxonomy->categoryPathBySlug('development'));
        $this->assertSame($taxonomy->tagPath($tag), $taxonomy->tagPathBySlug('retro'));
    }
}
