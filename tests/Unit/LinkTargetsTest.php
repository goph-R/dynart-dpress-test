<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\LinkTargets;
use Dynart\Dpress\Entity\Category;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Entity\Media;
use Dynart\Dpress\Media\MediaView;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Service\MediaService;
use Dynart\Dpress\Service\TaxonomyService;
use Dynart\Dpress\Test\StubConfig;
use Dynart\Micro\Request;
use Dynart\Micro\Router;
use PHPUnit\Framework\TestCase;

/**
 * Services with their constructors taken off, so one method can be watched
 *
 * Each of these has half a dozen collaborators it never touches on this path, and building them
 * would be building the application. What is under test is which of these get asked, and how
 * often.
 */
class SpyContent extends ContentService {
    public int $calls = 0;
    public ?Content $answer = null;
    public function __construct() {}
    public function findById(int $id): ?Content { $this->calls++; return $this->answer; }
    public function publicPath(Content $content): string { return '/post/'.$content->slug; }
}

class SpyTaxonomy extends TaxonomyService {
    public int $calls = 0;
    public ?Category $answer = null;
    public function __construct() {}
    public function findCategory(int $id): ?Category { $this->calls++; return $this->answer; }
}

class SpyMedia extends MediaService {
    public int $calls = 0;
    public ?Media $answer = null;
    public function __construct() {}
    public function findById(int $id): ?Media { $this->calls++; return $this->answer; }
}

class SpyMediaView extends MediaView {
    public function __construct() {}
    public function url(Media $media, string $preset = ''): string { return 'https://example.com/uploads/'.$media->path; }
}

/**
 * Where a reference actually points, and how long that answer is good for
 *
 * @covers \Dynart\Dpress\Content\LinkTargets
 */
class LinkTargetsTest extends TestCase {

    private SpyContent $content;
    private SpyTaxonomy $taxonomy;
    private SpyMedia $media;
    private LinkTargets $targets;

    protected function setUp(): void {
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->content = new SpyContent();
        $this->taxonomy = new SpyTaxonomy();
        $this->media = new SpyMedia();
        $this->targets = new LinkTargets(
            $this->content, $this->taxonomy, $this->media, new SpyMediaView(),
            new Router(new StubConfig([
                'app.base_url' => 'https://example.com',
                Router::CONFIG_USE_REWRITE => true,
            ]), new Request())
        );
    }

    // --- what it answers ---

    public function testAMediaReferenceIsTheFilesUrl(): void {
        $this->media->answer = new Media();
        $this->media->answer->path = '2026/08/photo.jpg';
        $this->assertSame('https://example.com/uploads/2026/08/photo.jpg', $this->targets->resolve('media', 1));
    }

    /**
     * A soft deleted file is gone as far as a document is concerned - the same answer the public
     * attachment list gives, and the alternative is a link to a file the library says is removed
     */
    public function testASoftDeletedFileIsGone(): void {
        $this->media->answer = new Media();
        $this->media->answer->path = '2026/08/photo.jpg';
        $this->media->answer->deleted_at = '2026-08-06 10:00:00';
        $this->assertNull($this->targets->resolve('media', 1));
    }

    public function testAMissingThingIsNull(): void {
        $this->assertNull($this->targets->resolve('media', 9));
        $this->assertNull($this->targets->resolve('post', 9));
        $this->assertNull($this->targets->resolve('category', 9));
    }

    public function testTheUrlGoesThroughTheRouter(): void {
        $this->content->answer = new Content();
        $this->content->answer->slug = 'hello';
        $this->assertSame('https://example.com/post/hello', $this->targets->resolve('post', 1));
    }

    /**
     * Content ids are unique across both types, so all three names are one lookup and the entity
     * decides the shape of its own URL
     */
    public function testPostPageAndContentAllReachContent(): void {
        $this->content->answer = new Content();
        $this->content->answer->slug = 'hello';
        foreach (['post', 'page', 'content'] as $kind) {
            $this->assertSame('https://example.com/post/hello', $this->targets->resolve($kind, 1), $kind);
        }
        $this->assertSame(3, $this->content->calls);
        $this->assertSame(0, $this->media->calls, 'a content reference went looking in the library');
    }

    // --- and for how long ---

    /**
     * Every call asks again, and that is not an oversight
     *
     * This held onto its answers once, and it was wrong inside a single request. Renaming a post
     * re-renders everything that links to it, in the same request that did the renaming - and
     * every one of those renders was handed the URL worked out *before* the slug changed. The
     * rename looked like it had done nothing at all.
     *
     * The saving this gives up is a handful of queries on save. The dedup that is safe - one
     * picture twice in one document - belongs to `InternalLinks`, which knows where a document
     * ends and therefore when an answer stops being current.
     */
    public function testAnswersAreNeverKept(): void {
        $this->content->answer = new Content();
        $this->content->answer->slug = 'before';
        $this->assertSame('https://example.com/post/before', $this->targets->resolve('post', 1));

        $this->content->answer->slug = 'after'; // as a rename in the same request would
        $this->assertSame('https://example.com/post/after', $this->targets->resolve('post', 1));
        $this->assertSame(2, $this->content->calls);
    }
}
