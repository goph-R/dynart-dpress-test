<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Controller\Admin\ContentAdminController;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Entity\ContentAttachment;
use Dynart\Dpress\Entity\Media;
use Dynart\Dpress\Query\CoreQueries;
use Dynart\Dpress\Service\MediaService;
use Dynart\Dpress\Test\StubDatabase;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Attribute\Route;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Attaching a file and showing one in the text are two different things
 *
 * They used to be one thing with a flag on it: putting a picture in an article attached it and
 * marked the attachment `hidden`, so the list under the published page would leave it out. 0.24.0
 * split them. **An attachment is a file somebody attached on purpose; an image in the body is a
 * `media#<id>` in the markdown**, and neither one implies the other.
 *
 * What is asserted below is mostly the *absence* of the old mechanism, because the failure mode
 * is it coming back one piece at a time - a column here, a parameter there - and two mechanisms
 * doing one job is what the split was for.
 *
 * @covers \Dynart\Dpress\Entity\ContentAttachment
 * @covers \Dynart\Dpress\Query\CoreQueries
 * @covers \Dynart\Dpress\Service\MediaService
 */
class AttachmentsTest extends TestCase {

    // --- the flag is gone ---

    public function testAnAttachmentHasNoVisibilityOfItsOwn(): void {
        $this->assertFalse(
            property_exists(ContentAttachment::class, 'hidden'),
            'an attachment is a file somebody attached - there is nothing to hide'
        );
    }

    /**
     * The two calls that existed only to move that flag around
     */
    public function testTheServiceOffersNoWayToHideOne(): void {
        $this->assertFalse(method_exists(MediaService::class, 'setAttachmentHidden'));
        $this->assertFalse(
            method_exists(MediaService::class, 'allAttachmentsOf'),
            '"everything attached" and "what to list" are the same question now'
        );
    }

    public function testAttachingTakesNoFlag(): void {
        $parameters = (new ReflectionMethod(MediaService::class, 'attach'))->getParameters();
        $names = array_map(fn($p) => $p->getName(), $parameters);
        $this->assertSame(['contentId', 'mediaId', 'position'], $names);
    }

    /**
     * The route the two row actions posted to. A live URL that still flipped a column nothing
     * reads would be the worst of the three states to leave this in.
     */
    public function testThereIsNoVisibilityEndpointLeft(): void {
        $paths = [];
        foreach ((new ReflectionClass(ContentAdminController::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Route::class) as $attribute) {
                $paths[] = $attribute->newInstance()->path;
            }
        }
        // the editor's own routes are here, so an empty list would mean the reflection found
        // nothing and this asserted nothing
        $this->assertContains('/admin/content/?/attach/?', $paths);
        $this->assertNotContains('/admin/content/?/attachment-visibility/?', $paths);
    }

    // --- the query ---

    /**
     * The editor's list and the public one ask the same question and get the same answer, which
     * is the whole of what the flag was buying
     */
    public function testTheAttachmentQueryFiltersNothingByVisibility(): void {
        $em = $this->createMock(EntityManager::class);
        $em->method('safeTableName')->willReturn('`dp_media`');
        $queries = new CoreQueries($em);
        $sql = join(' ', $queries->contentAttachments(['content_id' => 7])->conditions());
        $this->assertStringContainsString('`ca`.`content_id`', $sql);
        $this->assertStringNotContainsString('hidden', $sql);
    }

    // --- what "in use" means once an image is not attached ---

    /**
     * Deleting a picture an article shows has to warn about it
     *
     * With no attachment row behind an inline image, counting only `content_attachment` would
     * call the most common use of a picture "unused" and let a purge go through without a word.
     * So the markdown is searched too - a `like` candidate count, deliberately loose, and
     * advisory rather than blocking.
     */
    public function testUsageCountsTheBodyTextAsWellAsTheAttachments(): void {
        $db = new StubDatabase();
        $service = $this->serviceWith($db);

        $service->usageCount(12);

        $this->assertCount(1, $db->queries, 'one question, three sources');
        $query = $db->queries[0];
        $this->assertStringContainsString('`content_attachment`', $query['sql']);
        $this->assertStringContainsString('`featured_media_id`', $query['sql']);
        $this->assertStringContainsString('`markdown` like', $query['sql']);
        $this->assertContains('%media#12%', $query['params']);
    }

    /**
     * A post that attaches a file *and* shows it in the body is one thing that breaks, not two
     */
    public function testTheCountIsOfContentRatherThanOfMentions(): void {
        $db = new StubDatabase();
        $db->column = ['4', '9'];
        $this->assertSame(2, $this->serviceWith($db)->usageCount(12));
        $this->assertStringContainsString(
            'union', strtolower($db->queries[0]['sql']),
            'a union rather than three counts added up, or the same post is counted twice'
        );
    }

    /**
     * `MediaService` takes ten collaborators and this exercises two of them
     */
    private function serviceWith(StubDatabase $db): MediaService {
        $em = $this->createMock(EntityManager::class);
        $em->method('safeTableName')->willReturnCallback(
            fn(string $className) => $className === Content::class ? '`dp_content`' : '`content_attachment`'
        );
        $service = (new ReflectionClass(MediaService::class))->newInstanceWithoutConstructor();
        foreach (['em' => $em, 'db' => $db] as $name => $value) {
            $property = (new ReflectionClass(MediaService::class))->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($service, $value);
        }
        return $service;
    }

    // --- what the editor shows ---

    /**
     * `Media` is what the list renders, and it never carried the flag; this is here so that a
     * column named after it cannot be added back without a test noticing
     */
    public function testTheListedColumnsAreColumnsOfTheMedia(): void {
        $this->assertFalse(property_exists(Media::class, 'hidden'));
    }
}
