<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Dpress;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Service\ContentHistoryService;
use Dynart\Dpress\Test\StubDatabase;
use Dynart\Micro\Entities\EntityManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * The templates that render raw revision rows, against the queries behind them
 *
 * `$revision['operation']` on a row that has `rev_type` renders an **empty cell**. Not an error,
 * not a warning, not a log line - a column that is simply always blank, which is the kind of
 * thing nobody reports because it looks deliberate. Both templates that show revisions had the
 * same two names wrong (`changed_at`, `operation`) since the screens were written, so between
 * them six columns were blank: the dashboard's When and What, and the history screen's.
 *
 * Neither was found by a test. The dashboard was found because auto-drafts pushed title-less rows
 * to the top and made the panel *obviously* empty; the history screen was found because somebody
 * opened it and looked. Nothing connects a template to the SQL behind it, so this is the
 * connection - for both, and for whatever renders a revision next.
 *
 * @covers \Dynart\Dpress\Service\ContentHistoryService
 */
class RevisionColumnsTest extends TestCase {

    /** template => the method whose rows it renders */
    private const SCREENS = [
        'views/admin/dashboard.phtml'            => 'recent',
        'views/admin/content/history.phtml'      => 'revisions',
    ];

    public function testEveryRevisionTemplateAsksForColumnsItsQueryAnswersTo(): void {
        foreach (self::SCREENS as $template => $method) {
            $asked = $this->columnsReadBy($template);
            $this->assertNotEmpty($asked, "$template stopped reading revision rows, or this regex did");
            $available = $this->columnsSelectedBy($method);
            foreach ($asked as $column) {
                $this->assertContains(
                    $column, $available,
                    "$template reads '$column' and $method() does not select it"
                );
            }
        }
    }

    /**
     * Opening "New" is not a change worth reporting: the row has no title and nothing in it
     */
    public function testTheDashboardDoesNotReportAutoDrafts(): void {
        $query = $this->query('recent');
        $this->assertStringContainsString('`status` <> :notAutoDraft', $query['sql']);
        $this->assertSame(Content::STATUS_AUTO_DRAFT, $query['params'][':notAutoDraft']);
    }

    /**
     * The history of one piece of content is a different question: it is asked about a row by id,
     * and a promoted auto-draft's own first revision is genuinely part of its history
     */
    public function testTheHistoryOfOneThingIsNotFiltered(): void {
        $this->assertStringNotContainsString('notAutoDraft', $this->query('revisions')['sql']);
    }

    // --- reading the two sides ---

    /** @return string[] the `$revision['x']` keys a template reads */
    private function columnsReadBy(string $template): array {
        $path = Dpress::path($template);
        $this->assertFileExists($path);
        preg_match_all('/\$revision\[\'([a-z_]+)\'\]/', (string)file_get_contents($path), $matches);
        return array_unique($matches[1]);
    }

    /**
     * @return string[] every column name a query makes available, `a.*` expanded
     */
    private function columnsSelectedBy(string $method): array {
        $sql = $this->query($method)['sql'];
        preg_match_all('/as\s+`([a-z_]+)`/', $sql, $aliases);
        preg_match_all('/[a-z]\.`([a-z_]+)`/', $sql, $named);
        $available = array_merge($aliases[1], $named[1]);
        if (str_contains($sql, 'a.*')) {
            // the whole audit mirror: every column of the entity, plus the two the mirror adds
            foreach ((new ReflectionClass(Content::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                $available[] = $property->getName();
            }
            $available[] = 'rev_id';
            $available[] = 'rev_type';
        }
        return array_unique($available);
    }

    private function query(string $method): array {
        $em = $this->createMock(EntityManager::class);
        $em->method('safeTableName')->willReturn('`dp_revision`');
        $em->method('safeAuditTableName')->willReturn('`dp_content_aud`');
        $db = new StubDatabase();
        (new ContentHistoryService($em, $db))->$method(1);
        return $db->queries[0];
    }
}
