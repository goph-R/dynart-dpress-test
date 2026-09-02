<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Theme\ThemeService;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Test\StubConfig;
use Dynart\Dpress\Test\StubView;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A settings service that answers from an array
 */
class PlacesSettings extends \Dynart\Dpress\Service\SettingService {

    public array $values = [];

    public function __construct() {}

    public function get(string $name, mixed $default = null): mixed {
        return $this->values[$name] ?? $default;
    }
}

/**
 * Where a menu can render, and the case that had no answer
 *
 * A theme declares its places in `theme.ini`, and the built-in templates have no `theme.ini` -
 * so with no theme set `places()` came back empty and the menu screen said *"the active theme
 * declares no menu places, so a menu has nowhere to render"* on a site whose header was, at that
 * moment, rendering one. `views/layout.phtml` has always put `main` beside the logo.
 *
 * The templates and the manifest are two places one fact could live, and only one of them was
 * being read. `BUILT_IN_PLACES` is the other one written down.
 *
 * @covers \Dynart\Dpress\Theme\ThemeService
 */
class ThemePlacesTest extends TestCase {

    private function service(array $themes, string $active): ThemeService {
        $settings = new PlacesSettings();
        $settings->values[Setting::THEME] = $active;
        $service = new ThemeService(new StubConfig(), new StubView(), $settings, new RecordingEvents());
        // the folder scan is I/O and not what is under test
        $property = new ReflectionProperty(ThemeService::class, 'themes');
        $property->setAccessible(true);
        $property->setValue($service, $themes);
        return $service;
    }

    /**
     * The bug, as a test: no theme set, and the header renders `main` anyway
     */
    public function testTheBuiltInTemplatesDeclareTheirOwnPlace(): void {
        $places = $this->service([], ThemeService::FALLBACK)->places();
        $this->assertArrayHasKey('main', $places, 'the built-in layout renders `main` and said it did not');
        $this->assertSame(ThemeService::BUILT_IN_PLACES, $places);
    }

    /**
     * `active()` falls back when the setting names something that is not installed, so the places
     * have to fall back with it - otherwise deleting a theme folder leaves the menu screen
     * describing a theme that is not running
     */
    public function testAMissingThemeFallsBackToTheBuiltInPlaces(): void {
        $places = $this->service([], 'deleted-theme')->places();
        $this->assertSame(ThemeService::BUILT_IN_PLACES, $places);
    }

    public function testAThemeDeclaresItsOwn(): void {
        $themes = ['plain' => ['places' => ['main' => 'Main', 'footer' => 'Footer']]];
        $this->assertSame(
            ['main' => 'Main', 'footer' => 'Footer'],
            $this->service($themes, 'plain')->places()
        );
    }

    /**
     * The warning is still right when it is right: a theme that exists and declares nothing
     * really does render a menu nowhere
     */
    public function testAThemeThatDeclaresNoneReportsNone(): void {
        $this->assertSame([], $this->service(['bare' => ['places' => []]], 'bare')->places());
    }

    /**
     * `main` is what `AbstractController::render()` asks `MenuService` for, and the constant has
     * to agree with it or the place offered by the editor is not the place that renders
     */
    public function testTheDeclaredNameIsTheOneTheLayoutRenders(): void {
        $source = file_get_contents(\Dynart\Dpress\Dpress::path('src/Controller/AbstractController.php'));
        $this->assertStringContainsString("\$this->menu('main')", $source);
        $this->assertArrayHasKey('main', ThemeService::BUILT_IN_PLACES);
    }

    /**
     * The second built-in place, and the same rule as the first: it is declared because the
     * layout renders it. A place the editors offer and nothing draws is a promise the site
     * quietly breaks.
     */
    public function testTheBuiltInLayoutDeclaresTheSidebarItRenders(): void {
        $this->assertArrayHasKey('sidebar', ThemeService::BUILT_IN_PLACES);
        $layout = file_get_contents(\Dynart\Dpress\Dpress::path('views/layout.phtml'));
        $this->assertStringContainsString("\$places->render('sidebar')", $layout);
    }

    /**
     * A place is one idea rather than two: whatever is put there renders, menu or blocks. The
     * header declares `main`, so what somebody puts in `main` has to come out of it.
     */
    public function testWhatIsPutInTheHeaderPlaceIsRenderedThere(): void {
        $layout = file_get_contents(\Dynart\Dpress\Dpress::path('views/layout.phtml'));
        $this->assertStringContainsString("\$places->blocks('main')", $layout);
    }
}
