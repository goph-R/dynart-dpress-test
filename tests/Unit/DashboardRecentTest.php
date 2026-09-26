<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Controller\Admin\DashboardController;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Service\ContentHistoryService;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Test\StubConfig;
use Dynart\Micro\Request;
use Dynart\Micro\Router;
use PHPUnit\Framework\TestCase;

class RecentHistory extends ContentHistoryService {
    public array $rows = [];
    public function __construct() {}
    public function recent(int $limit = 20): array { return $this->rows; }
}

class RecentContent extends ContentService {
    public int $lookups = 0;
    public function __construct() {}
    public function postPath(string $slug): string { return '/post/'.$slug; }
    public function findById(int $id): ?Content {
        $this->lookups++;
        $page = new Content();
        $page->id = $id;
        $page->type = Content::TYPE_PAGE;
        $page->slug = 'team';
        return $page;
    }
    public function publicPath(Content $content): string { return '/about/'.$content->slug; }
}

/**
 * A dashboard whose viewer holds exactly the permissions a test gives them
 */
class RecentDashboard extends DashboardController {
    public array $granted = [];
    public function __construct(Router $router, ContentHistoryService $history, ContentService $content) {
        $this->router = $router;
        $this->history = $history;
        $this->content = $content;
    }
    protected function can(string $permission): bool { return in_array($permission, $this->granted, true); }
    public function recentRows(): array { return $this->recent(); }
}

/**
 * The eye and the pen at the end of the dashboard's Recent changes
 *
 * @covers \Dynart\Dpress\Controller\Admin\DashboardController
 */
class DashboardRecentTest extends TestCase {

    private RecentContent $content;

    private function dashboard(array $rows, array $granted): RecentDashboard {
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $history = new RecentHistory();
        $history->rows = $rows;
        $this->content = new RecentContent();
        $router = new Router(new StubConfig(['app.base_url' => 'https://example.com', Router::CONFIG_USE_REWRITE => true]), new Request());
        $dashboard = new RecentDashboard($router, $history, $this->content);
        $dashboard->granted = $granted;
        return $dashboard;
    }

    private function row(int $id, ?string $type, ?string $status, string $slug = 'hello'): array {
        return ['id' => (string)$id, 'title' => 'T', 'rev_type' => 'mod',
                'live_type' => $type, 'live_status' => $status, 'live_slug' => $type ? $slug : null];
    }

    public function testAPublishedPostHasTheEyeAndThePen(): void {
        $rows = $this->dashboard([$this->row(7, Content::TYPE_POST, Content::STATUS_PUBLISHED)], [Permissions::POST_UPDATE])->recentRows();
        $this->assertSame('https://example.com/post/hello', $rows[0]['view_url']);
        $this->assertSame('https://example.com/admin/content/post/edit/7', $rows[0]['edit_url']);
    }

    /**
     * Somebody who may not edit it still sees a published page, but not a draft - which is who
     * the site itself shows a draft to
     */
    public function testWithoutTheEditPermissionOnlyThePublishedHasAnEye(): void {
        $rows = $this->dashboard([
            $this->row(7, Content::TYPE_POST, Content::STATUS_PUBLISHED),
            $this->row(8, Content::TYPE_POST, Content::STATUS_DRAFT),
        ], [])->recentRows();
        $this->assertNotSame('', $rows[0]['view_url']);
        $this->assertSame('', $rows[0]['edit_url']);
        $this->assertSame('', $rows[1]['view_url']);
    }

    public function testNothingIsOfferedForWhatIsGoneOrInTheTrash(): void {
        $rows = $this->dashboard([
            $this->row(9, null, null),
            $this->row(10, Content::TYPE_POST, Content::STATUS_TRASH),
        ], [Permissions::POST_UPDATE])->recentRows();
        foreach ($rows as $row) {
            $this->assertSame('', $row['view_url']);
            $this->assertSame('', $row['edit_url']);
        }
    }

    /**
     * A page's address is its ancestors' too, so it is looked up - once, however many of its
     * revisions the list shows
     */
    public function testAPageIsLookedUpOnceForItsPath(): void {
        $rows = $this->dashboard([
            $this->row(5, Content::TYPE_PAGE, Content::STATUS_PUBLISHED),
            $this->row(5, Content::TYPE_PAGE, Content::STATUS_PUBLISHED),
        ], [Permissions::PAGE_UPDATE])->recentRows();
        $this->assertSame('https://example.com/about/team', $rows[0]['view_url']);
        $this->assertSame($rows[0]['view_url'], $rows[1]['view_url']);
        $this->assertSame(1, $this->content->lookups);
    }
}
