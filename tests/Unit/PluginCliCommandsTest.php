<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\DpressCliApp;
use Dynart\Dpress\Plugin\AbstractPlugin;
use Dynart\Dpress\Plugin\PluginCliCommands;
use Dynart\Dpress\Test\StubLogger;
use PHPUnit\Framework\TestCase;

/**
 * `dpress` commands a plugin adds - the Docs plugin's `docs:build` the first
 *
 * @covers \Dynart\Dpress\Plugin\PluginCliCommands
 * @covers \Dynart\Dpress\Plugin\AbstractPlugin
 */
class PluginCliCommandsTest extends TestCase {

    private StubLogger $logger;

    private function commands(): PluginCliCommands {
        $this->logger = new StubLogger();
        return new PluginCliCommands($this->logger);
    }

    private function build(): array {
        return ['callable' => ['Acme\\DocsCommands', 'build'], 'description' => 'Build the docs',
                'params' => ['source'], 'flags' => ['quiet']];
    }

    public function testAPluginsCommandIsKeptInTheCoresShape(): void {
        $commands = $this->commands();
        $commands->add('docs:build', $this->build(), 'docs', array_keys(DpressCliApp::COMMANDS));
        $this->assertSame([
            'callable' => ['Acme\\DocsCommands', 'build'], 'description' => 'Build the docs',
            'params' => ['source'], 'flags' => ['quiet'], 'plugin' => 'docs',
        ], $commands->all()['docs:build']);
    }

    /**
     * `dpress upgrade` answering with a plugin's code is not something a plugin gets by naming
     * its own the same
     */
    public function testACoreCommandsNameCannotBeTaken(): void {
        $commands = $this->commands();
        $commands->add('upgrade', $this->build(), 'greedy', array_keys(DpressCliApp::COMMANDS));
        $this->assertFalse($commands->has('upgrade'));
        $this->assertStringContainsString("'upgrade' from the plugin 'greedy' is already taken", $this->logger->lines[0]['message']);
    }

    public function testNorAnotherPluginsOnce(): void {
        $commands = $this->commands();
        $commands->add('docs:build', $this->build(), 'docs');
        $commands->add('docs:build', ['callable' => ['Other', 'run']], 'other');
        $this->assertSame('docs', $commands->all()['docs:build']['plugin']);
        $this->assertNotEmpty($this->logger->lines);
    }

    public function testACommandNeedsANameAndACallable(): void {
        $commands = $this->commands();
        $commands->add('docs:half', ['description' => 'no callable'], 'docs');
        $commands->add('', $this->build(), 'docs');
        $this->assertSame([], $commands->all());
        $this->assertCount(2, $this->logger->lines);
    }

    public function testAPluginOffersNoCommandsUnlessItSaysSo(): void {
        $this->assertSame([], (new class extends AbstractPlugin {})->commands());
    }
}
