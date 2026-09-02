<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\InternalLinks;
use Dynart\Dpress\Content\LinkTargetResolverInterface;
use Dynart\Dpress\Content\MarkdownRenderer;
use Dynart\Dpress\Controller\AbstractController;
use Dynart\Dpress\Controller\Admin\ContentAdminController;
use Dynart\Dpress\Dpress;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Test\StubConfig;
use Dynart\Micro\Request;
use Dynart\Micro\Router;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * A resolver that knows a fixed handful of things, and nothing else exists
 */
class FixedTargets implements LinkTargetResolverInterface {

    public array $asked = [];

    public function __construct(private array $map) {}

    public function resolve(string $kind, int $id): ?string {
        $this->asked[] = $kind.'#'.$id;
        return $this->map[$kind.'#'.$id] ?? null;
    }
}

/**
 * References inside a document, and what they turn into
 *
 * A stored document names *what* it points at - `media#12`, `post#42` - and never where that is
 * today. The point is that moving a site from a test domain to a real one, or renaming a post,
 * changes no stored markdown at all: the URL is worked out at render time from `app.base_url`
 * and a slug that are both outside the document.
 *
 * Which makes the interesting cases the ones where it must *not* fire. A sentence mentioning
 * "media#12", and a code block teaching somebody how to write one, are text - and a CMS whose
 * documentation cannot be written in itself is a CMS with a hole in it.
 *
 * @covers \Dynart\Dpress\Content\InternalLinks
 */
class InternalLinksTest extends TestCase {

    const TARGETS = [
        'media#12'    => 'https://example.com/uploads/2026/08/photo.jpg',
        'post#42'     => 'https://example.com/post/hello',
        'page#5'      => 'https://example.com/about/team',
        'content#42'  => 'https://example.com/post/hello',
        'category#21' => 'https://example.com/category/news',
        'tag#7'       => 'https://example.com/tag/php',
    ];

    private FixedTargets $targets;

    private function render(string $markdown, ?array $map = null): string {
        $events = new RecordingEvents();
        $renderer = new MarkdownRenderer($events);
        $this->targets = new FixedTargets($map ?? self::TARGETS);
        $links = new InternalLinks($this->targets);
        // a closure rather than a Micro callable: the container is what makes it lazy in the
        // application, and there is no container here
        $events->subscribe(MarkdownRenderer::EVENT_ENVIRONMENT, function ($environment) use ($links) {
            $links->onEnvironment($environment);
        });
        return trim($renderer->render($markdown));
    }

    // --- what a reference becomes ---

    public function testAnImageReferenceBecomesTheFilesUrl(): void {
        $this->assertSame(
            '<p><img src="https://example.com/uploads/2026/08/photo.jpg" alt="Screenshot" /></p>',
            $this->render('![Screenshot](media#12)')
        );
    }

    public function testALinkReferenceBecomesThePostsUrl(): void {
        $this->assertSame(
            '<p><a href="https://example.com/post/hello">hello</a></p>',
            $this->render('[hello](post#42)')
        );
    }

    public function testEveryKindResolves(): void {
        foreach (['post#42', 'page#5', 'content#42', 'category#21', 'tag#7'] as $reference) {
            $this->assertStringContainsString(
                'href="'.self::TARGETS[$reference].'"', $this->render('[x]('.$reference.')'), $reference
            );
        }
    }

    /**
     * The prefix is a note to the next reader, not a selector
     *
     * Content ids are unique across posts and pages and the entity decides the shape of its own
     * URL, so all three names reach the same lookup. Somebody writing prose should not have to
     * remember which of the two a thing was filed as.
     */
    public function testPostPageAndContentAreOneLookup(): void {
        $this->render('[a](post#1) [b](page#1) [c](content#1)');
        $this->assertSame(['post#1', 'page#1', 'content#1'], $this->targets->asked);
    }

    /**
     * A reference definition is a destination too, and the same rule has to reach it - otherwise
     * the one form of link that exists to be written once and used everywhere is the one form
     * that cannot be internal.
     */
    public function testAReferenceDefinitionResolves(): void {
        $this->assertSame(
            '<p><a href="https://example.com/uploads/2026/08/photo.jpg">ref</a></p>',
            $this->render("[ref]\n\n[ref]: media#12")
        );
    }

    public function testAnAnchorOrQueryIsCarriedOver(): void {
        $this->assertStringContainsString(
            'href="https://example.com/post/hello#section"', $this->render('[x](post#42#section)')
        );
        $this->assertStringContainsString(
            'href="https://example.com/post/hello?page=2"', $this->render('[x](post#42?page=2)')
        );
    }

    // --- when the thing it named is gone ---

    /**
     * The reference outlives the file, and a visitor should not be the one to find out
     *
     * Leaving `media#12` in the `src` would put a broken image on a published page. Unwrapping
     * the node leaves the alt text, which is a description of the missing picture written by the
     * person who put it there - the best thing available at that moment.
     */
    public function testAMissingImageLeavesItsAltText(): void {
        $this->assertSame('<p>Screenshot</p>', $this->render('![Screenshot](media#999)'));
    }

    public function testAMissingLinkLeavesItsLabel(): void {
        $this->assertSame('<p>the old post</p>', $this->render('[the old post](post#999)'));
    }

    /**
     * Whatever was inside comes out, not just plain words
     */
    public function testUnwrappingKeepsTheMarkupInsideTheLabel(): void {
        $this->assertSame('<p>an <em>old</em> post</p>', $this->render('[an *old* post](post#999)'));
    }

    public function testTheRestOfTheParagraphSurvivesAnUnwrap(): void {
        $this->assertSame(
            '<p>before <a href="https://example.com/post/hello">kept</a> after gone end</p>',
            $this->render('before [kept](post#42) after [gone](post#999) end')
        );
    }

    public function testAnImageWithNoAltTextSimplyDisappears(): void {
        $this->assertSame('<p></p>', $this->render('![](media#999)'));
    }

    // --- what must be left exactly as it was ---

    /**
     * A reference is recognised in a destination, nowhere else
     *
     * "See media#12" is a sentence. Rewriting it would mean no document could ever discuss the
     * feature, starting with this project's own.
     */
    public function testAReferenceInRunningTextIsText(): void {
        $this->assertSame('<p>see media#12 in running text</p>', $this->render('see media#12 in running text'));
        $this->assertSame([], $this->targets->asked);
    }

    public function testCodeIsLeftAlone(): void {
        $this->assertSame('<p><code>](media#12)</code></p>', $this->render('`](media#12)`'));
        $this->assertSame("<pre><code>![a](media#12)\n</code></pre>", $this->render("    ![a](media#12)"));
    }

    public function testAnOrdinaryUrlIsUntouched(): void {
        $this->assertStringContainsString('href="https://example.org/x"', $this->render('[x](https://example.org/x)'));
        $this->assertStringContainsString('href="/already/relative"', $this->render('[x](/already/relative)'));
        $this->assertSame([], $this->targets->asked);
    }

    /**
     * A kind nobody registered, and an id that is not a number, are both just URLs
     */
    public function testSomethingShapedLikeAReferenceButIsntStays(): void {
        $this->assertStringContainsString('href="issue#42"', $this->render('[x](issue#42)'));
        $this->assertStringContainsString('href="post#42x"', $this->render('[x](post#42x)'));
        $this->assertStringContainsString('href="post#"', $this->render('[x](post#)'));
    }

    // --- how far a resolved answer may be trusted ---

    /**
     * One picture used twice is one lookup, and the next document starts again
     *
     * The scope matters and it was wrong once. Holding the answers for longer than a document
     * looks like an easy saving until a rename: renaming a post re-renders everything that links
     * to it, in the same request that did the renaming, and those renders were being handed the
     * URL worked out *before* the slug changed. The rename appeared to do nothing.
     */
    public function testTheSameReferenceTwiceInOneDocumentIsOneLookup(): void {
        $this->render("![a](media#12) and ![b](media#12)\n\n[c](media#12)");
        $this->assertSame(['media#12'], $this->targets->asked);
    }

    public function testEachDocumentAsksAgain(): void {
        $events = new RecordingEvents();
        $renderer = new MarkdownRenderer($events);
        $targets = new FixedTargets(self::TARGETS);
        $links = new InternalLinks($targets);
        $events->subscribe(MarkdownRenderer::EVENT_ENVIRONMENT, function ($e) use ($links) {
            $links->onEnvironment($e);
        });
        $renderer->render('![a](media#12)');
        $renderer->render('![b](media#12)');
        $this->assertSame(['media#12', 'media#12'], $targets->asked);
    }

    /**
     * The editor and the renderer have to spell it the same way
     *
     * One place writes a reference - `Dpress.insertMedia()`, behind both the toolbar button and
     * the attachment list's insert action - and one place reads it. Nothing at runtime connects
     * them, so a prefix renamed on one side would go on inserting references that quietly
     * resolve to nothing.
     */
    public function testTheEditorWritesSomethingThisCanRead(): void {
        $this->assertSame(1, preg_match(InternalLinks::PATTERN, 'media#1'));
        $this->assertStringContainsString(
            "](media#' + item.id", file_get_contents(Dpress::path('assets/admin.js')),
            'admin.js no longer writes the reference this reads'
        );
    }

    /**
     * Every column the panel declares is a field the rows actually carry
     *
     * The list is built in the browser from a JSON config, so a column and the field behind it
     * are declared in two places that nothing checks against each other, and **a column with no
     * field is a row of blanks** - no error, no warning, just an empty cell that looks
     * deliberate. That is precisely how the dashboard's "Recent changes" stayed two-thirds blank
     * for three releases.
     *
     * This used to assert the one column by name. It asserts the agreement instead, which is the
     * thing that was actually worth pinning: the Reference column could then be removed in
     * 0.25.3 without touching it, and the next column added is covered the moment it exists.
     */
    public function testEveryAttachmentColumnHasAFieldBehindIt(): void {
        $controller = (new ReflectionClass(ContentAdminController::class))->newInstanceWithoutConstructor();
        $router = new ReflectionProperty(AbstractController::class, 'router');
        $router->setAccessible(true);
        $router->setValue($controller, new Router(new StubConfig([Router::CONFIG_USE_REWRITE => true]), new Request()));

        $method = new ReflectionMethod(ContentAdminController::class, 'attachmentPanel');
        $method->setAccessible(true);
        $content = new Content();
        $content->id = 7;
        $panel = $method->invoke($controller, 'post', $content);

        $columns = array_keys($panel['config']['columns']);
        $this->assertNotEmpty($columns);
        foreach ($columns as $column) {
            $this->assertContains(
                $column, $this->attachmentRowFields(),
                "the panel shows a '$column' column and attachmentRows() builds no such field"
            );
        }
    }

    /**
     * The keys of the row literal in `attachmentRows()`, read out of the source
     *
     * Building a row for real wants the media service and a database; the array is a literal and
     * the question is only what it is called.
     *
     * @return string[]
     */
    private function attachmentRowFields(): array {
        $source = file_get_contents(Dpress::path('src/Controller/Admin/ContentAdminController.php'));
        $start = strpos($source, 'foreach ($this->media->attachmentsOf(');
        $this->assertNotFalse($start, 'attachmentRows() no longer looks like this');
        $end = strpos($source, 'return $this->rows(', $start);
        preg_match_all("/'([a-z_]+)'\s*=>/", substr($source, $start, $end - $start), $matches);
        return $matches[1];
    }

    // --- the two guarantees the renderer already made ---

    /**
     * Rewriting destinations must not have opened either of the doors the renderer keeps shut.
     * The converter was rebuilt from an `Environment` to make this feature possible, and these
     * are the settings that rebuild could have dropped.
     */
    public function testRawHtmlIsStillStripped(): void {
        $this->assertSame('', $this->render('<script>alert(1)</script>'));
    }

    public function testUnsafeLinksAreStillRefused(): void {
        $this->assertSame('<p><a>js</a></p>', $this->render('[js](javascript:alert(1))'));
    }
}
