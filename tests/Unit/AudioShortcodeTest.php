<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\Shortcode\AudioShortcode;
use Dynart\Dpress\Content\Shortcode\VideoShortcode;
use Dynart\Dpress\DpressServices;
use Dynart\Dpress\Entity\Media;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * `{{ audio(…) }}`, and the machinery it shares with `{{ video(…) }}`
 *
 * The library half needs a database and is exercised on the running site; everything here is a
 * string going in and a string coming out. That is where the mistakes are: an extension list that
 * disagrees with what the library calls an audio file, a refusal that names the wrong noun, a URL
 * going into an attribute unescaped.
 *
 * @covers \Dynart\Dpress\Content\Shortcode\AudioShortcode
 * @covers \Dynart\Dpress\Content\Shortcode\AbstractMediaShortcode
 */
class AudioShortcodeTest extends TestCase {

    /** No dependency is reached on any of these paths, so there is nothing to stand in for */
    private function shortcode(string $class = AudioShortcode::class) {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private function render(array $arguments, string $class = AudioShortcode::class): string {
        return $this->shortcode($class)->render($arguments);
    }

    public function testAFileItCanPlayBecomesAPlayer(): void {
        $html = $this->render(['https://example.com/theme.mp3']);
        $this->assertStringContainsString('<audio class="dpress-audio"', $html);
        $this->assertStringContainsString('controls', $html);
        $this->assertStringContainsString('src="https://example.com/theme.mp3"', $html);
    }

    /**
     * A browser that will not play it still has to leave somebody a way to get the file
     */
    public function testThereIsAWayOutForABrowserThatCannotPlayIt(): void {
        $html = $this->render(['https://example.com/theme.mp3']);
        $this->assertStringContainsString('<a href="https://example.com/theme.mp3">Download the audio</a>', $html);
    }

    /**
     * The extensions a recorder actually writes, not only the two everybody remembers
     */
    public function testWhatCountsAsAnAudioFile(): void {
        foreach (['mp3', 'm4a', 'aac', 'oga', 'ogg', 'opus', 'wav', 'flac', 'weba'] as $extension) {
            $this->assertStringContainsString(
                '<audio', $this->render(['https://example.com/a.'.$extension]),
                ".$extension should be playable"
            );
        }
    }

    /**
     * `.ogg` is on both lists deliberately - the extension says nothing about which one a file is,
     * and the author already said which they meant by the name they typed
     */
    public function testOggIsBothAndTheNameDecides(): void {
        $this->assertStringContainsString('<audio', $this->render(['https://example.com/a.ogg']));
        $this->assertStringContainsString('<video', $this->render(['https://example.com/a.ogg'], VideoShortcode::class));
    }

    /**
     * A video handed to `audio` is a mistake worth reporting rather than an empty player
     */
    public function testAVideoFileIsNotPlayedAsAudio(): void {
        $this->assertSame(
            '<!-- audio: that is not an audio file or an address this understands -->',
            $this->render(['https://example.com/clip.mp4'])
        );
    }

    public function testAnEmptyCallSaysWhatIsMissing(): void {
        $this->assertSame('<!-- audio: an audio needs something to play -->', $this->render([]));
    }

    /**
     * Video has YouTube and Vimeo because that is where people's videos already are. Audio has no
     * equivalent, so a page somebody pasted is refused rather than handed to a player that would
     * fail silently and look like the CMS is broken.
     */
    public function testAPageIsRefusedRatherThanPlayed(): void {
        $this->assertStringStartsWith('<!-- audio:', $this->render(['https://open.spotify.com/track/abc']));
    }

    /**
     * The URL goes into an attribute, and the library is not the only thing that can put one here
     */
    public function testAQuoteInTheAddressCannotLeaveTheAttribute(): void {
        $html = $this->render(['https://example.com/a".mp3']);
        $this->assertStringNotContainsString('a".mp3"', $html);
        $this->assertStringContainsString('&quot;', $html);
    }

    /**
     * Every refusal is built from the noun, and `a audio` is what that says without this
     */
    public function testTheRefusalReadsAsEnglishWhereItIsBuiltFromTheNoun(): void {
        $article = new ReflectionMethod(AudioShortcode::class, 'article');
        $article->setAccessible(true);
        $this->assertSame('an audio', $article->invoke($this->shortcode()));
        $this->assertSame('a video', $article->invoke($this->shortcode(VideoShortcode::class)));
    }

    /**
     * The category it accepts is the one the library assigns, not a second opinion about mime
     * types - `MediaService` decides what an audio file is and this has to agree with it
     */
    public function testItAcceptsTheCategoryTheLibraryAssigns(): void {
        $this->assertSame(Media::CATEGORY_AUDIO, AudioShortcode::CATEGORY);
        $this->assertContains(Media::CATEGORY_AUDIO, Media::CATEGORIES);
    }

    /**
     * Registered, or the editor's insert button writes a shortcode nothing renders
     */
    public function testItIsRegisteredAsACoreShortcode(): void {
        $this->assertArrayHasKey('audio', DpressServices::SHORTCODES);
        $this->assertSame([AudioShortcode::class, 'render'], DpressServices::SHORTCODES['audio'][0]);
    }

    /**
     * The editor writes `{{ audio('media#5') }}`, so what it writes has to be what this reads
     */
    public function testTheEditorWritesSomethingThisCanRead(): void {
        $written = file_get_contents(\Dynart\Dpress\Dpress::path('assets/admin.js'));
        $this->assertStringContainsString("MEDIA_PLAYERS = {video: 'video', audio: 'audio'}", $written,
            'admin.js no longer writes a player for the categories these two shortcodes cover');
    }
}
