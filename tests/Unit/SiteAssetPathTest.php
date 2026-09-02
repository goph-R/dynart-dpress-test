<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Content\Slugger;
use Dynart\Dpress\Media\ImageProcessor;
use Dynart\Dpress\Media\MediaStorage;
use Dynart\Dpress\Media\MediaView;
use Dynart\Dpress\Test\StubConfig;
use Dynart\Dpress\Test\StubView;
use PHPUnit\Framework\TestCase;

/**
 * What the picker writes into a setting that names a file by path
 *
 * The site logo and the favicon are **chrome, not content**: they render on pages that show
 * nothing else, they have to work before anything has been uploaded, and deleting a library item
 * must not be able to take the header down. So `Setting::SITE_LOGO` holds a path rather than a
 * media id, and the `asset` field is a text box the picker can fill rather than a picker that
 * replaces it.
 *
 * What the picker writes therefore has to be **site-relative**. An absolute URL would put this
 * machine's hostname into a setting that a production site reads - the exact thing `media#<id>`
 * exists to avoid everywhere else in this CMS.
 *
 * @covers \Dynart\Dpress\Media\MediaView
 */
class SiteAssetPathTest extends TestCase {

    private function view(array $config = []): MediaView {
        $stub = new StubConfig($config + ['app.base_url' => 'http://localhost/dpress']);
        return new MediaView($stub, new StubView(), new MediaStorage($stub, new Slugger()), new ImageProcessor($stub));
    }

    public function testAPickedFileIsNamedFromTheSiteRoot(): void {
        $this->assertSame('/uploads/2026/09/fox-6641fe.mp4', $this->view()->sitePathOf('2026/09/fox-6641fe.mp4'));
    }

    /**
     * The one property that matters: a setting written on a test domain has to keep working when
     * the site becomes a real one
     */
    public function testItCarriesNoHostname(): void {
        $path = $this->view()->sitePathOf('2026/09/fox-6641fe.mp4');
        $this->assertStringNotContainsString('localhost', $path);
        $this->assertStringNotContainsString('http', $path);
        $this->assertStringStartsWith('/', $path);
    }

    /**
     * Where the files are served from is configurable, and the path has to follow it
     */
    public function testItFollowsTheConfiguredUploadsUrl(): void {
        $view = $this->view([MediaStorage::CONFIG_URL => '/files']);
        $this->assertSame('/files/2026/09/fox-6641fe.mp4', $view->sitePathOf('2026/09/fox-6641fe.mp4'));
    }

    public function testALeadingSlashOnTheStoredPathIsNotDoubled(): void {
        $this->assertSame('/uploads/a/b.png', $this->view()->sitePathOf('/a/b.png'));
    }

    /**
     * `siteAsset()` is the other half: it turns what is stored back into a URL, and leaves
     * anything already absolute alone, so a logo hosted elsewhere still works
     */
    public function testTheStoredPathIsWhatSiteAssetResolves(): void {
        $stored = $this->view()->sitePathOf('2026/09/fox-6641fe.mp4');
        $this->assertSame(
            'http://localhost/dpress/uploads/2026/09/fox-6641fe.mp4',
            rtrim('http://localhost/dpress', '/').'/'.ltrim($stored, '/')
        );
    }
}
