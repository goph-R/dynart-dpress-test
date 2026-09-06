<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Service\Doctor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * `dpress doctor`, and the failures that give no sign of themselves
 *
 * The database half needs a database and is exercised on the running site. What is here is every
 * check that is a config value going in and a verdict coming out - which is where the ones worth
 * having live: a log directory inside the document root, a placeholder secret, and stored HTML
 * rendered for a domain the site no longer has.
 *
 * Each check is invoked directly rather than through `run()`, because `run()` stops at the first
 * unreachable database and these are the rows above and below that line.
 *
 * @covers \Dynart\Dpress\Service\Doctor
 */
class DoctorTest extends TestCase {

    /** No dependency is reached on these paths, so there is nothing to stand in for */
    private function doctor(): Doctor {
        return (new ReflectionClass(Doctor::class))->newInstanceWithoutConstructor();
    }

    private function call(string $method, array $arguments = []): mixed {
        $reflection = new ReflectionMethod(Doctor::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($this->doctor(), $arguments);
    }

    // --- a log directory inside the document root ---

    /**
     * The check is lexical on purpose. A site set to log into `public/` that has not logged yet
     * is the one moment this is free to fix, and asking the filesystem about a directory that
     * does not exist answers "no" - so the leak would stay quiet until it had already happened.
     */
    public function testALogDirectoryUnderPublicIsCaughtBeforeItExists(): void {
        $this->assertTrue($this->call('isInside', ['/var/www/site/public/logs', '/var/www/site/public']));
        $this->assertTrue($this->call('isInside', ['/var/www/site/public', '/var/www/site/public']));
    }

    public function testALogDirectoryAbovePublicIsFine(): void {
        $this->assertFalse($this->call('isInside', ['/var/www/site/logs', '/var/www/site/public']));
        $this->assertFalse($this->call('isInside', ['/var/log/dpress', '/var/www/site/public']));
    }

    /**
     * `publicity/` is not inside `public/`, and a plain `str_starts_with` on the two says it is
     */
    public function testASiblingWithASharedPrefixIsNotInside(): void {
        $this->assertFalse($this->call('isInside', ['/var/www/site/publicity', '/var/www/site/public']));
    }

    public function testBackslashesAndDoubledSlashesAreTheSamePath(): void {
        $this->assertTrue($this->call('isInside', ['C:\\site\\public\\logs', 'C:/site/public']));
        $this->assertTrue($this->call('isInside', ['/var/www//site/public//logs', '/var/www/site/public']));
    }

    public function testNothingIsInsideNothing(): void {
        $this->assertFalse($this->call('isInside', ['/var/www/site/logs', '']));
        $this->assertFalse($this->call('isInside', ['', '/var/www/site/public']));
    }

    // --- the statuses, which are what an exit code is made of ---

    /**
     * A warning is not a failure. `utf8`, a development environment and an unrecorded render
     * address are all things a site runs on, and failing a deploy over one is how a check
     * becomes something people append `|| true` to.
     */
    public function testOnlyAFailureFailsTheRun(): void {
        $doctor = $this->doctor();
        $ok = ['name' => 'a', 'status' => Doctor::OK, 'detail' => '', 'fix' => ''];
        $warn = ['name' => 'b', 'status' => Doctor::WARN, 'detail' => '', 'fix' => ''];
        $fail = ['name' => 'c', 'status' => Doctor::FAIL, 'detail' => '', 'fix' => ''];

        $this->assertTrue($doctor->passed([$ok, $warn]));
        $this->assertFalse($doctor->passed([$ok, $warn, $fail]));
        $this->assertSame(
            [Doctor::OK => 1, Doctor::WARN => 1, Doctor::FAIL => 1],
            $doctor->counts([$ok, $warn, $fail])
        );
    }

    public function testAnEmptyRunPasses(): void {
        $this->assertTrue($this->doctor()->passed([]));
    }

    // --- every row carries the thing to do about it ---

    /**
     * A warning nobody can act on is noise. The charset check was the model for this - it says
     * what to run - and a row added later without one would quietly break the pattern.
     */
    public function testEveryProblemSaysWhatToDoAboutIt(): void {
        $doctor = $this->doctor();
        foreach (['warn', 'fail'] as $status) {
            $method = new ReflectionMethod(Doctor::class, $status);
            $method->setAccessible(true);
            $row = $method->invoke($doctor, 'Name', 'detail', 'the fix');
            $this->assertSame($status, $row['status']);
            $this->assertNotSame('', $row['fix'], "a $status with no fix is noise");
        }
        $method = new ReflectionMethod(Doctor::class, 'ok');
        $method->setAccessible(true);
        $this->assertSame('', $method->invoke($doctor, 'Name', 'detail')['fix'], 'an ok needs no fix');
    }

    // --- what the extension check reads ---

    /**
     * The list comes out of `composer.json` rather than being written again here. A second list
     * goes stale in both directions: a requirement added to the manifest that nothing checks, or
     * one removed from it that this still demands.
     */
    public function testTheExtensionListIsTheManifest(): void {
        $manifest = json_decode(
            (string)file_get_contents(\Dynart\Dpress\Dpress::path('composer.json')), true
        );
        $declared = [];
        foreach (array_keys((array)($manifest['require'] ?? [])) as $package) {
            if (str_starts_with((string)$package, 'ext-')) {
                $declared[] = substr((string)$package, 4);
            }
        }
        $this->assertNotEmpty($declared, 'composer.json declares no extensions to check');
        $this->assertContains('mbstring', $declared, 'the manifest lost ext-mbstring');
    }

    // --- the setting the whole thing turns on ---

    /**
     * `content:rerender` writes it and `doctor` reads it. Nothing at runtime connects the two, so
     * a renamed constant would leave the check silently comparing against nothing - which reads
     * as "never recorded" on a site that records it every time.
     */
    public function testTheRenderAddressIsWrittenWhereTheCheckReadsIt(): void {
        $this->assertSame('content_rendered_for', Setting::CONTENT_RENDERED_FOR);
        foreach (['src/Cli/ContentCommands.php', 'src/Cli/SchemaCommands.php'] as $file) {
            $this->assertStringContainsString(
                'Setting::CONTENT_RENDERED_FOR',
                (string)file_get_contents(\Dynart\Dpress\Dpress::path($file)),
                "$file no longer records the address doctor compares"
            );
        }
    }

    /**
     * `install` is safe to repeat and is what an upgrade runs, so recording the address every
     * time would overwrite the evidence of exactly the mismatch this exists to find - and the
     * check would then pass on the one site it was written for.
     */
    public function testAnUpgradeDoesNotOverwriteTheRecordedAddress(): void {
        $source = (string)file_get_contents(\Dynart\Dpress\Dpress::path('src/Cli/SchemaCommands.php'));
        $this->assertStringContainsString('if (!$wasInstalled) {', $source);
        $this->assertMatchesRegularExpression(
            '/if \(!\$wasInstalled\) \{\s*\$this->recordRenderAddress\(\);/', $source,
            'the render address is recorded outside the fresh-install branch'
        );
    }
}
