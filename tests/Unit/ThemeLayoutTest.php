<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Controller\AbstractController;
use Dynart\Dpress\Controller\Admin\DashboardController;
use Dynart\Dpress\Dpress;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Theme\ThemeAssets;
use Dynart\Dpress\Theme\ThemeService;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Test\StubConfig;
use Dynart\Dpress\Test\StubView;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * A theme may have more than one layout, and it says so by having the file
 *
 * A front page and a post being read are not the same document, so a theme writes
 * `layout-home.phtml` next to its `layout.phtml`. **Nothing registers it**: the resolution asks
 * the view whether the file is there, which is the same promise a theme itself makes and a
 * plugin after it - drop it in and it exists.
 *
 * That is what makes naming five kinds free. A theme that writes one file gets one layout and
 * the other four kinds quietly come back to it, so `archive`, `page` and `auth` cost nothing
 * until somebody wants them.
 *
 * @covers \Dynart\Dpress\Controller\AbstractController
 * @covers \Dynart\Dpress\Theme\ThemeAssets
 * @covers \Dynart\Dpress\Theme\ThemeService
 */
class ThemeLayoutTest extends TestCase {

    /**
     * @param array<string> $templates the view paths that exist
     */
    private function layoutFor(string $kind, array $templates = []): string {
        // any concrete controller will do - the method is on the base and touches only the view
        $controller = (new ReflectionClass(DashboardController::class))->newInstanceWithoutConstructor();
        $property = (new ReflectionClass(AbstractController::class))->getProperty('view');
        $property->setAccessible(true);
        $property->setValue($controller, new StubView(array_fill_keys($templates, '')));

        $method = new ReflectionMethod(AbstractController::class, 'layoutFor');
        $method->setAccessible(true);
        return $method->invoke($controller, $kind);
    }

    // --- the resolution ---

    public function testNoKindIsTheOrdinaryLayout(): void {
        $this->assertSame(AbstractController::LAYOUT, $this->layoutFor(''));
    }

    /**
     * The whole feature: the file is there, so it is used
     */
    public function testAKindWithATemplateBehindItGetsIt(): void {
        $this->assertSame(
            'dpress:layout-home',
            $this->layoutFor('home', ['dpress:layout-home'])
        );
    }

    /**
     * And the other half: a theme with one layout is a theme with one layout, whatever a
     * controller names
     */
    public function testAKindWithNoTemplateFallsBackRatherThanFailing(): void {
        foreach (['home', 'archive', 'post', 'page', 'auth'] as $kind) {
            $this->assertSame(AbstractController::LAYOUT, $this->layoutFor($kind), $kind);
        }
    }

    public function testOneKindHavingALayoutDoesNotGiveTheOthersOne(): void {
        $templates = ['dpress:layout-home'];
        $this->assertSame('dpress:layout-home', $this->layoutFor('home', $templates));
        $this->assertSame(AbstractController::LAYOUT, $this->layoutFor('post', $templates));
    }

    /**
     * A kind becomes part of a path, so it is matched rather than trusted. Controllers are where
     * kinds come from and a plugin ships controllers too.
     */
    public function testAKindThatIsNotAWordIsNotAPath(): void {
        foreach (['../admin/layout', 'a/b', 'Home', 'x.y', ' home', ''] as $kind) {
            $this->assertSame(
                AbstractController::LAYOUT,
                $this->layoutFor($kind, ['dpress:layout-'.$kind]),
                "'$kind' was allowed to name a template"
            );
        }
    }

    // --- the theme's own namespace ---

    /**
     * Two layouts want one header between them, and `theme:` is where a theme puts it. Without
     * it the only name available is `dpress:something` - a theme claiming a name in the CMS's
     * namespace for a file the CMS does not have.
     */
    public function testAnActiveThemeGetsANamespaceOfItsOwn(): void {
        $view = new RecordingView();
        $this->themes($view, ['plain' => ['name' => 'plain']], 'plain')->apply();
        $this->assertArrayHasKey(Dpress::THEME_VIEW_NAMESPACE, $view->folders);
        $this->assertStringEndsWith('/plain', $view->folders[Dpress::THEME_VIEW_NAMESPACE]);
    }

    public function testNoActiveThemeRegistersNoFolder(): void {
        $view = new RecordingView();
        $this->themes($view, [], ThemeService::FALLBACK)->apply();
        $this->assertSame([], $view->folders);
        $this->assertSame('', $view->themePath);
    }

    // --- what a theme may serve ---

    public function testAStylesheetInTheThemeIsServedAsOne(): void {
        $assets = $this->assets(['style.css' => 'body{}']);
        $file = $assets->file('style.css');
        $this->assertNotNull($file);
        $this->assertSame('text/css; charset=utf-8', $file['type']);
        $this->assertStringEndsWith('assets/style.css', str_replace('\\', '/', $file['path']));
    }

    /**
     * A design is a typeface and a picture as much as it is a stylesheet
     */
    public function testAFontAndAPictureAreServedToo(): void {
        $assets = $this->assets(['inter.woff2' => 'x', 'hero.png' => 'x']);
        $this->assertSame('font/woff2', $assets->file('inter.woff2')['type']);
        $this->assertSame('image/png', $assets->file('hero.png')['type']);
    }

    /**
     * The folder is flat and a name is one word and an extension, so there is no traversal to
     * reason about - it cannot be spelled
     */
    public function testNothingCanClimbOutOfTheAssetsFolder(): void {
        $assets = $this->assets(['style.css' => 'body{}']);
        foreach (['../theme.ini', '../../secret', 'sub/style.css', '.htaccess', '..'] as $name) {
            $this->assertNull($assets->file($name), "'$name' was allowed");
        }
    }

    /**
     * An allowlist by extension, so a template or a script the theme keeps beside its assets is
     * not a URL
     */
    public function testOnlyTheTypesAThemeMayServe(): void {
        $assets = $this->assets(['layout.phtml' => 'x', 'notes.txt' => 'x', 'style.css' => 'x']);
        $this->assertNull($assets->file('layout.phtml'));
        $this->assertNull($assets->file('notes.txt'));
        $this->assertNotNull($assets->file('style.css'));
    }

    public function testAFileThatIsNotThere(): void {
        $this->assertNull($this->assets(['style.css' => 'x'])->file('other.css'));
    }

    /**
     * The name is not in the URL, so this cannot read out of a theme the site is not using
     */
    public function testWithNoActiveThemeThereIsNothingToServe(): void {
        $assets = $this->assets(['style.css' => 'x'], ThemeService::FALLBACK);
        $this->assertNull($assets->file('style.css'));
        $this->assertSame('', $assets->url('style.css'));
    }

    // --- fixtures ---

    private function themes(StubView $view, array $themes, string $active): ThemeService {
        $settings = new PlacesSettings();
        $settings->values[Setting::THEME] = $active;
        $config = new StubConfig([ThemeService::CONFIG_PATH => $this->dir()]);
        $service = new ThemeService($config, $view, $settings, new RecordingEvents());
        $property = new ReflectionProperty(ThemeService::class, 'themes');
        $property->setAccessible(true);
        $property->setValue($service, $themes);
        return $service;
    }

    /**
     * A theme on disk, because `file()` answers about a file that is really there
     */
    private function assets(array $files, string $active = 'plain'): ThemeAssets {
        $base = $this->dir().'/plain/'.ThemeAssets::FOLDER;
        if (!is_dir($base)) {
            mkdir($base, 0777, true);
        }
        foreach ($files as $name => $contents) {
            file_put_contents($base.'/'.$name, $contents);
        }
        return new ThemeAssets($this->themes(
            new StubView(), ['plain' => ['name' => 'plain', 'version' => '1.0.0']], $active
        ));
    }

    private function dir(): string {
        return sys_get_temp_dir().'/dpress-theme-test';
    }

    public static function tearDownAfterClass(): void {
        $base = sys_get_temp_dir().'/dpress-theme-test';
        foreach ((array)glob($base.'/plain/'.ThemeAssets::FOLDER.'/*') as $file) {
            @unlink($file);
        }
        @rmdir($base.'/plain/'.ThemeAssets::FOLDER);
        @rmdir($base.'/plain');
        @rmdir($base);
    }
}

/**
 * A view that remembers the folders it was given, which is what `apply()` is being asked about
 */
class RecordingView extends StubView {

    public array $folders = [];
    public string $themePath = '';

    public function addFolder(string $namespace, string $path, bool $themeable = true): void {
        $this->folders[$namespace] = $path;
    }

    public function setTheme(string $path): void {
        $this->themePath = $path;
    }
}
