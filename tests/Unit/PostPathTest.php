<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Test\RecordingEvents;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Where a post lives: under `/post/`, or at the root beside the pages
 *
 * A pair of URLs for one post is what moving a blog costs, so this is a site decision rather than
 * a preference - every backlink, every search result and every link anybody ever wrote points at
 * one shape. A blog coming from WordPress has its posts at the root, and only that shape carries
 * its addresses across.
 *
 * **Nothing had to be added to the schema for it.** `Content::$slug` is already unique across
 * posts and pages - one flat namespace, by design - so a root-level URL has exactly one answer.
 * `findByPath()` was rejecting a post on purpose, and that rejection is the whole restriction.
 *
 * @covers \Dynart\Dpress\Service\ContentService
 */
class PostPathTest extends TestCase {

    private function content(string $shape = ''): ContentService {
        $settings = new PlacesSettings();
        if ($shape !== '') {
            $settings->values[Setting::POST_PATH] = $shape;
        }
        $reflection = new ReflectionClass(ContentService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        foreach (['settings' => $settings, 'events' => new RecordingEvents()] as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($service, $value);
        }
        return $service;
    }

    private function post(string $slug = 'hello-world'): Content {
        $content = new Content();
        $content->id = 12;
        $content->type = Content::TYPE_POST;
        $content->slug = $slug;
        return $content;
    }

    // --- which shape is in force ---

    /**
     * The default is what every dpress site has had until now, so an upgrade moves nothing
     */
    public function testAPostLivesUnderPostByDefault(): void {
        $this->assertFalse($this->content()->postsAtRoot());
        $this->assertSame('/post/hello-world', $this->content()->publicPath($this->post()));
    }

    public function testTheSettingMovesItToTheRoot(): void {
        $service = $this->content(Setting::POST_PATH_ROOT);
        $this->assertTrue($service->postsAtRoot());
        $this->assertSame('/hello-world', $service->publicPath($this->post()));
    }

    /**
     * A settings screen can be typed into, and a word nobody recognises must not put every post on
     * the site at an address nothing answers - the same bargain a missing theme and a bad timezone
     * both make
     */
    public function testAnythingElseIsThePrefixedShape(): void {
        foreach (['', '  ', 'roots', 'Root', 'nonsense'] as $value) {
            $this->assertFalse($this->content($value)->postsAtRoot(), var_export($value, true));
        }
    }

    /**
     * A page is at its own path whatever posts are doing - it was never part of this
     */
    public function testAPageIsUnmovedByTheSetting(): void {
        $page = new Content();
        $page->id = 13;
        $page->type = Content::TYPE_PAGE;
        $page->slug = 'about';
        foreach (['', Setting::POST_PATH_ROOT] as $shape) {
            $this->assertSame('/about', $this->content($shape)->publicPath($page));
        }
    }

    /**
     * The two shapes have to be the only two, or a select offers something that does nothing
     */
    public function testTheShapesAreTheOnesTheScreenOffers(): void {
        $this->assertSame(
            [Setting::POST_PATH_PREFIXED, Setting::POST_PATH_ROOT],
            Setting::POST_PATHS
        );
    }

    // --- and a post reached from the root is still a post ---

    /**
     * The catch-all serves everything living at the root, which is posts as well as pages now.
     * Both routes go through one method, because the moment the catch-all started answering for a
     * post it rendered it with the **page** template: no byline, no categories, no tags, and a
     * featured image the post template had deliberately left out. The kind of the content decides
     * the template, never the route that reached it.
     */
    public function testTheTemplateFollowsTheContentAndNotTheRoute(): void {
        $source = file_get_contents((new ReflectionClass(
            \Dynart\Dpress\Controller\AbstractController::class))->getFileName());
        $this->assertStringContainsString('renderContent', $source);

        // neither route may render a template of its own any more
        foreach ([\Dynart\Dpress\Controller\ContentController::class,
                  \Dynart\Dpress\Controller\PageController::class] as $className) {
            $body = file_get_contents((new ReflectionClass($className))->getFileName());
            $this->assertStringNotContainsString("render('dpress:content/page'", $body, $className);
            $this->assertStringNotContainsString("render('dpress:content/single'", $body, $className);
            $this->assertStringContainsString('renderContent($content)', $body, $className);
        }
    }

}
