<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Dpress;
use Dynart\Dpress\DpressServices;
use Dynart\Dpress\Plugin\AbstractPlugin;
use Dynart\Dpress\Plugin\Plugin;
use Dynart\Dpress\Plugin\PluginService;
use Dynart\Dpress\Service\AdminSections;
use Dynart\Dpress\Test\StubLogger;
use PHPUnit\Framework\TestCase;

/**
 * A plugin service with only the part that finds a plugin's own files
 */
class FileFindingPlugins extends PluginService {
    public function __construct() {}
    public function fileOf(Plugin $record, string $relative): ?string { return $this->pluginFile($record, $relative); }
}

/**
 * The admin's navigation as a registry the core and the plugins both add to
 *
 * @covers \Dynart\Dpress\Service\AdminSections
 * @covers \Dynart\Dpress\Plugin\PluginService
 * @covers \Dynart\Dpress\Plugin\AbstractPlugin
 * @covers \Dynart\Dpress\Dpress
 */
class AdminSectionsTest extends TestCase {

    private StubLogger $logger;

    private function sections(): AdminSections {
        $this->logger = new StubLogger();
        $sections = new AdminSections($this->logger);
        DpressServices::registerAdminSections($sections);
        return $sections;
    }

    private function keys(AdminSections $sections): array {
        return array_column($sections->sections(), 'key');
    }

    private function section(string $key, array $more = []): array {
        return ['key' => $key, 'label' => ucfirst($key), 'route' => '/admin/'.$key] + $more;
    }

    // --- the core ---

    /**
     * The core's own, through the call a plugin makes, in the order the navigation always had
     */
    public function testTheCoresSectionsAreInTheirOrder(): void {
        $this->assertSame(
            ['dashboard', 'content', 'pages', 'media', 'taxonomy', 'menus', 'blocks', 'users', 'roles', 'plugins', 'settings'],
            $this->keys($this->sections())
        );
    }

    // --- where a plugin's goes ---

    public function testAPluginsSectionGoesAfterTheOneItNames(): void {
        $sections = $this->sections();
        $sections->add($this->section('recipes', ['after' => 'media']), 'recipes');
        $keys = $this->keys($sections);
        $this->assertSame(array_search('media', $keys) + 1, array_search('recipes', $keys));
    }

    public function testOrBeforeIt(): void {
        $sections = $this->sections();
        $sections->add($this->section('stats', ['before' => 'dashboard']), 'stats');
        $this->assertSame('stats', $this->keys($sections)[0]);
    }

    /**
     * Saying nothing puts it at the end of the content group - after Blocks, before Users
     */
    public function testWithNoPositionItGoesAfterTheContent(): void {
        $sections = $this->sections();
        $sections->add($this->section('recipes'), 'recipes');
        $keys = $this->keys($sections);
        $this->assertSame(array_search('blocks', $keys) + 1, array_search('recipes', $keys));
        $this->assertSame(array_search('recipes', $keys) + 1, array_search('users', $keys));
    }

    /**
     * Two plugins after the same section keep the order they were added in, rather than each
     * landing in front of the last
     */
    public function testTwoAfterTheSameSectionKeepTheirOrder(): void {
        $sections = $this->sections();
        $sections->add($this->section('first'), 'one');
        $sections->add($this->section('second'), 'two');
        $keys = $this->keys($sections);
        $this->assertSame(['blocks', 'first', 'second', 'users'],
            array_slice($keys, array_search('blocks', $keys), 4));
    }

    /**
     * Named before or after being added makes no difference - the order is worked out on reading,
     * which is also why a plugin loaded where the core never adds its own costs nothing
     */
    public function testTheOrderDoesNotDependOnWhenItWasAdded(): void {
        $sections = new AdminSections(new StubLogger());
        $sections->add($this->section('recipes', ['after' => 'media']), 'recipes');
        DpressServices::registerAdminSections($sections);
        $keys = $this->keys($sections);
        $this->assertSame(array_search('media', $keys) + 1, array_search('recipes', $keys));
    }

    /**
     * A key that is not there - a plugin switched off, a typo - is the default place, not lost
     */
    public function testAnUnknownPositionFallsBackToTheDefault(): void {
        $sections = $this->sections();
        $sections->add($this->section('recipes', ['after' => 'no-such-section']), 'recipes');
        $keys = $this->keys($sections);
        $this->assertSame(array_search('blocks', $keys) + 1, array_search('recipes', $keys));
    }

    // --- what is refused ---

    /**
     * A plugin cannot take the Posts entry by naming its own section the same - and is told so
     */
    public function testAKeyAlreadyTakenIsLeftOutAndLogged(): void {
        $sections = $this->sections();
        $sections->add(['key' => 'content', 'label' => 'Mine now', 'route' => '/elsewhere'], 'greedy');
        $posts = array_values(array_filter($sections->sections(), fn($s) => $s['key'] === 'content'))[0];
        $this->assertSame('Posts', $posts['label']);
        $this->assertStringContainsString("'content' from 'greedy' is already taken", $this->logger->lines[0]['message'] ?? '');
    }

    public function testASectionWithoutARouteIsRefused(): void {
        $sections = $this->sections();
        $sections->add(['key' => 'half', 'label' => 'Half'], 'broken');
        $this->assertFalse($sections->has('half'));
        $this->assertNotEmpty($this->logger->lines);
    }

    public function testAPluginWithNoSectionsAddsNone(): void {
        $plugin = new class extends AbstractPlugin {};
        $this->assertSame([], $plugin->adminSections());
    }

    // --- a plugin's own icon ---

    private function pluginFolder(): string {
        $folder = sys_get_temp_dir().'/dpress-sections-'.getmypid();
        @mkdir($folder.'/icons', 0777, true);
        file_put_contents($folder.'/icons/recipes.svg',
            "<?php // a comment the page must not get ?>\n<svg xmlns=\"http://www.w3.org/2000/svg\"><circle r=\"1\"/></svg>");
        file_put_contents(dirname($folder).'/outside-'.getmypid().'.svg', '<svg><rect/></svg>');
        return $folder;
    }

    public function testAPluginsIconIsAFileInItsOwnFolder(): void {
        $folder = $this->pluginFolder();
        $found = (new FileFindingPlugins())->fileOf(new Plugin('recipes', $folder), 'icons/recipes.svg');
        $this->assertNotNull($found);
        $icon = Dpress::iconFile($found);
        $this->assertStringStartsWith('<svg', $icon);
        $this->assertStringNotContainsString('<?php', $icon);
    }

    /**
     * And never outside it: a `../` is no icon at all rather than a read of whatever it names
     */
    public function testAnIconPathCannotLeaveThePluginsFolder(): void {
        $folder = $this->pluginFolder();
        $plugins = new FileFindingPlugins();
        $this->assertNull($plugins->fileOf(new Plugin('recipes', $folder), '../outside-'.getmypid().'.svg'));
        $this->assertNull($plugins->fileOf(new Plugin('recipes', $folder), 'icons/missing.svg'));
        $this->assertNull($plugins->fileOf(new Plugin('recipes', ''), 'icons/recipes.svg'));
    }

    public function testAMissingIconFileIsTheGenericMark(): void {
        $this->assertSame(Dpress::icon('section'), Dpress::iconFile('/no/such/file.svg'));
    }
}
