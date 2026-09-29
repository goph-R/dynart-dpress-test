<?php

namespace Dynart\Dpress\Test\Unit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use PHPUnit\Framework\TestCase;
use Dynart\Dpress\Repository\RepositoryUpdater;

/**
 * Bringing a clone up to date - the git pull a Docs build can start with - against real
 * repositories in a temporary folder (moved from the Docs plugin in 0.93.0)
 *
 * A bare repository stands for GitHub, one clone for the machine somebody writes on and one for
 * the server - the folder the updater is pointed at.
 */
final class RepositoryUpdaterTest extends TestCase {

    private string $root = '';

    protected function setUp(): void {
        exec('git --version', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('git is not installed');
        }
        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/docs-updater-'.bin2hex(random_bytes(4));
        mkdir($this->root);
        $this->run_("git init -q --bare -b main {$this->root}/origin.git");
        $this->run_("git clone -q {$this->root}/origin.git {$this->root}/writer");
        $this->commit('index.md', "# Home\n");
        $this->run_("git -C {$this->root}/writer push -q origin main");
        $this->run_("git clone -q {$this->root}/origin.git {$this->root}/server");
    }

    protected function tearDown(): void {
        if ($this->root !== '' && is_dir($this->root)) {
            // git makes its objects read-only, which Windows will not delete
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                @chmod($file->getPathname(), 0777);
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->root);
        }
    }

    private function run_(string $command): void {
        exec($command.' 2>&1', $output, $code);
        $this->assertSame(0, $code, $command."\n".implode("\n", $output));
    }

    private function commit(string $file, string $content, string $clone = 'writer'): void {
        file_put_contents("{$this->root}/$clone/$file", $content);
        $this->run_("git -C {$this->root}/$clone add $file");
        $this->run_("git -C {$this->root}/$clone -c user.name=t -c user.email=t@t commit -q -m $file");
    }

    public function testNothingNewIsUpToDate(): void {
        $result = (new RepositoryUpdater())->update("{$this->root}/server");
        $this->assertTrue($result['ok'], $result['message']);
        $this->assertStringStartsWith('It was up to date, at ', $result['message']);
    }

    public function testAPushReachesTheServer(): void {
        $this->commit('page.md', "# Page\n");
        $this->run_("git -C {$this->root}/writer push -q origin main");
        $result = (new RepositoryUpdater())->update("{$this->root}/server");
        $this->assertTrue($result['ok'], $result['message']);
        $this->assertMatchesRegularExpression('/^It was updated from [0-9a-f]+ to [0-9a-f]+\.$/', $result['message']);
        $this->assertFileExists("{$this->root}/server/page.md");
        // the two commits as well, for a caller that says it in words of its own - the Docs plugin
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $result['before']);
        $this->assertNotSame($result['before'], $result['after']);
    }

    /** Somebody changed the server's copy: no merge is made there, and the pull says why */
    public function testDivergedHistoryIsRefusedNotMerged(): void {
        $this->commit('page.md', "# From the writer\n");
        $this->run_("git -C {$this->root}/writer push -q origin main");
        $this->commit('local.md', "# Made on the server\n", 'server');
        $result = (new RepositoryUpdater())->update("{$this->root}/server");
        $this->assertFalse($result['ok']);
        $this->assertStringStartsWith('git pull failed: ', $result['message']);
        $this->assertFileDoesNotExist("{$this->root}/server/page.md");
    }

    public function testAFolderThatIsNotAClone(): void {
        mkdir("{$this->root}/plain");
        $result = (new RepositoryUpdater())->update("{$this->root}/plain");
        $this->assertFalse($result['ok']);
        $this->assertStringEndsWith('is not a git clone.', $result['message']);
    }

    public function testTheTailOfGitsOutputIsItsLastLines(): void {
        $this->assertSame('c d e f', RepositoryUpdater::tail("a\nb\n\nc\nd\r\ne\nf\n"));
        $this->assertSame('it printed nothing.', RepositoryUpdater::tail("\n\n"));
    }

    /** What a pull that cannot fast-forward actually prints, as the Build button showed it */
    public function testTheTailIsTheErrorNotTheHints(): void {
        $output = "hint: Diverging branches can't be fast-forwarded, you need to either:\n"
            ."hint:\n"
            ."hint: \tgit merge --no-ff\n"
            ."hint: Disable this message with \"git config set advice.diverging false\"\n"
            ."fatal: Not possible to fast-forward, aborting.\n";
        $this->assertSame('fatal: Not possible to fast-forward, aborting.', RepositoryUpdater::tail($output));
    }
}
