<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Controller\Admin\AbstractAdminController;
use Dynart\Dpress\Controller\Admin\DashboardController;
use Dynart\Dpress\Dpress;
use Dynart\Dpress\Query\ListRequest;
use Dynart\Dpress\Test\StubConfig;
use Dynart\Micro\Request;
use Dynart\Micro\Router;
use Dynart\Micro\View;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Moving between admin screens without reloading the page
 *
 * Two things make it work, and both of them can be broken silently. The first is that a screen
 * asked with `?ajax=1` answers with its `<main>` element and nothing else - if that ever came
 * back as a whole document the browser would put a page inside a page. The second is that the
 * first page of a list rides along with the screen: the sort it is fetched with has to be the
 * sort the browser thinks it is looking at, or the table rearranges itself on the first click.
 *
 * @covers \Dynart\Dpress\Controller\Admin\AbstractAdminController
 */
class AdminPartialTest extends TestCase {

    protected function setUp(): void {
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void {
        $_REQUEST = [];
    }

    /**
     * A controller with nothing in it but a config and a request, which is all these methods use
     */
    private function controller(array $request = [], array $config = []): AbstractAdminController {
        $_REQUEST = $request;
        $controller = (new ReflectionClass(DashboardController::class))->newInstanceWithoutConstructor();
        $this->give($controller, 'config', new StubConfig($config));
        $this->give($controller, 'request', new Request());
        $this->give($controller, 'list', new ListRequest(new Request()));
        return $controller;
    }

    private function give(object $controller, string $property, mixed $value): void {
        $reflection = new ReflectionClass($controller);
        while (!$reflection->hasProperty($property)) {
            $reflection = $reflection->getParentClass();
        }
        $found = $reflection->getProperty($property);
        $found->setAccessible(true);
        $found->setValue($controller, $value);
    }

    private function call(object $controller, string $method, array $arguments = []): mixed {
        $reflection = new ReflectionMethod(AbstractAdminController::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($controller, $arguments);
    }

    // --- which layout a request gets ---

    public function testAPlainRequestIsNotAPartialOne(): void {
        $this->assertFalse($this->call($this->controller(), 'isPartial'));
    }

    /**
     * The value is not read, only its presence: `?ajax`, `?ajax=1` and `?ajax=0` all mean the
     * same thing, which is that something asked for the fragment
     */
    public function testAnythingUnderTheParameterAsksForTheFragment(): void {
        foreach (['1', '', '0'] as $value) {
            $this->assertTrue($this->call($this->controller(['ajax' => $value]), 'isPartial'), "ajax=$value");
        }
    }

    /**
     * The fragment layout is a real template, and it is the one the full layout fetches. If they
     * ever drifted apart, a partial load would put something on the screen that no whole page
     * would have contained.
     */
    public function testTheFragmentLayoutIsTheOneTheFullLayoutFetches(): void {
        $partial = str_replace(Dpress::VIEW_NAMESPACE.':', '', AbstractAdminController::LAYOUT_PARTIAL);
        $this->assertFileExists(Dpress::viewsPath().'/'.$partial.'.phtml');
        $layout = file_get_contents(Dpress::viewsPath().'/admin/layout.phtml');
        $this->assertStringContainsString(
            "\$this->fetch('".AbstractAdminController::LAYOUT_PARTIAL."')", $layout,
            'the full layout no longer renders the same file a partial request is answered with'
        );
    }

    /**
     * Every admin template has to take its layout from the controller, because that is the only
     * way a partial request can leave the chrome off. One that names the full layout itself
     * would answer a partial request with an entire document.
     */
    public function testNoAdminTemplateChoosesItsOwnLayout(): void {
        $templates = array_merge(
            glob(Dpress::viewsPath().'/admin/*.phtml'),
            glob(Dpress::viewsPath().'/admin/*/*.phtml')
        );
        $found = 0;
        foreach ($templates as $template) {
            $source = file_get_contents($template);
            if (!str_contains($source, 'useLayout')) {
                continue;
            }
            $found++;
            $this->assertStringContainsString(
                'useLayout($admin_layout)', $source,
                basename(dirname($template)).'/'.basename($template).' names a layout of its own'
            );
        }
        $this->assertGreaterThan(10, $found, 'the admin templates were not found at all');
    }

    /**
     * What a partial request actually answers with
     */
    public function testTheFragmentIsOneMainElementAndNoDocument(): void {
        $view = new View(new StubConfig());
        $view->addFolder(Dpress::VIEW_NAMESPACE, Dpress::viewsPath());
        $view->set('page_title', 'Posts – dpress');
        $view->set('admin_section', 'content');
        $view->set('notice', 'Saved.');
        $html = trim($view->fetch(AbstractAdminController::LAYOUT_PARTIAL));

        $this->assertStringStartsWith('<main', $html);
        $this->assertStringEndsWith('</main>', $html);
        $this->assertStringNotContainsString('<html', $html);
        $this->assertStringNotContainsString('<body', $html);
        $this->assertStringNotContainsString('<script', $html);
        // the two things the chrome cannot work out for itself after a swap
        $this->assertStringContainsString('data-title="Posts – dpress"', $html);
        $this->assertStringContainsString('data-section="content"', $html);
        $this->assertStringContainsString('Saved.', $html);
    }

    /**
     * The token the row actions post with is generated on every render and stored in the session,
     * which means the one rendered *before* a partial load is no longer the one the server
     * expects. The form has to be inside the part that gets replaced.
     */
    public function testTheActionFormIsInsideTheReplacedPart(): void {
        $fragment = file_get_contents(Dpress::viewsPath().'/admin/main.phtml');
        $this->assertStringContainsString('data-action-form', $fragment);
        $this->assertStringNotContainsString(
            'data-action-form', file_get_contents(Dpress::viewsPath().'/admin/layout.phtml'),
            'a form left outside the swapped part keeps a token the server has already replaced'
        );
    }

    // --- what the first page of a list is fetched with ---

    public function testTheSeededPageTakesItsSortFromTheListItIsSeeding(): void {
        $context = $this->call($this->controller(), 'firstPageContext', [
            ['orderBy' => 'published_at', 'orderDir' => 'desc'], ['title', 'published_at'],
        ]);
        $this->assertSame('published_at', $context['order_by']);
        $this->assertSame('desc', $context['order_dir']);
        $this->assertSame(0, $context['offset']);
    }

    /**
     * A sort in the URL is somebody having asked for it, and it wins - exactly as it does at the
     * endpoint, which is the same request read by the same code
     */
    public function testARequestedSortWinsOverTheListsDefault(): void {
        $context = $this->call($this->controller(['sort' => 'title', 'order' => 'asc']), 'firstPageContext', [
            ['orderBy' => 'published_at', 'orderDir' => 'desc'], ['title', 'published_at'],
        ]);
        $this->assertSame('title', $context['order_by']);
        $this->assertSame('asc', $context['order_dir']);
    }

    /**
     * The same whitelist as the endpoint: a sort column the screen never offered is dropped
     * rather than seeded into the SQL
     */
    public function testAnUnknownSortIsStillDroppedWhenSeeding(): void {
        $context = $this->call($this->controller(['sort' => 'password_hash']), 'firstPageContext', [
            ['orderBy' => 'name'], ['name'],
        ]);
        $this->assertSame('name', $context['order_by']);
    }

    public function testAFilterInTheUrlIsSeededWithTheRest(): void {
        $context = $this->call($this->controller(['status' => 'draft']), 'firstPageContext', [
            ['orderBy' => 'title'], ['title'], ['search', 'status'],
        ]);
        $this->assertSame('draft', $context['status']);
    }

    /**
     * A list that asks for a page of its own size has to be seeded with that many rows, or the
     * pager and the table disagree on the first render
     */
    public function testTheSeededPageIsAsLargeAsTheListsOwnPage(): void {
        $context = $this->call($this->controller(), 'firstPageContext', [['pageSize' => 12], []]);
        $this->assertSame(12, $context['max']);
    }

    public function testARequestedPageSizeWinsOverTheListsOwn(): void {
        $context = $this->call($this->controller(['max' => '5']), 'firstPageContext', [['pageSize' => 12], []]);
        $this->assertSame(5, $context['max']);
    }

    // --- how the browser is told what an admin link looks like ---

    /**
     * With rewriting on a route is a path, so there is no parameter to name
     */
    public function testWithRewritingThereIsNoRouteParameter(): void {
        $controller = $this->controller([], [Router::CONFIG_USE_REWRITE => true]);
        $this->assertSame('', $this->call($controller, 'routeParam'));
    }

    /**
     * Without it - which is the framework's default - every screen shares one path and the route
     * travels in a parameter, so the browser has to be told which one
     */
    public function testWithoutRewritingTheRouteParameterIsNamed(): void {
        $this->assertSame('route', $this->call($this->controller(), 'routeParam'));
        $controller = $this->controller([], [Router::CONFIG_ROUTE_PARAMETER => 'r']);
        $this->assertSame('r', $this->call($controller, 'routeParam'));
    }
}
