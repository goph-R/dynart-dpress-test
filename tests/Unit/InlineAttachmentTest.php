<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\Slugger;
use Dynart\Dpress\Media\ImageProcessor;
use Dynart\Dpress\Media\MediaStorage;
use Dynart\Dpress\Service\MediaService;
use Dynart\Dpress\Test\StubConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Which library items a piece of markdown points at
 *
 * An image in the body is attached so that "is this file still used" has a true answer - but
 * attaching at *upload* time cannot work, because a new post has no id until it is saved and
 * nothing would notice the image being deleted from the text again. So the text is the truth,
 * exactly as it is for everything else in the content model, and pasting a URL by hand behaves
 * the same as using the picker.
 *
 * This is the part with real logic. The attaching and detaching around it is two `array_diff`s.
 *
 * @covers \Dynart\Dpress\Service\MediaService
 */
class InlineAttachmentTest extends TestCase {

    /**
     * A media service with only the two collaborators this path uses
     */
    private function service(): MediaService {
        $config = new StubConfig([
            MediaStorage::CONFIG_URL => '/uploads',
            ImageProcessor::CONFIG_PRESETS => ImageProcessor::DEFAULT_PRESETS,
        ]);
        $service = (new ReflectionClass(MediaService::class))->newInstanceWithoutConstructor();
        $this->give($service, 'storage', new MediaStorage($config, new Slugger()));
        $this->give($service, 'images', new ImageProcessor($config));
        return $service;
    }

    private function give(object $target, string $property, mixed $value): void {
        $found = (new ReflectionClass(MediaService::class))->getProperty($property);
        $found->setAccessible(true);
        $found->setValue($target, $value);
    }

    /**
     * @return string[] The stored paths a markdown body refers to
     */
    private function paths(string $markdown): array {
        $service = $this->service();
        $method = (new ReflectionClass(MediaService::class))->getMethod('sourcePath');
        $method->setAccessible(true);

        preg_match_all('#/uploads/([\w./-]+\.\w+)#i', $markdown, $matches);
        $paths = [];
        foreach (array_unique($matches[1]) as $path) {
            $paths[$method->invoke($service, $path)] = true;
        }
        return array_keys($paths);
    }

    // --- what counts as a reference ---

    public function testAMarkdownImageIsAReference(): void {
        $this->assertSame(
            ['2026/08/photo-a1b2c3.jpg'],
            $this->paths('Before ![A sunset](/uploads/2026/08/photo-a1b2c3.jpg) after.')
        );
    }

    /**
     * The renderer strips HTML, so it will not reach the page - but it is still a reference, and
     * a file that is referenced must not be reported as unused
     */
    public function testARawImgTagIsAReference(): void {
        $this->assertSame(
            ['2026/08/photo-a1b2c3.jpg'],
            $this->paths('<img src="/uploads/2026/08/photo-a1b2c3.jpg" alt="">')
        );
    }

    /**
     * Matched on the path, not the whole URL: `app.base_url` may be a full URL today and a
     * different host tomorrow, and a stored document must not stop resolving because the site
     * moved - the same reasoning as `siteAsset()`
     */
    public function testAFullUrlResolvesToTheSameFileAsABareOne(): void {
        $this->assertSame(
            ['2026/08/photo-a1b2c3.jpg'],
            $this->paths('![a](https://example.com/uploads/2026/08/photo-a1b2c3.jpg)')
        );
    }

    public function testTheSameImageTwiceIsOneReference(): void {
        $markdown = '![a](/uploads/2026/08/photo-a1b2c3.jpg) and again '
            .'![b](/uploads/2026/08/photo-a1b2c3.jpg)';
        $this->assertCount(1, $this->paths($markdown));
    }

    public function testAnImageOnSomebodyElsesServerIsNotOursToAttach(): void {
        $this->assertSame([], $this->paths('![a](https://example.com/images/photo.jpg)'));
    }

    public function testTextWithNoImagesReferencesNothing(): void {
        $this->assertSame([], $this->paths("# A title\n\nJust words, and a [link](https://example.com)."));
    }

    // --- derivatives ---

    /**
     * `photo-a1b2c3-medium.jpg` is a generated size of `photo-a1b2c3.jpg`, and only the original
     * is a row in the library. Getting this wrong finds no media, attaches nothing, and shows up
     * much later as "deleting this image did not warn me it was in use".
     */
    public function testADerivativeResolvesToTheStoredFile(): void {
        foreach (['thumb', 'medium', 'large'] as $preset) {
            $this->assertSame(
                ['2026/08/photo-a1b2c3.jpg'],
                $this->paths("![a](/uploads/2026/08/photo-a1b2c3-$preset.jpg)"),
                "the $preset derivative did not resolve to its original"
            );
        }
    }

    /**
     * Only a preset this installation actually has is stripped, so a file somebody genuinely
     * named `notes-draft.txt` keeps its name
     */
    public function testASuffixThatIsNotAPresetIsLeftAlone(): void {
        $this->assertSame(
            ['2026/08/notes-draft.txt'],
            $this->paths('[notes](/uploads/2026/08/notes-draft.txt)')
        );
    }

    public function testADerivativeAndItsOriginalAreOneReference(): void {
        $markdown = '![a](/uploads/2026/08/photo-a1b2c3.jpg) ![b](/uploads/2026/08/photo-a1b2c3-thumb.jpg)';
        $this->assertSame(['2026/08/photo-a1b2c3.jpg'], $this->paths($markdown));
    }
}
