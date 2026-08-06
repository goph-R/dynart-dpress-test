<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Controller\Admin\PluginAdminController;
use Dynart\Dpress\DpressWebApp;
use Dynart\Dpress\Plugin\AbstractPlugin;
use Dynart\Dpress\Plugin\Plugin;
use Dynart\Dpress\Plugin\PluginInterface;
use Dynart\Dpress\Plugin\PluginService;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Test\StubConfig;
use Dynart\Dpress\Test\RecordingEvents;
use Dynart\Dpress\Test\StubLogger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A settings service that answers from an array, and can be made to fail
 */
class StubSettings extends \Dynart\Dpress\Service\SettingService {

    public array $values = [];
    public bool $broken = false;

    public function __construct() {}

    public function get(string $name, mixed $default = null): mixed {
        if ($this->broken) {
            // what a database with no `setting` table does - which is every database that has
            // not been installed yet, and boot reads this before `dpress install` can have run
            throw new \RuntimeException("Table 'dpress.dp_setting' doesn't exist");
        }
        return $this->values[$name] ?? $default;
    }

    public function set(string $name, mixed $value): void {
        $this->values[$name] = $value;
    }
}

/**
 * Finding, enabling and loading plugins
 *
 * The load path is the interesting half, and what makes it interesting is that **it is not
 * allowed to throw**. Enabling lives in the database and the screen that disables a plugin is in
 * the admin, so a plugin that fataled on the way up would take away the only way to turn it off.
 * Most of what is below is one shape of broken each.
 *
 * @covers \Dynart\Dpress\Plugin\PluginService
 * @covers \Dynart\Dpress\Plugin\Plugin
 */
class PluginTest extends TestCase {

    private string $dir;
    private StubSettings $settings;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir().'/dpress-plugins-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
        $this->settings = new StubSettings();
    }

    protected function tearDown(): void {
        $this->removeDirectory($this->dir);
    }

    private function removeDirectory(string $path): void {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array)scandir($path), ['.', '..']) as $entry) {
            $full = $path.'/'.$entry;
            is_dir($full) ? $this->removeDirectory($full) : @unlink($full);
        }
        @rmdir($path);
    }

    private function service(array $config = []): PluginService {
        return new PluginService(
            new StubConfig($config + [PluginService::CONFIG_PATH => $this->dir]),
            $this->settings,
            new StubLogger(),
            new RecordingEvents()
        );
    }

    /** Writes a plugin folder, with whatever manifest lines are given */
    private function makePlugin(string $name, array $manifest = []): void {
        mkdir($this->dir.'/'.$name, 0777, true);
        $lines = [];
        foreach ($manifest as $key => $value) {
            $lines[] = $key.' = "'.$value.'"';
        }
        file_put_contents($this->dir.'/'.$name.'/'.PluginService::MANIFEST, join("\n", $lines));
    }

    // --- discovery ---

    public function testAFolderWithAManifestIsAPlugin(): void {
        $this->makePlugin('acme', ['title' => 'Acme', 'version' => '1.2.3', 'author' => 'somebody']);
        $plugins = $this->service()->all();
        $this->assertArrayHasKey('acme', $plugins);
        $this->assertSame('Acme', $plugins['acme']->title());
        $this->assertSame('1.2.3', $plugins['acme']->version());
        $this->assertSame(Plugin::STATUS_AVAILABLE, $plugins['acme']->status);
    }

    /**
     * Dropping a folder in is installing it, and the manifest is what makes it a folder worth
     * looking at - the same rule a theme follows
     */
    public function testAFolderWithoutAManifestIsNotAPlugin(): void {
        mkdir($this->dir.'/not-a-plugin');
        $this->assertSame([], $this->service()->all());
    }

    /**
     * A folder moved aside to turn it off, a `.git`, an editor's cache
     */
    public function testDotFoldersAreIgnored(): void {
        $this->makePlugin('.hidden-acme', ['title' => 'Acme']);
        $this->assertSame([], $this->service()->all());
    }

    public function testAMissingPluginsFolderIsNotAnError(): void {
        $service = $this->service([PluginService::CONFIG_PATH => $this->dir.'/nowhere']);
        $this->assertSame([], $service->all());
        $this->assertSame([], $service->load());
    }

    public function testTheTitleFallsBackToTheFolderName(): void {
        $this->makePlugin('acme', []);
        $this->assertSame('acme', $this->service()->find('acme')->title());
    }

    // --- which are enabled ---

    /**
     * Order is subscription order, so it is a list and enabling appends
     */
    public function testEnablingAppendsToTheOrder(): void {
        $this->makePlugin('first', []);
        $this->makePlugin('second', []);
        $service = $this->service();
        $service->enable('second');
        $service->enable('first');
        $this->assertSame(['second', 'first'], $service->enabledNames());
    }

    public function testEnablingTwiceDoesNotDuplicate(): void {
        $this->makePlugin('acme', []);
        $service = $this->service();
        $service->enable('acme');
        $service->enable('acme');
        $this->assertSame(['acme'], $service->enabledNames());
    }

    public function testEnablingSomethingThatIsNotThereIsRefused(): void {
        $this->expectException(\Dynart\Dpress\DpressException::class);
        $this->service()->enable('nope');
    }

    /**
     * Disabling deliberately does not check the folder exists: the reason to disable something
     * may be that it is enabled, broken and half deleted
     */
    public function testSomethingNotOnDiskCanStillBeDisabled(): void {
        $service = $this->service();
        $this->settings->values[\Dynart\Dpress\Entity\Setting::PLUGINS] = 'ghost';
        $service->disable('ghost');
        $this->assertSame([], $service->enabledNames());
    }

    /**
     * The list is read during boot, and on a database that has not been installed there is no
     * `setting` table to read it from. No table, no plugins, and `dpress install` runs.
     */
    public function testNoSettingsTableMeansNoPlugins(): void {
        $this->makePlugin('acme', []);
        $this->settings->broken = true;
        $service = $this->service();
        $this->assertSame([], $service->enabledNames());
        $this->assertSame([], $service->load());
    }

    // --- loading, and the four ways it goes wrong ---

    public function testAnEnabledPluginThatIsGoneFromDiskIsMissingRatherThanFatal(): void {
        $this->settings->values[\Dynart\Dpress\Entity\Setting::PLUGINS] = 'ghost';
        $service = $this->service();
        $this->assertSame([], $service->load());
        $this->assertSame(Plugin::STATUS_MISSING, $service->find('ghost')->status);
    }

    public function testAPluginNamingNoClassFailsWithAReason(): void {
        $this->makePlugin('acme', ['namespace' => 'Acme\\Test']);
        $this->assertFailsWith('acme', 'names no class');
    }

    public function testAPluginNamingNoNamespaceFailsWithAReason(): void {
        $this->makePlugin('acme', ['class' => 'Acme\\Test\\Plugin']);
        $this->assertFailsWith('acme', 'names no namespace');
    }

    /**
     * The single most likely way for a plugin to be broken, and it raises an `Error` rather than
     * an `Exception` - which is why the catch is on `Throwable`
     */
    public function testAPluginWhoseClassIsNotThereFailsWithAReason(): void {
        $this->makePlugin('acme', ['namespace' => 'Acme\\Test', 'class' => 'Acme\\Test\\Missing']);
        $this->assertFailsWith('acme', 'was not found');
    }

    public function testAClassThatIsNotAPluginFailsWithAReason(): void {
        $this->makePlugin('acme', ['namespace' => 'Acme\\Test', 'class' => \stdClass::class]);
        $this->assertFailsWith('acme', 'does not implement');
    }

    private function assertFailsWith(string $name, string $fragment): void {
        $this->settings->values[\Dynart\Dpress\Entity\Setting::PLUGINS] = $name;
        $service = $this->service();
        $loaded = $service->load();
        $this->assertSame([], $loaded, 'a broken plugin counted as loaded');
        $plugin = $service->find($name);
        $this->assertSame(Plugin::STATUS_FAILED, $plugin->status);
        $this->assertStringContainsString($fragment, $plugin->error);
    }

    /**
     * One broken plugin must not stop the next one, or a site with two of them loses both to
     * whichever is listed first
     */
    public function testABrokenPluginDoesNotStopTheOnesAfterIt(): void {
        $this->makePlugin('broken', ['namespace' => 'Acme\\Test', 'class' => 'Acme\\Test\\Missing']);
        $this->makePlugin('fine', []);
        $this->settings->values[\Dynart\Dpress\Entity\Setting::PLUGINS] = 'broken,fine';
        $service = $this->service();
        $service->load();
        $this->assertSame(Plugin::STATUS_FAILED, $service->find('broken')->status);
        // 'fine' has no class either, so it fails too - what is being asserted is that it was
        // *reached*, rather than abandoned when the first one threw
        $this->assertSame(Plugin::STATUS_FAILED, $service->find('fine')->status);
        $this->assertNotSame('', $service->find('fine')->error);
    }

    /**
     * The escape hatch, for when a plugin breaks something subtler than boot - the login form,
     * say, which cannot be fixed from a screen you can no longer reach
     */
    public function testTheConfigSwitchLoadsNothing(): void {
        $this->makePlugin('acme', []);
        $this->settings->values[\Dynart\Dpress\Entity\Setting::PLUGINS] = 'acme';
        $service = $this->service([PluginService::CONFIG_OFF => true]);
        $this->assertTrue($service->isOff());
        $this->assertSame([], $service->load());
        $this->assertSame(Plugin::STATUS_AVAILABLE, $service->find('acme')->status);
    }

    public function testLoadRunsOnce(): void {
        $service = $this->service();
        $this->assertSame($service->load(), $service->load());
    }

    // --- the contract ---

    /**
     * Every method has a no-op default, so a plugin adding one field type is four lines and a
     * method added to the interface later does not break every plugin in existence
     */
    public function testTheAbstractPluginContributesNothing(): void {
        $plugin = new class extends AbstractPlugin {};
        foreach (['services', 'controllers', 'entities', 'migrations', 'widgets', 'permissions',
                  'views', 'assets'] as $method) {
            $this->assertSame([], $plugin->$method(), "$method does not default to nothing");
        }
        $this->assertNull($plugin->register());
    }

    public function testTheAbstractPluginImplementsTheWholeInterface(): void {
        $declared = (new ReflectionClass(PluginInterface::class))->getMethods();
        foreach ($declared as $method) {
            $this->assertTrue(
                method_exists(AbstractPlugin::class, $method->getName()),
                'AbstractPlugin has no default for '.$method->getName()
            );
        }
    }

    // --- the screen that turns a broken one off ---

    public function testTheAdminScreenIsRegisteredAndBehindItsPermission(): void {
        $this->assertContains(PluginAdminController::class, DpressWebApp::CONTROLLERS);
        $this->assertArrayHasKey(Permissions::PLUGIN_MANAGE, Permissions::CORE);
    }
}
