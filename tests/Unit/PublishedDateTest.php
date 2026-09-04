<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\Slugger;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Test\StubConfig;
use Dynart\Dpress\Test\StubDatabase;
use Dynart\Micro\Entities\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * A `ContentService` that renders nothing and remembers what it announced
 */
class DatedContent extends ContentService {

    public RecordingEvents $recorded;

    public function __construct(EntityManager $em, StubDatabase $db) {
        $this->em = $em;
        $this->db = $db;
        $this->slugger = new Slugger();
        $this->recorded = new RecordingEvents();
        $this->events = $this->recorded;
    }

    public function renderInto(Content $content): void {}
}

/**
 * Moving the moment a published post says it went out
 *
 * What a migration is: a post written in 2014 is published *today* and dated *then*, and the
 * archive, the ordering, the byline and `published_at <= now` all read that one column. The
 * status select cannot say it - the post is published before and after - so it needs a method of
 * its own beside `publish()` rather than another field `update()` writes.
 *
 * @covers \Dynart\Dpress\Service\ContentService
 */
class PublishedDateTest extends TestCase {

    private function service(): DatedContent {
        $db = new StubDatabase();
        $em = new EntityManager(new StubConfig(), $db, new RecordingEvents());
        $em->registerEntity(Content::class);
        return new DatedContent($em, $db);
    }

    private function published(string $at = '2026-09-04 10:00:00'): Content {
        $content = new Content();
        $content->id = 12;
        $content->type = Content::TYPE_POST;
        $content->status = Content::STATUS_PUBLISHED;
        $content->published_at = $at;
        return $content;
    }

    public function testTheDateMoves(): void {
        $service = $this->service();
        $content = $this->published();
        $service->setPublishedAt($content, '2014-03-08 23:00:00');
        $this->assertSame('2014-03-08 23:00:00', $content->published_at);
    }

    /**
     * Not `content:published`, which means "this just went out" - a listener that mails, pings a
     * feed or warms a cache would do it all again for a date correction, and a date correction is
     * what importing an old post is
     */
    public function testItIsAnnouncedAsSomethingOtherThanAFreshPublication(): void {
        $service = $this->service();
        $service->setPublishedAt($this->published(), '2014-03-08 23:00:00');
        $emitted = $service->recorded->emitted;
        $this->assertContains(ContentService::EVENT_RESCHEDULED, $emitted);
        $this->assertNotContains(ContentService::EVENT_PUBLISHED, $emitted);
    }

    /**
     * A draft has no date to move. Dating one would make it look published to everything that
     * reads the column while the status still says otherwise.
     */
    public function testADraftIsLeftAlone(): void {
        $service = $this->service();
        $draft = new Content();
        $draft->id = 13;
        $draft->status = Content::STATUS_DRAFT;
        $service->setPublishedAt($draft, '2014-03-08 23:00:00');
        $this->assertNull($draft->published_at);
        $this->assertSame([], $service->recorded->emitted);
    }

    /**
     * Saving a post without touching the box hands back the date it already had, and that is not
     * a change - it must not bump `updated_at` or announce anything
     */
    public function testTheSameDateAgainIsNothingAtAll(): void {
        $service = $this->service();
        $content = $this->published('2014-03-08 23:00:00');
        $content->updated_at = '2020-01-01 00:00:00';
        $service->setPublishedAt($content, '2014-03-08 23:00:00');
        $this->assertSame('2020-01-01 00:00:00', $content->updated_at);
        $this->assertSame([], $service->recorded->emitted);
    }
}
