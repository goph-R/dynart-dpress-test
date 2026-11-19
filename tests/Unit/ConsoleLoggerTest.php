<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\ConsoleLogger;
use Dynart\Dpress\DpressCliApp;
use Dynart\Dpress\DpressLogger;
use Dynart\Dpress\Test\StubConfig;
use PHPUnit\Framework\TestCase;

/**
 * A command with no site has nowhere to keep a log, and must not go looking (0.97.1)
 *
 * `dpress init` runs before there is a `dpress.ini`. The site's logger defaults to `~/logs`, the
 * site root's - and with no config the root is empty, so that was `/logs` at the top of the
 * filesystem. As root it was quietly made; as the web server's user it could not be, and the
 * first command anybody runs ended in a stack trace about `Logger::class`. Found deploying a
 * site as `www-data`.
 *
 * @covers \Dynart\Dpress\ConsoleLogger
 * @covers \Dynart\Dpress\DpressCliApp
 */
final class ConsoleLoggerTest extends TestCase {

    private string $folder;

    protected function setUp(): void {
        $this->folder = sys_get_temp_dir().'/dpress-nosite-'.bin2hex(random_bytes(4));
        mkdir($this->folder, 0777, true);
    }

    protected function tearDown(): void {
        foreach (glob($this->folder.'/{,.}[!.]*', GLOB_BRACE) ?: [] as $entry) {
            is_dir($entry) ? @rmdir($entry) : @unlink($entry);
        }
        @rmdir($this->folder);
    }

    public function testACommandWithNoSiteLogsToTheTerminal(): void {
        $this->assertSame(ConsoleLogger::class, DpressCliApp::loggerFor(false));
        $this->assertStringStartsWith('php://', ConsoleLogger::DEFAULT_DIR);
    }

    /** A site keeps its log file whatever the command: this is not about the command line */
    public function testACommandWithASiteKeepsTheSitesLog(): void {
        $this->assertSame(DpressLogger::class, DpressCliApp::loggerFor(true));
        $this->assertSame('~/logs', DpressLogger::DEFAULT_DIR);
    }

    /** With an empty config - which is what no `dpress.ini` is - it is built, and makes no file */
    public function testItIsBuiltWithNoConfigAndWritesNothingToDisk(): void {
        $before = getcwd();
        chdir($this->folder);
        try {
            $logger = new ConsoleLogger(new StubConfig([]));
            $this->assertInstanceOf(DpressLogger::class, $logger);
        } finally {
            chdir($before);
        }
        $this->assertSame([], array_diff(scandir($this->folder), ['.', '..']));
    }

    /**
     * The whole thing, the way it failed: a config-less command typed in a folder that is not a
     * site. Before the fix this exited 255 for anybody who may not write to `/`.
     *
     * Run as root it could not fail that way, so it also checks that nothing was left in the
     * folder - `help` typed in `/root` should not leave a `logs/` there.
     */
    public function testVersionRunsInAFolderThatIsNotASite(): void {
        // composer's launcher and not the package's own file: the package may be a symlinked
        // checkout with no vendor/ of its own, and the launcher is how a site runs it anyway
        $bin = dirname(__DIR__, 2).'/vendor/bin/dpress';
        $this->assertFileExists($bin);
        $process = proc_open(
            [PHP_BINARY, $bin, 'version'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->folder
        );
        $this->assertIsResource($process);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $code = proc_close($process);
        $this->assertSame(0, $code, $out.$err);
        $this->assertStringContainsString('dpress', $out);
        $this->assertStringNotContainsString('Logger', $out.$err);
        $this->assertSame([], array_diff(scandir($this->folder), ['.', '..']));
    }
}
