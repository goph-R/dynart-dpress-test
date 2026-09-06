<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Dpress;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\DpressCliApp;
use Dynart\Dpress\Service\SiteBundle;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The bundle: what travels, what does not, and what refuses
 *
 * Loading rows needs a database and is exercised against a real one - a bundle taken from the dev
 * site, imported into an empty schema under a different `app.base_url`, and checked for the thing
 * that matters: no row of `body_html` still naming the old host. What is here is everything that
 * is a decision rather than a query.
 *
 * @covers \Dynart\Dpress\Service\SiteBundle
 */
class SiteBundleTest extends TestCase {

    private string $temp = '';

    protected function setUp(): void {
        $this->temp = sys_get_temp_dir().'/dpress-bundle-'.bin2hex(random_bytes(4));
        mkdir($this->temp, 0775, true);
    }

    protected function tearDown(): void {
        $this->removeTree($this->temp);
    }

    private function bundle(): SiteBundle {
        return (new ReflectionClass(SiteBundle::class))->newInstanceWithoutConstructor();
    }

    private function call(string $method, array $arguments = []): mixed {
        $method = new ReflectionMethod(SiteBundle::class, $method);
        $method->setAccessible(true);
        return $method->invokeArgs($this->bundle(), $arguments);
    }

    private function removeTree(string $path): void {
        if (!is_dir($path)) {
            return;
        }
        foreach ((array)scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path.'/'.$entry;
            is_dir($full) ? $this->removeTree($full) : unlink($full);
        }
        rmdir($path);
    }

    // --- what a bundle must never carry ---

    /**
     * Session state is not content, and a bundle is as durable as an artifact gets: a folder
     * somebody tars up, copies to a server and keeps as a backup. This is the same reasoning that
     * keeps `RefreshToken` and `UserToken` out of the audit tables - they hold credentials and
     * they are short-lived, so copying them into something permanent is the mistake.
     */
    public function testSessionsAndCredentialsDoNotTravel(): void {
        foreach (['refresh_token', 'user_token', 'auth_attempt'] as $table) {
            $this->assertContains($table, SiteBundle::SKIP_TABLES,
                "$table holds session state and must not be in a bundle");
        }
    }

    /**
     * The schema on the new server was applied by that server, so its history is a true account
     * of what it did. The source's would be a story about a different computer.
     */
    public function testTheMigrationHistoryIsTheTargetsOwn(): void {
        $this->assertContains('migration_history', SiteBundle::SKIP_TABLES);
    }

    /**
     * `dpress.ini` holds the database password and the signing secret, and every value in it
     * describes the machine rather than the site
     */
    public function testTheConfigIsNotPartOfTheLayout(): void {
        $source = (string)file_get_contents(Dpress::path('src/Service/SiteBundle.php'));
        $this->assertStringNotContainsString("'dpress.ini'", $source);
        $this->assertSame(['site.json', 'data', 'uploads'],
            [SiteBundle::MANIFEST, SiteBundle::DATA_DIR, SiteBundle::UPLOADS_DIR]);
    }

    // --- refusing ---

    public function testExportWillNotWriteIntoAFolderWithSomethingInIt(): void {
        file_put_contents($this->temp.'/something.txt', 'mine');
        $this->expectException(DpressException::class);
        $this->expectExceptionMessageMatches('/already has something in it/');
        $this->bundle()->export($this->temp);
    }

    public function testExportNeedsSomewhereToGo(): void {
        $this->expectException(DpressException::class);
        $this->bundle()->export('');
    }

    public function testImportSaysSoWhenTheFolderIsNotABundle(): void {
        $this->expectException(DpressException::class);
        $this->expectExceptionMessageMatches('/Is that a bundle/');
        $this->bundle()->import($this->temp);
    }

    /**
     * Rows are loaded into the schema *this* site's migrations built, so a column added since the
     * export takes its default and one removed is dropped. That is survivable and it is not
     * something to discover afterwards, so it is refused unless somebody says they know.
     */
    public function testImportRefusesABundleFromAnotherVersion(): void {
        file_put_contents($this->temp.'/'.SiteBundle::MANIFEST, json_encode([
            'dpress' => '0.1.0', 'base_url' => 'https://example.com', 'tables' => [],
        ]));
        $this->expectException(DpressException::class);
        $this->expectExceptionMessageMatches('/0\.1\.0.*'.preg_quote(Dpress::VERSION, '/').'/s');
        $this->bundle()->import($this->temp);
    }

    public function testAManifestThatIsNotJsonIsRefused(): void {
        file_put_contents($this->temp.'/'.SiteBundle::MANIFEST, 'not json at all');
        $this->expectException(DpressException::class);
        $this->expectExceptionMessageMatches('/not readable as JSON/');
        $this->bundle()->import($this->temp);
    }

    // --- the files ---

    public function testTheUploadTreeIsCopiedWholeAndCounted(): void {
        $from = $this->temp.'/from';
        mkdir($from.'/2026/08', 0775, true);
        file_put_contents($from.'/.htaccess', 'deny');
        file_put_contents($from.'/2026/08/a.jpg', str_repeat('x', 100));
        file_put_contents($from.'/2026/08/b.png', str_repeat('y', 50));

        $result = $this->call('copyTree', [$from, $this->temp.'/to']);

        $this->assertSame(3, $result['files']);
        $this->assertSame(154, $result['bytes']);
        $this->assertFileExists($this->temp.'/to/2026/08/a.jpg');
        $this->assertFileExists($this->temp.'/to/.htaccess',
            'the uploads .htaccess is the lock on the folder and has to travel with it');
    }

    /** A site with nothing uploaded is not an error */
    public function testCopyingSomethingThatIsNotThereIsNotAFailure(): void {
        $this->assertSame(['files' => 0, 'bytes' => 0],
            $this->call('copyTree', [$this->temp.'/missing', $this->temp.'/to']));
    }

    public function testAnEmptyFolderIsEmptyAndOneWithADotFileIsNot(): void {
        $this->assertTrue($this->call('isEmptyDir', [$this->temp]));
        file_put_contents($this->temp.'/.hidden', 'x');
        $this->assertFalse($this->call('isEmptyDir', [$this->temp]),
            'a dotfile is something in the folder, and overwriting it would be a surprise');
    }

    // --- the commands ---

    public function testBothCommandsAreRegisteredAndNeedAConfig(): void {
        foreach (['export', 'import'] as $name) {
            $this->assertArrayHasKey($name, DpressCliApp::COMMANDS);
            $this->assertTrue(DpressCliApp::COMMANDS[$name]['needsConfig']);
        }
    }

    /**
     * Import replaces every row the site has. A flag rather than a prompt: a command that stops
     * to ask cannot be run from a deploy script.
     */
    public function testImportIsGuardedByAFlag(): void {
        $this->assertContains('confirm', DpressCliApp::COMMANDS['import']['flags']);
        $this->assertContains('force', DpressCliApp::COMMANDS['import']['flags']);
        $this->assertStringContainsString('-confirm',
            (string)file_get_contents(Dpress::path('src/Cli/BundleCommands.php')));
    }

    /**
     * The re-render is not offered and not suggested - it is done. Stored HTML holds absolute
     * URLs, so a site restored under a new address points at the old one on every link with
     * nothing on the page to say so, and a step that can be forgotten in that position will be.
     */
    public function testImportRerendersRatherThanRecommendingIt(): void {
        $source = (string)file_get_contents(Dpress::path('src/Cli/BundleCommands.php'));
        $this->assertStringContainsString('$this->content->rerenderAll();', $source);
        $this->assertStringContainsString('$this->blocks->rerenderAll();', $source);
        $this->assertStringContainsString('$this->bundle->recordRenderAddress();', $source);
    }
}
