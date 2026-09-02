<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\Shortcode\VideoShortcode;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * `{{ video(…) }}` and the addresses it recognises
 *
 * One shortcode for every kind of video, dispatching on what it was handed. The library half needs
 * a database and is exercised on the running site; the link half is a string going in and a string
 * coming out, which is where the mistakes are - a host check that matches `notyoutube.com`, an id
 * that swallows a query string, a start time silently dropped.
 *
 * @covers \Dynart\Dpress\Content\Shortcode\VideoShortcode
 */
class VideoShortcodeTest extends TestCase {

    private function embed(string $url): ?string {
        $method = new ReflectionMethod(VideoShortcode::class, 'embedUrl');
        $method->setAccessible(true);
        // no dependency is reached on this path, so there is nothing to stand in for
        return $method->invoke((new \ReflectionClass(VideoShortcode::class))->newInstanceWithoutConstructor(), $url);
    }

    public function testTheWaysPeopleActuallyCopyAYouTubeLink(): void {
        $expected = 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ';
        $this->assertSame($expected, $this->embed('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
        $this->assertSame($expected, $this->embed('https://youtube.com/watch?v=dQw4w9WgXcQ'));
        $this->assertSame($expected, $this->embed('https://youtu.be/dQw4w9WgXcQ'));
        $this->assertSame($expected, $this->embed('https://www.youtube.com/embed/dQw4w9WgXcQ'));
        $this->assertSame($expected, $this->embed('https://m.youtube.com/watch?v=dQw4w9WgXcQ'));
    }

    /**
     * The player is served from the domain that stores nothing until somebody presses play. Both
     * work identically, so the quieter one is the one to put on other people's sites.
     */
    public function testTheEmbedIsTheNoCookieDomain(): void {
        $this->assertStringStartsWith(
            'https://www.youtube-nocookie.com/', (string)$this->embed('https://youtu.be/dQw4w9WgXcQ')
        );
    }

    /**
     * A share link with a timestamp is somebody having taken care over where it starts, and the
     * player spells the same thing differently
     */
    public function testAStartTimeSurvives(): void {
        $this->assertSame(
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?start=90',
            $this->embed('https://youtu.be/dQw4w9WgXcQ?t=90')
        );
        $this->assertSame(
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?start=42',
            $this->embed('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42')
        );
    }

    public function testVimeo(): void {
        $this->assertSame('https://player.vimeo.com/video/76979871', $this->embed('https://vimeo.com/76979871'));
        $this->assertSame(
            'https://player.vimeo.com/video/76979871', $this->embed('https://player.vimeo.com/video/76979871')
        );
    }

    /**
     * The check that matters: a host ending in the right letters is not the right host
     */
    public function testALookalikeHostIsNotYouTube(): void {
        $this->assertNull($this->embed('https://notyoutube.com/watch?v=dQw4w9WgXcQ'));
        $this->assertNull($this->embed('https://youtube.com.example.net/watch?v=dQw4w9WgXcQ'));
        $this->assertNull($this->embed('https://evilvimeo.com/76979871'));
    }

    public function testSomethingThatIsNotAVideoSiteAtAll(): void {
        $this->assertNull($this->embed('https://example.com/a-page'));
        $this->assertNull($this->embed('not even a url'));
        $this->assertNull($this->embed('https://www.youtube.com/'), 'a bare youtube link has no video in it');
    }

    /**
     * A direct file is played rather than embedded, so the two branches must not overlap
     */
    public function testADirectFileIsNotAnEmbed(): void {
        $method = new ReflectionMethod(VideoShortcode::class, 'isDirectFile');
        $method->setAccessible(true);
        $shortcode = (new \ReflectionClass(VideoShortcode::class))->newInstanceWithoutConstructor();
        $this->assertTrue($method->invoke($shortcode, 'https://example.com/clip.mp4'));
        $this->assertTrue($method->invoke($shortcode, 'https://example.com/a/b/clip.WEBM?x=1'));
        $this->assertFalse($method->invoke($shortcode, 'https://youtu.be/dQw4w9WgXcQ'));
    }
}
