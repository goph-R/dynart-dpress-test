<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\Slugger;
use Dynart\Dpress\Media\ImageProcessor;
use Dynart\Dpress\Media\MediaStorage;
use Dynart\Dpress\Test\StubConfig;
use PHPUnit\Framework\TestCase;

/**
 * A GIF's thumb is a JPEG of its first frame (0.87.0) - a list of thumbnails that move is noise
 *
 * The bigger presets stay GIFs: a post shows those, and the animation is why it is a GIF.
 */
final class GifStillTest extends TestCase {

    private function storage(): MediaStorage {
        return new MediaStorage(new StubConfig([MediaStorage::CONFIG_PATH => sys_get_temp_dir().'/dpress-test-media']), new Slugger());
    }

    public function testAGifsThumbIsAJpeg(): void {
        $this->assertSame('2026/09/dance-a1b2c3-thumb.jpg', $this->storage()->derivativePath('2026/09/dance-a1b2c3.gif', 'thumb'));
        $this->assertSame('2026/09/dance-a1b2c3-medium.gif', $this->storage()->derivativePath('2026/09/dance-a1b2c3.gif', 'medium'));
        $this->assertSame('2026/09/photo-a1b2c3-thumb.png', $this->storage()->derivativePath('2026/09/photo-a1b2c3.png', 'thumb'));
    }

    /** the thumb a GIF had before - a GIF - is cleared too, or `media:regenerate` would leave it */
    public function testClearingKnowsTheOldNameToo(): void {
        $this->assertSame(
            ['2026/09/dance-a1b2c3-thumb.jpg', '2026/09/dance-a1b2c3-thumb.gif'],
            $this->storage()->derivativePaths('2026/09/dance-a1b2c3.gif', 'thumb')
        );
        $this->assertSame(['2026/09/photo-a1b2c3-thumb.jpg'], $this->storage()->derivativePaths('2026/09/photo-a1b2c3.jpg', 'thumb'));
    }

    /**
     * An optimised GIF's first frame is a box somewhere on the canvas; GD answers the box alone,
     * so the canvas and the box's place are read from the bytes
     */
    public function testTheFirstFramesPlaceIsReadFromTheGif(): void {
        $bytes = 'GIF89a'.pack('vvCCC', 256, 256, 0x80, 0, 0)   // a 256x256 screen, a 2 colour table
            .str_repeat("\0", 6)
            ."\x21\xF9\x04\x01\x00\x00\x00\x00"                  // a graphic control extension
            ."\x2C".pack('vvvvC', 48, 65, 171, 191, 0);          // the first frame, 171x191 at 48,65
        $this->assertSame(
            ['width' => 256, 'height' => 256, 'left' => 48, 'top' => 65],
            ImageProcessor::gifFirstFrame($bytes)
        );
        $this->assertNull(ImageProcessor::gifFirstFrame('not a gif at all'));
        $this->assertNull(ImageProcessor::gifFirstFrame('GIF89a'.pack('vvCCC', 1, 1, 0, 0, 0).';'));
    }

    /**
     * Small enough to fit the preset, which used to be a *copy* - the animation with it. Now the
     * format changes, so it is re-encoded: a JPEG, at its own size, see-through made white.
     */
    public function testASmallGifBecomesAStillJpegAtItsOwnSize(): void {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD is not available');
        }
        $dir = sys_get_temp_dir().'/dpress-gif-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $gif = imagecreate(40, 20);
        $clear = imagecolorallocate($gif, 0, 0, 0);
        imagecolortransparent($gif, $clear);
        imagefilledrectangle($gif, 20, 0, 39, 19, imagecolorallocate($gif, 255, 0, 0));
        imagegif($gif, $dir.'/dance.gif');
        imagedestroy($gif);

        (new ImageProcessor(new StubConfig()))->resize($dir.'/dance.gif', $dir.'/dance-thumb.jpg', 'thumb');

        $info = getimagesize($dir.'/dance-thumb.jpg');
        $this->assertSame([40, 20, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]]);
        $still = imagecreatefromjpeg($dir.'/dance-thumb.jpg');
        $left = imagecolorsforindex($still, imagecolorat($still, 5, 10));
        $this->assertGreaterThan(240, min($left['red'], $left['green'], $left['blue']), 'the transparent half is white, not black');
        imagedestroy($still);
        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }
}
