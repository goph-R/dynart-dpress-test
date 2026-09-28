<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Controller\Admin\ContentAdminController;
use Dynart\Dpress\Controller\Admin\SettingsAdminController;
use Dynart\Dpress\Controller\Admin\TaxonomyAdminController;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Test\StubConfig;
use Dynart\Micro\Attribute\Route;
use Dynart\Micro\Request;
use Dynart\Micro\Router;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * A taxonomy screen whose user holds exactly the permissions a test gives it
 */
class TabbedTaxonomy extends TaxonomyAdminController {
    public array $granted = [];
    public function __construct(Router $router) { $this->router = $router; }
    protected function can(string $permission): bool { return in_array($permission, $this->granted, true); }
    public function tabsFor(string $current): array { return $this->taxonomyTabs($current); }
}

class TabbedSettings extends SettingsAdminController {
    public function __construct(Router $router) { $this->router = $router; }
    protected function can(string $permission): bool { return true; }
    public function tabsFor(string $current): array { return $this->settingsTabs($current); }
}

/**
 * Settings and Taxonomy as sections of tabs, and the Pages list's picture column behind a setting
 *
 * Each tab is a page with an address of its own rather than a panel a script shows, so what is
 * tested is the row of links: which are there, which one is current, and that a user who may open
 * only one of them is not offered the other.
 *
 * @covers \Dynart\Dpress\Controller\Admin\AbstractAdminController
 * @covers \Dynart\Dpress\Controller\Admin\SettingsAdminController
 * @covers \Dynart\Dpress\Controller\Admin\TaxonomyAdminController
 * @covers \Dynart\Dpress\Controller\Admin\ContentAdminController
 */
class AdminTabsTest extends TestCase {

    private function router(): Router {
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        return new Router(new StubConfig([
            'app.base_url' => 'https://example.com',
            Router::CONFIG_USE_REWRITE => true,
        ]), new Request());
    }

    // --- settings ---

    public function testSettingsHasASiteAThemeAndAnAdminTab(): void {
        $tabs = (new TabbedSettings($this->router()))->tabsFor(SettingsAdminController::TAB_THEME);
        $this->assertSame(['Site', 'Theme', 'Admin UI'], array_column($tabs, 'label'));
        $this->assertSame([
            'https://example.com/admin/settings',
            'https://example.com/admin/settings/theme',
            'https://example.com/admin/settings/admin',
        ], array_column($tabs, 'url'));
        $this->assertSame([false, true, false], array_column($tabs, 'current'));
    }

    public function testEachSettingsTabIsAPageOfItsOwn(): void {
        $routes = [];
        foreach ((new ReflectionClass(SettingsAdminController::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Route::class) as $attribute) {
                $route = $attribute->newInstance();
                $routes[] = $route->method.' '.$route->path;
            }
        }
        foreach (['/admin/settings', '/admin/settings/theme', '/admin/settings/admin'] as $path) {
            $this->assertContains('GET '.$path, $routes);
            $this->assertContains('POST '.$path, $routes);
        }
    }

    // --- taxonomy ---

    public function testTaxonomyHasACategoriesAndATagsTab(): void {
        $controller = new TabbedTaxonomy($this->router());
        $controller->granted = [Permissions::CATEGORY_VIEW, Permissions::TAG_VIEW];
        $tabs = $controller->tabsFor('tags');
        $this->assertSame(['Categories', 'Tags'], array_column($tabs, 'label'));
        $this->assertSame([false, true], array_column($tabs, 'current'));
    }

    /**
     * A tab somebody may not open is left out rather than shown and refused - and one tab left is
     * no row at all, which the template decides
     */
    public function testATabThisUserMayNotOpenIsLeftOut(): void {
        $controller = new TabbedTaxonomy($this->router());
        $controller->granted = [Permissions::CATEGORY_VIEW];
        $this->assertSame(['Categories'], array_column($controller->tabsFor('categories'), 'label'));
    }

    // --- the picture column on the Pages list ---

    private function showsPictures(string $type, array $settings): bool {
        $controller = (new ReflectionClass(ContentAdminController::class))->newInstanceWithoutConstructor();
        $stub = new PlacesSettings();
        $stub->values = $settings;
        $property = (new ReflectionClass(ContentAdminController::class))->getProperty('settings');
        $property->setAccessible(true);
        $property->setValue($controller, $stub);
        $method = new ReflectionMethod(ContentAdminController::class, 'showsPictures');
        $method->setAccessible(true);
        return $method->invoke($controller, $type);
    }

    public function testThePostsListAlwaysShowsPictures(): void {
        $this->assertTrue($this->showsPictures(Content::TYPE_POST, []));
        $this->assertTrue($this->showsPictures(Content::TYPE_POST, [Setting::ADMIN_PAGES_THUMBNAIL => '0']));
    }

    /**
     * Off unless a site says otherwise: most pages have no picture, and the column would be a
     * strip of empty cells
     */
    public function testThePagesListShowsThemOnlyWhenTurnedOn(): void {
        $this->assertFalse($this->showsPictures(Content::TYPE_PAGE, []));
        $this->assertFalse($this->showsPictures(Content::TYPE_PAGE, [Setting::ADMIN_PAGES_THUMBNAIL => '0']));
        $this->assertTrue($this->showsPictures(Content::TYPE_PAGE, [Setting::ADMIN_PAGES_THUMBNAIL => '1']));
    }
}
