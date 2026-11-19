<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Service\MediaService;
use Dynart\Dpress\Test\StubConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Two limits, because there are two ways in
 *
 * An upload is a request body and PHP has an opinion about how big one may be. A file imported
 * from disk never was a request, and holding it to `upload_max_filesize` refused a 9 MB mp3 for
 * being "larger than the 2 MB limit" - a number out of a php.ini the command line uploads
 * nothing under. Found by the music plugin's import, which is fifty of those in a row.
 *
 * @covers \Dynart\Dpress\Service\MediaService
 */
class MediaSizeLimitTest extends TestCase {

    private function service(array $config): MediaService {
        $service = (new ReflectionClass(MediaService::class))->newInstanceWithoutConstructor();
        $property = (new ReflectionClass(MediaService::class))->getProperty('config');
        $property->setAccessible(true);
        $property->setValue($service, new StubConfig($config));
        return $service;
    }

    public function testTheLibrarysOwnLimitIsWhatIsConfigured(): void {
        $this->assertSame(MediaService::DEFAULT_MAX_SIZE, $this->service([])->maxSize());
        $this->assertSame(1024, $this->service([MediaService::CONFIG_MAX_SIZE => 1024])->maxSize());
    }

    /**
     * A terabyte is past any `upload_max_filesize` there is, so if the two answers are the same
     * the import is being held to the upload's limit again
     */
    public function testAnImportIsNotHeldToPhpsUploadLimit(): void {
        $terabyte = 1024 ** 4;
        $service = $this->service([MediaService::CONFIG_MAX_SIZE => $terabyte]);
        $this->assertSame($terabyte, $service->maxSize());
        if ((string)ini_get('upload_max_filesize') === '' || (int)ini_get('upload_max_filesize') === 0) {
            $this->markTestSkipped('This PHP has no upload limit to be capped by.');
        }
        $this->assertLessThan($terabyte, $service->maxUploadSize(), 'an upload still is');
    }

    public function testAnUploadIsNeverAllowedMoreThanTheLibraryHolds(): void {
        $service = $this->service([MediaService::CONFIG_MAX_SIZE => 1024]);
        $this->assertSame(1024, $service->maxUploadSize());
    }

    /**
     * The check itself: `store()` asks one limit for an upload and the other for an import
     */
    public function testStoreChoosesTheLimitByHowTheFileArrived(): void {
        $source = (string)file_get_contents((new ReflectionClass(MediaService::class))->getFileName());
        $this->assertStringContainsString('$isUpload ? $this->maxUploadSize() : $this->maxSize()', $source);
    }
}
