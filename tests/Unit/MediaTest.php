<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\Slugger;
use Dynart\Dpress\Entity\Media;
use Dynart\Dpress\Media\ImageProcessor;
use Dynart\Dpress\Media\MediaStorage;
use Dynart\Dpress\Media\MediaTypes;
use Dynart\Dpress\Test\StubConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Dynart\Dpress\Media\MediaTypes
 * @covers \Dynart\Dpress\Media\MediaStorage
 * @covers \Dynart\Dpress\Media\ImageProcessor
 * @covers \Dynart\Dpress\Entity\Media
 */
class MediaTest extends TestCase {

    private MediaTypes $types;

    protected function setUp(): void {
        $this->types = new MediaTypes();
    }

    // --- Types ---

    public function testTheAllowlistAcceptsTheUsualTypes(): void {
        $this->assertTrue($this->types->isAllowed('image/jpeg'));
        $this->assertTrue($this->types->isAllowed('application/pdf'));
        $this->assertTrue($this->types->isAllowed('image/svg+xml'));
    }

    /**
     * An allowlist, not a blocklist: a blocklist is a promise to have thought of every dangerous
     * extension, which nobody can keep.
     */
    public function testAnythingElseIsRefused(): void {
        $this->assertFalse($this->types->isAllowed('application/x-httpd-php'));
        $this->assertFalse($this->types->isAllowed('application/octet-stream'));
        $this->assertFalse($this->types->isAllowed(''));
    }

    public function testCategories(): void {
        $this->assertSame(Media::CATEGORY_IMAGE, $this->types->categoryOf('image/png'));
        $this->assertSame(Media::CATEGORY_SHEET, $this->types->categoryOf('text/csv'));
        $this->assertSame(Media::CATEGORY_ARCHIVE, $this->types->categoryOf('application/zip'));
        $this->assertSame(Media::CATEGORY_OTHER, $this->types->categoryOf('application/unknown'));
    }

    /**
     * The stored extension comes from the sniffed type, not from what the uploader typed.
     */
    public function testExtensionsComeFromTheType(): void {
        $this->assertSame('jpg', $this->types->extensionOf('image/jpeg'));
        $this->assertSame('svg', $this->types->extensionOf('image/svg+xml'));
        $this->assertSame('bin', $this->types->extensionOf('application/unknown'));
    }

    public function testEveryAllowedTypeHasAnExtension(): void {
        foreach (array_keys(MediaTypes::ALLOWED) as $mimeType) {
            $this->assertArrayHasKey($mimeType, MediaTypes::EXTENSIONS, "$mimeType has no extension");
        }
    }

    public function testEveryCategoryUsedIsAKnownOne(): void {
        foreach (MediaTypes::ALLOWED as $category) {
            $this->assertContains($category, Media::CATEGORIES);
        }
    }

    // --- Storage naming ---

    private function storage(): MediaStorage {
        return new MediaStorage(new StubConfig([MediaStorage::CONFIG_PATH => sys_get_temp_dir().'/dpress-test-media']), new Slugger());
    }

    public function testTheStoredNameIsTheSlugPlusASuffix(): void {
        $path = $this->storage()->reservePath('My Photo.JPG', 'jpg');
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/my-photo-[0-9a-f]{6}\.jpg$#', $path);
    }

    public function testAccentedNamesAreFolded(): void {
        $path = $this->storage()->reservePath('Árvíztűrő.png', 'png');
        $this->assertStringContainsString('arvizturo-', $path);
    }

    /**
     * Random, not a content hash: uploading the same bytes twice gives two items rather than
     * silently reusing a file somebody thought they had replaced.
     */
    public function testTwoUploadsOfTheSameNameGetDifferentPaths(): void {
        $storage = $this->storage();
        $this->assertNotSame(
            $storage->reservePath('photo.jpg', 'jpg'),
            $storage->reservePath('photo.jpg', 'jpg')
        );
    }

    public function testANameWithNothingUsableStillWorks(): void {
        $this->assertStringContainsString('file-', $this->storage()->reservePath('!!!.jpg', 'jpg'));
    }

    public function testDerivativePathKeepsTheExtension(): void {
        $this->assertSame(
            '2026/08/photo-a1b2c3-thumb.jpg',
            $this->storage()->derivativePath('2026/08/photo-a1b2c3.jpg', 'thumb')
        );
    }

    // --- Presets ---

    public function testTheDefaultPresets(): void {
        $processor = new ImageProcessor(new StubConfig());
        $this->assertTrue($processor->hasPreset('thumb'));
        $this->assertTrue($processor->hasPreset('medium'));
        $this->assertFalse($processor->hasPreset('nosuchsize'));
    }

    // --- The entity ---

    public function testIsResizable(): void {
        $media = new Media();
        $media->category = Media::CATEGORY_IMAGE;
        $media->mime_type = 'image/png';
        $this->assertTrue($media->isResizable());
    }

    /**
     * An SVG is an image but has no raster to resize, so it is served at its own size.
     */
    public function testAnSvgIsAnImageButNotResizable(): void {
        $media = new Media();
        $media->category = Media::CATEGORY_IMAGE;
        $media->mime_type = 'image/svg+xml';
        $this->assertTrue($media->isImage());
        $this->assertFalse($media->isResizable());
    }

    public function testADocumentIsNotResizable(): void {
        $media = new Media();
        $media->category = Media::CATEGORY_DOCUMENT;
        $media->mime_type = 'application/pdf';
        $this->assertFalse($media->isResizable());
    }

    public function testIsDeleted(): void {
        $media = new Media();
        $this->assertFalse($media->isDeleted());
        $media->deleted_at = '2026-08-04 12:00:00';
        $this->assertTrue($media->isDeleted());
    }

    public function testLabelFallsBackToTheFileName(): void {
        $media = new Media();
        $media->file_name = 'photo.jpg';
        $this->assertSame('photo.jpg', $media->label());
        $media->title = 'A sunset';
        $this->assertSame('A sunset', $media->label());
    }

    // --- the uploads .htaccess ---

    /**
     * Apache does not skip a directive it does not know
     *
     * It refuses the whole directory, with a 500 and `Invalid command` in the log. `php_flag`
     * belongs to mod_php and `Header` to mod_headers, so an unguarded one turned every image on
     * a PHP-FPM site into a server error - and the file is written once at install, so there
     * was no way back out of it either.
     */
    public function testEveryModuleDirectiveIsGuarded(): void {
        $depth = 0;
        $unguarded = [];
        foreach (explode("\n", MediaStorage::PROTECTION) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '<IfModule')) {
                $depth++;
                continue;
            }
            if ($line === '</IfModule>') {
                $depth--;
                continue;
            }
            $needsModule = str_starts_with($line, 'php_flag') || str_starts_with($line, 'php_value')
                || str_starts_with($line, 'Header');
            if ($needsModule && $depth === 0) {
                $unguarded[] = $line;
            }
        }
        $this->assertSame(0, $depth, 'an <IfModule> is not closed');
        $this->assertSame([], $unguarded, 'a directive outside <IfModule> 500s the whole folder');
    }

    /**
     * The lock that does not depend on a module being loaded
     *
     * `php_flag engine off` does nothing under FPM even when it is guarded, so refusing to
     * serve those files at all is what actually stops an uploaded `.php` being a remote shell.
     * It must stay outside every `<IfModule>`.
     */
    public function testExecutablesAreRefusedWhateverModulesAreLoaded(): void {
        $rule = MediaStorage::PROTECTION;
        $this->assertMatchesRegularExpression(
            '#<FilesMatch "\\\\\.\(php\|[^"]*"\>\s*\n\s*Require all denied#',
            $rule,
            'the deny rule is not where it was'
        );
        foreach (['php', 'phtml', 'phar', 'cgi', 'htaccess'] as $extension) {
            $this->assertStringContainsString($extension, $rule, "$extension is not refused");
        }
        // the deny block starts at column 0, so nothing indented it into an <IfModule>
        $this->assertStringContainsString("\n<FilesMatch", $rule);
    }
}
