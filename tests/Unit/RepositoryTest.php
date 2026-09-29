<?php

namespace Dynart\Dpress\Test\Unit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use PHPUnit\Framework\TestCase;
use Dynart\Dpress\Repository\Git;
use Dynart\Dpress\Repository\RepositoryStatus;
use Dynart\Dpress\Repository\RepositoryFiles;
use Dynart\Dpress\DpressException;

/**
 * An editor's two halves: writing a file of a clone back, and knowing it differs from the remote
 *
 * Moved from the Docs plugin with the classes (0.93.0) - the pages below are its source files.
 *
 * The git half runs against real repositories in a temporary folder - a bare one for the remote,
 * a clone of it for the server, and a second pair included in the first as a submodule, because
 * a page is usually a file of a submodule and its status has to be asked of that.
 */
final class RepositoryTest extends TestCase {

    private string $root = '';

    protected function tearDown(): void {
        if ($this->root !== '' && is_dir($this->root)) {
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

    private function folder(): string {
        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/docs-editor-'.bin2hex(random_bytes(4));
        mkdir($this->root);
        return $this->root;
    }

    private function run_(string $command): void {
        exec($command.' 2>&1', $output, $code);
        $this->assertSame(0, $code, $command."\n".implode("\n", $output));
    }

    private function commitAll(string $clone, string $message): void {
        $this->run_("git -C $clone add -A");
        $this->run_("git -C $clone -c user.name=t -c user.email=t@t commit -q -m $message");
    }

    // --- writing the file back ---

    public function testTheBrowsersLineEndingsBecomeTheFilesOwn(): void {
        $this->assertSame("a\nb\n", RepositoryFiles::asFileHas("a\r\nb\r\n", "x\ny\n"));
        $this->assertSame("a\r\nb\r\n", RepositoryFiles::asFileHas("a\r\nb", "x\r\ny\r\n"));
    }

    public function testAFinalNewlineStaysOrStaysAway(): void {
        $this->assertSame("a\n", RepositoryFiles::asFileHas('a', "x\n"));
        $this->assertSame('a', RepositoryFiles::asFileHas("a\r\n", 'x'));
    }

    public function testOnlyAFileInsideTheFolder(): void {
        $files = new RepositoryFiles('/srv/docs');
        $this->assertSame('/srv/docs/legal/index.md', $files->path('legal/index.md'));
        foreach (['../etc/passwd', 'a/../../b.md', '/etc/passwd', 'C:/x.md', ''] as $bad) {
            try {
                $files->path($bad);
                $this->fail("$bad was accepted");
            } catch (DpressException $e) {
                $this->assertStringContainsString('not a file of the folder', $e->getMessage());
            }
        }
    }

    public function testASaveWritesTheFileAsItHadIt(): void {
        $folder = $this->folder();
        file_put_contents("$folder/page.md", "# Page\r\n\r\nOld.\r\n");
        $files = new RepositoryFiles($folder);
        $opened = RepositoryFiles::hash($files->read('page.md'));
        $this->assertTrue($files->write('page.md', "# Page\r\n\r\nNew.", $opened));
        $this->assertSame("# Page\r\n\r\nNew.\r\n", file_get_contents("$folder/page.md"));
    }

    public function testTheSameTextWritesNothing(): void {
        $folder = $this->folder();
        file_put_contents("$folder/page.md", "# Page\n");
        $files = new RepositoryFiles($folder);
        $this->assertFalse($files->write('page.md', "# Page\r\n", RepositoryFiles::hash("# Page\n")));
    }

    /** Written, it would take the next build down with it - the renderer refuses anything else */
    public function testTextThatIsNotUtf8IsNotWritten(): void {
        $folder = $this->folder();
        file_put_contents("$folder/page.md", "# Page\n");
        $files = new RepositoryFiles($folder);
        try {
            $files->write('page.md', "# Page \x96 latin-1\n", RepositoryFiles::hash("# Page\n"));
            $this->fail('the text was written');
        } catch (DpressException $e) {
            $this->assertStringContainsString('not valid UTF-8', $e->getMessage());
        }
        $this->assertSame("# Page\n", file_get_contents("$folder/page.md"));
    }

    /** A pull or an edit elsewhere, between opening the editor and saving it */
    public function testAFileChangedSinceItWasOpenedIsNotWrittenOver(): void {
        $folder = $this->folder();
        file_put_contents("$folder/page.md", "# Page\n");
        $files = new RepositoryFiles($folder);
        $opened = RepositoryFiles::hash($files->read('page.md'));
        file_put_contents("$folder/page.md", "# Page, pulled\n");
        try {
            $files->write('page.md', "# Mine\n", $opened);
            $this->fail('the file was written over');
        } catch (DpressException $e) {
            $this->assertStringContainsString('changed on disk', $e->getMessage());
        }
        $this->assertSame("# Page, pulled\n", file_get_contents("$folder/page.md"));
    }

    // --- what differs from the remote ---

    private function repositories(): string {
        exec('git --version', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('git is not installed');
        }
        $root = $this->folder();
        foreach (['docs', 'engine'] as $name) {
            $this->run_("git init -q --bare -b main $root/$name.git");
            $this->run_("git clone -q $root/$name.git $root/$name-writer");
            file_put_contents("$root/$name-writer/index.md", "# $name\n");
            $this->commitAll("$root/$name-writer", 'first');
            $this->run_("git -C $root/$name-writer push -q origin main");
        }
        // the engine's docs as a submodule of the docs, as docs-public has them
        $this->run_("git -C $root/docs-writer -c protocol.file.allow=always submodule add -q $root/engine.git engine");
        $this->commitAll("$root/docs-writer", 'submodule');
        $this->run_("git -C $root/docs-writer push -q origin main");
        $this->run_("git -c protocol.file.allow=always clone -q --recurse-submodules $root/docs.git $root/server");
        return "$root/server";
    }

    public function testACleanCloneHasNothingToSay(): void {
        $this->assertSame([], (new RepositoryStatus(new Git()))->changes($this->repositories()));
    }

    public function testAnEditedFileIsNotCommitted(): void {
        $server = $this->repositories();
        file_put_contents("$server/index.md", "# docs, edited\n");
        $this->assertSame(['index.md' => RepositoryStatus::UNCOMMITTED], (new RepositoryStatus(new Git()))->changes($server));
    }

    /** Asked of the submodule, by its path in the whole source - the way pages are stored */
    public function testAnEditedFileOfASubmodule(): void {
        $server = $this->repositories();
        file_put_contents("$server/engine/index.md", "# engine, edited\n");
        $this->assertSame(['engine/index.md' => RepositoryStatus::UNCOMMITTED], (new RepositoryStatus(new Git()))->changes($server));
    }

    public function testACommittedFileIsNotPushed(): void {
        $server = $this->repositories();
        file_put_contents("$server/index.md", "# docs, edited\n");
        $this->commitAll($server, 'local');
        $this->assertSame(['index.md' => RepositoryStatus::UNPUSHED], (new RepositoryStatus(new Git()))->changes($server));
    }

    public function testAFolderThatIsNotAClone(): void {
        $this->assertNull((new RepositoryStatus(new Git()))->changes($this->folder()));
    }

    /** What `git status --porcelain=v2 --branch -z` prints, a rename and a path with a space included */
    public function testStatusIsReadForItsPathsAndTheBranch(): void {
        $output = "# branch.oid 1234\0# branch.head main\0# branch.upstream origin/main\0# branch.ab +2 -0\0"
            ."1 .M N... 100644 100644 100644 abc abc a page.md\0"
            ."2 R. N... 100644 100644 100644 abc abc R100 new.md\0old.md\0"
            ."1 M. N... 100644 100644 100644 abc def b.md\0";
        $this->assertSame(
            ['paths' => ['a page.md', 'new.md', 'b.md'], 'upstream' => 'origin/main', 'ahead' => 2],
            RepositoryStatus::parseStatus($output)
        );
    }

    public function testADetachedHeadHasNoUpstream(): void {
        $status = RepositoryStatus::parseStatus("# branch.oid 1234\0# branch.head (detached)\0");
        $this->assertNull($status['upstream']);
        $this->assertSame([], $status['paths']);
    }
}
