<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\DpressCliApp;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Dynart\Dpress\DpressCliApp
 */
class DpressCliAppTest extends TestCase {

    // --- The command table ---

    public function testEveryCommandHasACallableDescriptionAndConfigFlag(): void {
        foreach (DpressCliApp::COMMANDS as $name => $command) {
            $this->assertArrayHasKey('callable', $command, "$name has no callable");
            $this->assertArrayHasKey('description', $command, "$name has no description");
            $this->assertArrayHasKey('needsConfig', $command, "$name has no needsConfig");
            $this->assertIsBool($command['needsConfig'], "$name: needsConfig must be a bool");
            $this->assertNotSame('', $command['description'], "$name has an empty description");
        }
    }

    /**
     * The table is what `dpress help` renders and what the entry point checks before booting,
     * so a typo in a class or method name would only surface when the command is run.
     */
    public function testEveryCommandCallableExists(): void {
        foreach (DpressCliApp::COMMANDS as $name => $command) {
            [$className, $method] = $command['callable'];
            $this->assertTrue(class_exists($className), "$name points at a missing class $className");
            $this->assertTrue(
                method_exists($className, $method),
                "$name points at a missing method $className::$method"
            );
        }
    }

    /**
     * `CliApp::process()` hands the return value straight to `finish()`, which is typed
     * `string|int` - a command returning null is a TypeError at runtime.
     */
    public function testEveryCommandReturnsAnIntOrString(): void {
        foreach (DpressCliApp::COMMANDS as $name => $command) {
            [$className, $method] = $command['callable'];
            $returnType = (new \ReflectionMethod($className, $method))->getReturnType();
            $this->assertNotNull($returnType, "$name has no declared return type");
            $this->assertContains(
                (string)$returnType,
                ['int', 'string'],
                "$name must return int or string, CliApp passes it to finish()"
            );
        }
    }

    public function testTheExpectedCommandsArePresent(): void {
        $this->assertSame(
            ['install', 'upgrade', 'migrate:status', 'version', 'help'],
            array_keys(DpressCliApp::COMMANDS)
        );
    }

    // --- commandNeedsConfig ---

    public function testSchemaCommandsNeedAConfig(): void {
        $this->assertTrue(DpressCliApp::commandNeedsConfig('install'));
        $this->assertTrue(DpressCliApp::commandNeedsConfig('upgrade'));
        $this->assertTrue(DpressCliApp::commandNeedsConfig('migrate:status'));
    }

    public function testHelpAndVersionWorkOutsideASite(): void {
        $this->assertFalse(DpressCliApp::commandNeedsConfig('help'));
        $this->assertFalse(DpressCliApp::commandNeedsConfig('version'));
    }

    /**
     * An unknown command has to reach the application so it can answer with the help, rather
     * than be stopped by a config error the user never asked for.
     */
    public function testAnUnknownCommandNeedsNoConfig(): void {
        $this->assertFalse(DpressCliApp::commandNeedsConfig('nosuchcommand'));
    }

    public function testNoCommandNeedsNoConfig(): void {
        $this->assertFalse(DpressCliApp::commandNeedsConfig(null));
    }
}
