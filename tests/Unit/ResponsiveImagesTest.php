<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\Slugger;
use Dynart\Dpress\Entity\Media;
use Dynart\Dpress\Media\ImageProcessor;
use Dynart\Dpress\Media\MediaStorage;
use Dynart\Dpress\Media\MediaView;
use Dynart\Dpress\Test\StubConfig;
use Dynart\Dpress\Test\StubView;
use PHPUnit\Framework\TestCase;

/**
 * The sizes the presets come out at, and the `srcset` a theme builds from them (0.87.0)
 *
 * The thumb keeps the picture's shape since 0.87.0, so a small card can take it where it once
 * had to take `medium` to avoid a square crop.
 */
final class ResponsiveImagesTest extends TestCase {

    private function processor(): ImageProcessor {
        return new ImageProcessor(new StubConfig());
    }

    private function view(): MediaView {
        $config = new StubConfig(['app.base_url' => 'https://x.test']);
        return new MediaView($config, new StubView(), new MediaStorage($config, new Slugger()), $this->processor());
    }

    private function image(int $width, int $height, string $mime = 'image/jpeg'): Media {
        $media = new Media();
        $media->category = Media::CATEGORY_IMAGE;
        $media->mime_type = $mime;
        $media->path = '2026/09/shot-a1b2c3.jpg';
        $media->width = $width;
        $media->height = $height;
        return $media;
    }

    public function testTheThumbKeepsThePicturesShape(): void {
        $this->assertSame([320, 97], $this->processor()->outputSize(1244, 378, 'thumb'));
        $this->assertSame([213, 320], $this->processor()->outputSize(1000, 1500, 'thumb'));
    }

    public function testAPictureThatFitsIsItsOwnSize(): void {
        $this->assertSame([200, 100], $this->processor()->outputSize(200, 100, 'thumb'));
        $this->assertNull($this->processor()->outputSize(200, 100, 'nosuchsize'));
    }

    private function v(string $preset): string {
        return '?v='.$this->processor()->presetVersion($preset);
    }

    public function testTheSrcsetListsEachFileAtItsRealWidthSmallestFirst(): void {
        $this->assertSame(
            'https://x.test/uploads/2026/09/shot-a1b2c3-thumb.jpg'.$this->v('thumb').' 320w, https://x.test/uploads/2026/09/shot-a1b2c3-medium.jpg'.$this->v('medium').' 768w',
            $this->view()->srcset($this->image(1920, 1080), ['medium', 'thumb'])
        );
    }

    /** a small original is a copy in both presets: one width, one candidate */
    public function testTwoPresetsOfTheSameSizeAreOne(): void {
        $this->assertSame(
            'https://x.test/uploads/2026/09/shot-a1b2c3-thumb.jpg'.$this->v('thumb').' 200w',
            $this->view()->srcset($this->image(200, 100), ['thumb', 'medium'])
        );
    }

    public function testWhatCannotBeDescribedHasNoSrcset(): void {
        $this->assertSame('', $this->view()->srcset($this->image(100, 100, 'image/svg+xml'), ['thumb']));
        $unknown = $this->image(100, 100);
        $unknown->width = null;
        $this->assertSame('', $this->view()->srcset($unknown, ['thumb']));
    }

    /**
     * A derivative is rebuilt under the same name and an upload is cached for a month, so the
     * address carries what the preset makes: a changed preset is an address no browser has cached
     */
    public function testADerivativesAddressChangesWithItsPreset(): void {
        $square = new ImageProcessor(new StubConfig([ImageProcessor::CONFIG_PRESETS => ['thumb' => [320, 320, true]]]));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{6}$/', $this->processor()->presetVersion('thumb'));
        $this->assertNotSame($square->presetVersion('thumb'), $this->processor()->presetVersion('thumb'));
        $this->assertStringEndsWith('-thumb.jpg'.$this->v('thumb'), $this->view()->url($this->image(1920, 1080), 'thumb'));
        // the original is one file forever, and has no preset to name
        $this->assertSame('https://x.test/uploads/2026/09/shot-a1b2c3.jpg', $this->view()->url($this->image(1920, 1080)));
    }

    public function testAnEmptySrcsetIsLeftOffTheTag(): void {
        $tag = $this->view()->tag($this->image(100, 100, 'image/svg+xml'), 'medium', ['srcset' => '', 'sizes' => '']);
        $this->assertStringNotContainsString('srcset', $tag);
        $this->assertStringNotContainsString('sizes', $tag);
    }
}
