<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Entity\Media;
use Dynart\Dpress\Service\MediaService;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Test\StubConfig;
use Dynart\Dpress\Test\StubDatabase;
use Dynart\Micro\Entities\EntityManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Letting go of a media row before deleting it
 *
 * Two things hold a reference: an attachment, and a post using the item as its featured image.
 * **Both are foreign keys**, so missing either is not a stale pointer left behind - it is the
 * database refusing the delete outright:
 *
 *     Cannot delete or update a parent row: a foreign key constraint fails
 *     (`dp_content`, CONSTRAINT `dp_content_ibfk_2` FOREIGN KEY (`featured_media_id`))
 *
 * which is a true thing to say about the schema and a useless answer to somebody clearing out a
 * library. The attachments were released; the featured image was not.
 *
 * @covers \Dynart\Dpress\Service\MediaService
 */
class MediaPurgeTest extends TestCase {

    private StubDatabase $db;

    private function service(int $featuredCount = 0): MediaService {
        $this->db = new StubDatabase();
        $this->db->answers = [$featuredCount];
        $em = new EntityManager(new StubConfig(), $this->db, new RecordingEvents());
        foreach ([Content::class, Media::class] as $className) {
            $em->registerEntity($className);
        }
        $reflection = new ReflectionClass(MediaService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        foreach (['db' => $this->db, 'em' => $em] as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($service, $value);
        }
        return $service;
    }

    private function clear(MediaService $service, int $mediaId): int {
        $method = new \ReflectionMethod(MediaService::class, 'clearFeatured');
        $method->setAccessible(true);
        return $method->invoke($service, $mediaId);
    }

    /**
     * The row that blocked the delete is the one that has to be let go
     */
    public function testThePostsThatUsedItAreReleased(): void {
        $service = $this->service(2);
        $this->assertSame(2, $this->clear($service, 7));
        $update = $this->db->matching('update');
        $this->assertCount(1, $update);
        $this->assertStringContainsString('`featured_media_id` = null', $update[0]['sql']);
        $this->assertSame(7, $update[0]['params'][':id']);
    }

    /**
     * Nothing pointing at it is the ordinary case - a library is mostly pictures inside posts, and
     * writing to every row of the content table to discover that would be a waste on every purge
     */
    public function testNothingIsWrittenWhenNothingPointsAtIt(): void {
        $service = $this->service(0);
        $this->assertSame(0, $this->clear($service, 7));
        $this->assertSame([], $this->db->matching('update'));
    }

    /**
     * It says how many, because a purge that silently changed three posts is a purge somebody
     * finds out about later
     */
    public function testTheCountIsWhatTheCommandReports(): void {
        $this->assertSame(3, $this->clear($this->service(3), 12));
    }
}
