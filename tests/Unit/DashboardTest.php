<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Service\ContentHistoryService;
use Dynart\Dpress\Test\StubDatabase;
use Dynart\Micro\Entities\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard's "Recent changes", and the two ways it went blank
 *
 * A template reading `$revision['operation']` from a query that selects `rev_type` renders an
 * empty cell. Not an error, not a warning, not a log line - a column that is simply always empty,
 * which is exactly the kind of thing nobody reports because it looks deliberate. It was wrong for
 * two of the three columns for three releases, and what finally made it visible was the *third*
 * column going blank too: auto-drafts have no title, and ten of them in a row left a panel of
 * nothing but horizontal rules.
 *
 * So both halves are pinned here: the query does not report rows nobody wrote, and the names the
 * template asks for are names the query answers to.
 *
 * @covers \Dynart\Dpress\Service\ContentHistoryService
 */
class DashboardTest extends TestCase {

    /** Where the view lives, through the installed package rather than by guessing at a path */
    private const VIEW = __DIR__.'/../../vendor/dynart/dpress/views/admin/dashboard.phtml';

    private function recentSql(): string {
        $em = $this->createMock(EntityManager::class);
        $em->method('safeTableName')->willReturn('`dp_revision`');
        $em->method('safeAuditTableName')->willReturn('`dp_content_aud`');
        $db = new StubDatabase();
        (new ContentHistoryService($em, $db))->recent(10);
        return $db->queries[0]['sql'];
    }

    /**
     * Opening "New" is not a change worth reporting: the row has no title and nothing in it, and
     * the author has not done anything yet
     */
    public function testAnAutoDraftIsNotARecentChange(): void {
        $this->assertStringContainsString('`status` <> :notAutoDraft', $this->recentSql());
    }

    public function testTheFilterUsesTheStatusItMeans(): void {
        $em = $this->createMock(EntityManager::class);
        $em->method('safeTableName')->willReturn('`dp_revision`');
        $em->method('safeAuditTableName')->willReturn('`dp_content_aud`');
        $db = new StubDatabase();
        (new ContentHistoryService($em, $db))->recent(10);
        $this->assertSame(Content::STATUS_AUTO_DRAFT, $db->queries[0]['params'][':notAutoDraft']);
    }

    /**
     * The bug that lasted three releases: `changed_at` and `operation` were never columns of
     * anything. Nothing connects a template to the query behind it, so this is the connection.
     */
    public function testTheTemplateAsksForColumnsTheQueryAnswersTo(): void {
        $this->assertFileExists(self::VIEW);
        $template = (string)file_get_contents(self::VIEW);
        preg_match_all('/\$revision\[\'([a-z_]+)\'\]/', $template, $matches);
        $asked = array_unique($matches[1]);
        $this->assertNotEmpty($asked, 'the template stopped reading the rows, or this regex did');

        $sql = $this->recentSql();
        foreach ($asked as $column) {
            // an alias (`... as `rev_at``) or a plain selected column (`a.`title``)
            $this->assertMatchesRegularExpression(
                '/(as\s+`'.$column.'`|`'.$column.'`)/',
                $sql,
                "the dashboard reads '$column' and recent() does not select it"
            );
        }
    }
}
